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
        /* Page-specific names prevent dashboard theme styles from turning the
           intro into a second card inside the reset form. */
        body.password-reset-page {
          --reset-primary: var(--eco-primary, #16735f);
          --reset-primary-strong: var(--eco-primary-strong, #0b4f43);
          --reset-surface: var(--eco-surface, #ffffff);
          --reset-text: var(--eco-text, #17231f);
          --reset-text-soft: var(--eco-text-soft, #51645d);
          --reset-border: var(--eco-border, #cfddd7);
          --reset-bg: var(--eco-bg, #f3f7f5);
          --reset-grid: rgba(22, 115, 95, 0.055);
          --reset-orb-one: rgba(122, 166, 63, 0.24);
          --reset-orb-two: rgba(22, 115, 95, 0.16);
          min-height: 100dvh;
          margin: 0;
          padding: 24px;
          display: grid;
          place-items: center;
          overflow-x: hidden;
          position: relative;
          isolation: isolate;
          color: var(--reset-text);
          background:
            radial-gradient(ellipse 44rem 31rem at -9% -13%, var(--reset-orb-one), transparent 70%),
            radial-gradient(ellipse 38rem 28rem at 108% 108%, var(--reset-orb-two), transparent 72%),
            linear-gradient(var(--reset-grid) 1px, transparent 1px),
            linear-gradient(90deg, var(--reset-grid) 1px, transparent 1px),
            var(--reset-bg) !important;
          background-size: auto, auto, 32px 32px, 32px 32px, auto;
        }

        body.password-reset-page::before,
        body.password-reset-page::after {
          content: "";
          position: fixed;
          z-index: 0;
          pointer-events: none;
        }

        body.password-reset-page::before {
          width: min(42vw, 520px);
          aspect-ratio: 1;
          top: -20vw;
          left: -18vw;
          border: 1px solid rgba(22, 115, 95, 0.16);
          border-radius: 47% 53% 56% 44%;
          box-shadow: 0 0 0 36px rgba(122, 166, 63, 0.045), 0 0 0 72px rgba(122, 166, 63, 0.025);
          transform: rotate(-18deg);
        }

        body.password-reset-page::after {
          width: min(36vw, 440px);
          aspect-ratio: 1;
          right: -15vw;
          bottom: -19vw;
          border: 1px solid rgba(22, 115, 95, 0.14);
          border-radius: 56% 44% 42% 58%;
          box-shadow: 0 0 0 30px rgba(22, 115, 95, 0.04), 0 0 0 60px rgba(22, 115, 95, 0.025);
          transform: rotate(24deg);
        }

        html[data-theme="dark"] body.password-reset-page {
          --reset-grid: rgba(216, 238, 229, 0.04);
          --reset-orb-one: rgba(143, 213, 190, 0.15);
          --reset-orb-two: rgba(122, 166, 63, 0.12);
        }

        .password-reset-card {
          position: relative;
          z-index: 1;
          width: min(100%, 480px);
          padding: 40px;
          border: 1px solid var(--reset-border);
          border-radius: 20px;
          background: var(--reset-surface);
          box-shadow: 0 20px 50px rgba(15, 79, 70, 0.12);
        }

        .password-reset__intro {
          margin-bottom: 30px;
          text-align: center;
        }

        .password-reset__icon {
          width: 52px;
          height: 52px;
          display: inline-grid;
          place-items: center;
          margin-bottom: 18px;
          border-radius: 16px;
          background: var(--eco-primary-soft, #d9eee8);
          color: var(--reset-primary);
          font-size: 24px;
        }

        .password-reset__intro h1 {
          margin: 0;
          color: var(--reset-text) !important;
          font-size: clamp(1.65rem, 4vw, 2rem);
          font-weight: 700;
          line-height: 1.2;
          letter-spacing: -0.025em;
        }

        .password-reset__intro p {
          max-width: 350px;
          margin: 12px auto 0;
          color: var(--reset-text-soft);
          font-size: 0.96rem;
          line-height: 1.6;
        }

        .password-reset__alert {
          display: flex;
          gap: 10px;
          align-items: flex-start;
          margin: 0 0 22px;
          padding: 13px 14px;
          border: 1px solid color-mix(in srgb, var(--eco-danger, #b8423b) 30%, transparent);
          border-radius: 10px;
          background: var(--eco-danger-surface, #fcedeb);
          color: var(--eco-danger-text, #9a302b);
          font-size: 0.9rem;
          line-height: 1.45;
        }

        .password-reset__alert i { flex: 0 0 auto; margin-top: 2px; }
        .password-reset__form { display: grid; gap: 22px; }
        .password-reset__field { display: grid; gap: 8px; }

        .password-reset__field label {
          color: var(--reset-text) !important;
          font-size: 0.9rem;
          font-weight: 650;
        }

        .password-reset__input-wrap { position: relative; }

        .password-reset__input-wrap > i {
          position: absolute;
          top: 50%;
          left: 15px;
          color: var(--reset-text-soft);
          font-size: 1rem;
          transform: translateY(-50%);
          pointer-events: none;
        }

        .password-reset__input-wrap input {
          width: 100%;
          min-height: 52px;
          padding: 13px 14px 13px 44px;
          border: 1px solid var(--reset-border) !important;
          border-radius: 10px;
          outline: none;
          background: var(--reset-surface) !important;
          color: var(--reset-text) !important;
          font: inherit;
          transition: border-color 160ms ease, box-shadow 160ms ease;
        }

        .password-reset__input-wrap input::placeholder { color: var(--eco-muted, #73827d); }

        .password-reset__input-wrap input:focus {
          border-color: var(--reset-primary) !important;
          box-shadow: 0 0 0 4px color-mix(in srgb, var(--reset-primary) 17%, transparent) !important;
        }

        .password-reset__submit {
          min-height: 52px;
          display: inline-flex;
          align-items: center;
          justify-content: center;
          gap: 9px;
          width: 100%;
          border: 0;
          border-radius: 10px !important;
          background: var(--eco-primary-action, #16735f) !important;
          color: var(--eco-on-primary, #ffffff) !important;
          font: inherit;
          font-weight: 700;
          cursor: pointer;
          transition: transform 160ms ease, background 160ms ease, box-shadow 160ms ease;
        }

        .password-reset__submit:hover {
          background: var(--eco-primary-action-hover, #0b4f43) !important;
          box-shadow: 0 8px 18px rgba(11, 79, 67, 0.2);
          transform: translateY(-1px);
        }

        .password-reset__submit:focus-visible,
        .password-reset__back-link:focus-visible {
          outline: 3px solid color-mix(in srgb, var(--reset-primary) 40%, transparent);
          outline-offset: 3px;
        }

        .password-reset__footer {
          margin-top: 28px;
          padding-top: 22px;
          border-top: 1px solid var(--reset-border);
          text-align: center;
        }

        .password-reset__back-link {
          display: inline-flex;
          align-items: center;
          gap: 7px;
          color: var(--reset-primary) !important;
          font-size: 0.94rem;
          font-weight: 650;
          text-decoration: none;
        }

        .password-reset__back-link:hover { color: var(--reset-primary-strong) !important; }

        @media (max-width: 520px) {
          body.password-reset-page { padding: 16px; }
          .password-reset-card { padding: 30px 22px; border-radius: 16px; }

          body.password-reset-page::before { top: -35vw; left: -30vw; width: 76vw; }
          body.password-reset-page::after { right: -33vw; bottom: -29vw; width: 70vw; }
        }
    </style>
    <link rel="stylesheet" href="assets/css/ecotrack-theme.css">
</head>
<body class="password-reset-page">
    <main class="password-reset-card" aria-labelledby="passwordResetTitle">
        <?php if (!$passwordResetAvailable): ?>
            <header class="password-reset__intro">
                <span class="password-reset__icon" aria-hidden="true"><i class="bi bi-envelope-x"></i></span>
                <h1 id="passwordResetTitle">Password Reset Unavailable</h1>
                <p>Password reset email is not configured yet. Please contact an EcoTrack administrator.</p>
            </header>
        <?php else: ?>
            <header class="password-reset__intro">
                <span class="password-reset__icon" aria-hidden="true"><i class="bi bi-shield-lock"></i></span>
                <h1 id="passwordResetTitle">Forgot password?</h1>
                <p>Enter your email address and we'll send you a 6-digit verification code to reset your password.</p>
            </header>

            <?php if (!empty($error)): ?>
                <div class="password-reset__alert" role="alert">
                    <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form method="post" action="" class="password-reset__form">
                <div class="password-reset__field">
                    <label for="email">Email Address</label>
                    <div class="password-reset__input-wrap">
                        <i class="bi bi-envelope" aria-hidden="true"></i>
                        <input type="email" id="email" name="email" required autocomplete="email" inputmode="email"
                               placeholder="you@example.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                </div>

                <button type="submit" class="password-reset__submit">
                    <span>Send verification code</span>
                    <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </button>
            </form>
        <?php endif; ?>

        <footer class="password-reset__footer">
            <a class="password-reset__back-link" href="login.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to login</a>
        </footer>
    </main>
</body>
</html>
