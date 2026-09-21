<?php
$current_page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
$page_titles = [
    'dashboard' => 'Dashboard',
    'entries' => 'Enter Reading',
    'history' => 'History',
    'bills' => 'My Bills',
    'profile' => 'Profile'
];
$page_icons = [
    'dashboard' => 'speedometer2',
    'entries' => 'clipboard-data',
    'history' => 'clock-history',
    'bills' => 'receipt-cutoff',
    'profile' => 'person-circle'
];
$worker = getWorkerData();
?>
<div class="topbar">
    <div class="d-flex align-items-center gap-2">
        <button class="hamburger-btn" id="hamburgerBtn"><i class="bi bi-list"></i></button>
        <span class="page-title" id="pageTitle">
            <i class="bi bi-<?php echo $page_icons[$current_page]; ?> me-2" style="color:#1b5a7a;"></i>
            <?php echo $page_titles[$current_page]; ?>
        </span>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="user-badge">
            <span class="dot"></span>
            <i class="bi bi-person-circle me-1"></i> <?php echo htmlspecialchars($worker['full_name'] ?? 'Worker'); ?>
        </span>
        <i class="bi bi-bell fs-5" style="color:#2f5d78;cursor:pointer;"></i>
        <button class="btn btn-sm btn-outline-primary" onclick="showForcePinChange()">
            <i class="bi bi-shield-lock"></i> Change PIN
        </button>
    </div>
</div>