<?php
$otpModalId = $otpModalId ?? 'otpModal';
$otpModalTitle = $otpModalTitle ?? 'Enter verification code';
$otpModalMessage = $otpModalMessage ?? 'Enter the six-digit verification code sent to your email address.';
$otpModalError = $otpModalError ?? '';
$otpModalAction = $otpModalAction ?? '';
$otpModalField = $otpModalField ?? 'code';
$otpModalSubmitLabel = $otpModalSubmitLabel ?? 'Verify code';
$otpModalCancelHref = $otpModalCancelHref ?? '';
$otpModalCancelLabel = $otpModalCancelLabel ?? 'Cancel';
$otpModalHiddenInputs = $otpModalHiddenInputs ?? [];
$otpModalSuccessCookie = $otpModalSuccessCookie ?? '';
$otpModalSuccessMessage = $otpModalSuccessMessage ?? '';
?>
<div id="<?php echo htmlspecialchars($otpModalId); ?>" class="otp-modal" aria-hidden="true">
    <section class="otp-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo htmlspecialchars($otpModalId); ?>Title" aria-describedby="<?php echo htmlspecialchars($otpModalId); ?>Message">
        <div class="transaction-confirmation-icon" aria-hidden="true">&#128274;</div>
        <h1 id="<?php echo htmlspecialchars($otpModalId); ?>Title"><?php echo htmlspecialchars($otpModalTitle); ?></h1>
        <p id="<?php echo htmlspecialchars($otpModalId); ?>Message"><?php echo htmlspecialchars($otpModalMessage); ?></p>
        <?php if ($otpModalError !== ''): ?><p class="otp-modal-error" role="alert"><?php echo htmlspecialchars($otpModalError); ?></p><?php endif; ?>
        <form method="post" action="<?php echo htmlspecialchars($otpModalAction); ?>" autocomplete="one-time-code" data-transaction-confirm-skip<?php if ($otpModalSuccessCookie !== ''): ?> data-download-success-cookie="<?php echo htmlspecialchars($otpModalSuccessCookie); ?>"<?php endif; ?><?php if ($otpModalSuccessMessage !== ''): ?> data-download-success-message="<?php echo htmlspecialchars($otpModalSuccessMessage); ?>"<?php endif; ?>>
            <?php foreach ($otpModalHiddenInputs as $name => $value): ?>
                <input type="hidden" name="<?php echo htmlspecialchars($name); ?>" value="<?php echo htmlspecialchars((string)$value); ?>">
            <?php endforeach; ?>
            <label for="<?php echo htmlspecialchars($otpModalId); ?>Input">Verification code</label>
            <input id="<?php echo htmlspecialchars($otpModalId); ?>Input" class="otp-modal-input" name="<?php echo htmlspecialchars($otpModalField); ?>" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus>
            <button type="submit" class="otp-modal-submit"><?php echo htmlspecialchars($otpModalSubmitLabel); ?></button>
        </form>
        <?php if ($otpModalCancelHref !== ''): ?><a class="otp-modal-cancel" href="<?php echo htmlspecialchars($otpModalCancelHref); ?>"><?php echo htmlspecialchars($otpModalCancelLabel); ?></a><?php endif; ?>
    </section>
</div>
<script>
    (function () {
      var modal = document.getElementById(<?php echo json_encode($otpModalId); ?>);
      if (!modal) return;
      var input = modal.querySelector(".otp-modal-input");
      var form = modal.querySelector("form");
      modal.classList.add("is-open");
      modal.setAttribute("aria-hidden", "false");

      function getCookie(name) {
        var prefix = encodeURIComponent(name) + "=";
        return document.cookie.split(";").some(function (cookie) {
          return cookie.trim().indexOf(prefix) === 0;
        });
      }

      function clearCookie(name) {
        document.cookie = encodeURIComponent(name) + "=; Max-Age=0; path=/; SameSite=Lax";
      }

      function closeModal() {
        modal.classList.remove("is-open");
        modal.setAttribute("aria-hidden", "true");
      }

      function showSuccessMessage(message) {
        var main = document.querySelector(".main-content");
        if (!main) return;

        main.querySelectorAll(".message.error").forEach(function (error) {
          error.remove();
        });

        var notice = main.querySelector("[data-download-success-notice]");
        if (!notice) {
          notice = document.createElement("div");
          notice.className = "message success";
          notice.setAttribute("data-download-success-notice", "true");

          var header = main.querySelector(".header");
          if (header && header.nextSibling) {
            main.insertBefore(notice, header.nextSibling);
          } else {
            main.insertBefore(notice, main.firstChild);
          }
        }

        notice.textContent = message;
      }

      if (input) {
        input.addEventListener("input", function () {
          input.value = input.value.replace(/\D/g, "").slice(0, 6);
        });
        window.setTimeout(function () { input.focus(); }, 0);
      }
      if (form && form.dataset.downloadSuccessCookie) {
        form.addEventListener("submit", function () {
          var cookieName = form.dataset.downloadSuccessCookie;
          var successMessage = form.dataset.downloadSuccessMessage || "Successfully downloaded.";
          var submitButton = form.querySelector(".otp-modal-submit");
          var submitLabel = submitButton ? submitButton.textContent : "";
          var startedAt = Date.now();

          clearCookie(cookieName);

          if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = "Preparing download...";
          }

          var checkDownload = window.setInterval(function () {
            if (getCookie(cookieName)) {
              window.clearInterval(checkDownload);
              clearCookie(cookieName);
              closeModal();
              showSuccessMessage(successMessage);
            } else if (Date.now() - startedAt > 120000) {
              window.clearInterval(checkDownload);
              if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = submitLabel;
              }
            }
          }, 250);
        });
      }
    })();
</script>
