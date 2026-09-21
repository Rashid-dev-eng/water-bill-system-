<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('NOLELO_WORKER_SESSION');
    session_save_path(sys_get_temp_dir());
    session_start();
}
