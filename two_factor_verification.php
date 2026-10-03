<?php
require_once 'config.php';

initSession();

if (empty($_SESSION['pending_2fa_user_id'])) {
    header('Location: login.php');
    exit();
}

$error = '';
$conn = getDBConnection();
$pendingUserId = (int)$_SESSION['pending_2fa_user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    if (!preg_match('/^\d{6}$/', $code)) {
        $error = 'Enter the six-digit code sent to your email address.';
    } elseif (!$conn) {
        $error = 'The verification service is unavailable. Please try again.';
    } else {
        try {
            ensureTwoFactorCodesTable($conn);
            $stmt = $conn->prepare('SELECT id FROM two_factor_codes WHERE user_id = ? AND code = ? AND used = 0 AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1');
            $stmt->execute([$pendingUserId, $code]);
            $verification = $stmt->fetch();

            if (!$verification) {
                $error = 'That code is invalid or has expired. Return to login to request a new code.';
            } else {
                $userStmt = $conn->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
                $userStmt->execute([$pendingUserId]);
                $user = $userStmt->fetch();

                if (!$user) {
                    $error = 'This account is no longer available.';
                } else {
                    $markUsed = $conn->prepare('UPDATE two_factor_codes SET used = 1 WHERE id = ?');
                    $markUsed->execute([$verification['id']]);
                    $destination = completeEcoTrackLogin($conn, $user, $_SESSION['pending_2fa_ip'] ?? null);
                    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_ip']);
                    logActivity('Completed two-factor verification', 'Authentication', 'Success', $conn, $user);
                    header('Location: ' . $destination);
                    exit();
                }
            }
        } catch (PDOException $e) {
            $error = 'Verification could not be completed. Please try again.';
            error_log('Two-factor verification error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-Factor Verification - EcoTrack</title>
    <?php include 'includes/theme_head.php'; ?>
    <link rel="stylesheet" href="assets/css/ecotrack-theme.css?v=<?php echo filemtime(__DIR__ . '/assets/css/ecotrack-theme.css'); ?>">
    <style>
        * {
          box-sizing: border-box;
        }
        body {
          min-height: 100vh;
          margin: 0;
          display: grid;
          place-items: center;
          padding: 20px;
          background: #edf5f1;
          color: #173b33;
          font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        }
        .verify-card {
          width: min(100%, 440px);
          padding: 36px;
          background: #fff;
          border: 1px solid #cfe0d9;
          border-radius: 14px;
          box-shadow: 0 14px 34px rgba(20, 82, 67, 0.12);
          text-align: center;
        }
        .verify-icon {
          width: 58px;
          height: 58px;
          display: grid;
          place-items: center;
          margin: 0 auto 18px;
          border-radius: 50%;
          background: #dcefe8;
          font-size: 28px;
        }
        h1 {
          margin: 0 0 10px;
          font-size: 24px;
        }
        p {
          margin: 0 0 22px;
          color: #52736a;
          line-height: 1.5;
          font-size: 14px;
        }
        label {
          display: block;
          margin-bottom: 8px;
          text-align: left;
          color: #345a50;
          font-size: 12px;
          font-weight: 700;
          text-transform: uppercase;
        }
        input {
          width: 100%;
          padding: 13px;
          border: 1px solid #b8d0c7;
          border-radius: 8px;
          color: #173b33;
          font-size: 24px;
          font-weight: 700;
          letter-spacing: 8px;
          text-align: center;
        }
        input:focus {
          outline: 3px solid rgba(23, 122, 97, 0.18);
          border-color: #177a61;
        }
        button {
          width: 100%;
          margin-top: 18px;
          padding: 13px;
          border: 0;
          border-radius: 8px;
          background: #177a61;
          color: #fff;
          cursor: pointer;
          font-size: 14px;
          font-weight: 700;
        }
        button:hover {
          background: #105d4a;
        }
        .error {
          margin: 0 0 18px;
          padding: 11px;
          border-radius: 8px;
          background: #fde9e7;
          color: #a43126;
          font-size: 13px;
        }
        .back {
          display: inline-block;
          margin-top: 18px;
          color: #177a61;
          font-size: 13px;
          text-decoration: none;
        }
    </style>
</head>
<body>
    <main class="verify-card" aria-hidden="true">
        <div class="verify-icon">&#128274;</div>
        <h1>Verify your sign-in</h1>
        <p>We sent a six-digit verification code to the email address linked to your EcoTrack account.</p>
    </main>
    <?php
    $otpModalId = 'signInOtpModal';
    $otpModalTitle = 'Verify your sign-in';
    $otpModalMessage = 'We sent a six-digit verification code to the email address linked to your EcoTrack account.';
    $otpModalError = $error;
    $otpModalAction = 'two_factor_verification.php';
    $otpModalSubmitLabel = 'Verify and sign in';
    $otpModalCancelHref = 'login.php';
    $otpModalCancelLabel = 'Back to login';
    include 'includes/otp_modal.php';
    ?>
</body>
</html>
