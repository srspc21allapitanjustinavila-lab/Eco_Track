<?php
$notificationItems = $notificationItems ?? [];
$notificationCount = count($notificationItems);
$notificationLabel = $notificationCount > 0
    ? 'Open notifications, ' . $notificationCount . ' unread notification' . ($notificationCount === 1 ? '' : 's')
    : 'Open notifications';
?>
<div class="notification-control">
    <button type="button" class="header-icon notification-toggle" onclick="toggleNotificationMenu(event, this)" aria-label="<?php echo htmlspecialchars($notificationLabel); ?>" aria-expanded="false" aria-controls="notification-menu" title="Notifications">
        <i class="bi bi-bell-fill notification-toggle-icon" aria-hidden="true"></i>
        <?php if ($notificationCount > 0): ?><span class="notification-badge" aria-hidden="true"><?php echo $notificationCount > 9 ? '9+' : $notificationCount; ?></span><?php endif; ?>
    </button>
    <div class="notification-menu" id="notification-menu" role="menu" aria-label="Unread notifications">
        <div class="notification-menu-title">Unread notifications</div>
        <div class="notification-menu-items">
            <?php if (empty($notificationItems)): ?>
                <div class="notification-empty">You are all caught up.</div>
            <?php else: ?>
                <?php foreach ($notificationItems as $notification): ?>
                    <a class="notification-item" href="notification_read.php?id=<?php echo (int)($notification['id'] ?? 0); ?>">
                        <strong><?php echo htmlspecialchars($notification['title']); ?></strong>
                        <span><?php echo htmlspecialchars($notification['text']); ?></span>
                        <?php if (!empty($notification['time'])): ?><small class="notification-time"><?php echo htmlspecialchars($notification['time']); ?></small><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
    function toggleNotificationMenu(event, button) {
      event.stopPropagation();
      const control = button.closest(".notification-control");
      const open = control.classList.toggle("is-open");
      button.setAttribute("aria-expanded", open ? "true" : "false");
    }
    document.addEventListener("click", function () {
      document.querySelectorAll(".notification-control.is-open").forEach(function (control) {
        control.classList.remove("is-open");
        const button = control.querySelector(".notification-toggle");
        if (button) button.setAttribute("aria-expanded", "false");
      });
    });
    document.addEventListener("keydown", function (event) {
      if (event.key !== "Escape") return;
      document.querySelectorAll(".notification-control.is-open").forEach(function (control) {
        control.classList.remove("is-open");
        const button = control.querySelector(".notification-toggle");
        if (button) button.setAttribute("aria-expanded", "false");
      });
    });

    (function () {
      const script = document.currentScript;
      const control = script ? script.previousElementSibling : null;
      if (!control) return;

      const button = control.querySelector(".notification-toggle");
      const badge = () => control.querySelector(".notification-badge");
      const menuItems = control.querySelector(".notification-menu-items");

      function notificationLabel(count) {
        return count
          ? "Open notifications, " + count + " unread notification" + (count === 1 ? "" : "s")
          : "Open notifications";
      }

      function setBadge(count) {
        count = Math.max(0, Number(count) || 0);
        button.setAttribute("aria-label", notificationLabel(count));
        let countBadge = badge();
        if (!count) {
          if (countBadge) countBadge.remove();
          return;
        }
        if (!countBadge) {
          countBadge = document.createElement("span");
          countBadge.className = "notification-badge";
          countBadge.setAttribute("aria-hidden", "true");
          button.appendChild(countBadge);
        }
        countBadge.textContent = count > 9 ? "9+" : String(count);
      }

      function renderItems(items) {
        if (!menuItems) return;
        menuItems.replaceChildren();
        if (!items.length) {
          const empty = document.createElement("div");
          empty.className = "notification-empty";
          empty.textContent = "You are all caught up.";
          menuItems.appendChild(empty);
          return;
        }
        items.forEach(function (item) {
          const link = document.createElement("a");
          link.className = "notification-item";
          link.href = "notification_read.php?id=" + encodeURIComponent(item.id);
          const title = document.createElement("strong");
          title.textContent = item.title || "Notification";
          const text = document.createElement("span");
          text.textContent = item.text || "";
          link.append(title, text);
          if (item.time) {
            const time = document.createElement("small");
            time.className = "notification-time";
            time.textContent = item.time;
            link.appendChild(time);
          }
          menuItems.appendChild(link);
        });
      }

      function refreshNotifications() {
        fetch("notification_feed.php", { cache: "no-store", credentials: "same-origin" })
          .then(function (response) {
            return response.ok ? response.json() : null;
          })
          .then(function (payload) {
            if (!payload || !Array.isArray(payload.items)) return;
            setBadge(payload.items.length);
            renderItems(payload.items);
          })
          .catch(function () {
            // Keep the server-rendered list visible if a temporary request fails.
          });
      }

      window.setInterval(refreshNotifications, 30000);
      document.addEventListener("visibilitychange", function () {
        if (!document.hidden) refreshNotifications();
      });
    })();
</script>
