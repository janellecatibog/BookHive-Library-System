<?php
require_once 'functions.php';

if (isLoggedIn()) {
    redirectToDashboard();
}

$name = "";
$username = "";
$new_password = "";
$name_err = $username_err = $new_password_err = $success_msg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (empty(trim($_POST["name"]))) {
        $name_err = "Please enter your name.";
    } else {
        $name = trim($_POST["name"]);
    }

    if (empty(trim($_POST["username"]))) {
        $username_err = "Please enter your username.";
    } else {
        $username = trim($_POST["username"]);
    }

    if (empty(trim($_POST["new_password"]))) {
        $new_password_err = "Please enter your desired new password.";
    } else {
        $new_password = trim($_POST["new_password"]);
    }

    if (empty($name_err) && empty($username_err) && empty($new_password_err)) {
        // Create a notification for the librarian
        $message = "Student {$name} (Username: {$username}) has requested a password change. Desired new password: {$new_password}.";
        notifyLibrarian($message); // Function to send notification

        $success_msg = "Your request has been submitted. The librarian will review it shortly.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Password Change - BookHive</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        body {
            background-color: #E7DFD2;
            color: #2B2B2B;
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .forgot-password-container {
            max-width: 440px;
            width: 100%;
            background: #FDF9F3;
            border-radius: 20px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            padding: 48px;
            border: 1px solid rgba(216, 146, 51, 0.2);
        }

        .icon-container {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #BD1B19, #D89233);
            border-radius: 16px;
            margin: 0 auto 24px auto;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 20px -5px rgba(189, 27, 25, 0.3);
        }

        .icon-container i {
            color: white;
            font-size: 32px;
        }

        .welcome-section {
            text-align: center;
            margin-bottom: 32px;
        }

        .welcome-section h2 {
            font-size: 28px;
            font-weight: 700;
            color: #2B2B2B;
            margin-bottom: 8px;
        }

        .welcome-section p {
            color: #8B7355;
            font-size: 16px;
            line-height: 1.5;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #2B2B2B;
            margin-bottom: 8px;
        }

        .form-control {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #E7DFD2;
            border-radius: 12px;
            background: #FDF9F3;
            color: #2B2B2B;
            font-size: 16px;
            transition: all 0.3s;
            line-height: 1.5;
        }

        .form-control:focus {
            outline: none;
            border-color: #BD1B19;
            background: white;
            box-shadow: 0 0 0 3px rgba(189, 27, 25, 0.1);
            transform: translateY(-1px);
        }

        .form-control::placeholder {
            color: #8B7355;
            opacity: 0.7;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 16px 24px;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 16px;
            width: 100%;
            margin-top: 8px;
        }

        .btn-primary {
            background: #BD1B19;
            color: white;
        }

        .btn-primary:hover {
            background: #a31614;
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(189, 27, 25, 0.4);
        }

        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 14px;
            line-height: 1.5;
            border: 1px solid;
        }

        .alert-danger {
            background: rgba(189, 27, 25, 0.08);
            color: #BD1B19;
            border-color: rgba(189, 27, 25, 0.3);
        }

        .alert-success {
            background: rgba(40, 167, 69, 0.08);
            color: #28A745;
            border-color: rgba(40, 167, 69, 0.3);
        }

        .back-link {
            text-align: center;
            margin-top: 24px;
        }

        .back-link a {
            color: #BD1B19;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s;
        }

        .back-link a:hover {
            color: #D89233;
        }

        @media (max-width: 768px) {
            .forgot-password-container {
                margin: 16px;
                padding: 40px 32px;
                max-width: 100%;
            }
            
            .welcome-section h2 {
                font-size: 24px;
            }
            
            .icon-container {
                width: 64px;
                height: 64px;
                margin-bottom: 20px;
            }
            
            .icon-container i {
                font-size: 24px;
            }
        }

        @media (max-width: 480px) {
            .forgot-password-container {
                padding: 32px 24px;
            }
            
            body {
                padding: 16px;
            }
            
            .form-control {
                padding: 12px 14px;
                font-size: 15px;
            }
            
            .btn {
                padding: 14px 20px;
                font-size: 15px;
            }
        }
    </style>
</head>
<body class="min-h-screen bg-background">
    <div class="forgot-password-container">
        <!-- Icon Section -->
        <div class="icon-container">
            <i data-lucide="key"></i>
        </div>

        <!-- Welcome Section -->
        <div class="welcome-section">
            <h2>Request Password Change</h2>
            <p>Please fill in your details to request a password change</p>
        </div>

        <!-- Alert Messages -->
        <?php if (!empty($name_err)): ?>
            <div class="alert alert-danger"><?php echo $name_err; ?></div>
        <?php endif; ?>
        <?php if (!empty($username_err)): ?>
            <div class="alert alert-danger"><?php echo $username_err; ?></div>
        <?php endif; ?>
        <?php if (!empty($new_password_err)): ?>
            <div class="alert alert-danger"><?php echo $new_password_err; ?></div>
        <?php endif; ?>
        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-success"><?php echo $success_msg; ?></div>
        <?php endif; ?>

        <!-- Form -->
        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
            <div class="form-group">
                <label for="name">Full Name</label>
                <input type="text" 
                       id="name" 
                       name="name" 
                       class="form-control" 
                       placeholder="Enter your full name" 
                       value="<?php echo htmlspecialchars($name); ?>" 
                       required>
            </div>

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" 
                       id="username" 
                       name="username" 
                       class="form-control" 
                       placeholder="Enter your username" 
                       value="<?php echo htmlspecialchars($username); ?>" 
                       required>
            </div>

            <div class="form-group">
                <label for="new_password">New Password</label>
                <input type="password" 
                       id="new_password" 
                       name="new_password" 
                       class="form-control" 
                       placeholder="Enter your desired new password" 
                       required>
            </div>

            <button type="submit" class="btn btn-primary">
                Request Password Change
            </button>
        </form>

        <!-- Back to Login Link -->
        <div class="back-link">
            <a href="login.php">← Back to Login</a>
        </div>
    </div>

    <script>
        // Initialize Lucide icons
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });
    </script>
</body>
</html>