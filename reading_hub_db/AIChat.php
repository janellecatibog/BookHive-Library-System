<?php
require_once 'functions.php';
require_once 'config.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Ensure user is logged in
if (!isLoggedIn()) {
    echo '<div class="ai-chat-message-bot"><div class="ai-chat-bubble ai-chat-bubble-bot">Please log in to use the AI assistant.</div></div>';
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? $_SESSION['username'];
$user_role = getUserRole();

// Handle incoming AJAX message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    header('Content-Type: application/json');
    $user_message = trim($_POST['message']);
    
    error_log("AI Chat - Processing: " . $user_message);
    
    if (empty($user_message)) {
        echo json_encode(['success' => false, 'message' => 'Empty message.']);
        exit();
    }
    
    // Simple response logic for testing
    $response = handleSimpleAIResponse($user_message, $user_id, $conn);
    echo json_encode($response);
    exit();
}

function handleSimpleAIResponse($message, $user_id, $conn) {
    global $user_name;
    
    $lower_message = strtolower($message);
    
    // Check for different intents
    if (strpos($lower_message, 'loan') !== false || strpos($lower_message, 'borrow') !== false || strpos($lower_message, 'borrowing status') !== false) {
        return handleLoanQuery($user_id, $conn);
    } elseif (strpos($lower_message, 'search') !== false || strpos($lower_message, 'find') !== false || strpos($lower_message, 'find a book') !== false || strpos($lower_message, 'find a specific book') !== false) {
        return handleSearchQuery($message, $conn);
    } elseif (strpos($lower_message, 'popular') !== false || strpos($lower_message, 'top') !== false || strpos($lower_message, 'most borrowed') !== false) {
        return handlePopularBooks($conn);
    } elseif (strpos($lower_message, 'recommend') !== false || strpos($lower_message, 'suggest') !== false) {
        return handleRecommendation($user_id, $conn);
    } elseif (strpos($lower_message, 'penalty') !== false || strpos($lower_message, 'fine') !== false || strpos($lower_message, 'check fines') !== false) {
        return handlePenalties($user_id, $conn);
    } elseif (strpos($lower_message, 'availability') !== false || strpos($lower_message, 'available') !== false || strpos($lower_message, 'check book availability') !== false) {
        return handleAvailabilityQuery($message, $conn);
    } elseif (strpos($lower_message, 'bookstore') !== false || strpos($lower_message, 'partner') !== false || strpos($lower_message, 'find books elsewhere') !== false || strpos($lower_message, 'partner libraries') !== false) {
        return handlePartnerLibraries($conn);
    } elseif (strpos($lower_message, 'direction') !== false || strpos($lower_message, 'location') !== false || strpos($lower_message, 'get library directions') !== false) {
        return handleLibraryDirections();
    } elseif (strpos($lower_message, 'hello') !== false || strpos($lower_message, 'hi') !== false || strpos($lower_message, 'hey') !== false) {
        return [
            'content' => "Hello $user_name! I'm your AI library assistant. I can help you find specific books, check availability, manage loans, handle penalties, and guide you to external sources when books aren't available in our library. Just ask me about any book or library service!",
            'suggestions' => ['Find a specific book', 'Check book availability', 'Borrowing status', 'Check fines']
        ];
    } else {
        // REVISION: If no intent matches, assume it's a book search (e.g., user typed a title directly)
        // This fixes the issue where typing "Harry Potter" doesn't work
        return handleSearchQuery($message, $conn);
    }
}

function handleLoanQuery($user_id, $conn) {
    // FIXED: Changed student_id to user_id
    $sql = "SELECT b.title, l.due_date, l.status 
            FROM loans l 
            JOIN books b ON l.book_id = b.book_id 
            WHERE l.user_id = ? 
            ORDER BY l.due_date DESC 
            LIMIT 5";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $loans = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    if (!empty($loans)) {
        $content = "**Your Borrowing Status:**\n\n";
        foreach ($loans as $loan) {
            $status = $loan['status'] === 'overdue' ? '🔴 OVERDUE' : '🟢 On Time';
            $content .= "• **{$loan['title']}**\n  Due: {$loan['due_date']} | Status: {$status}\n\n";
        }
        return [
            'content' => $content,
            'suggestions' => ['Check fines', 'Search books', 'Popular books']
        ];
    } else {
        return [
            'content' => "You don't have any current loans. Would you like to browse our book collection?",
            'suggestions' => ['Search books', 'Popular books', 'Get recommendations']
        ];
    }
}

