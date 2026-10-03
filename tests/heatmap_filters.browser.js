// Browser regression fixture for the real Heat Map page. It keeps Leaflet and
// the page JavaScript intact while substituting only the JSON refresh payload.
(function () {
  var payload = JSON.parse(JSON.stringify(window.heatmapFixture));
  var originalFetch = window.fetch;
  window.fetch = function (url, options) {
    if (String(url).indexOf("waste_heatmap.php?format=json") !== 0) return originalFetch(url, options);
    return Promise.resolve({
      ok: true,
      json: function () {
        return Promise.resolve(JSON.parse(JSON.stringify(payload)));
      },
    });
  };

  window.addEventListener("load", async function () {
    var checks = [];
    function assert(condition, message) {
      if (!condition) throw new Error(message);
      checks.push(message);
    }
    function cards() {
      return Array.from(document.querySelectorAll(".location-card"));
    }
    function cardIds() {
      return cards().map(function (card) {
        return card.dataset.locationId;
      });
    }
    function wasteTotal(location) {
      var value = Number(location && location.total_waste);
      return Number.isFinite(value) ? value : 0;
    }
    function compareNames(left, right) {
      return String((left && left.name) || "").localeCompare(String((right && right.name) || ""), "en", {
        numeric: true,
        sensitivity: "base",
      });
    }
    function expectedLocationIds(locations, order) {
      return locations
        .slice()
        .sort(function (left, right) {
          var nameOrder = compareNames(left, right);
          if (order === "name") return nameOrder;
          var difference = wasteTotal(left) - wasteTotal(right);
          return difference === 0 ? nameOrder : order === "lowest" ? difference : -difference;
        })
        .map(function (location) {
          return location.id;
        });
    }
    function setSortOrder(order) {
      sortFilter.value = order;
      sortFilter.dispatchEvent(new Event("change", { bubbles: true }));
    }
    function result(message) {
      var output = document.createElement("pre");
      output.id = "test-results";
      output.textContent = message;
      document.body.prepend(output);
      document.title = message.split("\n")[0];
    }
    try {
      assert(document.querySelector(".heatmap-period-form"), "The prominent time-filter control is rendered");
      assert(
        document.getElementById("heatmapStartDate") && document.getElementById("heatmapDailyAverage"),
        "Date-range selection and daily-average summary are rendered",
      );
      var calendarTrigger = document.querySelector(".year-picker-trigger");
      assert(calendarTrigger, "The Heatmap uses a year-navigable calendar control");
      calendarTrigger.click();
      var calendarYear = document.querySelector(".year-picker-year");
      var calendarMonth = document.querySelector(".year-picker-month");
      assert(
        Array.from(calendarYear.options)
          .map(function (option) {
            return option.value;
          })
          .join(",") === "2026,2025,2024",
        "The Heatmap calendar only lists years with imported data",
      );
      calendarMonth.value = "1";
      calendarMonth.dispatchEvent(new Event("change", { bubbles: true }));
      assert(
        document.querySelector(".year-picker-caption").textContent.indexOf("February") !== -1,
        "The Heatmap calendar allows direct month selection",
      );
      calendarYear.value = "2024";
      calendarYear.dispatchEvent(new Event("change", { bubbles: true }));
      assert(
        calendarYear.value === "2024" && document.querySelector(".year-picker-popover:not([hidden])"),
        "The Heatmap calendar can navigate only within imported years",
      );
      var monthlyMode = document.querySelector('input[name="mode"][value="month"]');
      var monthlyPanel = document.querySelector('[data-heatmap-mode-panel="month"]');
      var monthlyInput = document.getElementById("heatmapMonth");
      monthlyMode.checked = true;
      monthlyMode.dispatchEvent(new Event("change", { bubbles: true }));
      assert(
        !monthlyPanel.hidden &&
          !monthlyInput.disabled &&
          document.querySelector('.heatmap-period-form input[name="view"]').value === "month" &&
          document.querySelector(".heatmap-period-submit").textContent === "Apply month",
        "Monthly view activates its month field and request state",
      );
      monthlyInput.value = "2026-03";
      var monthlyQuery = new FormData(document.querySelector(".heatmap-period-form"));
      assert(
        monthlyQuery.get("mode") === "month" && monthlyQuery.get("view") === "month" && monthlyQuery.get("period") === "2026-03",
        "Monthly Heatmap submission uses the expected period query shape",
      );
      var yearlyMode = document.querySelector('input[name="mode"][value="year"]');
      var yearlyPanel = document.querySelector('[data-heatmap-mode-panel="year"]');
      var yearlyInput = document.getElementById("heatmapYear");
      assert(yearlyMode && yearlyPanel && yearlyInput, "The Heatmap exposes a Yearly view selector");
      yearlyMode.checked = true;
      yearlyMode.dispatchEvent(new Event("change", { bubbles: true }));
      assert(
        !yearlyPanel.hidden &&
          document.querySelector('.heatmap-period-form input[name="view"]').value === "year" &&
          !yearlyInput.disabled &&
          document.querySelector(".heatmap-period-submit").textContent === "Apply year",
        "Yearly view activates the year field and annual request state",
      );
      yearlyInput.value = "2025";
      var yearlyQuery = new FormData(document.querySelector(".heatmap-period-form"));
      assert(
        yearlyQuery.get("mode") === "year" && yearlyQuery.get("view") === "year" && yearlyQuery.get("period") === "2025",
        "Yearly Heatmap submission uses the expected period query shape",
      );
      var dailyMode = document.querySelector('input[name="mode"][value="daily"]');
      var startDateInput = document.getElementById("heatmapStartDate");
      var endDateInput = document.getElementById("heatmapEndDate");
      dailyMode.checked = true;
      dailyMode.dispatchEvent(new Event("change", { bubbles: true }));
      endDateInput.value = "2026-03-02";
      startDateInput.value = "2026-02-10";
      startDateInput.dispatchEvent(new Event("change", { bubbles: true }));
      endDateInput.dispatchEvent(new Event("change", { bubbles: true }));
      assert(
        startDateInput.value === "2026-02-10" && endDateInput.value === "2026-03-02",
        "Daily view preserves the independently selected flexible date range",
      );
      if (typeof L === "undefined") {
        assert(
          document.querySelector(".map-layout").hidden && document.querySelector(".map-placeholder").style.display === "block",
          "Heatmap controls remain usable and show the fallback state when Leaflet is unavailable (" +
            (window.heatmapFixtureErrors || []).join("; ") +
            ")",
        );
        result("PASS: " + checks.length + " heatmap filter resilience checks\n" + checks.join("\n"));
        return;
      }
      assert(typeof renderLocations === "function", "Actual heatmap and Leaflet initialize");
      assert(!document.getElementById("levelFilter"), "The Waste Classification dropdown is removed");
      assert(
        map.options.maxBounds.equals(barangayBounds) && map.getMinZoom() === barangayMinZoom,
        "The map uses the exact San Manuel bounds with no zoom-out padding",
      );
      var visiblePayload = payload.locations.filter(isVisibleHeatmapLocation);
      assert(
        cards().length === visiblePayload.length && cards().length < payload.locations.length,
        "Only selected-period locations visible on the map appear in the sidebar",
      );
      var originalLocations = locationData;
      locationData = payload.locations.map(function (location) {
        return Object.assign({}, location, { has_data: false });
      });
      updateHeatmapSummary();
      assert(
        !document.getElementById("heatmapNoRecordsAlert").hidden &&
          document.getElementById("heatmapNoRecordsAlert").textContent.trim() === "No available records",
        "The Heatmap shows the required no-records alert for an empty selected period",
      );
      locationData = originalLocations;
      updateHeatmapSummary();
      var success = payload.locations.find(function (location) {
        return location.dss && location.dss.success;
      });
      var lowWithoutReduction = payload.locations.find(function (location) {
        return (
          location.classification && location.classification.label === "Low" && (!location.dss || !location.dss.success)
        );
      });
      assert(
        success && success.waste_level.color_name === success.classification.color_name,
        "A reduced location keeps the color assigned by its daily-average classification",
      );
      assert(
        lowWithoutReduction && lowWithoutReduction.waste_level.color_name === lowWithoutReduction.classification.color_name,
        "Low data keeps its daily-average color without a separate neutral category",
      );

      document.getElementById("resetHeatmapFilters").click();
      assert(
        sortFilter.value === "highest" &&
          JSON.stringify(cardIds()) === JSON.stringify(expectedLocationIds(visiblePayload, "highest")),
        "Sort Locations defaults to highest waste first for visible streets and establishments",
      );
      setSortOrder("lowest");
      assert(
        JSON.stringify(cardIds()) === JSON.stringify(expectedLocationIds(visiblePayload, "lowest")),
        "Sort Locations orders visible streets and establishments from lowest to highest waste",
      );
      setSortOrder("name");
      assert(
        JSON.stringify(cardIds()) === JSON.stringify(expectedLocationIds(visiblePayload, "name")),
        "Sort Locations orders visible streets and establishments by name",
      );

      var tiedLocations = locationData.map(function (location) {
        return Object.assign({}, location);
      });
      var tiedCandidates = tiedLocations.filter(isVisibleHeatmapLocation).slice(0, 2);
      tiedCandidates[0].total_waste = 999999;
      tiedCandidates[1].total_waste = 999999;
      locationData = tiedLocations;
      setSortOrder("highest");
      assert(
        JSON.stringify(cardIds().slice(0, 2)) === JSON.stringify(expectedLocationIds(tiedCandidates, "name")),
        "Equal waste totals use location name as a stable alphabetical tie-breaker",
      );
      locationData = originalLocations;
      searchInput.value = "Phase 6";
      searchInput.dispatchEvent(new Event("input", { bubbles: true }));
      var searchedLocations = visiblePayload.filter(function (location) {
        return (location.name + " " + location.address).toLowerCase().indexOf("phase 6") !== -1;
      });
      assert(
        JSON.stringify(cardIds()) === JSON.stringify(expectedLocationIds(searchedLocations, "highest")),
        "Sorting applies to the currently search-filtered location list",
      );
      searchInput.value = "";
      searchInput.dispatchEvent(new Event("input", { bubbles: true }));

      var mappable = payload.locations.find(function (location) {
        return location.phase_key || (Number.isFinite(location.lat) && Number.isFinite(location.lng));
      });
      document.querySelector('[data-location-id="' + mappable.id + '"]').click();
      assert(
        map._popup.getContent().indexOf("Daily average") !== -1 &&
          map._popup.getContent().indexOf("Previous daily average") !== -1,
        "Location popups expose current and previous daily metrics",
      );

      var refreshedPayload = JSON.parse(JSON.stringify(payload));
      var refreshedLocation = refreshedPayload.locations.find(function (location) {
        return isVisibleHeatmapLocation(location) && location.id !== visiblePayload[0].id;
      });
      refreshedLocation.total_waste = 123456;
      payload = refreshedPayload;
      await refreshHeatmapData();
      assert(
        JSON.stringify(cardIds()) === JSON.stringify(expectedLocationIds(payload.locations.filter(isVisibleHeatmapLocation), "highest")),
        "The selected sort order is preserved when refreshed Heatmap data changes",
      );
      var legendText = document.getElementById("heatmapLegend").textContent;
      assert(
        ["Red", "Orange", "Green", "Yellow"].every(function (name) {
          return legendText.indexOf(name) !== -1;
        }) &&
          legendText.indexOf("Amber") === -1 &&
          legendText.indexOf("Neutral gray") === -1 &&
          legendText.indexOf("DSS success") === -1,
        "The refreshed legend contains exactly the requested four categories",
      );
      result("PASS: " + checks.length + " heatmap browser checks\n" + checks.join("\n"));
    } catch (error) {
      result("FAIL: " + error.message + "\n" + checks.join("\n") + "\n" + error.stack);
    }
  });
})();
