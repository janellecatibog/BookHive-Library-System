<?php
require_once 'header.php';

// Redirect if not a student
if (getUserRole() !== 'student') {
    header("Location: librarian_dashboard.php");
    exit();
}

// ✅ Accurate time function (API + fallback)
function getAccurateTime() {
    $api_url = 'https://worldtimeapi.org/api/timezone/Asia/Manila';
    $response = @file_get_contents($api_url);

    if ($response !== false) {
        $data = json_decode($response, true);
        if (isset($data['datetime'])) {
            return new DateTime($data['datetime']);
        }
    }

    // Fallback if API fails
    return new DateTime('now', new DateTimeZone('Asia/Manila'));
}

// ✅ Library hours check using accurate time
function isLibraryOpenFromAPI() {
    $ph_time = getAccurateTime();
    $current_time = $ph_time->format('H:i');
    $current_day = $ph_time->format('N'); // 1 (Mon) to 7 (Sun)

    // Weekdays: 8:00 AM - 5:00 PM
    if ($current_day >= 1 && $current_day <= 5) {
        return ($current_time >= '08:00' && $current_time < '17:00');
    }
    // Weekends: 8:00 AM - 2:00 PM
    else {
        return ($current_time >= '08:00' && $current_time < '14:00');
    }
}

// ✅ Use accurate API-based check
$ph_time = getAccurateTime();
$current_ph_time = $ph_time->format('Y-m-d H:i:s');
$is_library_open = isLibraryOpenFromAPI();

$library_message = '';

if (!$is_library_open) {
    $current_day = $ph_time->format('N');

    if ($current_day >= 1 && $current_day <= 5) {
        $library_message = "Library is currently CLOSED. It opens Monday to Friday, 8:00 AM - 5:00 PM.";
    } else {
        $library_message = "Library is currently CLOSED. It opens Saturday to Sunday, 8:00 AM - 2:00 PM.";
    }

    // 🔔 Popup alert remains
    echo "<script>
        alert('📚 The library is currently closed. Some features may not be available until it reopens.');
    </script>";
}

$student_id = $_SESSION['user_id'];
// Assess penalties for overdue loans
assessPenalties($conn);
$loans = [];

// FIXED: Changed l.student_id to l.user_id
$sql = "SELECT l.loan_id, b.title, a.author_name, l.borrow_date, l.due_date, l.return_date, l.status,
               b.book_id, b.quantity_available,
               (SELECT SUM(p.amount) FROM penalties p WHERE p.loan_id = l.loan_id AND p.status = 'pending') AS pending_penalty
        FROM loans l
        JOIN books b ON l.book_id = b.book_id
        LEFT JOIN authors a ON b.author_id = a.author_id
        WHERE l.user_id = ?
        ORDER BY l.due_date ASC";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $loans[] = $row;
    }
    $stmt->close();
} else {
    echo "Error: " . $conn->error;
}

