(function () {
  'use strict';
  var editForm = document.getElementById('attendance-save-form');
  var filterForm = document.getElementById('attendance-filter-form');
  var dirty = false;
  var navigating = false;

  function colorStatus(select) {
    select.className = 'attendance-status-control status-' + (select.value || 'not-recorded');
  }
  if (editForm) {
    editForm.querySelectorAll('[data-attendance-edit]').forEach(function (control) {
      if (control.tagName === 'SELECT') colorStatus(control);
      control.addEventListener('change', function () {
        dirty = true;
        if (control.tagName === 'SELECT') colorStatus(control);
      });
      control.addEventListener('input', function () { dirty = true; });
    });
    editForm.addEventListener('submit', function (event) {
      if (editForm.dataset.submitting === 'true') { event.preventDefault(); return; }
      editForm.dataset.submitting = 'true';
      navigating = true;
      var button = editForm.querySelector('button[type="submit"]');
      if (button) { button.disabled = true; button.textContent = 'Saving…'; }
    });
  }
  function confirmDiscard() { return !dirty || window.confirm('Discard unsaved attendance changes?'); }
  if (filterForm) filterForm.addEventListener('submit', function (event) {
    if (!confirmDiscard()) {
      event.preventDefault();
      return;
    }
    navigating = true;
  });
  document.querySelectorAll('[data-attendance-filter]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!confirmDiscard()) event.preventDefault();
      else navigating = true;
    });
  });
  document.querySelectorAll('[data-attendance-navigation]').forEach(function (link) {
    link.addEventListener('click', function (event) {
      if (!confirmDiscard()) event.preventDefault();
      else navigating = true;
    });
  });
  window.addEventListener('beforeunload', function (event) {
    if (dirty && !navigating) { event.preventDefault(); event.returnValue = ''; }
  });

  var printPeriod = document.getElementById('print-period');
  var printWeekField = document.getElementById('print-week-field');
  var printMonthField = document.getElementById('print-month-field');
  if (printPeriod && printWeekField && printMonthField) {
    function updatePrintPeriod() {
      var monthly = printPeriod.value === 'month';
      var weekInput = document.getElementById('print-date');
      var monthInput = document.getElementById('print-month');
      printWeekField.hidden = monthly;
      printMonthField.hidden = !monthly;
      weekInput.disabled = monthly;
      weekInput.required = !monthly;
      monthInput.disabled = !monthly;
      monthInput.required = monthly;
    }
    printPeriod.addEventListener('change', updatePrintPeriod);
    updatePrintPeriod();
  }

  var dialog = document.getElementById('attendance-delete-dialog');
  if (!dialog) return;
  document.querySelectorAll('[data-delete-id]').forEach(function (button) {
    button.addEventListener('click', function () {
      document.getElementById('attendance-delete-id').value = button.dataset.deleteId;
      document.getElementById('attendance-delete-version').value = button.dataset.deleteVersion;
      document.getElementById('attendance-delete-name').textContent = button.dataset.deleteName;
      dialog.showModal();
    });
  });
  dialog.querySelector('[data-close-delete]').addEventListener('click', function () { dialog.close(); });
  document.getElementById('attendance-delete-form').addEventListener('submit', function (event) {
    if (!confirmDiscard()) { event.preventDefault(); return; }
    if (this.dataset.submitting === 'true') { event.preventDefault(); return; }
    this.dataset.submitting = 'true';
    navigating = true;
    this.querySelector('button[type="submit"]').disabled = true;
  });
})();
