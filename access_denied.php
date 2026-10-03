<?php
require_once 'config.php';
initSession();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unauthorized Access - EcoTrack</title>
    <?php include 'includes/theme_head.php'; ?>
    <link rel="stylesheet" href="assets/css/ecotrack-theme.css?v=<?php echo filemtime(__DIR__ . '/assets/css/ecotrack-theme.css'); ?>">
    <style>
        * {
          margin: 0;
          padding: 0;
          box-sizing: border-box;
        }
        html,
        body {
          min-height: 100%;
        }

        body {
          min-height: 100vh;
          padding: 28px;
          color: var(--eco-text);
          font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
          background:
            radial-gradient(circle at 12% 12%, rgba(143, 213, 190, 0.42), transparent 29rem),
            radial-gradient(circle at 88% 88%, rgba(122, 166, 63, 0.18), transparent 24rem),
            var(--eco-bg);
        }

        .access-page {
          display: grid;
          min-height: calc(100vh - 56px);
          place-items: center;
        }

        .error-container {
          position: relative;
          width: min(100%, 520px);
          padding: 46px;
          overflow: hidden;
          border: 1px solid var(--eco-border);
          border-radius: 24px;
          background: var(--eco-surface);
          box-shadow: 0 24px 56px rgba(11, 79, 67, 0.16);
          text-align: center;
        }

        .error-container::before {
          position: absolute;
          top: 0;
          right: 0;
          left: 0;
          height: 5px;
          background: linear-gradient(90deg, var(--eco-primary), var(--eco-accent));
          content: "";
        }

        .product-mark {
          margin-bottom: 28px;
          color: var(--eco-primary-strong);
          font-size: 13px;
          font-weight: 800;
          letter-spacing: 0.08em;
          text-transform: uppercase;
        }

        .error-icon {
          display: grid;
          width: 82px;
          height: 82px;
          margin: 0 auto 24px;
          place-items: center;
          border: 1px solid color-mix(in srgb, var(--eco-danger) 28%, transparent);
          border-radius: 50%;
          background: var(--eco-danger-surface);
          color: var(--eco-danger);
          font-size: 38px;
          line-height: 1;
        }

        .error-icon i {
          display: block;
        }

        .error-title {
          margin-bottom: 12px;
          color: var(--eco-text);
          font-size: clamp(28px, 5vw, 34px);
          font-weight: 800;
          letter-spacing: -0.04em;
          line-height: 1.12;
        }

        .error-message {
          max-width: 390px;
          margin: 0 auto 30px;
          color: var(--eco-text-soft);
          font-size: 15px;
          line-height: 1.65;
        }

        .access-actions {
          display: grid;
          width: min(100%, 310px);
          gap: 12px;
          margin: 0 auto;
        }

        .btn,
        .back-link {
          display: inline-flex;
          min-height: 48px;
          align-items: center;
          justify-content: center;
          gap: 9px;
          padding: 12px 20px;
          border: 1px solid transparent;
          border-radius: 10px;
          font-size: 14px;
          font-weight: 750;
          text-decoration: none;
          transition: transform 0.18s ease, background-color 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
        }

        .btn {
          background: var(--eco-primary-action);
          color: var(--eco-on-primary);
          box-shadow: 0 10px 20px rgba(11, 79, 67, 0.18);
        }

        .btn:hover {
          background: var(--eco-primary-action-hover);
          color: var(--eco-on-primary);
          box-shadow: 0 14px 24px rgba(11, 79, 67, 0.24);
          transform: translateY(-1px);
        }

        .back-link {
          border-color: var(--eco-border);
          background: var(--eco-surface);
          color: var(--eco-primary-strong);
        }

        .back-link:hover {
          border-color: var(--eco-primary);
          background: var(--eco-primary-soft);
          color: var(--eco-primary-strong);
        }

        .btn:focus-visible,
        .back-link:focus-visible {
          outline: 3px solid color-mix(in srgb, var(--eco-primary) 35%, transparent);
          outline-offset: 3px;
        }

        @media (max-width: 560px) {
          body {
            padding: 18px;
          }

          .access-page {
            min-height: calc(100vh - 36px);
          }

          .error-container {
            padding: 36px 24px 28px;
            border-radius: 20px;
          }

          .product-mark {
            margin-bottom: 24px;
          }

          .error-icon {
            width: 72px;
            height: 72px;
            margin-bottom: 20px;
            font-size: 33px;
          }
        }
    </style>
</head>
<body>
    <main class="access-page">
        <section class="error-container" aria-labelledby="access-denied-title">
            <p class="product-mark">EcoTrack · Secure area</p>
            <div class="error-icon" aria-hidden="true"><i class="bi bi-shield-lock-fill"></i></div>
            <h1 id="access-denied-title" class="error-title">Access restricted</h1>
            <p class="error-message">You don't have permission to open this page. Return to your dashboard, or contact an administrator if you think this is an error.</p>
            <nav class="access-actions" aria-label="Access recovery options">
                <a href="<?php echo isset($_SESSION['user_type']) && $_SESSION['user_type'] == 'staff' ? 'staff_home.php' : 'admin_dashboard.php'; ?>" class="btn"><?php echo isset($_SESSION['user_type']) && $_SESSION['user_type'] == 'staff' ? 'Go to Home' : 'Go to Dashboard'; ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                <a href="login.php" class="back-link"><i class="bi bi-box-arrow-left" aria-hidden="true"></i> Back to Login</a>
            </nav>
        </section>
    </main>
</body>
</html>
