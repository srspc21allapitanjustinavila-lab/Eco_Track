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

    function itemsFor(count) {
      return Array.from({ length: count }, function (_, index) {
        return {
          id: index + 1,
          title: "Fixture notification " + (index + 1),
          text: "Browser regression fixture",
          time: "Just now",
        };
      });
    }

    function waitForRefresh() {
      return new Promise(function (resolve) {
        window.setTimeout(resolve, 0);
      });
    }

    async function refreshTo(count) {
      window.fetch = function () {
        return Promise.resolve({
          ok: true,
          json: function () {
            return Promise.resolve({ items: itemsFor(count) });
          },
        });
      };
      document.dispatchEvent(new Event("visibilitychange"));
      await waitForRefresh();
    }

    try {
      var control = document.querySelector(".notification-control");
      var button = document.querySelector(".notification-toggle");
      var icon = document.querySelector(".notification-toggle-icon");
      var badge = document.querySelector(".notification-badge");

      assert(control && button && icon && badge, "The notification control mounts with its icon and badge");
      assert(icon.classList.contains("bi") && icon.classList.contains("bi-bell-fill"), "The notification control uses Bootstrap's filled bell icon");
      assert(getComputedStyle(button).width === "42px" && getComputedStyle(button).height === "42px", "The shared-header bell keeps the 42px icon-control size");
      assert(badge.textContent === "9+" && badge.getAttribute("aria-hidden") === "true", "More than nine notifications use the capped decorative badge");
      assert(button.getAttribute("aria-label") === "Open notifications, 10 unread notifications", "The initial accessible label reports the full unread count");

      button.click();
      assert(control.classList.contains("is-open") && button.getAttribute("aria-expanded") === "true", "Clicking the bell opens the notification menu");

      document.body.click();
      assert(!control.classList.contains("is-open") && button.getAttribute("aria-expanded") === "false", "An outside click closes the notification menu");

      button.click();
      document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }));
      assert(!control.classList.contains("is-open") && button.getAttribute("aria-expanded") === "false", "Escape closes the notification menu");

      assert(!document.hidden, "The fixture is visible so polling refreshes are exercised");
      await refreshTo(1);
      badge = document.querySelector(".notification-badge");
      assert(badge && badge.textContent === "1" && button.getAttribute("aria-label") === "Open notifications, 1 unread notification", "One unread notification updates the badge and singular accessible label");

      await refreshTo(0);
      assert(!document.querySelector(".notification-badge") && button.getAttribute("aria-label") === "Open notifications", "Zero unread notifications hides the badge and uses the zero-count label");

      await refreshTo(9);
      badge = document.querySelector(".notification-badge");
      assert(badge && badge.textContent === "9" && button.getAttribute("aria-label") === "Open notifications, 9 unread notifications", "Nine unread notifications retain the exact badge value and plural label");

      await refreshTo(10);
      badge = document.querySelector(".notification-badge");
      assert(badge && badge.textContent === "9+" && button.getAttribute("aria-label") === "Open notifications, 10 unread notifications", "Polling preserves the capped display while keeping the full accessible count");

      result("PASS: " + checks.length + " notification bell browser checks\n" + checks.join("\n"));
    } catch (error) {
      result("FAIL: " + error.message + "\n" + checks.join("\n") + "\n" + error.stack);
    }
  });
})();
