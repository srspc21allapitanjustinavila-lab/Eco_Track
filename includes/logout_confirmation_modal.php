<div id="logoutModal" class="shared-logout-modal" aria-hidden="true">
    <div class="shared-logout-content" role="dialog" aria-modal="true" aria-labelledby="logoutModalTitle">
        <div class="shared-logout-header">
            <span class="shared-logout-icon" aria-hidden="true">&#x21AA;</span>
            <span class="shared-logout-eyebrow">EcoTrack MRF Management</span>
            <h3 id="logoutModalTitle">Confirm logout</h3>
        </div>
        <div class="shared-logout-body">
            <p>Are you sure you want to log out?</p>
            <span>You will need to sign in again to access your account.</span>
        </div>
        <div class="shared-logout-actions">
            <button type="button" class="btn btn-gray" data-logout-cancel onclick="closeLogoutModal()">Stay signed in</button>
            <a href="login.php?logout=1" class="btn btn-green">Log out</a>
        </div>
    </div>
</div>
<style>
    .shared-logout-modal {
      display: none;
      position: fixed;
      z-index: 1300;
      inset: 0;
      align-items: center;
      justify-content: center;
      padding: 20px;
      background: rgba(11, 35, 30, 0.58);
      backdrop-filter: blur(3px);
    }
    .shared-logout-modal.show {
      display: flex;
    }
    .shared-logout-content {
      width: min(420px, 100%);
      overflow: hidden;
      background: #fff;
      border: 1px solid rgba(255, 255, 255, 0.45);
      border-radius: 18px;
      box-shadow: 0 24px 60px rgba(5, 30, 25, 0.35);
      animation: sharedLogoutEnter 0.18s ease-out;
    }
    .shared-logout-header {
      padding: 26px 28px 22px;
      text-align: center;
      color: #fff;
      background: #126b5d;
    }
    .shared-logout-icon {
      display: flex;
      width: 44px;
      height: 44px;
      align-items: center;
      justify-content: center;
      margin: 0 auto 11px;
      border: 1px solid rgba(255, 255, 255, 0.33);
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.14);
      color: #fff;
      font-size: 25px;
      font-weight: 700;
      line-height: 1;
    }
    .shared-logout-eyebrow {
      display: block;
      margin-bottom: 5px;
      color: rgba(255, 255, 255, 0.78);
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.06em;
      text-transform: uppercase;
    }
    .shared-logout-header h3 {
      margin: 0;
      color: #fff !important;
      font-size: 20px;
      font-weight: 750;
      letter-spacing: 0;
    }
    .shared-logout-body {
      padding: 25px 30px 12px;
      color: #38534c;
      text-align: center;
    }
    .shared-logout-body p {
      margin: 0;
      color: #263d37;
      font-size: 16px;
      font-weight: 700;
      line-height: 1.45;
    }
    .shared-logout-body span {
      display: block;
      margin-top: 7px;
      color: #6a7d77;
      font-size: 13px;
      line-height: 1.5;
    }
    .shared-logout-actions {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      padding: 20px 28px 28px;
    }
    .shared-logout-actions .btn {
      display: inline-flex;
      min-height: 46px;
      align-items: center;
      justify-content: center;
      padding: 11px 16px;
      border: 1px solid transparent;
      border-radius: 10px !important;
      font-size: 14px;
      font-weight: 750;
      text-decoration: none;
      text-transform: none;
      transition:
        transform 0.15s ease,
        background 0.15s ease,
        box-shadow 0.15s ease;
    }
    .shared-logout-actions .btn:hover {
      transform: translateY(-1px);
    }
    .shared-logout-actions .btn-gray {
      color: #244a40 !important;
      background: #edf5f1 !important;
      border-color: #c7ddd5 !important;
    }
    .shared-logout-actions .btn-gray:hover {
      background: #e0eee8 !important;
    }
    .shared-logout-actions .btn-green {
      background: #0b6657 !important;
      box-shadow: 0 8px 16px rgba(11, 102, 87, 0.22);
    }
    .shared-logout-actions .btn-green:hover {
      background: #095548 !important;
    }
    @keyframes sharedLogoutEnter {
      from {
        opacity: 0;
        transform: translateY(10px) scale(0.98);
      }
      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }
    @media (max-width: 420px) {
      .shared-logout-modal {
        padding: 14px;
      }
      .shared-logout-header {
        padding: 23px 20px 19px;
      }
      .shared-logout-body {
        padding: 22px 22px 10px;
      }
      .shared-logout-actions {
        grid-template-columns: 1fr;
        padding: 18px 22px 22px;
      }
      .shared-logout-actions .btn-green {
        order: -1;
      }
    }
</style>
<script>
    let sharedLogoutTrigger = null;
    function showLogoutModal(event) {
      if (event) {
        event.preventDefault();
        sharedLogoutTrigger = event.currentTarget;
      }
      const modal = document.getElementById("logoutModal");
      if (modal) {
        modal.classList.add("show");
        modal.setAttribute("aria-hidden", "false");
        const cancelButton = modal.querySelector("[data-logout-cancel]");
        if (cancelButton) window.setTimeout(() => cancelButton.focus(), 0);
      }
    }
    function closeLogoutModal() {
      const modal = document.getElementById("logoutModal");
      if (modal) {
        modal.classList.remove("show");
        modal.setAttribute("aria-hidden", "true");
      }
      if (sharedLogoutTrigger) sharedLogoutTrigger.focus();
    }
    document.addEventListener("click", function (event) {
      const modal = document.getElementById("logoutModal");
      if (modal && event.target === modal) closeLogoutModal();
    });
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape") {
        const modal = document.getElementById("logoutModal");
        if (modal && modal.classList.contains("show")) closeLogoutModal();
      }
    });
</script>