// Handle return book action - Only if library is open
if (isset($_POST['return_book']) && $is_library_open) {
    $loan_id = $_POST['loan_id'];
    
    // Update loan status to returned
    $return_sql = "UPDATE loans SET return_date = CURDATE(), status = 'returned' WHERE loan_id = ?";
    if ($stmt = $conn->prepare($return_sql)) {
        $stmt->bind_param("i", $loan_id);
        if ($stmt->execute()) {
            // Get book_id to update quantity
            $book_sql = "SELECT book_id FROM loans WHERE loan_id = ?";
            if ($book_stmt = $conn->prepare($book_sql)) {
                $book_stmt->bind_param("i", $loan_id);
                $book_stmt->execute();
                $book_stmt->bind_result($book_id);
                $book_stmt->fetch();
                $book_stmt->close();
                
                // Update book quantity
                $update_qty_sql = "UPDATE books SET quantity_available = quantity_available + 1 WHERE book_id = ?";
                if ($update_stmt = $conn->prepare($update_qty_sql)) {
                    $update_stmt->bind_param("i", $book_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
            }
            
            // Log the action
            logAudit($student_id, 'return_book', $loan_id, 'Returned book for loan ID: ' . $loan_id);
            
            $return_success = true;
            $return_message = 'The book has been successfully returned.';
        }
        $stmt->close();
    }
} elseif (isset($_POST['return_book']) && !$is_library_open) {
    $library_closed_error = "Library is currently closed. You can only return books during library hours.";
}

// Handle renew loan action - FIXED: Proper success message handling - Only if library is open
if (isset($_POST['renew_loan']) && $is_library_open) {
    $loan_id = $_POST['loan_id'];
    
    // Check if loan can be renewed (not overdue and not returned)
    $check_sql = "SELECT status, due_date FROM loans WHERE loan_id = ? AND status = 'borrowed'";
    if ($check_stmt = $conn->prepare($check_sql)) {
        $check_stmt->bind_param("i", $loan_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $loan_data = $check_result->fetch_assoc();
            $current_due_date = $loan_data['due_date'];
            
            // Calculate new due date (extend by 14 days from current due date)
            $renew_sql = "UPDATE loans SET due_date = DATE_ADD(due_date, INTERVAL 14 DAY) WHERE loan_id = ?";
            if ($stmt = $conn->prepare($renew_sql)) {
                $stmt->bind_param("i", $loan_id);
                if ($stmt->execute()) {
                    // Get the new due date
                    $new_due_sql = "SELECT due_date FROM loans WHERE loan_id = ?";
                    $new_stmt = $conn->prepare($new_due_sql);
                    $new_stmt->bind_param("i", $loan_id);
                    $new_stmt->execute();
                    $new_stmt->bind_result($new_due_date);
                    $new_stmt->fetch();
                    $new_stmt->close();
                    
                    // Log the action
                    logAudit($student_id, 'renew_loan', $loan_id, "Renewed loan ID: $loan_id. New due date: $new_due_date");
                    
                    // FIXED: Proper success message without DOMContentLoaded
                    $renew_success = true;
                    $renew_message = "Your loan has been extended for 14 more days. New due date: " . $new_due_date;
                } else {
                    $renew_error = "Unable to renew loan. Please try again.";
                }
                $stmt->close();
            }
        } else {
            $renew_error = "Loan cannot be renewed. It may be overdue or already returned.";
        }
        $check_stmt->close();
    }
} elseif (isset($_POST['renew_loan']) && !$is_library_open) {
    $library_closed_error = "Library is currently closed. You can only renew loans during library hours.";
}

// Calculate statistics - FIXED: This was missing the actual calculation
$stats = [
    'borrowed' => 0,
    'overdue' => 0,
    'returned' => 0,
    'penalty' => 0
];

// For each loan, calculate penalty if overdue and update stats
foreach ($loans as &$loan) {
    $loan['is_overdue'] = (new DateTime($loan['due_date']) < new DateTime()) && $loan['status'] !== 'returned';
    
    // Update statistics
    if ($loan['status'] === 'returned') {
        $stats['returned']++;
    } elseif ($loan['is_overdue']) {
        $stats['overdue']++;
        $stats['borrowed']++;
    } else {
        $stats['borrowed']++;
    }
    
    // Calculate penalty amount
    if ($loan['is_overdue']) {
        $due = new DateTime($loan['due_date']);
        $today = new DateTime();
        $days_overdue = max(0, $today->diff($due)->days);
        $loan['penalty_amount'] = $days_overdue * 20.00;  // 20 PHP per day
    } else {
        $loan['penalty_amount'] = 0;
    }
}
unset($loan);

// Calculate total pending penalties from displayed loans
$total_pending_penalties = 0;
foreach ($loans as $loan) {
    if ($loan['status'] !== 'returned' && $loan['penalty_amount'] > 0) {
        $total_pending_penalties += $loan['penalty_amount'];
    }
}

// Update penalty stat with the calculated total
$stats['penalty'] = $total_pending_penalties;

?>

<link rel="stylesheet" href="loans.css">

<!-- Library Closed Popup -->
<?php if (!$is_library_open): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
            icon: 'warning',
            title: 'Library Closed',
            html: `<?php echo $library_message; ?><br><br>
                   <small>Current Philippines Time: <?php echo $current_ph_time; ?></small><br><br>
                   <div style="text-align: left; background: #fff3cd; padding: 15px; border-radius: 8px; border-left: 4px solid #ffc107;">
                       <strong>⚠️ Services Unavailable:</strong>
                       <ul style="margin: 10px 0 0 0; padding-left: 20px;">
                           <li>Book Returns</li>
                           <li>Loan Renewals</li>
                           <li>Penalty Payments</li>
                           <li>New Book Borrowing</li>
                       </ul>
                   </div>`,
            confirmButtonColor: '#FFC107',
            confirmButtonText: 'I Understand',
            backdrop: true,
            allowOutsideClick: false,
            allowEscapeKey: false
        });
    });
