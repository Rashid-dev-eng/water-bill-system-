<footer class="footer mt-auto py-3 border-top" style="background:white;">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-6">
                <span class="text-muted-small">&copy; 2026 <strong>NOLELO Clean Water Supply</strong> · All rights reserved</span>
            </div>
            <div class="col-md-6 text-md-end">
                <span class="text-muted-small">
                    <i class="bi bi-droplet-half me-1" style="color:#1b5a7a;"></i> 
                    Version <?php echo APP_VERSION; ?> · 
                    <i class="bi bi-clock me-1"></i> 
                    <span id="footerTime"></span>
                </span>
            </div>
        </div>
    </div>
</footer>
<script>
window.showNoleloToast = window.showNoleloToast || function(type, message) {
    var container = document.getElementById('noleloGlobalToastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'noleloGlobalToastContainer';
        container.style.cssText = 'position:fixed;top:80px;right:20px;z-index:10000;width:min(380px,calc(100% - 40px));';
        document.body.appendChild(container);
    }
    var toast = document.createElement('div');
    var safeType = ['success', 'danger', 'warning', 'info'].indexOf(type) >= 0 ? type : 'info';
    var safeMessage = String(message || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    toast.className = 'alert alert-' + safeType + ' alert-dismissible fade show shadow-sm';
    toast.style.cssText = 'border-radius:14px;border:0;margin-bottom:10px;';
    toast.innerHTML = '<strong>' + (safeType === 'success' ? 'Success' : safeType === 'danger' ? 'Failed' : 'Notice') + '</strong><div>' + safeMessage + '</div><button type="button" class="btn-close" aria-label="Close"></button>';
    toast.querySelector('.btn-close').addEventListener('click', function() { toast.remove(); });
    container.appendChild(toast);
    window.setTimeout(function() { if (toast.parentNode) toast.remove(); }, 3500);
};
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.flash-message').forEach(function(message) {
        var type = message.classList.contains('alert-success') ? 'success' : 'danger';
        window.showNoleloToast(type, message.textContent.trim());
        message.remove();
    });
});
</script>
