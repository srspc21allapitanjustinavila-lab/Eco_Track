// Browser assertions for tests/street_chart_filters.php.
(function () {
  "use strict";

  window.addEventListener("load", async function () {
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
    function wait(milliseconds) {
      return new Promise(function (resolve) { setTimeout(resolve, milliseconds); });
    }
    function replacementCardHtml() {
      var card = document.querySelector('[data-visualization-key="area"]').cloneNode(true);
      card.querySelectorAll("button, select, input").forEach(function (control) { control.disabled = false; });
      card.querySelector("[data-street-chart-filter]").removeAttribute("data-street-refresh-in-flight");
      return '<script>let dashboardExplanationData = {};\n</script>' + card.outerHTML;
    }

    try {
      var form = document.querySelector("[data-street-chart-filter]");
      var period = form.querySelector("#street-period");
      var rangeStart = form.querySelector("#street-range-start-month");
      var rangeEnd = form.querySelector("#street-range-end-month");
      var customStart = form.querySelector("#street-custom-start");
      var customEnd = form.querySelector("#street-custom-end");
      var rangeFields = form.querySelectorAll('[data-street-period-field="range"]');
      var customFields = form.querySelectorAll('[data-street-period-field="custom"]');

      assert(rangeFields.length === 2 && !rangeFields[0].hidden && !rangeFields[1].hidden, "Month Range shows clear From month and To month fields.");
      assert(customFields[0].hidden && customFields[1].hidden, "Only the controls for the selected period are visible.");
      assert(!form.querySelector("[data-street-month-range-picker]"), "The old single-popup Date Range control is absent.");

      period.value = "custom";
      period.dispatchEvent(new Event("change", { bubbles: true }));
      assert(rangeFields[0].hidden && rangeFields[1].hidden && !customFields[0].hidden && !customFields[1].hidden, "Custom Dates hides Month Range and exposes only start and end dates.");

      var customTrigger = customStart.parentElement.querySelector(".year-picker-trigger");
      customTrigger.click();
      var popover = document.querySelector(".year-picker-popover:not([hidden])");
      assert(popover && popover.querySelectorAll(".year-picker-grid.days button:not(:disabled)").length === 31, "Custom Dates enables every valid day in an available month.");
      popover.querySelector('button[aria-label="January 21, 2025"]').click();
      assert(customStart.value === "2025-01-21", "A custom date without a collection record can be selected.");

      var calendarRequests = 0;
      window.fetch = function (url) {
        if (String(url).indexOf("street_chart_filter_state.php") !== -1) {
          calendarRequests++;
          return Promise.resolve({
            ok: true,
            json: function () {
              return Promise.resolve({
                filters: { phase: "Phase 2", category: "all" },
                calendar: { years: [2025], months_by_year: { "2025": [2] }, dates_by_month: { "2025-02": ["2025-02-04"] } }
              });
            }
          });
        }
        return Promise.reject(new Error("Unexpected request"));
      };
      customEnd.value = "2025-01-25";
      form.querySelector("#street-phase").value = "Phase 2";
      form.querySelector("#street-phase").dispatchEvent(new Event("change", { bubbles: true }));
      await wait(160);
      assert(calendarRequests === 1 && !customStart.value && !customEnd.value, "Phase/category changes clear custom dates whose months are no longer available.");

      period.value = "range";
      period.dispatchEvent(new Event("change", { bubbles: true }));
      rangeStart.value = "2025-02";
      rangeEnd.value = "2025-12";
      var filterRequest = "";
      window.fetch = function (url) {
        filterRequest = String(url);
        return Promise.resolve({ ok: true, text: function () { return Promise.resolve(replacementCardHtml()); } });
      };
      form.dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
      await wait(0);
      var filterParams = new URL(filterRequest).searchParams;
      assert(
        filterParams.get("street_period") === "range"
          && filterParams.get("street_range_start_month") === "2025-02"
          && filterParams.get("street_range_end_month") === "2025-12"
          && !filterParams.get("street_custom_start"),
        "Month Range submits only its From month and To month values."
      );

      var refreshedForm = document.querySelector("[data-street-chart-filter]");
      var resetRequest = "";
      window.fetch = function (url) {
        resetRequest = String(url);
        return Promise.resolve({ ok: true, text: function () { return Promise.resolve(replacementCardHtml()); } });
      };
      refreshedForm.querySelector("[data-street-filter-reset]").click();
      await wait(0);
      var resetParams = new URL(resetRequest).searchParams;
      assert(
        resetParams.get("street_period") === "all"
          && !resetParams.get("street_range_start_month")
          && !resetParams.get("street_range_end_month")
          && !resetParams.get("street_custom_start")
          && !resetParams.get("street_custom_end"),
        "Reset clears every period-specific value through the in-place refresh path."
      );

      var detailCard = document.querySelector('[data-visualization-key="area"]');
      var detailMainView = detailCard.querySelector("[data-street-main-view]");
      var detailView = detailCard.querySelector("[data-street-detail-view]");
      var detailForm = detailCard.querySelector("[data-street-chart-filter]");
      var preservedFilterState = new FormData(detailForm);
      var preservedOrder = detailCard.querySelector("[data-street-chart-order]").dataset.streetChartOrder;
      var nameTrigger = detailCard.querySelector(".street-chart-row-label[data-street-detail-key]");
      nameTrigger.click();
      assert(
        detailMainView.hidden
          && !detailView.hidden
          && detailView.querySelector("[data-street-detail-street]").textContent === "Acacia St."
          && detailView.querySelector("[data-street-detail-phase]").textContent === "Phase 1"
          && detailView.querySelector("[data-street-detail-total]").textContent.indexOf("12.00 kg") !== -1,
        "Clicking a street name opens its in-card database-derived collection details."
      );
      assert(
        detailView.querySelectorAll("[data-street-detail-stack] span").length === 2
          && detailView.querySelector("[data-street-detail-category-list]").textContent.indexOf("Factory Returnable") !== -1
          && detailView.querySelector("[data-street-detail-category-list]").textContent.indexOf("58.3%") !== -1,
        "Street details render the category quantities and percentage breakdown."
      );
      detailView.querySelector("[data-street-detail-back]").click();
      assert(
        !detailMainView.hidden
          && detailView.hidden
          && new FormData(detailForm).get("street_period") === preservedFilterState.get("street_period")
          && detailCard.querySelector("[data-street-chart-order]").dataset.streetChartOrder === preservedOrder,
        "Back restores the existing street chart, active filters, and chart order without a refresh."
      );
      detailCard.querySelector(".street-chart-details-button[data-street-detail-key]").click();
      assert(!detailView.hidden, "The visible View Details button opens the selected street details.");
      detailView.querySelector("[data-street-detail-back]").click();
      detailCard.querySelector(".street-chart-bar[data-street-detail-key]").click();
      assert(!detailView.hidden, "Clicking the corresponding stacked bar also opens street details.");
      detailView.querySelector("[data-street-detail-back]").click();
      assert(!detailMainView.hidden && detailView.hidden, "The Back control continues to work after repeated drill-down interactions.");

      result("PASS: " + checks.length + " street chart filter checks\n" + checks.join("\n"));
    } catch (error) {
      result("FAIL: " + error.message + "\n" + checks.join("\n") + "\n" + error.stack);
    }
  });
})();