</script>
<?php endif; ?>

<!-- Success/Error Messages -->
<?php if (isset($return_success) && $return_success): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
            icon: 'success',
            title: 'Book Returned!',
            text: '<?php echo $return_message; ?>',
            confirmButtonColor: '#28A745'
        });
    });
</script>
<?php endif; ?>

<?php if (isset($renew_success) && $renew_success): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
            icon: 'success',
            title: 'Loan Renewed!',
            text: '<?php echo $renew_message; ?>',
            confirmButtonColor: '#17A2B8'
        });
    });
</script>
<?php elseif (isset($renew_error)): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
            icon: 'error',
            title: 'Renewal Failed',
            text: '<?php echo $renew_error; ?>',
            confirmButtonColor: '#DC3545'
        });
    });
</script>
<?php endif; ?>

<?php if (isset($library_closed_error)): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
            icon: 'warning',
            title: 'Library Closed',
            text: '<?php echo $library_closed_error; ?>',
            confirmButtonColor: '#FFC107'
        });
    });
</script>
<?php endif; ?>

<div class="loans-container">
    <!-- Library Status Banner -->
    <div class="library-status-banner <?php echo $is_library_open ? 'open' : 'closed'; ?>">
        <div class="status-indicator">
            <i data-lucide="<?php echo $is_library_open ? 'door-open' : 'door-closed'; ?>" class="status-icon"></i>
            <span class="status-text">
                Library is <?php echo $is_library_open ? 'OPEN' : 'CLOSED'; ?>
            </span>
        </div>
        <div class="status-details">
            <?php if ($is_library_open): ?>
                <span class="hours">Weekdays: 8:00 AM - 5:00 PM | Weekends: 8:00 AM - 2:00 PM</span>
            <?php else: ?>
                <span class="hours"><?php echo $library_message; ?></span>
            <?php endif; ?>
            <span class="current-time">PH Time: <?php echo $current_ph_time; ?></span>
        </div>
    </div>

    <div class="loans-content">
        <div class="loans-header">
            <i data-lucide="book-open-text" class="header-icon"></i>
            <h2>My Loans & History</h2>
            <p>Track your borrowed books and reading history</p>
        </div>

        <div class="loans-grid">
            <!-- Statistics Cards -->
            <div class="loans-stats">
                <div class="stat-card borrowed">
                    <i data-lucide="book-open" class="stat-icon"></i>
                    <div class="stat-number"><?php echo $stats['borrowed']; ?></div>
                    <div class="stat-label">Currently Borrowed</div>
                </div>
                <div class="stat-card overdue">
                    <i data-lucide="alert-triangle" class="stat-icon"></i>
                    <div class="stat-number"><?php echo $stats['overdue']; ?></div>
                    <div class="stat-label">Overdue Books</div>
                </div>
                <div class="stat-card returned">
                    <i data-lucide="check-circle" class="stat-icon"></i>
                    <div class="stat-number"><?php echo $stats['returned']; ?></div>
                    <div class="stat-label">Returned Books</div>
                </div>
                <div class="stat-card penalty">
                    <i data-lucide="dollar-sign" class="stat-icon"></i>
                    <div class="stat-number">₱<?php echo number_format($stats['penalty'], 2); ?></div>
                    <div class="stat-label">Pending Penalties</div>
                </div>
            </div>

            <!-- Filter Tabs -->
            <div class="loans-filter" id="loansFilter">
                <div class="filter-tab active" data-filter="all">All Loans</div>
                <div class="filter-tab" data-filter="borrowed">Currently Borrowed</div>
                <div class="filter-tab" data-filter="overdue">Overdue</div>
                <div class="filter-tab" data-filter="returned">Returned</div>
                <div class="filter-tab" data-filter="penalty">With Penalties</div>
            </div>

            <!-- Loans Cards -->
            <div class="loan-cards" id="loanCards">
                <?php if (!empty($loans)): ?>
                    <?php foreach ($loans as $loan): ?>
                        <?php
                        // Determine card status and styling
                        $card_class = '';
                        $status_class = '';
                        $due_date_class = '';
                        $can_renew = false;

                        if ($loan['return_date']) {
                            $card_class = 'returned';
                            $status_class = 'returned';
                            $due_date_class = 'returned';
                        } else {
                            $today = new DateTime();
                            $due_date = new DateTime($loan['due_date']);

                            if ($today > $due_date) {
                                $card_class = 'overdue';
                                $status_class = 'overdue';
                                $due_date_class = 'overdue';
                            } else {
                                $card_class = 'borrowed';
                                $status_class = 'borrowed';
                                $interval = $today->diff($due_date);
                                if ($interval->days <= 3) {
                                    $due_date_class = 'due-soon';
                                }

                                // Check if can renew (not overdue)
                                $can_renew = true;
                            }
                        }

                        // Calculate estimated penalty for display
                        $estimated_penalty = 0;
                        if ($loan['pending_penalty'] > 0) {
                            $estimated_penalty = $loan['pending_penalty'];
                        } elseif ($card_class == 'overdue' && !$loan['return_date']) {
                            // Calculate days overdue
                            $daysOverdue = $today->diff($due_date)->days;
                            $dailyPenalty = 20.00;  // Assuming the penalty is 20 PHP per day
                            $estimated_penalty = $daysOverdue * $dailyPenalty;
                        }
                        ?>
                        
                        <div class="loan-card <?php echo $card_class; ?>" data-status="<?php echo $card_class; ?>" data-penalty="<?php echo ($estimated_penalty > 0) ? 'yes' : 'no'; ?>">
                            <div class="loan-card-header">
                                <div class="loan-book-info">
                                    <h3 class="loan-book-title"><?php echo htmlspecialchars($loan['title']); ?></h3>
                                    <p class="loan-book-author">
                                        <i data-lucide="user" class="meta-icon"></i>
                                        <?php echo htmlspecialchars($loan['author_name'] ?? 'Unknown Author'); ?>
                                    </p>
                                </div>
                                <div class="loan-status <?php echo $status_class; ?>">
                                    <?php echo ucfirst($loan['status']); ?>
                                </div>
                            </div>

                            <div class="loan-details">
                                <div class="loan-detail">
                                    <span class="detail-label">Borrow Date</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($loan['borrow_date']); ?></span>
                                </div>
                                <div class="loan-detail">
                                    <span class="detail-label">Due Date</span>
                                    <span class="detail-value <?php echo $due_date_class; ?>">
                                        <?php echo htmlspecialchars($loan['due_date']); ?>
                                        <?php if ($due_date_class == 'due-soon'): ?>
                                            <i data-lucide="clock-alert" class="meta-icon" style="margin-left: 5px;"></i>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="loan-detail">
                                    <span class="detail-label">Return Date</span>
                                    <span class="detail-value">
                                        <?php echo htmlspecialchars($loan['return_date'] ?? 'Not returned yet'); ?>
                                    </span>
                                </div>
                                <div class="loan-detail">
                                    <span class="detail-label">Loan ID</span>
                                    <span class="detail-value">#<?php echo htmlspecialchars($loan['loan_id']); ?></span>
                                </div>
                            </div>

                            <?php if ($estimated_penalty > 0): ?>
                                <div class="loan-penalty">
                                    <div class="penalty-header">
                                        <i data-lucide="alert-circle" class="penalty-icon"></i>
                                        <span class="penalty-title">Pending Penalty</span>
                                    </div>
                                    <div class="penalty-amount">₱<?php echo number_format($estimated_penalty, 2); ?></div>
                                    <div class="penalty-note">
                                        <?php if ($loan['pending_penalty'] > 0): ?>
                                            This penalty needs to be paid. Please visit the library.
                                        <?php else: ?>
                                            Estimated overdue penalty. Final amount will be calculated upon return.
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="loan-actions">
                                <?php if (!$loan['return_date']): ?>
                                    <?php if ($can_renew): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="loan_id" value="<?php echo $loan['loan_id']; ?>">
                                            <button type="submit" name="renew_loan" class="btn-action btn-renew" <?php echo !$is_library_open ? 'disabled' : ''; ?>>
                                                <i data-lucide="refresh-cw" class="btn-icon"></i>
                                                <?php echo $is_library_open ? 'Renew Loan' : 'Renew (Library Closed)'; ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="loan_id" value="<?php echo $loan['loan_id']; ?>">
                                        <button type="submit" name="return_book" class="btn-action btn-return" <?php echo !$is_library_open ? 'disabled' : ''; ?> onclick="<?php echo $is_library_open ? 'return confirmReturn(this)' : 'return false'; ?>">
                                            <i data-lucide="check" class="btn-icon"></i>
                                            <?php echo $is_library_open ? 'Return Book' : 'Return (Library Closed)'; ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                
                                <?php if ($estimated_penalty > 0): ?>
                                    <!-- FIXED: Use the actual pending penalty amount from database -->
                                    <button class="btn-action btn-pay" onclick="<?php echo $is_library_open ? 'payPenalty(' . $loan['loan_id'] . ', ' . number_format($loan['pending_penalty'] ?: $estimated_penalty, 2, '.', '') . ')' : 'showLibraryClosedMessage()'; ?>">
                                        <i data-lucide="dollar-sign" class="btn-icon"></i>
                                        <?php echo $is_library_open ? 'Pay Penalty' : 'Pay (Library Closed)'; ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i data-lucide="book-x" class="empty-icon"></i>
                        <h3>No Loan Records Found</h3>
                        <p>You haven't borrowed any books yet. Start exploring our library collection!</p>
                        <a href="books_available.php" class="btn-browse" <?php echo !$is_library_open ? 'onclick="showLibraryClosedMessage(); return false;"' : ''; ?>>
                            <i data-lucide="search" class="btn-icon"></i>
                            Browse Books
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function () {
        // Filter functionality
        const filterTabs = document.querySelectorAll('.filter-tab');
        const loanCards = document.querySelectorAll('.loan-card');
        
        filterTabs.forEach(tab => {
            tab.addEventListener('click', function() {
                // Update active tab
                filterTabs.forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                
                const filter = this.getAttribute('data-filter');
                
                // Filter cards
                loanCards.forEach(card => {
                    const status = card.getAttribute('data-status');
                    const hasPenalty = card.getAttribute('data-penalty');
                    
                    switch (filter) {
                        case 'all':
                            card.style.display = 'block';
                            break;
                        case 'borrowed':
                            card.style.display = status === 'borrowed' ? 'block' : 'none';
                            break;
                        case 'overdue':
                            card.style.display = status === 'overdue' ? 'block' : 'none';
                            break;
                        case 'returned':
                            card.style.display = status === 'returned' ? 'block' : 'none';
                            break;
                        case 'penalty':
                            card.style.display = hasPenalty === 'yes' ? 'block' : 'none';
                            break;
                    }
                });
            });
        });

        // Initialize Lucide icons
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    });

    function confirmReturn(button) {
        if (!confirm('Are you sure you want to mark this book as returned? This action cannot be undone.')) {
            return false;
        }
        return true;
    }

    // FIXED: Updated payPenalty function to work properly
    function payPenalty(loanId, amount) {
        console.log('Pay penalty clicked:', loanId, amount); // Debug log
        
        Swal.fire({
            title: 'Pay Penalty',
            html: `You are about to pay a penalty of <strong>₱${parseFloat(amount).toFixed(2)}</strong> for loan #${loanId}.<br><br>This action cannot be undone.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#28A745',
            cancelButtonColor: '#DC3545',
            confirmButtonText: 'Yes, Pay Now',
            cancelButtonText: 'Cancel',
            showLoaderOnConfirm: true,
            preConfirm: () => {
                return fetch('process_payment.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `loan_id=${loanId}&amount=${amount}`
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (!data.success) {
                        throw new Error(data.message || 'Payment failed');
                    }
                    return data;
                })
                .catch(error => {
                    Swal.showValidationMessage(`Request failed: ${error}`);
                });
            }
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    icon: 'success',
                    title: 'Payment Successful!',
                    text: 'Your penalty has been paid successfully.',
                    confirmButtonColor: '#28A745'
                }).then(() => {
                    // Reload the page to update the UI
                    window.location.reload();
                });
            }
        });
    }

    // Show library closed message
    function showLibraryClosedMessage() {
        Swal.fire({
            icon: 'warning',
            title: 'Library Closed',
            html: 'This service is only available during library hours.<br><br><strong>Library Hours:</strong><br>Weekdays: 8:00 AM - 5:00 PM<br>Weekends: 8:00 AM - 2:00 PM<br><br><small>All times are in Philippines Time</small>',
            confirmButtonColor: '#FFC107',
            confirmButtonText: 'I Understand'
        });
    }
	fetch('https://worldtimeapi.org/api/timezone/Asia/Manila')
  .then(res => res.json())
  .then(data => {
    const now = new Date(data.datetime);
    document.querySelector('.current-time').textContent = 'PH Time: ' + now.toLocaleString();
  })
  .catch(() => {
    // fallback if API fails
    console.log('Using fallback PHP time.');
  });
	

    // Add keyboard navigation for filters
    document.addEventListener('keydown', function(e) {
        const filterTabs = document.querySelectorAll('.filter-tab');
        const activeTab = document.querySelector('.filter-tab.active');
        let currentIndex = Array.from(filterTabs).indexOf(activeTab);
        
        if (e.key === 'ArrowRight') {
            currentIndex = (currentIndex + 1) % filterTabs.length;
            filterTabs[currentIndex].click();
        } else if (e.key === 'ArrowLeft') {
            currentIndex = (currentIndex - 1 + filterTabs.length) % filterTabs.length;
            filterTabs[currentIndex].click();
        }
    });
	
	
</script>

<!-- Debug section (remove this after testing) -->
<div style="display: none; background: #f8f9fa; padding: 10px; margin: 10px 0; border-radius: 5px;">
    <h4>Debug Info:</h4>
    <?php foreach ($loans as $loan): ?>
        <p>Loan ID: <?php echo $loan['loan_id']; ?> | 
           Pending Penalty: <?php echo $loan['pending_penalty']; ?> | 
           Estimated Penalty: <?php echo $loan['penalty_amount'] ?? 0; ?></p>
    <?php endforeach; ?>
</div>

<?php include 'ai_chat_component.php'; ?>
<?php
require_once 'footer.php';
?>