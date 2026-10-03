<?php
require_once 'config.php';

initSession();

$error = '';

if (!isPasswordResetEnabled()) {
    header('Location: password_reset_request.php?disabled=1');
    exit();
}

// Check if we have the email in session
if (!isset($_SESSION['reset_email']) || !isset($_SESSION['reset_user_id'])) {
    header("Location: password_reset_request.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $code = trim($_POST['code'] ?? '');

    if (empty($code)) {
        $error = "Please enter the verification code.";
    } elseif (!preg_match('/^\d{6}$/', $code)) {
        $error = "Please enter a valid 6-digit code.";
    } else {
        $conn = getDBConnection();
        if ($conn) {
            try {
                // Check if code exists and is valid (use PHP time to avoid timezone issues)
                $current_time = date('Y-m-d H:i:s');
                $stmt = $conn->prepare("SELECT * FROM verification_codes
                                       WHERE user_id = ? AND code = ? AND used = 0 AND expires_at > ?
                                       ORDER BY created_at DESC LIMIT 1");
                $stmt->execute([$_SESSION['reset_user_id'], $code, $current_time]);
                $verification = $stmt->fetch();

                if ($verification) {
                    // Mark code as used
                    $stmt = $conn->prepare("UPDATE verification_codes SET used = 1 WHERE id = ?");
                    $stmt->execute([$verification['id']]);

                    // Store code verified in session
                    $_SESSION['code_verified'] = true;

                    // Redirect to reset password page
                    header("Location: password_reset.php");
                    exit();
                } else {
                    $error = "Invalid or expired verification code. Please try again or request a new code.";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
                error_log("Code verification error: " . $e->getMessage());
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
    <title>Verify Code - EcoTrack</title>
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
          text-align: center;
        }

        .header {
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

        .code-inputs {
          display: flex;
          gap: 10px;
          justify-content: center;
          margin-bottom: 30px;
        }

        .code-inputs input {
          width: 50px;
          height: 60px;
          text-align: center;
          font-size: 24px;
          font-weight: bold;
          border: 2px solid #e0e0e0;
          border-radius: 10px;
          transition: border-color 0.3s;
        }

        .code-inputs input:focus {
          outline: none;
          border-color: #4caf50;
        }

        .full-code {
          width: 100%;
          padding: 15px;
          border: 2px solid #e0e0e0;
          border-radius: 10px;
          font-size: 24px;
          text-align: center;
          letter-spacing: 10px;
          font-weight: bold;
          margin-bottom: 20px;
        }

        .full-code:focus {
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

        .timer {
          color: #666;
          font-size: 14px;
          margin-top: 20px;
        }

        .timer span {
          color: #e74c3c;
          font-weight: bold;
        }

        .resend {
          margin-top: 15px;
        }

        .resend a {
          color: #4caf50;
          text-decoration: none;
          font-weight: 500;
        }

        .resend a:hover {
          text-decoration: underline;
        }

        .email-display {
          background: #f5f5f5;
          padding: 10px;
          border-radius: 8px;
          margin-bottom: 20px;
          font-size: 14px;
          color: #333;
        }
    </style>
    <link rel="stylesheet" href="assets/css/ecotrack-theme.css?v=<?php echo filemtime(__DIR__ . '/assets/css/ecotrack-theme.css'); ?>">
</head>
<body>
    <div class="container" aria-hidden="true">
        <div class="header">
            <h1>Enter Verification Code</h1>
            <p>We've sent a 6-digit code to your email</p>
        </div>

        <div class="email-display">
            📧 <?php echo htmlspecialchars($_SESSION['reset_email']); ?>
        </div>

        <div class="timer">
            Code expires in <span id="countdown">5:00</span> minutes
        </div>

        <div class="resend">
            <a href="password_reset_request.php">← Didn't receive code? Try again</a>
        </div>
    </div>
    <?php
    $otpModalId = 'passwordResetOtpModal';
    $otpModalTitle = 'Enter verification code';
    $otpModalMessage = 'We sent a six-digit verification code to your email address.';
    $otpModalError = $error;
    $otpModalAction = 'password_reset_verification.php';
    $otpModalSubmitLabel = 'Verify code';
    $otpModalCancelHref = 'password_reset_request.php';
    $otpModalCancelLabel = 'Request a new code';
    include 'includes/otp_modal.php';
    ?>

    <script>
        // 5 minutes countdown timer
        let timeLeft = 300; // 5 minutes in seconds

        function updateTimer() {
          const minutes = Math.floor(timeLeft / 60);
          const seconds = timeLeft % 60;
          document.getElementById("countdown").textContent = minutes + ":" + (seconds < 10 ? "0" : "") + seconds;

          if (timeLeft > 0) {
            timeLeft--;
            setTimeout(updateTimer, 1000);
          } else {
            document.getElementById("countdown").textContent = "EXPIRED";
            document.getElementById("countdown").style.color = "#e74c3c";
          }
        }

        // Start the countdown
        updateTimer();
    </script>
</body>
</html>
