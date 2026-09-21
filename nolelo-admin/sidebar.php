<?php
$current_page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
$nav_items = [
    'dashboard' => ['icon' => 'grid-1x2-fill', 'label' => 'Dashboard'],
    'users' => ['icon' => 'people-fill', 'label' => 'User management'],
    'categories' => ['icon' => 'tags-fill', 'label' => 'Categories & sites'],
    'entries' => ['icon' => 'clipboard-data', 'label' => 'Water entries'],
    'bills' => ['icon' => 'receipt-cutoff', 'label' => 'Bill generation'],
    'reports' => ['icon' => 'file-earmark-spreadsheet', 'label' => 'Reports'],
    'activities' => ['icon' => 'activity', 'label' => 'Worker activities']
];
?>
<div class="col-md-3 col-lg-2 sidebar d-flex flex-column" id="desktopSidebar">
    <div class="brand"><i class="bi bi-droplet-half"></i> <?php echo htmlspecialchars(nolelo_setting('app_name', APP_NAME)); ?></div>
    <nav class="nav flex-column" id="sidebarNav">
        <?php foreach ($nav_items as $key => $item): ?>
            <a class="nav-link <?php echo $current_page === $key ? 'active' : ''; ?>" 
               href="index.php?page=<?php echo $key; ?>">
                <i class="bi bi-<?php echo $item['icon']; ?>"></i> <?php echo $item['label']; ?>
            </a>
        <?php endforeach; ?>
        <hr class="my-2" style="border-color:#2f5770;">
        <a class="nav-link <?php echo $current_page === 'settings' ? 'active' : ''; ?>" 
           href="index.php?page=settings">
            <i class="bi bi-gear"></i> Settings
        </a>
        <a class="nav-link" href="logout.php">
            <i class="bi bi-box-arrow-right"></i> Logout
        </a>
    </nav>
    <div class="mt-auto small text-white-50 ps-2 d-none d-md-block" style="opacity:0.6;">
        <i class="bi bi-person-circle me-1"></i> 
        <?php echo $_SESSION['full_name'] ?? 'Admin'; ?>
    </div>
</div>

<!-- Mobile Sidebar -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="sidebar-widget" id="sidebarWidget">
    <div class="brand">
        <span><i class="bi bi-droplet-half"></i> <?php echo htmlspecialchars(nolelo_setting('app_name', APP_NAME)); ?></span>
        <button class="close-btn" id="closeSidebarBtn"><i class="bi bi-x-lg"></i></button>
    </div>
    <nav class="nav flex-column">
        <?php foreach ($nav_items as $key => $item): ?>
            <a class="nav-link <?php echo $current_page === $key ? 'active' : ''; ?>" 
               href="index.php?page=<?php echo $key; ?>">
                <i class="bi bi-<?php echo $item['icon']; ?>"></i> <?php echo $item['label']; ?>
            </a>
        <?php endforeach; ?>
        <hr class="my-2" style="border-color:#2f5770;">
        <a class="nav-link <?php echo $current_page === 'settings' ? 'active' : ''; ?>" 
           href="index.php?page=settings">
            <i class="bi bi-gear"></i> Settings
        </a>
        <a class="nav-link" href="logout.php">
            <i class="bi bi-box-arrow-right"></i> Logout
        </a>
    </nav>
    <div class="mt-auto small text-white-50 ps-2" style="opacity:0.6;">
        <i class="bi bi-person-circle me-1"></i> 
        <?php echo $_SESSION['full_name'] ?? 'Admin'; ?>
    </div>
</div>
