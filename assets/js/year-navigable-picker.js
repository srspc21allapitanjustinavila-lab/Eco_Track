/* Dependency-free date and month picker for reporting filters. */
(function () {
  "use strict";

  var MIN_YEAR = 1900;
  var MAX_YEAR = 2099;
  var MONTHS = [
    "January", "February", "March", "April", "May", "June",
    "July", "August", "September", "October", "November", "December",
  ];
  var mounted = new WeakMap();
  var openState = null;

  function pad(value) {
    return String(value).padStart(2, "0");
  }

  function todayParts() {
    var today = new Date();
    return { year: today.getFullYear(), month: today.getMonth(), day: today.getDate() };
  }

  function parseValue(value, type) {
    var match = type === "month"
      ? /^(\d{4})-(\d{2})$/.exec(value || "")
      : /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || "");
    if (!match) return null;
    var year = Number(match[1]);
    var month = Number(match[2]) - 1;
    var day = type === "month" ? 1 : Number(match[3]);
    var date = new Date(year, month, day);
    if (year < MIN_YEAR || year > MAX_YEAR || date.getFullYear() !== year || date.getMonth() !== month || date.getDate() !== day)
      return null;
    return { year: year, month: month, day: day };
  }

  function canonical(parts, type) {
    return String(parts.year) + "-" + pad(parts.month + 1) + (type === "month" ? "" : "-" + pad(parts.day));
  }

  function displayValue(parts, type) {
    return type === "month" ? MONTHS[parts.month] + " " + parts.year : MONTHS[parts.month] + " " + parts.day + ", " + parts.year;
  }

  function parseJson(value, fallback) {
    try {
      return JSON.parse(value || "");
    } catch (error) {
      return fallback;
    }
  }

  function availableYears(input) {
    var rawYears = parseJson(input.dataset.calendarYears, []);
    var seen = {};
    var years = Array.isArray(rawYears) ? rawYears.map(Number).filter(function (year) {
      if (!Number.isInteger(year) || year < MIN_YEAR || year > MAX_YEAR || seen[year]) return false;
      seen[year] = true;
      return true;
    }) : [];
    years.sort(function (left, right) { return right - left; });
    return years;
  }

  function availableMonthsByYear(input) {
    var rawMonths = parseJson(input.dataset.calendarMonths, {});
    var monthsByYear = {};
    if (!rawMonths || typeof rawMonths !== "object" || Array.isArray(rawMonths)) return monthsByYear;

    Object.keys(rawMonths).forEach(function (yearKey) {
      var year = Number(yearKey);
      if (!Number.isInteger(year) || year < MIN_YEAR || year > MAX_YEAR || !Array.isArray(rawMonths[yearKey])) return;
      var seen = {};
      var months = rawMonths[yearKey].map(Number).filter(function (month) {
        if (!Number.isInteger(month) || month < 1 || month > 12 || seen[month]) return false;
        seen[month] = true;
        return true;
      });
      months.sort(function (left, right) { return right - left; });
      monthsByYear[String(year)] = months;
    });
    return monthsByYear;
  }

  function yearSelect(years, selectedYear, includeAllYears) {
    var select = document.createElement("select");
    select.className = "year-picker-year";
    select.setAttribute("aria-label", "Calendar year");
    if (includeAllYears) {
      var allYears = document.createElement("option");
      allYears.value = "";
      allYears.textContent = "All years";
      allYears.selected = !selectedYear;
      select.appendChild(allYears);
    }
    years.forEach(function (year) {
      var option = document.createElement("option");
      option.value = String(year);
      option.textContent = String(year);
      option.selected = year === selectedYear;
      select.appendChild(option);
    });
    return select;
  }

  function monthSelect(selectedMonth) {
    var select = document.createElement("select");
    select.className = "year-picker-month";
    select.setAttribute("aria-label", "Calendar month");
    MONTHS.forEach(function (monthName, month) {
      var option = document.createElement("option");
      option.value = String(month);
      option.textContent = monthName;
      option.selected = month === selectedMonth;
      select.appendChild(option);
    });
    return select;
  }

  function button(label, className, ariaLabel) {
    var element = document.createElement("button");
    element.type = "button";
    element.className = className;
    element.textContent = label;
    element.setAttribute("aria-label", ariaLabel);
    return element;
  }

  function isSupportedYear(year, years) {
    return years.indexOf(Number(year)) !== -1;
  }

  function markSource(field) {
    if (!field) return;
    field.classList.add("year-picker-source");
    field.setAttribute("aria-hidden", "true");
    field.setAttribute("tabindex", "-1");
  }

  function emitValueChange(field) {
    field.dispatchEvent(new Event("input", { bubbles: true }));
    field.dispatchEvent(new Event("change", { bubbles: true }));
  }

  function portalPopover(state) {
    if (state.popover.parentElement === document.body) return;
    document.body.appendChild(state.popover);
    state.popover.classList.add("year-picker-popover-portal");
  }

  function restorePopover(state) {
    if (state.popover.parentElement !== state.root) state.root.appendChild(state.popover);
    state.popover.classList.remove("year-picker-popover-portal");
    state.popover.removeAttribute("style");
  }

  function positionPopover(state) {
    if (!state || state.popover.hidden || state.popover.parentElement !== document.body) return;

    var viewportWidth = window.innerWidth || document.documentElement.clientWidth;
    var viewportHeight = window.innerHeight || document.documentElement.clientHeight;
    var inset = 12;
    var gap = 6;
    var popover = state.popover;
    var triggerRect = state.trigger.getBoundingClientRect();

    popover.style.maxWidth = Math.max(1, viewportWidth - (inset * 2)) + "px";
    popover.style.maxHeight = Math.max(1, viewportHeight - (inset * 2)) + "px";
    popover.style.left = "0px";
    popover.style.top = "0px";

    var width = popover.offsetWidth;
    var height = popover.offsetHeight;
    var maximumLeft = Math.max(inset, viewportWidth - width - inset);
    var left = Math.min(Math.max(triggerRect.right - width, inset), maximumLeft);
    var below = viewportHeight - triggerRect.bottom - inset;
    var above = triggerRect.top - inset;
    var openBelow = below >= height || below >= above;
    var maximumTop = Math.max(inset, viewportHeight - height - inset);
    var top = openBelow ? triggerRect.bottom + gap : triggerRect.top - height - gap;

    popover.style.left = Math.min(Math.max(left, inset), maximumLeft) + "px";
    popover.style.top = Math.min(Math.max(top, inset), maximumTop) + "px";
  }

  function mount(input) {
    if (!input || mounted.has(input)) return mounted.get(input);

    var type = input.dataset.yearNavigablePicker === "month" ? "month" : "date";
    var parsedValue = parseValue(input.value, type);
    var allowEmpty = input.dataset.yearPickerAllowEmpty === "true";
    var years = availableYears(input);
    var externalYearId = input.dataset.yearPickerExternalYear || "";
    var externalYear = externalYearId ? document.getElementById(externalYearId) : null;
    var combinedPeriod = type === "month" && input.dataset.yearPickerCombinedPeriod === "true" && !!externalYear;
    var initial = parsedValue || todayParts();

    if (parsedValue && isSupportedYear(parsedValue.year, years)) {
      initial.year = parsedValue.year;
    } else if (externalYear && isSupportedYear(externalYear.value, years)) {
      initial.year = Number(externalYear.value);
    } else if (years.length && !isSupportedYear(initial.year, years)) {
      initial.year = years[0];
      if (!(allowEmpty && !parsedValue)) input.value = canonical(initial, type);
    }

    var state = {
      input: input,
      type: type,
      years: years,
      hasImportedYears: years.length > 0,
      monthsByYear: availableMonthsByYear(input),
      externalYear: externalYear,
      combinedPeriod: combinedPeriod,
      allowEmpty: allowEmpty,
      isEmpty: allowEmpty && !parsedValue,
      hasYearOnly: combinedPeriod && !parsedValue && externalYear && isSupportedYear(externalYear.value, years),
      selected: initial,
      viewYear: initial.year,
      viewMonth: initial.month,
      root: document.createElement("div"),
      trigger: null,
      popover: null,
      year: null,
      month: null,
      grid: null,
      caption: null,
      previous: null,
      next: null,
      render: null,
      close: null,
    };

    state.root.className = "year-picker";
    markSource(input);
    if (combinedPeriod) markSource(externalYear);
    input.insertAdjacentElement("afterend", state.root);

    state.trigger = button("", "year-picker-trigger", type === "month" ? "Choose month" : "Choose date");
    state.trigger.setAttribute("aria-haspopup", "dialog");
    state.trigger.setAttribute("aria-expanded", "false");
    var fieldLabel = input.parentElement ? input.parentElement.querySelector('label[for="' + input.id + '"]') : null;
    if (fieldLabel) {
      if (!fieldLabel.id) fieldLabel.id = input.id + "-calendar-label";
      state.trigger.setAttribute("aria-labelledby", fieldLabel.id);
      fieldLabel.addEventListener("click", function (event) {
        event.preventDefault();
        state.trigger.focus();
      });
    }
    state.root.appendChild(state.trigger);

    state.popover = document.createElement("div");
    state.popover.className = "year-picker-popover";
    state.popover.hidden = true;
    state.popover.setAttribute("role", "dialog");
    state.popover.setAttribute("aria-label", type === "month" ? "Choose reporting month" : "Choose reporting date");
    state.root.appendChild(state.popover);

    function externalYearValue() {
      return state.externalYear && isSupportedYear(state.externalYear.value, state.years) ? Number(state.externalYear.value) : 0;
    }

    function availableMonthsForYear(year) {
      var months = state.monthsByYear[String(year)];
      return Array.isArray(months) ? months : [];
    }

    function monthIsAvailableForYear(year, month) {
      var months = availableMonthsForYear(year);
      return months.length === 0 || months.indexOf(month + 1) !== -1;
    }

    function allYearsAreSelected() {
      return state.combinedPeriod && !externalYearValue();
    }

    function availableMonthsForAllYears() {
      var seen = {};
      var months = [];
      var unrestrictedYear = false;
      state.years.forEach(function (year) {
        var availableMonths = availableMonthsForYear(year);
        if (availableMonths.length === 0) {
          unrestrictedYear = true;
          return;
        }
        availableMonths.forEach(function (month) {
          if (seen[month]) return;
          seen[month] = true;
          months.push(month);
        });
      });
      return unrestrictedYear ? [] : months;
    }

    function monthIsAvailable(year, month) {
      var months = allYearsAreSelected() ? availableMonthsForAllYears() : availableMonthsForYear(year);
      return months.length === 0 || months.indexOf(month + 1) !== -1;
    }

    function firstAvailableYearForMonth(month) {
      for (var index = 0; index < state.years.length; index++) {
        if (monthIsAvailableForYear(state.years[index], month)) return state.years[index];
      }
      return 0;
    }

    function close(restoreFocus) {
      if (state.popover.hidden) return;
      state.popover.hidden = true;
      state.trigger.setAttribute("aria-expanded", "false");
      restorePopover(state);
      if (openState === state) openState = null;
      if (restoreFocus) state.trigger.focus();
    }

    function open() {
      if (openState && openState !== state) openState.close(false);
      state.popover.hidden = false;
      portalPopover(state);
      openState = state;
      state.trigger.setAttribute("aria-expanded", "true");
      render();
      positionPopover(state);
      var focusTarget = state.year || state.grid.querySelector("button:not(:disabled)");
      if (focusTarget) focusTarget.focus();
    }

    function submitIfRequested() {
      if (input.dataset.yearPickerAutoSubmit !== "true" || !input.form) return;
      if (typeof input.form.requestSubmit === "function") input.form.requestSubmit();
      else input.form.submit();
    }

    function updateValue(parts, submitAfterChange) {
      state.isEmpty = false;
      state.hasYearOnly = false;
      state.selected = parts;
      state.viewYear = parts.year;
      state.viewMonth = parts.month;
      if (state.externalYear) state.externalYear.value = String(parts.year);
      input.value = canonical(parts, type);
      emitValueChange(input);
      render();
      if (submitAfterChange) submitIfRequested();
    }

    function clearValue() {
      state.isEmpty = true;
      state.hasYearOnly = false;
      input.value = "";
      emitValueChange(input);
      render();
    }

    function selectCombinedYear(year, submitAfterChange) {
      state.viewYear = year;
      state.selected.year = year;
      state.isEmpty = true;
      state.hasYearOnly = true;
      state.externalYear.value = String(year);
      input.value = "";
      emitValueChange(input);
      render();
      if (submitAfterChange) submitIfRequested();
    }

    function clearCombinedPeriod(submitAfterChange) {
      state.isEmpty = true;
      state.hasYearOnly = false;
      state.externalYear.value = "";
      input.value = "";
      emitValueChange(input);
      render();
      if (submitAfterChange) submitIfRequested();
    }

    function changeYear(direction) {
      var currentIndex = state.years.indexOf(state.viewYear);
      var targetIndex = currentIndex + (direction < 0 ? 1 : -1);
      if (targetIndex < 0 || targetIndex >= state.years.length) return false;
      state.viewYear = state.years[targetIndex];
      return true;
    }

    function changeView(direction) {
      if (type === "month") {
        if (!changeYear(direction)) {
          render();
          return;
        }
        if (state.combinedPeriod) {
          selectCombinedYear(state.viewYear, true);
          return;
        }
      } else {
        var date = new Date(state.viewYear, state.viewMonth + direction, 1);
        if (state.years.indexOf(date.getFullYear()) !== -1) {
          state.viewYear = date.getFullYear();
          state.viewMonth = date.getMonth();
        } else if (direction < 0 && changeYear(-1)) {
          state.viewMonth = 11;
        } else if (direction > 0 && changeYear(1)) {
          state.viewMonth = 0;
        }
      }
      render();
    }

    function renderGrid() {
      state.grid.replaceChildren();
      if (type === "month") {
        state.grid.className = "year-picker-grid";
        MONTHS.forEach(function (monthName, month) {
          var monthButton = button(monthName.slice(0, 3), "", monthName);
          var available = monthIsAvailable(state.viewYear, month);
          monthButton.disabled = !available;
          if (!state.isEmpty && state.selected.year === state.viewYear && state.selected.month === month)
            monthButton.classList.add("is-selected");
          monthButton.addEventListener("click", function () {
            if (!available) return;
            var year = state.combinedPeriod
              ? allYearsAreSelected() ? firstAvailableYearForMonth(month) : state.viewYear
              : state.externalYear ? externalYearValue() : state.viewYear;
            if (!year) return;
            updateValue({ year: year, month: month, day: 1 }, true);
            close(true);
          });
          state.grid.appendChild(monthButton);
        });
        return;
      }

      state.grid.className = "year-picker-grid days";
      var firstDay = new Date(state.viewYear, state.viewMonth, 1).getDay();
      var daysInMonth = new Date(state.viewYear, state.viewMonth + 1, 0).getDate();
      for (var blank = 0; blank < firstDay; blank++) {
        var spacer = document.createElement("span");
        spacer.setAttribute("aria-hidden", "true");
        state.grid.appendChild(spacer);
      }
      for (var day = 1; day <= daysInMonth; day++) {
        (function (dayValue) {
          var dayButton = button(String(dayValue), "", MONTHS[state.viewMonth] + " " + dayValue + ", " + state.viewYear);
          if (!state.isEmpty && state.selected.year === state.viewYear && state.selected.month === state.viewMonth && state.selected.day === dayValue)
            dayButton.classList.add("is-selected");
          dayButton.addEventListener("click", function () {
            updateValue({ year: state.viewYear, month: state.viewMonth, day: dayValue }, true);
            close(true);
          });
          state.grid.appendChild(dayButton);
        })(day);
      }
    }

    function render() {
      state.trigger.textContent = !state.hasImportedYears
        ? "No imported years"
        : state.combinedPeriod && state.hasYearOnly
          ? String(externalYearValue())
          : state.isEmpty
            ? input.dataset.yearPickerPlaceholder || "Select a value"
            : state.externalYear && type === "month" && !state.combinedPeriod
              ? MONTHS[state.selected.month]
              : displayValue(state.selected, type);
      state.trigger.disabled = input.disabled || !state.hasImportedYears || (state.externalYear && !state.combinedPeriod && !externalYearValue());
      if (input.disabled) close(false);
      if (!state.grid) return;

      if (state.year) {
        state.year.value = state.combinedPeriod && state.isEmpty && !state.hasYearOnly ? "" : String(state.viewYear);
      }
      if (state.month) state.month.value = String(state.viewMonth);
      if (state.previous) {
        var yearIndex = state.years.indexOf(state.viewYear);
        state.previous.disabled = type === "month" ? yearIndex === state.years.length - 1 : yearIndex === state.years.length - 1 && state.viewMonth === 0;
        state.next.disabled = type === "month" ? yearIndex === 0 : yearIndex === 0 && state.viewMonth === 11;
      }
      state.caption.textContent = type === "month"
        ? state.combinedPeriod
          ? allYearsAreSelected()
            ? "Choose an available month"
            : state.hasYearOnly
              ? "Choose a month in " + state.viewYear
              : "Choose a month"
          : "Choose a month"
        : MONTHS[state.viewMonth] + " " + state.viewYear;
      renderGrid();
      positionPopover(state);
    }

    if (!state.externalYear || state.combinedPeriod) {
      var header = document.createElement("div");
      header.className = "year-picker-header";
      state.previous = button("\u2039", "year-picker-nav", type === "month" ? "Previous year" : "Previous month");
      state.year = yearSelect(state.years, state.viewYear, state.combinedPeriod);
      if (type === "date") state.month = monthSelect(state.viewMonth);
      state.next = button("\u203a", "year-picker-nav", type === "month" ? "Next year" : "Next month");
      state.previous.addEventListener("click", function () { changeView(-1); });
      state.next.addEventListener("click", function () { changeView(1); });
      state.year.addEventListener("change", function () {
        if (state.combinedPeriod) {
          if (state.year.value === "") {
            clearCombinedPeriod(true);
            close(true);
          } else {
            selectCombinedYear(Number(state.year.value), true);
          }
          return;
        }
        state.viewYear = Number(state.year.value);
        render();
      });
      if (state.month) {
        state.month.addEventListener("change", function () {
          state.viewMonth = Number(state.month.value);
          render();
        });
      }
      var periodSelects = document.createElement("div");
      periodSelects.className = "year-picker-period-selects";
      if (state.month) periodSelects.classList.add("has-month-select");
      if (state.month) periodSelects.appendChild(state.month);
      periodSelects.appendChild(state.year);
      header.append(state.previous, periodSelects, state.next);
      state.popover.appendChild(header);
    }

    state.caption = document.createElement("p");
    state.caption.className = "year-picker-caption";
    state.grid = document.createElement("div");
    state.popover.append(state.caption, state.grid);

    state.trigger.addEventListener("click", function () {
      if (state.trigger.disabled) return;
      if (state.popover.hidden) open();
      else close(true);
    });

    input.addEventListener("change", function () {
      var changed = parseValue(input.value, type);
      if (changed && isSupportedYear(changed.year, state.years)) {
        state.isEmpty = false;
        state.hasYearOnly = false;
        state.selected = changed;
        state.viewYear = changed.year;
        state.viewMonth = changed.month;
        if (state.externalYear) state.externalYear.value = String(changed.year);
      } else if (state.allowEmpty && input.value === "") {
        state.isEmpty = true;
        state.hasYearOnly = state.combinedPeriod && !!externalYearValue();
        if (state.hasYearOnly) state.viewYear = externalYearValue();
      }
      render();
    });

    if (state.externalYear) {
      state.externalYear.addEventListener("change", function () {
        var year = externalYearValue();
        if (state.combinedPeriod) {
          if (year) selectCombinedYear(year, true);
          else clearCombinedPeriod(true);
          return;
        }
        if (!year) {
          if (state.allowEmpty) clearValue();
          return;
        }
        state.viewYear = year;
        if (state.isEmpty) {
          render();
          return;
        }
        var month = state.selected.month;
        var availableMonths = availableMonthsForYear(year);
        if (availableMonths.length && availableMonths.indexOf(month + 1) === -1) month = availableMonths[0] - 1;
        updateValue({ year: year, month: month, day: state.selected.day }, true);
      });
    }

    state.render = render;
    state.close = close;
    mounted.set(input, state);
    render();
    return state;
  }

  function sync(input) {
    var state = mounted.get(input);
    if (!state) return;
    var changed = parseValue(input.value, state.type);
    if (changed) {
      state.isEmpty = false;
      state.hasYearOnly = false;
      state.selected = changed;
      state.viewYear = changed.year;
      state.viewMonth = changed.month;
      if (state.externalYear) state.externalYear.value = String(changed.year);
    } else if (state.allowEmpty && input.value === "") {
      state.isEmpty = true;
      state.hasYearOnly = state.combinedPeriod && !!(state.externalYear && isSupportedYear(state.externalYear.value, state.years));
      if (state.hasYearOnly) state.viewYear = Number(state.externalYear.value);
    }
    if (state.render) state.render();
  }

  function mountAll(root) {
    var container = root || document;
    if (container.matches && container.matches("input[data-year-navigable-picker]")) mount(container);
    if (container.querySelectorAll) container.querySelectorAll("input[data-year-navigable-picker]").forEach(mount);
  }

  function closeOutside(target) {
    if (!openState) return;
    if (target && (openState.root.contains(target) || openState.popover.contains(target))) return;
    openState.close(false);
  }

  function repositionOpenPopover() {
    if (openState) positionPopover(openState);
  }

  window.EcoTrackYearPicker = { mount: mount, mountAll: mountAll, sync: sync };
  function initialize() {
    mountAll(document);
    document.dispatchEvent(new Event("ecotrackyearpickerready"));
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", initialize);
  else initialize();
  document.addEventListener("click", function (event) { closeOutside(event.target); });
  document.addEventListener("keydown", function (event) {
    if (event.key !== "Escape") return;
    closeOutside(null);
  });
  window.addEventListener("resize", repositionOpenPopover);
  document.addEventListener("scroll", repositionOpenPopover, true);
})();
