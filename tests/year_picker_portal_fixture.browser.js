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
      var trigger = document.querySelector(".year-picker-trigger");

      assert(clip && year && month && trigger, "The combined period fixture mounted");
      assert(
        year.classList.contains("year-picker-source") && month.classList.contains("year-picker-source"),
        "The native year and month fields are hidden after mounting",
      );

      trigger.click();
      var popover = document.querySelector(".year-picker-popover");
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

      var march = popover.querySelector('button[aria-label="March"]');
      march.click();
      assert(year.value === "2025" && month.value === "2025-03" && trigger.textContent === "March 2025", "Choosing a month synchronizes both submitted values");

      trigger.click();
      calendarYear = document.querySelector(".year-picker-popover .year-picker-year");
      calendarYear.value = "";
      calendarYear.dispatchEvent(new Event("change", { bubbles: true }));
      assert(year.value === "" && month.value === "" && trigger.textContent === "All periods", "All years clears the combined period filter");

      trigger.click();
      var allYearsMarch = document.querySelector('.year-picker-popover button[aria-label="March"]');
      var allYearsDecember = document.querySelector('.year-picker-popover button[aria-label="December"]');
      assert(
        !allYearsMarch.disabled && allYearsDecember.disabled,
        "All years enables months found in any available reporting year",
      );
      allYearsMarch.click();
      assert(
        year.value === "2025" && month.value === "2025-03",
        "An all-years month selection uses the newest reporting year containing that month",
      );

      result("PASS: " + checks.length + " year-picker browser checks\n" + checks.join("\n"));
    } catch (error) {
      result("FAIL: " + error.message + "\n" + checks.join("\n") + "\n" + error.stack);
    }
  });
})();