function handleSearchQuery($message, $conn) {
    // Extract search term
    $search_term = str_ireplace(['search', 'find', 'for', 'book', 'books', 'look', 'specific'], '', $message);
    $search_term = trim($search_term);
    
    if (empty($search_term) || strlen($search_term) < 2) {
        return [
            'content' => "What book or author would you like me to search for? Please be more specific.",
            'suggestions' => ['Harry Potter', 'Computer Science', 'Mathematics', 'Science Fiction']
        ];
    }
    
    $sql = "SELECT b.title, a.author_name, b.quantity_available 
            FROM books b 
            LEFT JOIN authors a ON b.author_id = a.author_id 
            WHERE b.title LIKE ? OR a.author_name LIKE ? 
            LIMIT 5";
    $stmt = $conn->prepare($sql);
    $like_term = "%" . $search_term . "%";
    $stmt->bind_param("ss", $like_term, $like_term);
    $stmt->execute();
    $result = $stmt->get_result();
    $books = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    if (!empty($books)) {
        $content = "**Books Found for '$search_term':**\n\n";
        $all_available = true;  // Track if any book is unavailable
        foreach ($books as $book) {
            $status = $book['quantity_available'] > 0 ? "✅ Available ({$book['quantity_available']} copies)" : "❌ Checked Out";
            $content .= "• **{$book['title']}** by {$book['author_name']}\n  Status: {$status}\n\n";
            if ($book['quantity_available'] == 0) {
                $all_available = false;
            }
        }
        
        // REVISION: If any book is unavailable, recommend partner libraries (show ALL, no filter)
        if (!$all_available) {
            $libraries = getPartnerLibraries();  // Remove $search_term to show all libraries
            $content .= "**Some books are unavailable here. Try these partner libraries:**\n";
            foreach ($libraries as $lib) {
                $content .= "• {$lib['name']} - {$lib['location']}\n";
            }
        }
        
        return [
            'content' => $content,
            'suggestions' => ['Check availability', 'Popular books', 'My loans']
        ];
    } else {
        // REVISION: If no books found, recommend partner libraries (show ALL, no filter)
        $libraries = getPartnerLibraries();  // Remove $search_term to show all libraries
        $content = "No books found for '$search_term' in our library.\n\n";
        $content .= "**Try these partner libraries where it may be available:**\n";
        foreach ($libraries as $lib) {
            $content .= "• {$lib['name']} - {$lib['location']}\n";
        }
        
        return [
            'content' => $content,
            'suggestions' => ['Popular books', 'Bookstores', 'Partner libraries']
        ];
    }
}

function handleAvailabilityQuery($message, $conn) {
    $search_term = str_ireplace(['check', 'availability', 'available', 'for', 'is', 'book'], '', $message);
    $search_term = trim($search_term);
    
    if (empty($search_term)) {
        return [
            'content' => "Which book would you like to check availability for?",
            'suggestions' => ['Harry Potter', 'Computer Science', 'Mathematics', 'Physics']
        ];
    }
    
    $sql = "SELECT b.title, a.author_name, b.quantity_available 
            FROM books b 
            LEFT JOIN authors a ON b.author_id = a.author_id 
            WHERE b.title LIKE ? 
            LIMIT 3";
    $stmt = $conn->prepare($sql);
    $like_term = "%" . $search_term . "%";
    $stmt->bind_param("s", $like_term);
    $stmt->execute();
    $result = $stmt->get_result();
    $books = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    if (!empty($books)) {
        $content = "**Book Availability:**\n\n";
        foreach ($books as $book) {
            $status = $book['quantity_available'] > 0 ? "✅ **Available** ({$book['quantity_available']} copies)" : "❌ **Not Available**";
            $content .= "• **{$book['title']}** by {$book['author_name']}\n  Status: {$status}\n\n";
        }
        
        return [
            'content' => $content,
            'suggestions' => ['Search books', 'Popular books', 'My loans']
        ];
    } else {
        return [
            'content' => "No books found matching '$search_term'.",
            'suggestions' => ['Search books', 'Popular books', 'Partner libraries']
        ];
    }
}

