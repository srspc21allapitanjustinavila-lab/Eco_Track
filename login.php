<?php
require_once 'config.php';
require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/SMTP.php';
require_once 'PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;

initSession();
sendNoCacheHeaders();
$error = '';

function beginTwoFactorChallenge($conn, array $user, $ipAddress = null)
{
    if (empty($user['email'])) {
        logActivity('Two-factor login could not start', 'Authentication', 'Failed', $conn, $user);
        return 'Two-factor authentication is enabled, but this account has no email address.';
    }
    if (!isSmtpConfigured()) {
        logActivity('Two-factor login could not start', 'Authentication', 'Failed', $conn, $user);
        return 'Two-factor authentication is unavailable because email delivery is not configured.';
    }

    try {
        ensureTwoFactorCodesTable($conn);
        $code = sprintf('%06d', random_int(0, 999999));
        $deleteCode = $conn->prepare('DELETE FROM two_factor_codes WHERE user_id = ? AND used = 0');
        $deleteCode->execute([$user['id']]);
        // Use the database clock for both storing and validating the expiry.
        // PHP and MySQL may have different time zones.
        $storeCode = $conn->prepare('INSERT INTO two_factor_codes (user_id, code, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))');
        $storeCode->execute([$user['id'], $code]);

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = EMAIL_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = EMAIL_USERNAME;
        $mail->Password = EMAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = EMAIL_PORT;
        $mail->setFrom(EMAIL_FROM, EMAIL_FROM_NAME);
        $mail->addAddress($user['email']);
        $mail->isHTML(true);
        $mail->Subject = 'EcoTrack two-factor verification code';
        $mail->Body = '<p>Hello ' . htmlspecialchars($user['username']) . ',</p><p>Your EcoTrack sign-in verification code is:</p><p style="font-size:28px;font-weight:700;letter-spacing:6px;">' . $code . '</p><p>This code expires in 5 minutes. If you did not attempt to sign in, you can ignore this email.</p>';
        $mail->AltBody = 'Your EcoTrack sign-in verification code is ' . $code . '. It expires in 5 minutes.';
        $mail->send();

        unset($_SESSION['user_id'], $_SESSION['user'], $_SESSION['last_activity'], $_SESSION['session_timeout_seconds']);
        $_SESSION['pending_2fa_user_id'] = (int)$user['id'];
        $_SESSION['pending_2fa_ip'] = $ipAddress ?: ($_SERVER['REMOTE_ADDR'] ?? '');
        return '';
    } catch (Throwable $e) {
        error_log('Two-factor login error: ' . $e->getMessage());
        logActivity('Two-factor login email failed', 'Authentication', 'Failed', $conn, $user);
        return 'Unable to send the two-factor verification code. Please try again.';
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    if (!empty($_SESSION['user'])) {
        logActivity('Logged out', 'Authentication');
    }
    logoutUser();
    header("Location: login.php");
    exit();
}

// A signed-in user should never receive a cacheable login screen. This also
// makes Back return to a freshly validated destination rather than an old form.
if (isLoggedIn()) {
    if (isSessionTimeout()) {
        logoutUser();
        header('Location: login.php?timeout=1');
        exit();
    }
    requireLogin();
    $existingUser = getCurrentUser();
    header('Location: ' . (($existingUser['user_type'] ?? '') === 'admin' ? 'admin_dashboard.php' : 'staff_home.php'));
    exit();
}

// A remembered browser can resume a standard login immediately. Accounts with
// two-factor authentication still have to complete a new email verification.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $conn = getDBConnection();
    if ($conn) {
        $rememberedUser = getPersistentLoginUser($conn);
        if ($rememberedUser) {
            $settings = getUserSettings($conn, $rememberedUser['id']);
            if (!empty($settings['two_factor_enabled'])) {
                $error = beginTwoFactorChallenge($conn, $rememberedUser);
                if ($error === '') {
                    header('Location: two_factor_verification.php');
                    exit();
                }
            } else {
                header('Location: ' . completeEcoTrackLogin($conn, $rememberedUser, null, true));
                exit();
            }
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    if (empty($username) || empty($password)) {
        $error = "Please enter both username and password.";
        logActivity('Failed login attempt', 'Authentication', 'Failed', null, ['username' => $username ?: 'Unknown user', 'user_type' => 'Guest']);
    } elseif (isAccountLocked($username)) {
        $error = "Account temporarily locked due to too many failed attempts. Please try again later.";
        logLoginAttempt($username, $ip, false);
        logActivity('Failed login attempt', 'Authentication', 'Failed', null, ['username' => $username, 'user_type' => 'Guest']);
    } else {
        $conn = getDBConnection();
        if ($conn) {
            try {
                $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password_hash'])) {
                    $settings = getUserSettings($conn, $user['id']);

                    if (!empty($settings['two_factor_enabled'])) {
                        $error = beginTwoFactorChallenge($conn, $user, $ip);
                        if ($error === '') {
                            header('Location: two_factor_verification.php');
                            exit();
                        }
                    } else {
                        header('Location: ' . completeEcoTrackLogin($conn, $user, $ip));
                        exit();
                    }
                } else {
                    $error = "Invalid username or password.";
                    logLoginAttempt($username, $ip, false);
                    logActivity('Failed login attempt', 'Authentication', 'Failed', $conn, ['username' => $username, 'user_type' => 'Guest']);
                }
            } catch (PDOException $e) {
                $error = "Login failed. Please try again.";
                error_log("Login error: " . $e->getMessage());
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
    <title>EcoTrack Login</title>
    <?php include 'includes/theme_head.php'; ?>
    <style>
        /* CSS Variables for Light/Dark Mode */
        :root {
          --bg-primary: white;
          --bg-secondary: #f8f9fa;
          --bg-left: linear-gradient(135deg, #e8f5e8 0%, #c3e9c3 100%);
          --bg-city: linear-gradient(to top, #87ceeb 0%, #b0e0e6 50%, transparent 100%);
          --text-primary: #333;
          --text-secondary: #666;
          --text-muted: #999;
          --border-color: #ddd;
          --card-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
          --input-bg: white;
          --input-border: #ddd;
          --toggle-bg: #ccc;
          --toggle-active: #8bc34a;
        }

        [data-theme="dark"] {
          --bg-primary: #1a1a1a;
          --bg-secondary: #2d2d2d;
          --bg-left: linear-gradient(135deg, #1a2e1a 0%, #0d1f0d 100%);
          --bg-city: linear-gradient(to top, #2c4a2c 0%, #1e3a1e 50%, transparent 100%);
          --text-primary: #ffffff;
          --text-secondary: #cccccc;
          --text-muted: #888888;
          --border-color: #444;
          --card-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
          --input-bg: #3d3d3d;
          --input-border: #555;
          --toggle-bg: #555;
          --toggle-active: #7cb342;
        }

        * {
          margin: 0;
          padding: 0;
          box-sizing: border-box;
        }

        body {
          font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
          background: var(--bg-primary);
          min-height: 100vh;
          display: flex;
          overflow: hidden;
          transition:
            background 0.3s,
            color 0.3s;
        }

        .login-container {
          display: flex;
          width: 100%;
          height: 100vh;
        }

        .left-side {
          flex: 1;
          background: var(--bg-left);
          display: flex;
          align-items: center;
          justify-content: center;
          position: relative;
          overflow: hidden;
        }

        .city-background {
          position: absolute;
          bottom: 0;
          width: 100%;
          height: 40%;
          background: var(--bg-city);
          z-index: 1;
        }

        .truck-illustration {
          position: relative;
          z-index: 2;
          width: 80%;
          max-width: 400px;
          height: 300px;
          background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 300"><rect x="50" y="150" width="200" height="80" fill="%23228b22" rx="10"/><rect x="250" y="130" width="80" height="100" fill="%23228b22" rx="10"/><circle cx="100" cy="240" r="20" fill="%23333"/><circle cx="200" cy="240" r="20" fill="%23333"/><circle cx="280" cy="240" r="20" fill="%23333"/><rect x="60" y="160" width="60" height="40" fill="%23fff" opacity="0.3"/><text x="90" y="185" font-family="Arial" font-size="12" fill="%23fff" text-anchor="middle">TRASH</text><circle cx="150" cy="180" r="15" fill="%23fff" opacity="0.8"/><path d="M145 180 L155 180 M150 175 L150 185" stroke="%23228b22" stroke-width="2"/><rect x="260" y="110" width="60" height="20" fill="%23333" rx="5"/><rect x="270" y="100" width="40" height="10" fill="%23666" rx="3"/><rect x="50" y="140" width="200" height="10" fill="%23333" rx="5"/><rect x="70" y="120" width="160" height="20" fill="%23444" rx="5"/><rect x="90" y="100" width="120" height="20" fill="%23555" rx="5"/></svg>')
            center/contain no-repeat;
        }

        .right-side {
          flex: 1;
          background: var(--bg-secondary);
          display: flex;
          align-items: center;
          justify-content: center;
          position: relative;
        }

        .green-curve {
          position: absolute;
          top: -100px;
          right: -100px;
          width: 400px;
          height: 400px;
          background: var(--toggle-active);
          border-radius: 50%;
          opacity: 0.1;
        }

        .login-form {
          width: 100%;
          max-width: 400px;
          padding: 2rem;
          z-index: 1;
        }

        .sign-in-title {
          font-size: 2.5rem;
          font-weight: bold;
          color: var(--toggle-active);
          margin-bottom: 3rem;
          text-align: center;
          position: relative;
        }

        .form-group {
          margin-bottom: 1.5rem;
          position: relative;
        }

        .input-wrapper {
          position: relative;
          display: flex;
          align-items: center;
        }

        .input-icon {
          position: absolute;
          left: 15px;
          color: var(--text-muted);
          z-index: 1;
        }

        input[type="text"],
        input[type="password"] {
          width: 100%;
          padding: 15px 15px 15px 45px;
          border: 2px solid var(--input-border);
          border-radius: 25px;
          font-size: 1rem;
          transition: all 0.3s;
          background: var(--input-bg);
          color: var(--text-primary);
        }

        input[type="text"]:focus,
        input[type="password"]:focus {
          outline: none;
          border-color: var(--toggle-active);
          background: var(--bg-secondary);
        }

        .show-password-wrapper {
          display: flex;
          align-items: center;
          margin-top: 0.5rem;
          margin-left: 10px;
        }

        .show-checkbox {
          width: 20px;
          height: 20px;
          margin-right: 8px;
          accent-color: var(--toggle-active);
        }

        .show-label {
          font-size: 0.9rem;
          color: var(--text-secondary);
        }

        .login-btn {
          width: 100%;
          padding: 15px;
          background: var(--toggle-active);
          color: white;
          border: none;
          border-radius: 25px;
          font-size: 1.1rem;
          font-weight: 600;
          cursor: pointer;
          transition: all 0.3s;
          margin-top: 2rem;
        }

        .login-btn:hover {
          background: #45a049;
          transform: translateY(-2px);
          box-shadow: 0 5px 15px rgba(76, 175, 80, 0.3);
        }

        .forgot-password {
          text-align: center;
          margin-top: 2rem;
        }

        .forgot-password a {
          color: var(--toggle-active);
          text-decoration: none;
          font-weight: 500;
          transition: color 0.3s;
        }

        .forgot-password a:hover {
          color: #45a049;
          text-decoration: underline;
        }

        .error {
          background: var(--bg-primary);
          color: #f44336;
          padding: 1rem;
          border-radius: 10px;
          margin-bottom: 1.5rem;
          border: 1px solid rgba(244, 67, 54, 0.3);
          text-align: center;
        }

        .success {
          background: var(--bg-secondary);
          color: var(--toggle-active);
          padding: 1rem;
          border-radius: 10px;
          margin-bottom: 1.5rem;
          border: 1px solid rgba(76, 175, 80, 0.3);
          text-align: center;
        }

        .demo-info {
          background: var(--bg-secondary);
          padding: 1rem;
          border-radius: 10px;
          margin-top: 2rem;
          font-size: 0.85rem;
          color: var(--toggle-active);
          border: 1px solid rgba(139, 195, 74, 0.3);
        }

        .demo-info h4 {
          color: var(--toggle-active);
          margin-bottom: 0.5rem;
        }

        @media (max-width: 768px) {
          .login-container {
            flex-direction: column;
          }

          .left-side {
            display: none;
          }

          .right-side {
            flex: 1;
          }
        }

        .login-container {
          background: var(--eco-bg);
        }

        .left-side {
          flex-direction: column;
          gap: 34px;
          padding: 56px;
          background:
            linear-gradient(135deg, rgba(47, 125, 79, 0.94) 0%, rgba(31, 81, 56, 0.94) 100%),
            radial-gradient(circle at 20% 15%, rgba(255, 255, 255, 0.22), transparent 34%);
        }

        .login-brand {
          position: relative;
          z-index: 3;
          max-width: 520px;
          color: #ffffff;
        }

        .login-brand-mark {
          width: 124px;
          height: 124px;
          display: flex;
          align-items: center;
          justify-content: center;
          margin-bottom: 22px;
          border-radius: 50%;
          background: rgba(255, 255, 255, 0.96);
          border: 1px solid rgba(255, 255, 255, 0.72);
          color: #1f5138;
          padding: 10px;
          box-shadow: 0 18px 38px rgba(0, 0, 0, 0.22);
        }

        .login-brand-logo {
          display: block;
          width: 100%;
          height: 100%;
          object-fit: contain;
          border-radius: 50%;
        }

        .mobile-login-logo {
          display: none;
        }

        .login-brand h2 {
          font-size: 44px;
          line-height: 1.05;
          margin-bottom: 14px;
          letter-spacing: 0;
        }

        .login-brand p {
          max-width: 440px;
          color: rgba(255, 255, 255, 0.82);
          font-size: 16px;
          line-height: 1.7;
        }

        .city-background,
        .green-curve {
          display: none;
        }

        .truck-illustration {
          width: min(72%, 430px);
          height: 250px;
          padding: 24px;
          border-radius: 8px;
          background-color: rgba(255, 255, 255, 0.14);
          box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.2);
        }

        .right-side {
          background: radial-gradient(circle at 90% 10%, rgba(134, 176, 73, 0.14), transparent 28%), var(--eco-bg);
          padding: 48px 24px;
        }

        .login-form {
          max-width: 430px;
          padding: 34px;
          background: var(--eco-surface);
          border: 1px solid var(--eco-border);
          border-radius: 8px;
          box-shadow: var(--eco-shadow);
        }

        .sign-in-title {
          margin-bottom: 28px;
          font-size: 32px;
          text-align: left;
          letter-spacing: 0;
        }

        input[type="text"],
        input[type="password"] {
          border-radius: 8px;
        }

        .login-btn {
          border-radius: 8px;
        }

        @media (max-width: 768px) {
          body {
            overflow: auto;
          }

          .login-form {
            padding: 28px;
          }

          .mobile-login-logo {
            display: flex;
            justify-content: center;
            margin-bottom: 22px;
          }

          .mobile-login-logo img {
            width: 92px;
            height: 92px;
            object-fit: contain;
            border-radius: 50%;
            padding: 7px;
            background: #ffffff;
            border: 1px solid var(--eco-border);
            box-shadow: 0 12px 26px rgba(31, 81, 56, 0.14);
          }

          .sign-in-title {
            text-align: center;
          }
        }
    </style>
    <link rel="stylesheet" href="assets/css/ecotrack-theme.css">
</head>
<body>
    <div class="login-container">
        <div class="left-side">
            <div class="login-brand">
                <div class="login-brand-mark">
                    <img
                        src="assets/images/barangay-san-manuel-logo.jpg"
                        alt="Barangay San Manuel logo"
                        class="login-brand-logo"
                        onerror="this.onerror=null; this.src='assets/images/barangay-san-manuel-logo.svg';"
                    >
                </div>
                <h2>EcoTrack MRF Management</h2>
            </div>
            <div class="city-background"></div>
            <div class="truck-illustration"></div>
        </div>

        <div class="right-side">
            <div class="green-curve"></div>

            <div class="login-form">
                <div class="mobile-login-logo">
                    <img
                        src="assets/images/barangay-san-manuel-logo.jpg"
                        alt="Barangay San Manuel logo"
                        onerror="this.onerror=null; this.src='assets/images/barangay-san-manuel-logo.svg';"
                    >
                </div>
                <h1 class="sign-in-title">SIGN IN</h1>

                <?php if (isset($error) && !empty($error)): ?>
                    <div class="error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <?php if (isset($_GET['reset']) && $_GET['reset'] == 'success'): ?>
                    <div class="success">Password reset successfully. Please login with your new password.</div>
                <?php endif; ?>

                <form method="post" action="">
                    <div class="form-group">
                        <div class="input-wrapper">
                            <span class="input-icon">&#128100;</span>
                            <input type="text" id="username" name="username" required
                                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="input-wrapper">
                            <span class="input-icon">&#128274;</span>
                            <input type="password" id="password" name="password" required>
                        </div>
                        <div class="show-password-wrapper">
                            <input type="checkbox" id="showPassword" class="show-checkbox" onchange="togglePassword()">
                            <label for="showPassword" class="show-label">SHOW PASSWORD</label>
                        </div>
                    </div>

                    <button type="submit" class="login-btn">LOG IN</button>
                </form>

                <?php if (isPasswordResetEnabled()): ?>
                    <div class="forgot-password">
                        <a href="password_reset_request.php">FORGOT PASSWORD?</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
          const passwordInput = document.getElementById("password");
          const showCheckbox = document.getElementById("showPassword");

          if (showCheckbox.checked) {
            passwordInput.type = "text";
          } else {
            passwordInput.type = "password";
          }
        }
    </script>
</body>
</html>
