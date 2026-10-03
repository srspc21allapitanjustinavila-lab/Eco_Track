(function () {
  "use strict";

  var sidebar = document.getElementById("primaryNavigation");
  var toggle = document.querySelector("[data-sidebar-toggle]");
  var backdrop = document.querySelector("[data-sidebar-backdrop]");
  if (!sidebar || !toggle || !backdrop) return;

  var mobileQuery = window.matchMedia("(max-width: 899px)");

  function setOpen(open, restoreFocus) {
    if (!mobileQuery.matches) {
      sidebar.classList.remove("is-open");
      sidebar.removeAttribute("aria-hidden");
      sidebar.removeAttribute("inert");
      backdrop.hidden = true;
      document.body.classList.remove("sidebar-drawer-open");
      toggle.setAttribute("aria-expanded", "false");
      return;
    }

    sidebar.classList.toggle("is-open", open);
    sidebar.setAttribute("aria-hidden", open ? "false" : "true");
    sidebar.toggleAttribute("inert", !open);
    backdrop.hidden = !open;
    document.body.classList.toggle("sidebar-drawer-open", open);
    toggle.setAttribute("aria-expanded", open ? "true" : "false");

    if (open) {
      var firstItem = sidebar.querySelector("a, button");
      if (firstItem) firstItem.focus();
    } else if (restoreFocus) {
      toggle.focus();
    }
  }

  toggle.addEventListener("click", function () {
    setOpen(!sidebar.classList.contains("is-open"), false);
  });

  backdrop.addEventListener("click", function () {
    setOpen(false, true);
  });

  sidebar.addEventListener("click", function (event) {
    if (event.target.closest("a")) setOpen(false, false);
  });

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape" && sidebar.classList.contains("is-open")) {
      event.preventDefault();
      setOpen(false, true);
    }
  });

  function syncViewport() {
    setOpen(false, false);
  }

  if (typeof mobileQuery.addEventListener === "function") {
    mobileQuery.addEventListener("change", syncViewport);
  } else {
    mobileQuery.addListener(syncViewport);
  }
  syncViewport();
})();
