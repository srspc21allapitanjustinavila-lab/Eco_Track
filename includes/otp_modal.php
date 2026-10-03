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
?>
<div id="<?php echo htmlspecialchars($otpModalId); ?>" class="otp-modal" aria-hidden="true">
    <section class="otp-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo htmlspecialchars($otpModalId); ?>Title" aria-describedby="<?php echo htmlspecialchars($otpModalId); ?>Message">
        <div class="transaction-confirmation-icon" aria-hidden="true">&#128274;</div>
        <h1 id="<?php echo htmlspecialchars($otpModalId); ?>Title"><?php echo htmlspecialchars($otpModalTitle); ?></h1>
        <p id="<?php echo htmlspecialchars($otpModalId); ?>Message"><?php echo htmlspecialchars($otpModalMessage); ?></p>
        <?php if ($otpModalError !== ''): ?><p class="otp-modal-error" role="alert"><?php echo htmlspecialchars($otpModalError); ?></p><?php endif; ?>
        <form method="post" action="<?php echo htmlspecialchars($otpModalAction); ?>" autocomplete="one-time-code" data-transaction-confirm-skip>
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
      modal.classList.add("is-open");
      modal.setAttribute("aria-hidden", "false");
      if (input) {
        input.addEventListener("input", function () {
          input.value = input.value.replace(/\D/g, "").slice(0, 6);
        });
        window.setTimeout(function () { input.focus(); }, 0);
      }
    })();
</script>
