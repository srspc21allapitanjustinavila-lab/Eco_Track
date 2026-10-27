(function () {
  "use strict";

  window.addEventListener("load", function () {
    var checks = [];

    function assert(condition, message) {
      if (!condition) throw new Error(message);
      checks.push(message);
    }

    function result(message) {
      var output = document.createElement("pre");
      output.id = "test-results";
      output.textContent = message;
      document.body.prepend(output);
      document.title = message.split("\n")[0];
    }

    try {
      var clip = document.querySelector(".calendar-clip");
      var year = document.getElementById("fixture-year");
      var month = document.getElementById("fixture-month");
      var trigger = month.nextElementSibling.querySelector(".year-picker-trigger");

      assert(clip && year && month && trigger, "The combined period fixture mounted");
      assert(
        year.classList.contains("year-picker-source") && month.classList.contains("year-picker-source"),
        "The native year and month fields are hidden after mounting",
      );

      trigger.click();
      var popover = document.querySelector(".year-picker-popover:not([hidden])");
      var popoverRect = popover.getBoundingClientRect();
      var triggerRect = trigger.getBoundingClientRect();
      assert(popover.parentElement === document.body, "The open calendar is portaled outside the clipping container");
      assert(
        popoverRect.left >= 0 && popoverRect.top >= 0 && popoverRect.right <= window.innerWidth && popoverRect.bottom <= window.innerHeight,
        "The open calendar stays fully inside the viewport",
      );
      assert(popoverRect.bottom <= triggerRect.top, "The calendar opens upward when space below the trigger is limited");

      var calendarYear = popover.querySelector(".year-picker-year");
      calendarYear.value = "2025";
      calendarYear.dispatchEvent(new Event("change", { bubbles: true }));
      assert(year.value === "2025" && month.value === "" && trigger.textContent === "2025", "Choosing a year keeps the year-only filter state");
      assert(
        Array.from(popover.querySelectorAll(".year-picker-grid button:not(:disabled)")).map(function (button) { return button.textContent; }).join(",") === "Mar",
        "The combined picker enables only months uploaded in the selected year",
      );

      var march = popover.querySelector('button[aria-label="March"]');
      march.click();
      assert(year.value === "2025" && month.value === "2025-03" && trigger.textContent === "March 2025", "Choosing a month synchronizes both submitted values");

      trigger.click();
      calendarYear = document.querySelector(".year-picker-popover:not([hidden]) .year-picker-year");
      calendarYear.value = "";
      calendarYear.dispatchEvent(new Event("change", { bubbles: true }));
      assert(year.value === "" && month.value === "" && trigger.textContent === "All periods", "All years clears the combined period filter");

      trigger.click();
      var allYearsMarch = document.querySelector('.year-picker-popover:not([hidden]) button[aria-label="March"]');
      var allYearsDecember = document.querySelector('.year-picker-popover:not([hidden]) button[aria-label="December"]');
      assert(
        !allYearsMarch.disabled && allYearsDecember.disabled,
        "All years enables months found in any available reporting year",
      );
      allYearsMarch.click();
      assert(
        year.value === "2025" && month.value === "2025-03",
        "An all-years month selection uses the newest reporting year containing that month",
      );

      var standaloneInput = document.getElementById("fixture-standalone-month");
      standaloneInput.nextElementSibling.querySelector(".year-picker-trigger").click();
      popover = document.querySelector(".year-picker-popover:not([hidden])");
      function enabledMonths() {
        return Array.from(popover.querySelectorAll(".year-picker-grid button:not(:disabled)")).map(function (button) { return button.textContent; }).join(",");
      }
      assert(enabledMonths() === "Dec", "A standalone month picker enables only December for a December-only upload");
      var unavailableMonth = popover.querySelector('button[aria-label="January"]');
      unavailableMonth.click();
      assert(standaloneInput.value === "2026-12", "An unavailable month cannot change the submitted value");
      calendarYear = popover.querySelector(".year-picker-year");
      calendarYear.value = "2025";
      calendarYear.dispatchEvent(new Event("change", { bubbles: true }));
      assert(enabledMonths() === "Feb,Sep", "Switching standalone years refreshes the available months");
      calendarYear.value = "2024";
      calendarYear.dispatchEvent(new Event("change", { bubbles: true }));
      assert(enabledMonths() === "", "An explicitly empty year does not enable every month");
      calendarYear.value = "2025";
      calendarYear.dispatchEvent(new Event("change", { bubbles: true }));
      popover.querySelector('button[aria-label="February"]').click();
      assert(standaloneInput.value === "2025-02", "A standalone month selection uses the selected year");

      var dateInput = document.getElementById("fixture-date");
      var dateTrigger = dateInput.nextElementSibling.querySelector(".year-picker-trigger");
      assert(dateTrigger.textContent === "12/23/2026", "Opt-in daily pickers display dates in MM/DD/YYYY format");
      dateTrigger.click();
      popover = document.querySelector(".year-picker-popover:not([hidden])");
      function dateMonths() {
        return Array.from(popover.querySelector(".year-picker-month").options).map(function (option) { return option.textContent; }).join(",");
      }
      assert(dateMonths() === "December", "Daily calendar dropdowns list only uploaded months");
      assert(
        popover.querySelector('button[aria-label="December 22, 2026"]').disabled && !popover.querySelector('button[aria-label="December 23, 2026"]').disabled,
        "Daily calendar disables dates without matching collection records",
      );
      assert(popover.querySelector('[aria-label="Next month"]').disabled, "Daily calendar navigation stops at the latest uploaded month");
      calendarYear = popover.querySelector(".year-picker-year");
      calendarYear.value = "2025";
      calendarYear.dispatchEvent(new Event("change", { bubbles: true }));
      assert(dateMonths() === "February,September", "Daily month choices refresh when the year changes");
      assert(popover.querySelector(".year-picker-caption").textContent === "September 2025", "Changing years displays an available month");
      popover.querySelector('[aria-label="Previous month"]').click();
      assert(popover.querySelector(".year-picker-caption").textContent === "February 2025", "Daily navigation skips months without uploaded records");
      popover.querySelector('[aria-label="Next month"]').click();
      popover.querySelector('[aria-label="Next month"]').click();
      assert(dateMonths() === "December" && calendarYear.value === "2026", "Daily navigation crosses gaps between reporting years");
      calendarYear.value = "2024";
      calendarYear.dispatchEvent(new Event("change", { bubbles: true }));
      assert(dateMonths() === "January" && popover.querySelector('[aria-label="Previous month"]').disabled, "Daily navigation stops at the earliest uploaded month");
      popover.querySelector('button[aria-label="January 15, 2024"]').click();
      assert(dateInput.value === "2024-01-15", "Daily selection submits a date in the chosen uploaded month");

      result("PASS: " + checks.length + " year-picker browser checks\n" + checks.join("\n"));
    } catch (error) {
      result("FAIL: " + error.message + "\n" + checks.join("\n") + "\n" + error.stack);
    }
  });
})();
