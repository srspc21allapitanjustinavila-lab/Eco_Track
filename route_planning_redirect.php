<?php
require_once 'config.php';
requireLogin();

// Route planning redirects to the live data-driven Heatmap.
header('Location: waste_heatmap.php');
exit();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Route Optimization - EcoTrack</title>
    <?php include 'includes/theme_head.php'; ?>
    <style>
        :root {
          --bg-primary: #f5f5f5;
          --bg-secondary: white;
          --bg-sidebar: linear-gradient(180deg, #8bc34a 0%, #689f38 100%);
          --text-primary: #333;
          --text-secondary: #666;
          --text-muted: #999;
          --border-color: #ddd;
          --card-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
          --toggle-bg: #ccc;
          --toggle-active: #8bc34a;
          --input-bg: white;
          --input-border: #ddd;
          --modal-overlay: rgba(0, 0, 0, 0.5);
        }

        [data-theme="dark"] {
          --bg-primary: #1a1a1a;
          --bg-secondary: #2d2d2d;
          --bg-sidebar: linear-gradient(180deg, #2e4a20 0%, #1e3a10 100%);
          --text-primary: #ffffff;
          --text-secondary: #cccccc;
          --text-muted: #888888;
          --border-color: #444;
          --card-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
          --toggle-bg: #555;
          --toggle-active: #7cb342;
          --input-bg: #3d3d3d;
          --input-border: #555;
          --modal-overlay: rgba(0, 0, 0, 0.7);
        }
        * {
          margin: 0;
          padding: 0;
          box-sizing: border-box;
        }

        body {
          font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
          background: var(--bg-primary);
          color: var(--text-primary);
          display: flex;
          min-height: 100vh;
          transition:
            background 0.3s,
            color 0.3s;
        }

        /* Sidebar - Matching Admin Dashboard */
        .sidebar {
          width: 280px;
          background: var(--bg-sidebar);
          color: white;
          display: flex;
          flex-direction: column;
          padding: 20px 0;
          position: fixed;
          height: 100vh;
        }

        .logo-section {
          padding: 20px;
          text-align: center;
          border-bottom: 1px solid rgba(255, 255, 255, 0.2);
          margin-bottom: 20px;
        }

        .logo {
          width: 60px;
          height: 60px;
          background: white;
          border-radius: 50%;
          display: flex;
          align-items: center;
          justify-content: center;
          margin: 0 auto 10px;
          font-size: 28px;
        }

        .logo-text {
          font-size: 13px;
          font-weight: bold;
          line-height: 1.3;
          text-transform: uppercase;
        }

        .nav-menu {
          flex: 1;
          padding: 0 15px;
        }

        .nav-item {
          display: flex;
          align-items: center;
          padding: 12px 20px;
          margin: 5px 0;
          border-radius: 25px;
          cursor: pointer;
          transition: all 0.3s;
          text-decoration: none;
          color: white;
        }

        .nav-item:hover,
        .nav-item.active {
          background: rgba(255, 255, 255, 0.2);
        }

        .nav-icon {
          width: 24px;
          height: 24px;
          margin-right: 15px;
        }

        .nav-text {
          font-size: 13px;
          font-weight: 500;
        }

        /* Main Content */
        .main-content {
          flex: 1;
          margin-left: 280px;
          padding: 20px;
        }

        .header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-bottom: 30px;
        }

        .header h1 {
          font-size: 28px;
          font-weight: 600;
          color: var(--text-primary);
        }

        .header-actions {
          display: flex;
          gap: 15px;
        }

        .btn {
          padding: 12px 25px;
          border: none;
          border-radius: 25px;
          font-size: 13px;
          font-weight: 600;
          cursor: pointer;
          transition: all 0.3s;
          text-decoration: none;
          display: inline-flex;
          align-items: center;
          gap: 8px;
        }

        .btn-green {
          background: #8bc34a;
          color: white;
        }

        .btn-green:hover {
          background: #7cb342;
        }

        .btn-gray {
          background: var(--toggle-bg);
          color: var(--text-primary);
        }

        .btn-gray:hover {
          background: var(--text-secondary);
        }

        /* Map Container */
        .map-container {
          background: var(--bg-secondary);
          border-radius: 15px;
          height: 500px;
          box-shadow: var(--card-shadow);
          display: flex;
          align-items: center;
          justify-content: center;
        }

        .map-placeholder {
          text-align: center;
        }

        .map-placeholder-icon {
          font-size: 80px;
          color: var(--text-secondary);
          margin-bottom: 20px;
        }

        .map-placeholder-text {
          font-size: 24px;
          font-weight: 600;
          color: var(--text-primary);
          margin-bottom: 10px;
        }

        .map-placeholder-hint {
          font-size: 16px;
          color: var(--text-secondary);
        }

        .header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-bottom: 30px;
        }

        .header h1 {
          color: #8bc34a;
          font-size: 28px;
          font-weight: 600;
          letter-spacing: 1px;
        }

        .header-icons {
          display: flex;
          gap: 20px;
        }

        .header-icons a {
          color: #666;
          text-decoration: none;
          font-size: 20px;
        }

        /* Filter Buttons Section */
        .filter-section {
          display: flex;
          gap: 15px;
          margin-bottom: 30px;
          flex-wrap: wrap;
        }

        .btn {
          padding: 12px 25px;
          border: none;
          border-radius: 25px;
          cursor: pointer;
          font-size: 13px;
          font-weight: 600;
          text-transform: uppercase;
          transition: all 0.3s;
          display: inline-flex;
          align-items: center;
          gap: 8px;
          text-decoration: none;
        }

        .btn-green {
          background: #aed581;
          color: #333;
        }

        .btn-green:hover {
          background: #9ccc65;
        }

        .btn-gray {
          background: #9e9e9e;
          color: white;
        }

        .btn-gray:hover {
          background: #757575;
        }

        /* Map Container */
        .map-container {
          background: white;
          border-radius: 15px;
          padding: 30px;
          box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
          min-height: 500px;
          display: flex;
          justify-content: center;
          align-items: center;
        }

        .map-placeholder {
          width: 100%;
          max-width: 800px;
          height: 400px;
          background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
          border-radius: 10px;
          display: flex;
          flex-direction: column;
          justify-content: center;
          align-items: center;
          border: 2px dashed #4caf50;
        }

        .map-placeholder-icon {
          font-size: 80px;
          margin-bottom: 20px;
        }

        .map-placeholder-text {
          font-size: 18px;
          color: #666;
          text-align: center;
        }

        .map-placeholder-hint {
          font-size: 14px;
          color: #999;
          margin-top: 10px;
        }
    </style>
    <link rel="stylesheet" href="assets/css/ecotrack-theme.css">
</head>
<body>
    <?php $active_page = 'route_planning_redirect.php';
$useLogoutModal = false;
include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <h1>ROUTE OVERVIEW</h1>
            <div class="header-icons">
                <?php include 'includes/notification_bell.php'; ?>
            </div>
        </div>

        <!-- Filter Buttons -->
        <div class="filter-section">
            <button class="btn btn-green">&#128203; SELECT WASTE TYPE</button>
            <button class="btn btn-green">&#128197; SELECT DATE</button>
            <button class="btn btn-green">&#128269; APPLY FILTER</button>
            <button class="btn btn-green">&#128260; RESET FILTER</button>
        </div>

        <!-- Map Area -->
        <div class="map-container">
            <div class="map-placeholder">
                <div class="map-placeholder-icon">&#128663;</div>
                <div class="map-placeholder-text">Route Optimization Map</div>
                <div class="map-placeholder-hint">Optimized collection routes will be displayed here</div>
            </div>
        </div>
    </div>
</body>
</html>
