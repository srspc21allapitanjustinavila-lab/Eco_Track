<?php
$sidebarUserType = $_SESSION['user']['user_type'] ?? $user_type ?? 'staff';
$sidebarActive = $active_page ?? basename($_SERVER['PHP_SELF']);
$sidebarWorkspaceLabel = $sidebarUserType === 'admin' ? 'Administrator workspace' : 'Staff workspace';

$adminMenu = [
    ['href' => 'admin_dashboard.php', 'text' => 'Dashboard'],
    ['href' => 'admin_collection_schedule.php', 'text' => 'Collection DSS'],
    ['href' => 'admin_waste_records.php', 'text' => 'Waste Data'],
    ['href' => 'admin_waste_import.php', 'text' => 'Upload File'],
    ['href' => 'admin_waste_heatmap.php', 'text' => 'Heatmap'],
    ['href' => 'admin_operations_reports.php', 'text' => 'Reports'],
    ['href' => 'admin_user_management.php', 'text' => 'Users'],
    ['href' => 'admin_system_settings.php', 'text' => 'Settings'],
];

$staffMenu = [
    ['href' => 'staff_home.php', 'text' => 'Home'],
    ['href' => 'staff_waste_records.php', 'text' => 'Waste Data'],
    ['href' => 'staff_daily_tasks.php', 'text' => 'Daily Tasks'],
    ['href' => 'staff_waste_heatmap.php', 'text' => 'Heatmap'],
    ['href' => 'staff_announcements.php', 'text' => 'Updates'],
    ['href' => 'staff_settings.php', 'text' => 'Settings'],
];

$menuItems = $sidebarUserType === 'admin' ? $adminMenu : $staffMenu;
?>
<button type="button" class="sidebar-menu-toggle" data-sidebar-toggle aria-controls="primaryNavigation" aria-expanded="false">
    <span>Menu</span>
</button>
<div class="sidebar-backdrop" data-sidebar-backdrop hidden></div>
<aside id="primaryNavigation" class="sidebar" aria-label="Primary navigation">
    <header class="logo-section">
        <p class="sidebar-brand-eyebrow">Waste management</p>
        <p class="logo-text">EcoTrack</p>
        <p class="sidebar-workspace"><?php echo htmlspecialchars($sidebarWorkspaceLabel); ?></p>
    </header>
    <nav class="nav-menu">
        <?php foreach ($menuItems as $item): ?>
            <?php $isActive = $sidebarActive === $item['href']; ?>
            <a href="<?php echo $item['href']; ?>" class="nav-item <?php echo $isActive ? 'active' : ''; ?>"<?php echo $isActive ? ' aria-current="page"' : ''; ?>>
                <span class="nav-text"><?php echo $item['text']; ?></span>
            </a>
        <?php endforeach; ?>
        <div class="sidebar-footer">
            <p class="sidebar-footer-label">Account</p>
            <a href="login.php?logout=1" class="nav-item nav-logout" onclick="<?php echo !empty($useLogoutModal) ? 'showLogoutModal(event)' : "return confirm('Are you sure you want to logout?')"; ?>">
                <span class="nav-text">Sign out</span>
            </a>
        </div>
    </nav>
</aside>
<?php include __DIR__ . '/transaction_confirmation_modal.php'; ?>
<script defer src="assets/js/sidebar.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/sidebar.js'); ?>"></script>
