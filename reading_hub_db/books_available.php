<?php
require_once 'header.php';

// Fetch available books from DB
$books = [];
$sql = "SELECT b.book_id, b.title, a.author_name, b.year_level, g.genre_name, b.quantity_available, b.illustrator
        FROM books b
        LEFT JOIN authors a ON b.author_id = a.author_id
        LEFT JOIN genres g ON b.genre_id = g.genre_id
        WHERE b.quantity_available > 0
        ORDER BY b.title ASC";

if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $books[] = $row;
    }
    $result->free();
} else {
    echo "Error: " . $conn->error;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Available Books - BookHive</title>
    <style>
        /* Only keep the essential styles for the books page */
        .books-page {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .search-filter-container {
            display: flex;
            gap: 16px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }

        #searchInput {
            flex: 1;
            min-width: 300px;
            padding: 12px 16px;
            border: 2px solid #E5E5E5;
            border-radius: 8px;
            font-size: 16px;
        }

        .filter-dropdowns {
            display: flex;
            gap: 12px;
        }

        .filter-dropdowns select {
            padding: 12px 16px;
            border: 2px solid #E5E5E5;
            border-radius: 8px;
            background: white;
            font-size: 14px;
            min-width: 150px;
        }

        .section-title {
            font-size: 28px;
            font-weight: 700;
            color: #2B2B2B;
            margin-bottom: 24px;
        }

        .books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 24px;
        }

        .book-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border: 1px solid #E5E5E5;
            transition: all 0.3s ease;
        }

        .book-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        }

        .book-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .book-header h3 {
            font-size: 18px;
            font-weight: 600;
            color: #2B2B2B;
            margin: 0;
            flex: 1;
            margin-right: 12px;
        }

        .availability {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .availability.available {
            background: #28A745;
            color: white;
        }

        .availability.unavailable {
            background: #DC3545;
            color: white;
        }

        .author {
            color: #666666;
            font-size: 14px;
            margin-bottom: 16px;
        }

        .book-cover {
            width: 60px;
            height: 80px;
            background: linear-gradient(135deg, #D89233, #E8B14A);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .book-cover i {
            color: white;
            font-size: 24px;
        }

        .desc {
            color: #666666;
            font-size: 14px;
            margin-bottom: 16px;
            line-height: 1.5;
        }

        .book-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .genre-tag {
            background: rgba(216, 146, 51, 0.1);
            color: #D89233;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid rgba(216, 146, 51, 0.3);
        }

        .illustrator {
            color: #8B7355;
            font-size: 12px;
            font-style: italic;
        }

        .no-books {
            text-align: center;
            color: #666666;
            font-size: 18px;
            grid-column: 1 / -1;
            padding: 40px;
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .books-page {
                padding: 16px;
            }

            .search-filter-container {
                flex-direction: column;
            }

            #searchInput {
                min-width: 100%;
            }

            .filter-dropdowns {
                width: 100%;
            }

            .filter-dropdowns select {
                flex: 1;
            }

            .books-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .book-header {
                flex-direction: column;
                gap: 8px;
            }

            .book-header h3 {
                margin-right: 0;
            }

            .book-footer {
                flex-direction: column;
                gap: 8px;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body class="min-h-screen bg-background">
    <div class="books-page">
        <!-- Search and Filter Bar -->
        <div class="search-filter-container">
            <input type="text" id="searchInput" placeholder="Search by title, author, or keywords 🔍" onkeyup="filterBooks()">
            <div class="filter-dropdowns">
                <select id="filterGenre" onchange="filterBooks()">
                    <option value="">All Categories</option>
                    <?php
                    $genreResult = $conn->query("SELECT genre_name FROM genres ORDER BY genre_name ASC");
                    while ($genre = $genreResult->fetch_assoc()) {
                        echo '<option value="' . htmlspecialchars($genre['genre_name']) . '">' . htmlspecialchars($genre['genre_name']) . '</option>';
                    }
                    ?>
                </select>
                <select id="filterAuthor" onchange="filterBooks()">
                    <option value="">All Authors</option>
                    <?php
                    $authorResult = $conn->query("SELECT author_name FROM authors ORDER BY author_name ASC");
                    while ($author = $authorResult->fetch_assoc()) {
                        echo '<option value="' . htmlspecialchars($author['author_name']) . '">' . htmlspecialchars($author['author_name']) . '</option>';
                    }
                    ?>
                </select>
            </div>
        </div>

        <!-- Books Grid -->
        <h2 class="section-title">📚 Books Available</h2>
        <div id="booksGrid" class="books-grid">
            <?php if (!empty($books)): ?>
                <?php foreach ($books as $book): ?>
                    <div class="book-card" 
                         data-title="<?php echo strtolower($book['title']); ?>" 
                         data-author="<?php echo strtolower($book['author_name']); ?>" 
                         data-genre="<?php echo strtolower($book['genre_name']); ?>">
                        <div class="book-header">
                            <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                            <span class="availability <?php echo $book['quantity_available'] > 0 ? 'available' : 'unavailable'; ?>">
                                <?php echo $book['quantity_available'] > 0 ? $book['quantity_available'] . ' available' : 'Checked out'; ?>
                            </span>
                        </div>
                        <p class="author"><?php echo htmlspecialchars($book['author_name'] ?? 'N/A'); ?></p>
                        <div class="book-cover">
                            <i data-lucide="book-open"></i>
                        </div>
                        <p class="desc">A great read for <?php echo htmlspecialchars($book['year_level'] ?? 'students'); ?>.</p>
                        <div class="book-footer">
                            <span class="genre-tag"><?php echo htmlspecialchars($book['genre_name'] ?? 'General'); ?></span>
                            <span class="illustrator"><?php echo htmlspecialchars($book['illustrator'] ?? '-'); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="no-books">No books currently available.</p>
            <?php endif; ?>
        </div>
    </div>

    <?php include 'ai_chat_component.php'; ?>

    <script>
    function filterBooks() {
      const searchInput = document.getElementById("searchInput").value.toLowerCase();
      const filterGenre = document.getElementById("filterGenre").value.toLowerCase();
      const filterAuthor = document.getElementById("filterAuthor").value.toLowerCase();
      const books = document.querySelectorAll(".book-card");

      books.forEach(book => {
        const title = book.dataset.title;
        const author = book.dataset.author;
        const genre = book.dataset.genre;
        const match = 
          (title.includes(searchInput) || author.includes(searchInput)) &&
          (filterGenre === "" || genre === filterGenre) &&
          (filterAuthor === "" || author === filterAuthor);
        book.style.display = match ? "block" : "none";
      });
    }

    // Initialize Lucide icons
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    });
    </script>
</body>
</html>

<?php require_once 'footer.php'; ?>