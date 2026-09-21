<?php
$current_page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
$page_titles = [
    'dashboard' => 'Dashboard',
    'users' => 'User Management',
    'categories' => 'Categories & Sites',
    'entries' => 'Water Entries',
    'bills' => 'Bill Generation',
    'reports' => 'Reports',
    'activities' => 'Worker Activities',
    'settings' => 'Settings'
];
$page_icons = [
    'dashboard' => 'speedometer2',
    'users' => 'people-fill',
    'categories' => 'tags-fill',
    'entries' => 'clipboard-data',
    'bills' => 'receipt-cutoff',
    'reports' => 'file-earmark-spreadsheet',
    'activities' => 'activity',
    'settings' => 'gear'
];
?>
<div class="topbar">
    <div class="d-flex align-items-center gap-2">
        <button class="hamburger-btn" id="hamburgerBtn"><i class="bi bi-list"></i></button>
        <span class="page-title">
            <i class="bi bi-<?php echo $page_icons[$current_page]; ?> me-2" style="color:#1b5a7a;"></i>
            <?php echo $page_titles[$current_page]; ?>
        </span>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="admin-badge"><i class="bi bi-shield-lock me-1"></i> Admin</span>
        <i class="bi bi-bell fs-5" style="color:#2f5d78;"></i>
        <img src="https://ui-avatars.com/api/?name=Admin&background=1b5a7a&color=fff&size=28" class="rounded-circle" width="32" height="32" alt="admin">
    </div>
</div>
