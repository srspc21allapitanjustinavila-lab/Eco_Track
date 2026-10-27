(function () {
  "use strict";

  var MONTHS = [
    "January", "February", "March", "April", "May", "June",
    "July", "August", "September", "October", "November", "December"
  ];
  var mounted = new WeakMap();
  var openState = null;

  function pad(value) {
    return String(value).padStart(2, "0");
  }

  function parseJson(value, fallback) {
    try { return JSON.parse(value || ""); } catch (error) { return fallback; }
  }

  function parseBound(value) {
    var match = /^(\d{4})-(\d{2})-\d{2}$/.exec(value || "");
    if (!match) return null;
    var year = Number(match[1]);
    var month = Number(match[2]) - 1;
    if (!Number.isInteger(year) || year < 1900 || year > 2099 || month < 0 || month > 11) return null;
    return { year: year, month: month };
  }

  function periodValue(parts) {
    return String(parts.year) + "-" + pad(parts.month + 1);
  }

  function periodNumber(parts) {
    return (parts.year * 12) + parts.month;
  }

  function samePeriod(left, right) {
    return !!left && !!right && left.year === right.year && left.month === right.month;
  }

  function clonePeriod(parts) {
    return parts ? { year: parts.year, month: parts.month } : null;
  }

  function lastDayOfMonth(parts) {
    return new Date(Date.UTC(parts.year, parts.month + 1, 0)).getUTCDate();
  }

  function displayPeriod(parts) {
    return MONTHS[parts.month].slice(0, 3) + " " + parts.year;
  }

  function displayRange(start, end) {
    if (!start) return "Select month range";
    if (!end || samePeriod(start, end)) return displayPeriod(start);
    return displayPeriod(start) + " \u2013 " + displayPeriod(end);
  }

  function parseYears(root) {
    var seen = {};
    var years = parseJson(root.dataset.calendarYears, []);
    if (!Array.isArray(years)) return [];
    return years.map(Number).filter(function (year) {
      if (!Number.isInteger(year) || year < 1900 || year > 2099 || seen[year]) return false;
      seen[year] = true;
      return true;
    }).sort(function (left, right) { return right - left; });
  }

  function parseMonths(root) {
    var raw = parseJson(root.dataset.calendarMonths, {});
    var monthsByYear = {};
    if (!raw || typeof raw !== "object" || Array.isArray(raw)) return monthsByYear;
    Object.keys(raw).forEach(function (yearKey) {
      var year = Number(yearKey);
      var seen = {};
      if (!Number.isInteger(year) || !Array.isArray(raw[yearKey])) return;
      monthsByYear[String(year)] = raw[yearKey].map(Number).filter(function (month) {
        if (!Number.isInteger(month) || month < 1 || month > 12 || seen[month]) return false;
        seen[month] = true;
        return true;
      });
    });
    return monthsByYear;
  }

  function button(label, className, ariaLabel) {
    var element = document.createElement("button");
    element.type = "button";
    element.className = className;
    element.textContent = label;
    element.setAttribute("aria-label", ariaLabel);
    return element;
  }

  function emitValueChange(input) {
    input.dispatchEvent(new Event("input", { bubbles: true }));
    input.dispatchEvent(new Event("change", { bubbles: true }));
  }

  function portalPopover(state) {
    if (state.popover.parentElement !== document.body) document.body.appendChild(state.popover);
  }

  function restorePopover(state) {
    if (state.popover.parentElement !== state.root) state.root.appendChild(state.popover);
    state.popover.removeAttribute("style");
  }

  function positionPopover(state) {
    if (!state || state.popover.hidden || state.popover.parentElement !== document.body) return;
    var viewportWidth = window.innerWidth || document.documentElement.clientWidth;
    var viewportHeight = window.innerHeight || document.documentElement.clientHeight;
    var inset = 12;
    var gap = 6;
    var triggerRect = state.trigger.getBoundingClientRect();
    var popover = state.popover;
    popover.style.maxWidth = Math.max(1, viewportWidth - (inset * 2)) + "px";
    popover.style.maxHeight = Math.max(1, viewportHeight - (inset * 2)) + "px";
    popover.style.left = "0px";
    popover.style.top = "0px";

    var width = popover.offsetWidth;
    var height = popover.offsetHeight;
    var maximumLeft = Math.max(inset, viewportWidth - width - inset);
    var maximumTop = Math.max(inset, viewportHeight - height - inset);
    var left = Math.min(Math.max(triggerRect.right - width, inset), maximumLeft);
    var below = viewportHeight - triggerRect.bottom - inset;
    var above = triggerRect.top - inset;
    var top = (below >= height || below >= above)
      ? triggerRect.bottom + gap
      : triggerRect.top - height - gap;
    popover.style.left = Math.min(Math.max(left, inset), maximumLeft) + "px";
    popover.style.top = Math.min(Math.max(top, inset), maximumTop) + "px";
  }

  function mount(root) {
    if (!root || mounted.has(root)) return mounted.get(root);
    var startInput = document.getElementById(root.dataset.startInput || "");
    var endInput = document.getElementById(root.dataset.endInput || "");
    if (!startInput || !endInput) return null;

    var appliedStart = parseBound(startInput.value);
    var appliedEnd = parseBound(endInput.value) || clonePeriod(appliedStart);
    if (appliedStart && appliedEnd && periodNumber(appliedEnd) < periodNumber(appliedStart)) {
      var appliedSwap = appliedStart;
      appliedStart = appliedEnd;
      appliedEnd = appliedSwap;
    }

    var state = {
      root: root,
      startInput: startInput,
      endInput: endInput,
      years: parseYears(root),
      monthsByYear: parseMonths(root),
      appliedStart: appliedStart,
      appliedEnd: appliedEnd,
      draftStart: null,
      draftEnd: null,
      viewYear: appliedStart ? appliedStart.year : 0,
      trigger: null,
      popover: null,
      render: null,
      close: null
    };

    if (state.years.indexOf(state.viewYear) === -1) state.viewYear = state.years[0] || 0;

    state.trigger = button("", "street-month-range-trigger", "Choose collection month range");
    state.trigger.setAttribute("aria-haspopup", "dialog");
    state.trigger.setAttribute("aria-expanded", "false");
    if (root.getAttribute("aria-labelledby")) state.trigger.setAttribute("aria-labelledby", root.getAttribute("aria-labelledby"));
    root.appendChild(state.trigger);

    state.popover = document.createElement("div");
    state.popover.className = "street-month-range-popover";
    state.popover.hidden = true;
    state.popover.setAttribute("role", "dialog");
    state.popover.setAttribute("aria-label", "Choose collection month range");
    root.appendChild(state.popover);

    function isAvailable(parts) {
      return (state.monthsByYear[String(parts.year)] || []).indexOf(parts.month + 1) !== -1;
    }

    function copyAppliedToDraft() {
      state.draftStart = clonePeriod(state.appliedStart);
      state.draftEnd = clonePeriod(state.appliedEnd);
    }

    function setApplied(start, end, emit) {
      state.appliedStart = clonePeriod(start);
      state.appliedEnd = clonePeriod(end || start);
      startInput.value = start ? periodValue(start) + "-01" : "";
      endInput.value = start ? periodValue(end || start) + "-" + pad(lastDayOfMonth(end || start)) : "";
      if (emit) {
        emitValueChange(startInput);
        emitValueChange(endInput);
      }
    }

    function close(restoreFocus) {
      if (state.popover.hidden) return;
      state.popover.hidden = true;
      state.trigger.setAttribute("aria-expanded", "false");
      restorePopover(state);
      if (openState === state) openState = null;
      if (restoreFocus) state.trigger.focus();
    }

    function selectMonth(parts) {
      if (!isAvailable(parts)) return;
      if (!state.draftStart || state.draftEnd) {
        state.draftStart = clonePeriod(parts);
        state.draftEnd = null;
      } else {
        state.draftEnd = clonePeriod(parts);
        if (periodNumber(state.draftEnd) < periodNumber(state.draftStart)) {
          var swap = state.draftStart;
          state.draftStart = state.draftEnd;
          state.draftEnd = swap;
        }
      }
      state.render();
    }

    function render() {
      state.trigger.textContent = state.years.length
        ? displayRange(state.appliedStart, state.appliedEnd)
        : "No collection months";
      state.trigger.disabled = !state.years.length;
      if (state.popover.hidden) return;

      state.popover.replaceChildren();
      var header = document.createElement("div");
      header.className = "street-month-range-header";
      var currentIndex = state.years.indexOf(state.viewYear);
      var previous = button("\u2039", "street-month-range-nav", "Previous year");
      previous.disabled = currentIndex < 0 || currentIndex === state.years.length - 1;
      previous.addEventListener("click", function () {
        if (previous.disabled) return;
        state.viewYear = state.years[currentIndex + 1];
        state.render();
      });
      var year = document.createElement("select");
      year.className = "street-month-range-year";
      year.setAttribute("aria-label", "Calendar year");
      state.years.forEach(function (yearValue) {
        var option = document.createElement("option");
        option.value = String(yearValue);
        option.textContent = String(yearValue);
        option.selected = yearValue === state.viewYear;
        year.appendChild(option);
      });
      year.addEventListener("change", function () {
        state.viewYear = Number(year.value);
        state.render();
      });
      var next = button("\u203a", "street-month-range-nav", "Next year");
      next.disabled = currentIndex <= 0;
      next.addEventListener("click", function () {
        if (next.disabled) return;
        state.viewYear = state.years[currentIndex - 1];
        state.render();
      });
      header.append(previous, year, next);

      var caption = document.createElement("p");
      caption.className = "street-month-range-caption";
      caption.textContent = "Choose a month";
      var grid = document.createElement("div");
      grid.className = "street-month-range-grid";
      MONTHS.forEach(function (monthName, month) {
        var parts = { year: state.viewYear, month: month };
        var monthButton = button(monthName.slice(0, 3), "", monthName + " " + state.viewYear);
        var available = isAvailable(parts);
        var start = state.draftStart;
        var end = state.draftEnd || state.draftStart;
        var inRange = start && end && periodNumber(parts) >= periodNumber(start) && periodNumber(parts) <= periodNumber(end);
        monthButton.disabled = !available;
        if (inRange) monthButton.classList.add("is-in-range");
        if (start && samePeriod(parts, start)) monthButton.classList.add("is-range-start", "is-selected");
        if (state.draftEnd && samePeriod(parts, state.draftEnd)) monthButton.classList.add("is-range-end", "is-selected");
        monthButton.addEventListener("click", function () { selectMonth(parts); });
        grid.appendChild(monthButton);
      });

      var actions = document.createElement("div");
      actions.className = "street-month-range-actions";
      var clear = button("Clear Selection", "street-month-range-clear", "Clear selected month range");
      clear.addEventListener("click", function () {
        state.draftStart = null;
        state.draftEnd = null;
        setApplied(null, null, true);
        state.render();
      });
      var apply = button("Apply", "street-month-range-apply", "Apply selected month range");
      apply.disabled = !state.draftStart;
      apply.addEventListener("click", function () {
        if (!state.draftStart) return;
        setApplied(state.draftStart, state.draftEnd || state.draftStart, true);
        close(true);
        state.render();
      });
      actions.append(clear, apply);
      state.popover.append(header, caption, grid, actions);
      positionPopover(state);
    }

    function open() {
      if (openState && openState !== state) openState.close(false);
      copyAppliedToDraft();
      if (state.draftStart && state.years.indexOf(state.draftStart.year) !== -1) state.viewYear = state.draftStart.year;
      state.popover.hidden = false;
      portalPopover(state);
      openState = state;
      state.trigger.setAttribute("aria-expanded", "true");
      state.render();
      var focusTarget = state.popover.querySelector(".street-month-range-year") || state.popover.querySelector("button:not(:disabled)");
      if (focusTarget) focusTarget.focus();
    }

    state.trigger.addEventListener("click", function () {
      if (state.trigger.disabled) return;
      if (state.popover.hidden) open();
      else close(true);
    });
    startInput.addEventListener("change", function () { sync(root); });
    endInput.addEventListener("change", function () { sync(root); });

    state.render = render;
    state.close = close;
    mounted.set(root, state);
    render();
    return state;
  }

  function sync(root) {
    var state = mounted.get(root) || mount(root);
    if (!state) return;
    var start = parseBound(state.startInput.value);
    var end = parseBound(state.endInput.value) || clonePeriod(start);
    if (start && end && periodNumber(end) < periodNumber(start)) {
      var swap = start;
      start = end;
      end = swap;
    }
    state.appliedStart = start;
    state.appliedEnd = end;
    if (start && state.years.indexOf(start.year) !== -1) state.viewYear = start.year;
    state.render();
  }

  function setAvailability(root, calendar) {
    var state = mounted.get(root) || mount(root);
    if (!state || !calendar || typeof calendar !== "object") return false;
    root.dataset.calendarYears = JSON.stringify(Array.isArray(calendar.years) ? calendar.years : []);
    root.dataset.calendarMonths = JSON.stringify(calendar.months_by_year && typeof calendar.months_by_year === "object" ? calendar.months_by_year : {});
    state.years = parseYears(root);
    state.monthsByYear = parseMonths(root);
    if (state.years.indexOf(state.viewYear) === -1) state.viewYear = state.years[0] || 0;

    var invalid = (state.appliedStart && !((state.monthsByYear[String(state.appliedStart.year)] || []).indexOf(state.appliedStart.month + 1) !== -1))
      || (state.appliedEnd && !((state.monthsByYear[String(state.appliedEnd.year)] || []).indexOf(state.appliedEnd.month + 1) !== -1));
    if (invalid) {
      state.appliedStart = null;
      state.appliedEnd = null;
      state.draftStart = null;
      state.draftEnd = null;
      state.startInput.value = "";
      state.endInput.value = "";
      emitValueChange(state.startInput);
      emitValueChange(state.endInput);
    }
    state.render();
    return Boolean(invalid);
  }

  function clear(root) {
    var state = mounted.get(root) || mount(root);
    if (!state) return;
    state.appliedStart = null;
    state.appliedEnd = null;
    state.draftStart = null;
    state.draftEnd = null;
    state.startInput.value = "";
    state.endInput.value = "";
    emitValueChange(state.startInput);
    emitValueChange(state.endInput);
    state.render();
  }

  function close(root) {
    var state = root ? mounted.get(root) : openState;
    if (state) state.close(false);
  }

  function mountAll(container) {
    var root = container || document;
    if (root.matches && root.matches("[data-street-month-range-picker]")) mount(root);
    if (root.querySelectorAll) root.querySelectorAll("[data-street-month-range-picker]").forEach(mount);
  }

  function closeOutside(target, path) {
    if (!openState) return;
    // Rendering a month or year replaces the clicked element before the click
    // reaches document. The original event path still identifies the popover.
    if (path && (path.indexOf(openState.root) !== -1 || path.indexOf(openState.popover) !== -1)) return;
    if (target && (openState.root.contains(target) || openState.popover.contains(target))) return;
    openState.close(false);
  }

  window.EcoTrackStreetMonthRangePicker = {
    mount: mount,
    mountAll: mountAll,
    sync: sync,
    setAvailability: setAvailability,
    clear: clear,
    close: close
  };

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", function () { mountAll(document); });
  else mountAll(document);
  document.addEventListener("click", function (event) {
    closeOutside(event.target, typeof event.composedPath === "function" ? event.composedPath() : null);
  });
  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") closeOutside(null);
  });
  window.addEventListener("resize", function () { if (openState) positionPopover(openState); });
  document.addEventListener("scroll", function () { if (openState) positionPopover(openState); }, true);
})();