function handlePartnerLibraries($conn) {
    $libraries = getPartnerLibraries();
    
    $content = "**Partner Libraries & Bookstores:**\n\n";
    foreach ($libraries as $lib) {
        $contact = $lib['contact_info'] ?? 'Contact for hours';
        $content .= "• **{$lib['name']}**\n  📍 {$lib['location']}\n  📞 {$contact}\n\n";
    }
    
    return [
        'content' => $content,
        'suggestions' => ['Get library directions', 'Search books', 'Check availability']
    ];
}

function handleLibraryDirections() {
    $content = "**Library Directions & Information:**\n\n";
    $content .= "• **Main Library**\n  📍 123 Library Street, Academic City\n  🕒 Mon-Fri: 8AM-8PM, Sat-Sun: 10AM-6PM\n  📞 (555) 123-4567\n\n";
    $content .= "• **Digital Access**\n  🌐 ebook.access.com\n  📱 Available 24/7\n\n";
    $content .= "Need specific directions? Visit our website or contact us!";
    
    return [
        'content' => $content,
        'suggestions' => ['Partner libraries', 'Search books', 'Check availability']
    ];
}

function handlePopularBooks($conn) {
    $sql = "SELECT b.title, a.author_name, COUNT(l.loan_id) as borrow_count 
            FROM loans l 
            JOIN books b ON l.book_id = b.book_id 
            LEFT JOIN authors a ON b.author_id = a.author_id 
            GROUP BY b.book_id 
            ORDER BY borrow_count DESC 
            LIMIT 5";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    $books = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $content = "**Most Popular Books:**\n\n";
    foreach ($books as $book) {
        $content .= "• **{$book['title']}** by {$book['author_name']}\n  📊 Borrowed {$book['borrow_count']} times\n\n";
    }
    
    return [
        'content' => $content,
        'suggestions' => ['Search books', 'My loans', 'Get recommendations']
    ];
}

function handleRecommendation($user_id, $conn) {
    $sql = "SELECT b.title, a.author_name, b.quantity_available 
            FROM books b 
            LEFT JOIN authors a ON b.author_id = a.author_id 
            WHERE b.quantity_available > 0 
            ORDER BY RAND() 
            LIMIT 3";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    $books = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $content = "**Book Recommendations for You:**\n\n";
    foreach ($books as $book) {
        $content .= "• **{$book['title']}** by {$book['author_name']}\n  ✅ Available for borrowing\n\n";
    }
    
    return [
        'content' => $content,
        'suggestions' => ['Search books', 'Popular books', 'My loans']
    ];
}

