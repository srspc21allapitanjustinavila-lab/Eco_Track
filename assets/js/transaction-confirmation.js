(function () {
  "use strict";

  var modal = document.getElementById("transactionConfirmationModal");
  if (!modal) return;

  var title = document.getElementById("transactionConfirmationTitle");
  var message = document.getElementById("transactionConfirmationMessage");
  var cancel = modal.querySelector("[data-transaction-confirm-cancel]");
  var continueButton = modal.querySelector("[data-transaction-confirm-continue]");
  var pendingForm = null;
  var pendingSubmitter = null;
  var trigger = null;

  function formValue(form, submitter, name, fallback) {
    return (submitter && submitter.dataset[name]) || form.dataset[name] || fallback;
  }

  function closeModal(restoreFocus) {
    modal.classList.remove("is-open");
    modal.setAttribute("aria-hidden", "true");
    if (restoreFocus && trigger && typeof trigger.focus === "function") trigger.focus();
    pendingForm = null;
    pendingSubmitter = null;
    trigger = null;
  }

  function openModal(form, submitter) {
    pendingForm = form;
    pendingSubmitter = submitter || null;
    trigger = submitter || document.activeElement;
    title.textContent = formValue(form, submitter, "confirmTitle", "Confirm changes");
    message.textContent = formValue(form, submitter, "confirmMessage", "Do you want to apply these changes?");
    continueButton.textContent = formValue(form, submitter, "confirmAction", "Apply changes");
    modal.classList.add("is-open");
    modal.setAttribute("aria-hidden", "false");
    window.setTimeout(function () {
      cancel.focus();
    }, 0);
  }

  document.addEventListener("submit", function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement) || String(form.method).toLowerCase() !== "post") return;
    if (form.matches("[data-transaction-confirm-skip]")) return;
    if (form.dataset.transactionConfirmed === "true") {
      delete form.dataset.transactionConfirmed;
      return;
    }

    event.preventDefault();
    openModal(form, event.submitter || null);
  });

  cancel.addEventListener("click", function () {
    closeModal(true);
  });

  continueButton.addEventListener("click", function () {
    if (!pendingForm) return;
    var form = pendingForm;
    var submitter = pendingSubmitter;
    form.dataset.transactionConfirmed = "true";
    closeModal(false);
    if (submitter && !submitter.disabled) {
      form.requestSubmit(submitter);
    } else {
      form.requestSubmit();
    }
  });

  modal.addEventListener("click", function (event) {
    if (event.target === modal) closeModal(true);
  });

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape" && modal.classList.contains("is-open")) {
      event.preventDefault();
      closeModal(true);
    }
  });
})();
