<?php
require_once 'config.php';
requireUserType('admin');
$userCreationRoute = 'admin_user_creation.php';
if (empty($canonicalRouteEntry) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $routeQuery = http_build_query($_GET);
    header('Location: ' . $userCreationRoute . ($routeQuery === '' ? '' : '?' . $routeQuery));
    exit();
}

// Check session timeout
if (isSessionTimeout()) {
    logoutUser();
    header("Location: login.php?timeout=1");
    exit();
}
$_SESSION['last_activity'] = time();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $user_type = $_POST['user_type'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validation
    if (empty($username) || empty($email) || empty($first_name) || empty($last_name) || empty($user_type) || empty($password)) {
        $error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (!in_array($user_type, ['admin', 'staff'])) {
        $error = "Invalid user type selected.";
    } else {
        $conn = getDBConnection();
        if ($conn) {
            try {
                // Check if username already exists
                $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$username]);
                if ($stmt->fetch()) {
                    $error = "Username already exists.";
                } else {
                    // Check if email already exists
                    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
                    $stmt->execute([$email]);
                    if ($stmt->fetch()) {
                        $error = "Email already exists.";
                    } else {
                        // Create new user
                        $password_hash = password_hash($password, PASSWORD_DEFAULT);

                        $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, user_type, first_name, last_name)
                                              VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$username, $email, $password_hash, $user_type, $first_name, $last_name]);

                        $message = "User created successfully!";
                        logActivity('Added user: ' . $username, 'Users', 'Success', $conn);

                        // Clear form
                        $_POST = [];
                    }
                }
            } catch (PDOException $e) {
                $error = "Failed to create user. Please try again.";
                error_log("User creation error: " . $e->getMessage());
                logActivity('Added user: ' . $username, 'Users', 'Failed', $conn);
            }
        } else {
            $error = "Database connection failed. Please try again later.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create User - EcoTrack Admin</title>
    <?php include 'includes/theme_head.php'; ?>
    <style>
        * {
          margin: 0;
          padding: 0;
          box-sizing: border-box;
        }

        body {
          font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
          background: #f5f5f5;
          color: #333;
        }

        .header {
          background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
          color: white;
          padding: 1rem 2rem;
          display: flex;
          justify-content: space-between;
          align-items: center;
        }

        .header h1 {
          font-size: 1.5rem;
        }

        .user-info {
          display: flex;
          align-items: center;
          gap: 1rem;
        }

        .user-info span {
          background: rgba(255, 255, 255, 0.2);
          padding: 0.5rem 1rem;
          border-radius: 20px;
        }

        .btn {
          background: rgba(255, 255, 255, 0.2);
          color: white;
          border: 1px solid rgba(255, 255, 255, 0.3);
          padding: 0.5rem 1rem;
          border-radius: 5px;
          cursor: pointer;
          text-decoration: none;
          transition: background 0.3s;
        }

        .btn:hover {
          background: rgba(255, 255, 255, 0.3);
        }

        .container {
          max-width: 800px;
          margin: 2rem auto;
          padding: 0 2rem;
        }

        .card {
          background: white;
          padding: 2rem;
          border-radius: 10px;
          box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
          margin-bottom: 1.5rem;
        }

        .card h2 {
          color: #333;
          margin-bottom: 1.5rem;
          padding-bottom: 0.5rem;
          border-bottom: 2px solid #667eea;
        }

        .form-grid {
          display: grid;
          grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
          gap: 1.5rem;
          margin-bottom: 1.5rem;
        }

        .form-group {
          margin-bottom: 1.5rem;
        }

        label {
          display: block;
          margin-bottom: 0.5rem;
          color: #333;
          font-weight: 500;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"],
        select {
          width: 100%;
          padding: 0.75rem;
          border: 2px solid #e1e1e1;
          border-radius: 5px;
          font-size: 1rem;
          transition: border-color 0.3s;
        }

        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="password"]:focus,
        select:focus {
          outline: none;
          border-color: #667eea;
        }

        .btn-primary {
          background: #667eea;
          color: white;
          border: none;
          padding: 0.75rem 1.5rem;
          border-radius: 5px;
          cursor: pointer;
          text-decoration: none;
          transition: background 0.3s;
          font-size: 1rem;
        }

        .btn-primary:hover {
          background: #5a6fd8;
        }

        .btn-secondary {
          background: #6c757d;
          color: white;
          border: none;
          padding: 0.75rem 1.5rem;
          border-radius: 5px;
          cursor: pointer;
          text-decoration: none;
          transition: background 0.3s;
          font-size: 1rem;
        }

        .btn-secondary:hover {
          background: #5a6268;
        }

        .error {
          background: #fee;
          color: #c33;
          padding: 0.75rem;
          border-radius: 5px;
          margin-bottom: 1rem;
          border: 1px solid #fcc;
        }

        .success {
          background: #efe;
          color: #3c3;
          padding: 0.75rem;
          border-radius: 5px;
          margin-bottom: 1rem;
          border: 1px solid #cfc;
        }

        .actions {
          display: flex;
          gap: 1rem;
          margin-top: 2rem;
        }

        .password-requirements {
          background: #f8f9fa;
          padding: 1rem;
          border-radius: 5px;
          margin-top: 0.5rem;
          font-size: 0.85rem;
          color: #666;
        }

        .password-requirements h4 {
          color: #333;
          margin-bottom: 0.5rem;
        }

        .password-requirements ul {
          margin-left: 1.5rem;
        }

        .password-requirements li {
          margin: 0.25rem 0;
        }
    </style>
    <link rel="stylesheet" href="assets/css/ecotrack-theme.css">
</head>
<body>
    <div class="header">
        <h1>Create New User</h1>
        <div class="user-info">
            <span>Admin Panel</span>
            <a href="admin_dashboard.php" class="btn">Dashboard</a>
            <a href="admin_user_management.php" class="btn">Manage Users</a>
            <a href="login.php?logout=1" class="btn">Logout</a>
        </div>
    </div>

    <div class="container">
        <div class="card">
            <h2>Create New User Account</h2>

            <?php if (isset($error) && !empty($error)): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (isset($message) && !empty($message)): ?>
                <div class="success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <form method="post" action="" data-confirm-title="Create user account?" data-confirm-message="Review the account details before creating this user." data-confirm-action="Create user">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="username">Username:</label>
                        <input type="text" id="username" name="username" required
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address:</label>
                        <input type="email" id="email" name="email" required
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="first_name">First Name:</label>
                        <input type="text" id="first_name" name="first_name" required
                               value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="last_name">Last Name:</label>
                        <input type="text" id="last_name" name="last_name" required
                               value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="user_type">User Type:</label>
                        <select id="user_type" name="user_type" required>
                            <option value="">Select User Type</option>
                            <option value="staff" <?php echo ($_POST['user_type'] ?? '') === 'staff' ? 'selected' : ''; ?>>Staff</option>
                            <option value="admin" <?php echo ($_POST['user_type'] ?? '') === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        </select>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="password">Password:</label>
                        <input type="password" id="password" name="password" required>
                        <div class="password-requirements">
                            <h4>Password Requirements:</h4>
                            <ul>
                                <li>At least 8 characters long</li>
                                <li>Contains both letters and numbers</li>
                                <li>Not easily guessable</li>
                            </ul>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm Password:</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                </div>

                <div class="actions">
                    <button type="submit" class="btn-primary">Create User</button>
                    <a href="admin_user_management.php" class="btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <?php include 'includes/transaction_confirmation_modal.php'; ?>
</body>
</html>
