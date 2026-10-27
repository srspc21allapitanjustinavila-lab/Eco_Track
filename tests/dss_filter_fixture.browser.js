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
    var form = document.querySelector(".dss-toolbar");
    var startInput = document.getElementById("dssStartDate");
    var endInput = document.getElementById("dssEndDate");
    var monthInput = document.getElementById("dssMonth");
    var dailyPanel = document.querySelector('[data-dss-mode-panel="daily"]');
    var monthPanel = document.querySelector('[data-dss-mode-panel="month"]');
    var sourceInputs = [startInput, endInput, monthInput];

    assert(form && startInput && endInput && monthInput, "The DSS period controls are rendered");
    assert(
      sourceInputs.every(function (input) {
        return input.classList.contains("year-picker-source") && getComputedStyle(input).opacity === "0" && input.tabIndex === -1;
      }),
      "Native DSS date and month inputs are hidden after picker mounting",
    );
    assert(
      dailyPanel.querySelectorAll(".year-picker-trigger").length === 2,
      "Daily DSS displays one custom picker trigger for each date field",
    );

    startInput.value = "2026-05-10";
    startInput.dispatchEvent(new Event("change", { bubbles: true }));
    assert(endInput.value === "2026-05-16", "Changing the DSS start date fixes the end date at seven inclusive days");

    endInput.value = "2026-06-20";
    endInput.dispatchEvent(new Event("change", { bubbles: true }));
    assert(startInput.value === "2026-06-14", "Changing the DSS end date fixes the start date at seven inclusive days");

    var monthlyMode = form.querySelector('input[name="mode"][value="month"]');
    monthlyMode.checked = true;
    monthlyMode.dispatchEvent(new Event("change", { bubbles: true }));
    assert(
      !monthPanel.hidden && monthInput.disabled === false && dailyPanel.hidden,
      "Monthly DSS mode exposes only its month field",
    );
    monthPanel.querySelector(".year-picker-trigger").click();
    var popover = document.querySelector(".year-picker-popover:not([hidden])");
    function enabledMonths() {
      return Array.from(popover.querySelectorAll(".year-picker-grid button:not(:disabled)")).map(function (button) { return button.textContent; }).join(",");
    }
    assert(enabledMonths() === "Dec", "Monthly DSS enables only uploaded months for the selected year");
    var calendarYear = popover.querySelector(".year-picker-year");
    calendarYear.value = "2025";
    calendarYear.dispatchEvent(new Event("change", { bubbles: true }));
    assert(enabledMonths() === "Feb,Sep", "Changing the DSS year refreshes its available months");
    popover.querySelector('button[aria-label="February"]').click();
    assert(monthInput.value === "2025-02", "DSS submits the chosen available year and month");

    var dailyMode = form.querySelector('input[name="mode"][value="daily"]');
    dailyMode.checked = true;
    dailyMode.dispatchEvent(new Event("change", { bubbles: true }));
    dailyPanel.querySelector(".year-picker-trigger").click();
    popover = document.querySelector(".year-picker-popover:not([hidden])");
    calendarYear = popover.querySelector(".year-picker-year");
    calendarYear.value = "2025";
    calendarYear.dispatchEvent(new Event("change", { bubbles: true }));
    assert(
      Array.from(popover.querySelector(".year-picker-month").options).map(function (option) { return option.textContent; }).join(",") === "February,September",
      "The DSS daily dropdown also restricts months to uploaded data",
    );

    result("PASS: " + checks.length + " DSS filter checks\n" + checks.join("\n"));
  } catch (error) {
    result("FAIL: " + error.message + "\n" + checks.join("\n"));
  }
});
