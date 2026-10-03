<?php
require_once 'config.php';

initSession();

$error = '';
$success = '';

if (!isPasswordResetEnabled()) {
    header('Location: password_reset_request.php?disabled=1');
    exit();
}

// Check if user has verified the code
if (!isset($_SESSION['code_verified']) || !isset($_SESSION['reset_user_id'])) {
    header("Location: password_reset_request.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($password) || empty($confirm_password)) {
        $error = "Please enter and confirm your new password.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match. Please try again.";
    } else {
        $conn = getDBConnection();
        if ($conn) {
            try {
                // Hash the new password
                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                if (!ensurePersistentLoginTokensTable($conn)) {
                    throw new RuntimeException('Unable to prepare remembered login storage.');
                }
                $conn->beginTransaction();
                $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->execute([$password_hash, $_SESSION['reset_user_id']]);
                if (!revokePersistentLoginsForUser($conn, $_SESSION['reset_user_id'])) {
                    throw new RuntimeException('Unable to revoke remembered logins.');
                }
                $actorStmt = $conn->prepare('SELECT id, username, first_name, last_name, user_type FROM users WHERE id = ?');
                $actorStmt->execute([$_SESSION['reset_user_id']]);
                $resetActor = $actorStmt->fetch() ?: ['username' => 'Unknown user', 'user_type' => 'Guest'];

                // Clear all verification codes for this user
                $stmt = $conn->prepare("DELETE FROM verification_codes WHERE user_id = ?");
                $stmt->execute([$_SESSION['reset_user_id']]);
                $conn->commit();
                clearPersistentLoginCookie();
                logActivity('Reset password through recovery', 'Authentication', 'Success', $conn, $resetActor);

                // Clear session
                unset($_SESSION['reset_email']);
                unset($_SESSION['reset_user_id']);
                unset($_SESSION['code_verified']);

                $success = "Password reset successfully! You can now login with your new password.";

                // Redirect to login after 3 seconds
                header("refresh:3;url=login.php?reset=success");
            } catch (Throwable $e) {
                if ($conn && $conn->inTransaction()) {
                    $conn->rollBack();
                }
                $error = "Database error. Please try again later.";
                error_log("Password reset error: " . $e->getMessage());
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
    <title>Reset Password - EcoTrack</title>
    <?php include 'includes/theme_head.php'; ?>
    <style>
        * {
          margin: 0;
          padding: 0;
          box-sizing: border-box;
        }

        body {
          font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
          background: linear-gradient(135deg, #8bc34a 0%, #4caf50 100%);
          min-height: 100vh;
          display: flex;
          align-items: center;
          justify-content: center;
          padding: 20px;
        }

        .container {
          background: white;
          padding: 40px;
          border-radius: 20px;
          box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
          width: 100%;
          max-width: 450px;
        }

        .header {
          text-align: center;
          margin-bottom: 30px;
        }

        .header h1 {
          color: #4caf50;
          font-size: 28px;
          margin-bottom: 10px;
        }

        .header p {
          color: #666;
          font-size: 14px;
        }

        .form-group {
          margin-bottom: 20px;
        }

        .form-group label {
          display: block;
          margin-bottom: 8px;
          color: #333;
          font-weight: 500;
        }

        .form-group input {
          width: 100%;
          padding: 15px;
          border: 2px solid #e0e0e0;
          border-radius: 10px;
          font-size: 16px;
          transition: border-color 0.3s;
        }

        .form-group input:focus {
          outline: none;
          border-color: #4caf50;
        }

        .btn {
          width: 100%;
          padding: 15px;
          background: #4caf50;
          color: white;
          border: none;
          border-radius: 10px;
          font-size: 16px;
          font-weight: 600;
          cursor: pointer;
          transition: all 0.3s;
        }

        .btn:hover {
          background: #45a049;
          transform: translateY(-2px);
          box-shadow: 0 5px 15px rgba(76, 175, 80, 0.3);
        }

        .error {
          background: #ffebee;
          color: #c62828;
          padding: 15px;
          border-radius: 10px;
          margin-bottom: 20px;
          border: 1px solid #ffcdd2;
          text-align: center;
        }

        .success {
          background: #e8f5e8;
          color: #2e7d32;
          padding: 15px;
          border-radius: 10px;
          margin-bottom: 20px;
          border: 1px solid #c8e6c9;
          text-align: center;
        }

        .back-link {
          text-align: center;
          margin-top: 20px;
        }

        .back-link a {
          color: #4caf50;
          text-decoration: none;
          font-weight: 500;
        }

        .back-link a:hover {
          text-decoration: underline;
        }

        .password-requirements {
          background: #f5f5f5;
          padding: 15px;
          border-radius: 10px;
          margin-bottom: 20px;
          font-size: 13px;
          color: #666;
        }

        .password-requirements h4 {
          color: #333;
          margin-bottom: 8px;
        }

        .password-requirements ul {
          margin-left: 20px;
        }
    </style>
    <link rel="stylesheet" href="assets/css/ecotrack-theme.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔒 Reset Password</h1>
            <p>Enter your new password below</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if (empty($success)): ?>
            <div class="password-requirements">
                <h4>Password Requirements:</h4>
                <ul>
                    <li>At least 6 characters long</li>
                    <li>Must match in both fields</li>
                </ul>
            </div>

            <form method="post" action="">
                <div class="form-group">
                    <label for="password">New Password</label>
                    <input type="password" id="password" name="password" required>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>

                <button type="submit" class="btn">Reset Password</button>
            </form>
        <?php endif; ?>

        <div class="back-link">
            <a href="login.php">← Back to Login</a>
        </div>
    </div>
</body>
</html>
