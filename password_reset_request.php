<?php
require_once 'config.php';
require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/SMTP.php';
require_once 'PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

initSession();

$error = '';
$passwordResetAvailable = isPasswordResetEnabled();
if (!$passwordResetAvailable) {
    unset($_SESSION['reset_email'], $_SESSION['reset_user_id'], $_SESSION['code_verified']);
    http_response_code(503);
}

if ($passwordResetAvailable && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $error = "Please enter your email address.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        $conn = getDBConnection();
        if ($conn) {
            try {
                // Check if email exists in database
                $stmt = $conn->prepare("SELECT id, username, email FROM users WHERE email = ? AND is_active = 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user) {
                    // Generate 6-digit code
                    $code = sprintf('%06d', random_int(0, 999999));

                    // Set expiration (5 minutes from now)
                    $expires_at = date('Y-m-d H:i:s', strtotime('+5 minutes'));

                    // Delete any existing unused codes for this user
                    $stmt = $conn->prepare("DELETE FROM verification_codes WHERE user_id = ? AND used = 0");
                    $stmt->execute([$user['id']]);

                    // Insert new code
                    $stmt = $conn->prepare("INSERT INTO verification_codes (user_id, code, expires_at) VALUES (?, ?, ?)");
                    $stmt->execute([$user['id'], $code, $expires_at]);

                    // Store in session for verification page
                    $_SESSION['reset_email'] = $email;
                    $_SESSION['reset_user_id'] = $user['id'];

                    // Send email with PHPMailer
                    $mail = new PHPMailer(true);

                    try {
                        // Server settings
                        $mail->isSMTP();
                        $mail->Host       = EMAIL_HOST;
                        $mail->SMTPAuth   = true;
                        $mail->Username   = EMAIL_USERNAME;
                        $mail->Password   = EMAIL_PASSWORD;
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port       = EMAIL_PORT;

                        // Recipients
                        $mail->setFrom(EMAIL_FROM, EMAIL_FROM_NAME);
                        $mail->addAddress($email);

                        // Content
                        $mail->isHTML(true);
                        $mail->Subject = 'Password Reset Verification Code - EcoTrack';
                        $mail->Body = "
                        <html>
                        <body style='font-family: Arial, sans-serif; padding: 20px;'>
                            <h2 style='color: #4caf50;'>Password Reset Verification</h2>
                            <p>Hello {$user['username']},</p>
                            <p>You requested a password reset for your EcoTrack account.</p>
                            <p><strong>Your 6-digit verification code is:</strong></p>
                            <div style='background: #f5f5f5; padding: 20px; text-align: center; font-size: 32px; font-weight: bold; letter-spacing: 10px; color: #4caf50; border-radius: 10px; margin: 20px 0;'>
                                {$code}
                            </div>
                            <p><strong>This code will expire in 5 minutes.</strong></p>
                            <p>If you didn't request this password reset, please ignore this email.</p>
                            <p>Best regards,<br>EcoTrack System</p>
                        </body>
                        </html>
                        ";

                        $mail->send();

                        // Redirect to verification code page
                        header("Location: password_reset_verification.php");
                        exit();
                    } catch (Exception $e) {
                        $error = "Failed to send verification email. Please try again.";
                        error_log("PHPMailer error: " . $e->getMessage());
                    }
                } else {
                    $error = "No account found with this email address.";
                }
            } catch (PDOException $e) {
                $error = "An error occurred. Please try again later.";
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
    <title>Forgot Password - EcoTrack</title>
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
    </style>
    <link rel="stylesheet" href="assets/css/ecotrack-theme.css">
</head>
<body>
    <div class="container">
        <?php if (!$passwordResetAvailable): ?>
            <div class="header">
                <h1>Password Reset Unavailable</h1>
                <p>Password reset email is not configured yet. Please contact an EcoTrack administrator.</p>
            </div>
        <?php else: ?>
            <div class="header">
                <h1>Forgot Password?</h1>
                <p>Enter your email address and we'll send you a 6-digit verification code to reset your password.</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="post" action="">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>

                <button type="submit" class="btn">Send Verification Code</button>
            </form>
        <?php endif; ?>

        <div class="back-link">
            <a href="login.php">← Back to Login</a>
        </div>
    </div>
</body>
</html>
