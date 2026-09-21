<?php
$current_page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
$worker = getWorkerData();
$initial = strtoupper(substr($worker['full_name'] ?? 'W', 0, 1));
?>
<div class="col-md-3 col-lg-2 sidebar d-flex flex-column" id="desktopSidebar">
    <div class="brand">
        <i class="bi bi-droplet-half"></i> <?php echo htmlspecialchars(nolelo_setting('app_name', APP_NAME)); ?>
        <span>v2.0</span>
    </div>
    <nav class="nav flex-column" id="sidebarNav">
        <a class="nav-link <?php echo $current_page === 'dashboard' ? 'active' : ''; ?>" href="index.php?page=dashboard">
            <i class="bi bi-grid-1x2-fill"></i> Dashboard
        </a>
        <a class="nav-link <?php echo $current_page === 'entries' ? 'active' : ''; ?>" href="index.php?page=entries">
            <i class="bi bi-clipboard-data"></i> Enter Reading
        </a>
        <a class="nav-link <?php echo $current_page === 'history' ? 'active' : ''; ?>" href="index.php?page=history">
            <i class="bi bi-clock-history"></i> History
        </a>
        <a class="nav-link <?php echo $current_page === 'bills' ? 'active' : ''; ?>" href="index.php?page=bills">
            <i class="bi bi-receipt-cutoff"></i> My Bills
        </a>
        <a class="nav-link <?php echo $current_page === 'profile' ? 'active' : ''; ?>" href="index.php?page=profile">
            <i class="bi bi-person-circle"></i> Profile
        </a>
        <hr class="my-2" style="border-color:#2f5770;">
        <a class="nav-link" href="logout.php">
            <i class="bi bi-box-arrow-right"></i> Logout
        </a>
    </nav>
    <div class="user-info d-none d-md-block">
        <div class="d-flex align-items-center gap-3">
            <div class="avatar"><?php echo $initial; ?></div>
            <div>
                <div class="fw-semibold" style="font-size:0.9rem;"><?php echo htmlspecialchars($worker['full_name'] ?? 'Worker'); ?></div>
                <div class="text-muted-small" style="font-size:0.7rem;">Worker</div>
            </div>
        </div>
    </div>
</div>

<!-- Mobile Sidebar -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="sidebar-widget" id="sidebarWidget">
    <div class="brand d-flex justify-content-between align-items-center">
        <span><i class="bi bi-droplet-half"></i> <?php echo htmlspecialchars(nolelo_setting('app_name', APP_NAME)); ?></span>
        <button class="close-btn" id="closeSidebarBtn"><i class="bi bi-x-lg"></i></button>
    </div>
    <nav class="nav flex-column" id="mobileSidebarNav">
        <a class="nav-link <?php echo $current_page === 'dashboard' ? 'active' : ''; ?>" href="index.php?page=dashboard">
            <i class="bi bi-grid-1x2-fill"></i> Dashboard
        </a>
        <a class="nav-link <?php echo $current_page === 'entries' ? 'active' : ''; ?>" href="index.php?page=entries">
            <i class="bi bi-clipboard-data"></i> Enter Reading
        </a>
        <a class="nav-link <?php echo $current_page === 'history' ? 'active' : ''; ?>" href="index.php?page=history">
            <i class="bi bi-clock-history"></i> History
        </a>
        <a class="nav-link <?php echo $current_page === 'bills' ? 'active' : ''; ?>" href="index.php?page=bills">
            <i class="bi bi-receipt-cutoff"></i> My Bills
        </a>
        <a class="nav-link <?php echo $current_page === 'profile' ? 'active' : ''; ?>" href="index.php?page=profile">
            <i class="bi bi-person-circle"></i> Profile
        </a>
        <hr class="my-2" style="border-color:#2f5770;">
        <a class="nav-link" href="logout.php">
            <i class="bi bi-box-arrow-right"></i> Logout
        </a>
    </nav>
    <div class="user-info mt-auto">
        <div class="d-flex align-items-center gap-3">
            <div class="avatar"><?php echo $initial; ?></div>
            <div>
                <div class="fw-semibold" style="font-size:0.9rem;"><?php echo htmlspecialchars($worker['full_name'] ?? 'Worker'); ?></div>
                <div class="text-muted-small" style="font-size:0.7rem;">Worker</div>
            </div>
        </div>
    </div>
</div>
