<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
<script>
    (function () {
      const savedTheme = localStorage.getItem("theme") || "light";
      document.documentElement.setAttribute("data-theme", savedTheme);
      // A browser may restore a page from its back/forward cache without a
      // network request. Reload it so the server-side session/role guard is
      // always evaluated before protected content is shown again.
      window.addEventListener("pageshow", function (event) {
        if (event.persisted) window.location.reload();
      });
    })();
</script>
