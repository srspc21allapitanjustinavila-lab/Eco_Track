<div id="transactionConfirmationModal" class="transaction-confirmation-modal" aria-hidden="true">
    <section class="transaction-confirmation-dialog" role="dialog" aria-modal="true" aria-labelledby="transactionConfirmationTitle" aria-describedby="transactionConfirmationMessage">
        <div class="transaction-confirmation-icon" aria-hidden="true">!</div>
        <h2 id="transactionConfirmationTitle">Confirm changes</h2>
        <p id="transactionConfirmationMessage">Do you want to apply these changes?</p>
        <div class="transaction-confirmation-actions">
            <button type="button" class="transaction-confirmation-cancel" data-transaction-confirm-cancel>Cancel</button>
            <button type="button" class="transaction-confirmation-continue" data-transaction-confirm-continue>Apply changes</button>
        </div>
    </section>
</div>
<link rel="stylesheet" href="assets/css/ecotrack-theme.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/ecotrack-theme.css'); ?>">
<script defer src="assets/js/transaction-confirmation.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/transaction-confirmation.js'); ?>"></script>