function handlePenalties($user_id, $conn) {
    // FIXED: Changed student_id to user_id
    $sql = "SELECT p.penalty_id, b.title, p.amount, p.status 
            FROM penalties p 
            JOIN loans l ON p.loan_id = l.loan_id 
            JOIN books b ON l.book_id = b.book_id 
            WHERE l.user_id = ? AND p.status != 'paid'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $penalties = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    if (!empty($penalties)) {
        $total = array_sum(array_column($penalties, 'amount'));
        $content = "**Your Current Fines:**\n\nTotal: ₱" . number_format($total, 2) . "\n\n";
        foreach ($penalties as $penalty) {
            $content .= "• **{$penalty['title']}**\n  Amount: ₱" . number_format($penalty['amount'], 2) . " | Status: {$penalty['status']}\n\n";
        }
        return [
            'content' => $content,
            'suggestions' => ['My loans', 'Search books', 'Popular books']
        ];
    } else {
        return [
            'content' => "You don't have any outstanding fines. Great job!",
            'suggestions' => ['My loans', 'Search books', 'Popular books']
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BookHive AI Assistant</title>
    <link rel="stylesheet" href="ai-chat.css">
</head>
<body>
    <div class="ai-chat-popup" id="aiChatPopup">
        <!-- Header Section -->
        <div class="ai-chat-header">
            <div class="ai-chat-title">
                <i data-lucide="bot"></i>
                <span>AI Library Assistant</span>
            </div>
            <button class="ai-chat-close-btn" onclick="closeAIChat()">
                &times;
            </button>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions-grid">
            <button type="button" class="action-button" data-message="Find a Book">Find a Book</button>
            <button type="button" class="action-button" data-message="Borrowing Status">Borrowing Status</button>
            <button type="button" class="action-button" data-message="Check fines">Check fines</button>
            <button type="button" class="action-button" data-message="Partner Libraries">Partner Libraries</button>
        </div>

        <!-- Messages Area -->
        <div id="ai-chat-messages-list" class="ai-chat-messages-list">
            <!-- Initial Bot Message -->
            <div class="ai-chat-message-container ai-chat-message-bot">
                <div class="ai-chat-avatar ai-chat-avatar-bot">
                    AI
                </div>
                <div class="ai-chat-bubble ai-chat-bubble-bot">
                    <p>Hello <?php echo htmlspecialchars($user_name); ?>! I'm your AI library assistant. How can I help you today?</p>
                    <span class="ai-chat-timestamp"><?php echo date('h:i A'); ?></span>
                    
                    <div class="ai-chat-suggestions">
                        <span class="ai-chat-suggestion-badge" data-message="Find a specific book">Find a book</span>
                        <span class="ai-chat-suggestion-badge" data-message="Check book availability">Check availability</span>
                        <span class="ai-chat-suggestion-badge" data-message="My loans">My loans</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Input Area -->
        <div class="ai-chat-input-area">
            <textarea id="aiChatInput" placeholder="Ask me about books, loans, fines..." rows="1"></textarea>
            <button id="aiChatSendBtn" type="button">
                ↑
            </button>
        </div>
    </div>

    <script>
        // Simple and robust JavaScript implementation
        document.addEventListener('DOMContentLoaded', function() {
            console.log('AI Chat Popup initialized');
            
            const aiChatPopup = document.getElementById('aiChatPopup');
            const aiChatMessagesList = document.getElementById('ai-chat-messages-list');
            const aiChatInput = document.getElementById('aiChatInput');
            const aiChatSendBtn = document.getElementById('aiChatSendBtn');
            let isTyping = false;

            // Setup all event listeners
            function setupEventListeners() {
                // Send button click
                aiChatSendBtn.addEventListener('click', function() {
                    const message = aiChatInput.value.trim();
                    if (message) {
                        sendAIChatMessage(message);
                    }
                });

                // Enter key in input
                aiChatInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        const message = aiChatInput.value.trim();
                        if (message) {
                            sendAIChatMessage(message);
                        }
                    }
                });

                // Auto-resize textarea
                aiChatInput.addEventListener('input', function() {
                    this.style.height = 'auto';
                    this.style.height = (this.scrollHeight) + 'px';
                });

                // Action buttons
                document.querySelectorAll('.action-button').forEach(button => {
                    button.addEventListener('click', function() {
                        const message = this.getAttribute('data-message');
                        sendAIChatMessage(message);
                    });
                });

                // Suggestion badges
                document.querySelectorAll('.ai-chat-suggestion-badge').forEach(badge => {
                    badge.addEventListener('click', function() {
                        const message = this.getAttribute('data-message');
                        sendAIChatMessage(message);
                    });
                });

                // Focus on input when popup opens
                if (aiChatPopup.classList.contains('active')) {
                    aiChatInput.focus();
                }
            }

            function appendMessage(type, content, suggestions = []) {
                const messageContainer = document.createElement('div');
                messageContainer.classList.add('ai-chat-message-container', `ai-chat-message-${type}`);
                
                const avatar = document.createElement('div');
                avatar.classList.add('ai-chat-avatar', `ai-chat-avatar-${type}`);
                avatar.textContent = type === 'user' ? 'You' : 'AI';
                
                const bubble = document.createElement('div');
                bubble.classList.add('ai-chat-bubble', `ai-chat-bubble-${type}`);
                
                // Format content with line breaks and bold text
                const formattedContent = content
                    .replace(/\n/g, '<br>')
                    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
                    
                // Get current time in 12-hour format
                const now = new Date();
                const timeString = now.toLocaleTimeString('en-US', { 
                    hour: '2-digit', 
                    minute: '2-digit',
                    hour12: true 
                });
                    
                bubble.innerHTML = `<p>${formattedContent}</p><span class="ai-chat-timestamp">${timeString}</span>`;
                
                // Add suggestions if any
                if (suggestions.length > 0) {
                    const suggestionsDiv = document.createElement('div');
                    suggestionsDiv.classList.add('ai-chat-suggestions');
                    suggestions.forEach(suggestion => {
                        const badge = document.createElement('span');
                        badge.classList.add('ai-chat-suggestion-badge');
                        badge.textContent = suggestion;
                        badge.setAttribute('data-message', suggestion);
                        badge.addEventListener('click', function() {
                            sendAIChatMessage(suggestion);
                        });
                        suggestionsDiv.appendChild(badge);
                    });
                    bubble.appendChild(suggestionsDiv);
                }
                
                messageContainer.appendChild(avatar);
                messageContainer.appendChild(bubble);
                aiChatMessagesList.appendChild(messageContainer);
                
                scrollToBottom();
            }

            async function sendAIChatMessage(message) {
                if (!message || !message.trim()) {
                    console.log('Empty message, skipping');
                    return;
                }
                
                if (isTyping) {
                    console.log('Already typing, please wait');
                    return;
                }
                
                console.log('Sending message:', message);
                
                // Add user message to chat
                appendMessage('user', message);
                aiChatInput.value = '';
                aiChatInput.style.height = 'auto';
                
                // Show typing indicator
                showTypingIndicator();
                
                try {
                    const response = await fetch('AIChat.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `message=${encodeURIComponent(message)}`
                    });
                    
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    
                    const result = await response.json();
                    console.log('Response received:', result);
                    
                    hideTypingIndicator();
                    
                    if (result.content) {
                        appendMessage('bot', result.content, result.suggestions || []);
                    } else {
                        appendMessage('bot', 'I apologize, but I encountered an issue. Please try again.', []);
                    }
                    
                } catch (error) {
                    console.error('Error sending message:', error);
                    hideTypingIndicator();
                    appendMessage('bot', 'Sorry, I am having trouble connecting. Please check your internet connection and try again.', []);
                }
            }

            function showTypingIndicator() {
                if (isTyping) return;
                
                isTyping = true;
                const typingContainer = document.createElement('div');
                typingContainer.id = 'typing-indicator';
                typingContainer.classList.add('ai-chat-message-container', 'ai-chat-message-bot');
                
                typingContainer.innerHTML = `
                    <div class="ai-chat-avatar ai-chat-avatar-bot">
                        AI
                    </div>
                    <div class="ai-chat-bubble ai-chat-bubble-bot typing-indicator">
                        <div class="typing-dots">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                    </div>
                `;
                
                aiChatMessagesList.appendChild(typingContainer);
                scrollToBottom();
            }

            function hideTypingIndicator() {
                isTyping = false;
                const typingIndicator = document.getElementById('typing-indicator');
                if (typingIndicator) {
                    typingIndicator.remove();
                }
            }

            function scrollToBottom() {
                if (aiChatMessagesList) {
                    aiChatMessagesList.scrollTop = aiChatMessagesList.scrollHeight;
                }
            }

            // Initialize
            setupEventListeners();
            scrollToBottom();

            // Make functions available globally
            window.sendAIChatMessage = sendAIChatMessage;
            window.toggleAIChat = function() {
                aiChatPopup.classList.toggle('active');
                if (aiChatPopup.classList.contains('active')) {
                    aiChatInput.focus();
                }
            };
            window.closeAIChat = function() {
                aiChatPopup.classList.remove('active');
            };
        });
    </script>
</body>
</html>

<?php
// Close DB connection
$conn->close();
?>