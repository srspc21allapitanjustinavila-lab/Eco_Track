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

  if (new URLSearchParams(window.location.search).has("manual")) return;

  window.addEventListener("load", async function () {
    var checks = [];
    var periodForm = document.querySelector(".heatmap-period-form");
    var submittedPeriod = null;
    periodForm.addEventListener("submit", function (event) {
      event.preventDefault();
      submittedPeriod = new FormData(periodForm);
    });
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
    function expectedLocationIds(locations) {
      return locations.map(function (location) { return location.id; });
    }
    function setLocationSearch(query) {
      var input = document.getElementById("locationSearch");
      input.value = query;
      input.dispatchEvent(new Event("input", { bubbles: true }));
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
      assert(!document.getElementById("sortFilter") && !periodForm.querySelector('[name="sort"]') && !document.querySelector(".heatmap-toolbar"), "The sorting dropdown and separate toolbar are removed");
      assert(document.getElementById("locationSearch").form === periodForm && periodForm.querySelector(".heatmap-search-field"), "Search location is grouped with the period controls above the map");
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
      assert(
        Array.from(calendarMonth.options).map(function (option) { return option.textContent; }).join(",") === "January,February,March",
        "The Heatmap daily dropdown only lists months with uploaded data",
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
      assert(
        Array.from(calendarMonth.options).map(function (option) { return option.textContent; }).join(",") === "December",
        "Changing the Heatmap year updates its available daily months",
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
      assert(yearlyInput.closest(".heatmap-filter-fields").contains(document.getElementById("locationSearch")), "The year and Search location share the same control row");
      setLocationSearch("Phase 6");
      var appliedSelectionBeforeYearChange = JSON.stringify(heatmapSelection);
      var appliedLocationsBeforeYearChange = locationData;
      var appliedUrlBeforeYearChange = window.location.href;
      yearlyInput.value = "2025";
      yearlyInput.dispatchEvent(new Event("change", { bubbles: true }));
      assert(
        submittedPeriod === null && JSON.stringify(heatmapSelection) === appliedSelectionBeforeYearChange && locationData === appliedLocationsBeforeYearChange && window.location.href === appliedUrlBeforeYearChange,
        "Choosing a year does not submit or change the applied Heatmap data or URL",
      );
      if (typeof L !== "undefined") {
        var searchEnter = new KeyboardEvent("keydown", { key: "Enter", bubbles: true, cancelable: true });
        document.getElementById("locationSearch").dispatchEvent(searchEnter);
        assert(searchEnter.defaultPrevented && submittedPeriod === null, "Enter in location search cannot accidentally apply a pending year");
      }
      document.querySelector(".heatmap-period-submit").click();
      assert(
        submittedPeriod && submittedPeriod.get("mode") === "year" && submittedPeriod.get("view") === "year" && submittedPeriod.get("period") === "2025" && submittedPeriod.get("search") === "Phase 6" && !submittedPeriod.has("sort") && !submittedPeriod.has("start_date") && !submittedPeriod.has("end_date"),
        "Apply year submits the chosen year and location search without sorting or stale daily dates",
      );
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
      setLocationSearch("");
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
      assert(cards().every(function (card) {
        var style = getComputedStyle(card);
        return !card.style.getPropertyValue("--marker-color") && style.borderLeftWidth === style.borderRightWidth && style.borderLeftColor === style.borderRightColor;
      }), "Sidebar cards use neutral borders without a waste-classification color indicator");
      assert(cards().every(function (card) {
        return !/\b(?:low|medium[ -](?:low|high)|high)\b/i.test(card.querySelector(".location-card-meta").textContent);
      }), "Sidebar cards no longer display Low, Medium or High classification labels");
      assert(cards().every(function (card, index) {
        var text = card.querySelector(".location-card-meta").textContent;
        return text.includes(Number(visiblePayload[index].total_waste).toFixed(2) + " kg") && text.includes(Number(visiblePayload[index].daily_average_kg).toFixed(2) + " kg/day");
      }), "Sidebar cards retain their total waste and daily-average values");
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
        searchInput.value === "" && JSON.stringify(cardIds()) === JSON.stringify(expectedLocationIds(visiblePayload)),
        "Reset lists every location displayed on the map without an extra sorting filter",
      );
      assert(
        !new URLSearchParams(window.location.search).has("sort") && !Object.prototype.hasOwnProperty.call(JSON.parse(sessionStorage.getItem(filterStorageKey)), "sort"),
        "Legacy sort query parameters and saved preferences are removed",
      );
      var appliedUrl = new URL(window.location.href);
      appliedUrl.searchParams.delete("search");
      setLocationSearch("Phase 6");
      var searchedLocations = visiblePayload.filter(function (location) {
        return (location.name + " " + location.address).toLowerCase().indexOf("phase 6") !== -1;
      });
      assert(
        JSON.stringify(cardIds()) === JSON.stringify(expectedLocationIds(searchedLocations)),
        "The street and establishment list contains all matching map locations",
      );
      assert(map.hasLayer(phaseBoundaryLayers.phase6) && !map.hasLayer(phaseBoundaryLayers.phase1a) && !map.hasLayer(phaseBoundaryLayers.phase5) && Object.keys(phaseWasteOverlays).join(",") === "phase6", "Search updates the map areas and sidebar together");
      assert(new URLSearchParams(window.location.search).get("search") === "Phase 6" && JSON.parse(sessionStorage.getItem(filterStorageKey)).search === "Phase 6" && new FormData(periodForm).get("search") === "Phase 6", "Location search stays consistent in the field, URL, saved preference and Apply form");
      var searchedUrl = new URL(window.location.href);
      searchedUrl.searchParams.delete("search");
      assert(searchedUrl.href === appliedUrl.href, "Searching does not change the applied period or URL fragment");
      setLocationSearch("no matching location");
      assert(cards().length === 0 && Object.keys(phaseWasteOverlays).length === 0 && Object.keys(phaseBoundaryLayers).every(function (key) { return !map.hasLayer(phaseBoundaryLayers[key]); }) && locationData.every(function (location) { return !location.marker || !map.hasLayer(location.marker); }), "A search with no matches clears both the map and sidebar");
      document.getElementById("resetHeatmapFilters").click();
      assert(JSON.stringify(cardIds()) === JSON.stringify(expectedLocationIds(visiblePayload)) && Object.keys(phaseWasteOverlays).length === 3, "Clearing search restores every selected-period map location and matching sidebar item");
      searchInput.value = "Phase 6";
      window.dispatchEvent(new PageTransitionEvent("pageshow"));
      assert(JSON.stringify(cardIds()) === JSON.stringify(expectedLocationIds(searchedLocations)) && Object.keys(phaseWasteOverlays).join(",") === "phase6", "Browser-restored search values are reapplied to the map and sidebar together");
      setLocationSearch("");
      var mappedEstablishment = Object.assign({}, visiblePayload[0], {
        id: "fixture-establishment", name: "Fixture Establishment", address: "San Manuel",
        phase_key: null, map_location_id: "fixture-establishment", lat: 14.7818, lng: 121.071, marker: undefined,
      });
      var beforeEstablishment = locationData;
      try {
        locationData = locationData.concat([mappedEstablishment]);
        renderLocations();
        assert(cardIds().includes(mappedEstablishment.id) && map.hasLayer(mappedEstablishment.marker), "A mapped establishment appears in both the map and the full street/establishment list");
        setLocationSearch("Fixture Establishment");
        assert(cardIds().join(",") === mappedEstablishment.id && map.hasLayer(mappedEstablishment.marker) && Object.keys(phaseWasteOverlays).length === 0, "Searching an establishment shows its marker and its sidebar item only");
        setLocationSearch("Phase 6");
        assert(!map.hasLayer(mappedEstablishment.marker) && JSON.stringify(cardIds()) === JSON.stringify(expectedLocationIds(searchedLocations)), "An establishment outside the search is removed from both map and list");
      } finally {
        if (mappedEstablishment.marker) map.removeLayer(mappedEstablishment.marker);
        locationData = beforeEstablishment;
        setLocationSearch("");
      }

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
        JSON.stringify(cardIds()) === JSON.stringify(expectedLocationIds(payload.locations.filter(isVisibleHeatmapLocation))),
        "The sidebar still lists every mapped location after saved data refreshes",
      );
      assert(cards().every(function (card) {
        return !card.style.getPropertyValue("--marker-color") && !/\b(?:low|medium[ -](?:low|high)|high)\b/i.test(card.querySelector(".location-card-meta").textContent);
      }), "Data refresh does not restore sidebar color indicators or classification labels");
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

      yearlyMode.checked = true;
      yearlyMode.dispatchEvent(new Event("change", { bubbles: true }));
      yearlyInput.value = "2025";
      heatmapSelection = window.heatmapAnnualFixtures[2025].selection;
      heatmapDataUrl = "waste_heatmap.php?format=json&mode=year&view=year&period=2025";
      payload = JSON.parse(JSON.stringify(window.heatmapAnnualFixtures[2025]));
      await refreshHeatmapData();
      assert(JSON.stringify(cardIds()) === JSON.stringify(expectedLocationIds(payload.locations.filter(isVisibleHeatmapLocation))), "Yearly results list all and only the selected year's mapped locations");
      var annualTotal = payload.locations.filter(isVisibleHeatmapLocation).reduce(function (total, location) { return total + location.total_waste; }, 0);
      assert(document.getElementById("mappedWasteTotal").textContent === annualTotal.toFixed(2) + " kg", "Yearly summary matches the displayed year's location totals");
      var submittedBeforePendingYear = submittedPeriod;
      yearlyInput.value = "2026";
      yearlyInput.dispatchEvent(new Event("change", { bubbles: true }));
      await refreshHeatmapData();
      assert(
        submittedPeriod === submittedBeforePendingYear && heatmapSelection.period === "2025" && document.getElementById("mappedWasteTotal").textContent === annualTotal.toFixed(2) + " kg" && JSON.stringify(cardIds()) === JSON.stringify(expectedLocationIds(payload.locations.filter(isVisibleHeatmapLocation))),
        "Background refresh keeps the applied year's totals and mapped locations while a different year is only selected",
      );
      document.querySelector(".heatmap-period-submit").click();
      assert(submittedPeriod.get("period") === "2026" && !submittedPeriod.has("sort"), "Apply year uses the pending year without a sorting parameter");
      heatmapSelection = window.heatmapAnnualFixtures[2026].selection;
      heatmapDataUrl = "waste_heatmap.php?format=json&mode=year&view=year&period=2026";
      payload = JSON.parse(JSON.stringify(window.heatmapAnnualFixtures[2026]));
      await refreshHeatmapData();
      var nextAnnualTotal = payload.locations.filter(isVisibleHeatmapLocation).reduce(function (total, location) { return total + location.total_waste; }, 0);
      assert(nextAnnualTotal !== annualTotal && document.getElementById("mappedWasteTotal").textContent === nextAnnualTotal.toFixed(2) + " kg", "Applying a new year replaces the previous year's totals");
      var appliedSnapshot = JSON.stringify(locationData.map(function (location) { return [location.id, location.total_waste]; }));
      payload = JSON.parse(JSON.stringify(window.heatmapAnnualFixtures[2025]));
      await refreshHeatmapData();
      assert(
        heatmapSelection.period === "2026" && JSON.stringify(locationData.map(function (location) { return [location.id, location.total_waste]; })) === appliedSnapshot,
        "A response for a different year cannot replace the selected year's map or sidebar data",
      );
      result("PASS: " + checks.length + " heatmap browser checks\n" + checks.join("\n"));
    } catch (error) {
      result("FAIL: " + error.message + "\n" + checks.join("\n") + "\n" + error.stack);
    }
  });
})();
