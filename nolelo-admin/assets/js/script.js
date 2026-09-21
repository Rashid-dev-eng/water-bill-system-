document.addEventListener('DOMContentLoaded', function() {
    // Charts (only if elements exist on the page)
    const dailyChartEl = document.getElementById('dailyChart');
    const weeklyChartEl = document.getElementById('weeklyChart');
    const monthlyChartEl = document.getElementById('monthlyChart');

    if (dailyChartEl) {
        new Chart(dailyChartEl, {
            type: 'bar',
            data: { 
                labels: ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'], 
                datasets: [{ 
                    label: 'm³', 
                    data: [12,18,22,14,25,20,24.8], 
                    backgroundColor: '#1b5a7a', 
                    borderRadius: 6 
                }] 
            },
            options: { 
                responsive: true, 
                maintainAspectRatio: false, 
                plugins: { legend: { display: false } }, 
                scales: { y: { beginAtZero: true } } 
            }
        });
    }

    if (weeklyChartEl) {
        new Chart(weeklyChartEl, {
            type: 'line',
            data: { 
                labels: ['W1','W2','W3','W4'], 
                datasets: [{ 
                    label: 'm³', 
                    data: [98,115,142,128], 
                    borderColor: '#1b5a7a', 
                    backgroundColor: 'rgba(27,90,122,0.1)', 
                    fill: true, 
                    tension: 0.3, 
                    pointBackgroundColor: '#1b5a7a' 
                }] 
            },
            options: { 
                responsive: true, 
                maintainAspectRatio: false, 
                plugins: { legend: { display: false } }, 
                scales: { y: { beginAtZero: true } } 
            }
        });
    }

    if (monthlyChartEl) {
        new Chart(monthlyChartEl, {
            type: 'bar',
            data: { 
                labels: ['Feb','Mar','Apr','May','Jun','Jul'], 
                datasets: [{ 
                    label: 'm³', 
                    data: [280,310,340,390,370,418], 
                    backgroundColor: '#4fc3f7', 
                    borderRadius: 6 
                }] 
            },
            options: { 
                responsive: true, 
                maintainAspectRatio: false, 
                plugins: { legend: { display: false } }, 
                scales: { y: { beginAtZero: true } } 
            }
        });
    }

    // Bill filter logic (only if elements exist)
    const filterOptions = document.querySelectorAll('.bill-option');
    const filterSelect = document.getElementById('filterSelect');
    
    if (filterOptions.length && filterSelect) {
        function updateFilterField(filter) {
            let options = '';
            if (filter === 'all') options = `<option value="">All (default)</option>`;
            else if (filter === 'category') options = `<option value="">Select category</option><option value="residential">Residential</option><option value="commercial">Commercial</option><option value="institutional">Institutional</option>`;
            else if (filter === 'site') options = `<option value="">Select site</option><option value="site-a">Site A</option><option value="site-b">Site B</option><option value="site-c">Site C</option>`;
            else if (filter === 'user') options = `<option value="">Select user</option><option value="grace">Grace Mushi</option><option value="aisha">Aisha Kikwete</option><option value="john">E. John</option><option value="peter">Peter Ngowi</option><option value="mary">Mary S.</option>`;
            filterSelect.innerHTML = options;
        }
        
        filterOptions.forEach(btn => {
            btn.addEventListener('click', function() {
                filterOptions.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                updateFilterField(this.dataset.filter);
            });
        });
        updateFilterField('all');
    }

    // ===== SIDEBAR TOGGLE FOR MOBILE =====
    const sidebarWidget = document.getElementById('sidebarWidget');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const hamburgerBtn = document.getElementById('hamburgerBtn');
    const closeSidebarBtn = document.getElementById('closeSidebarBtn');

    function openSidebar() {
        if (sidebarWidget) {
            sidebarWidget.classList.add('open');
            sidebarOverlay.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
    }
    
    function closeSidebar() {
        if (sidebarWidget) {
            sidebarWidget.classList.remove('open');
            sidebarOverlay.classList.remove('show');
            document.body.style.overflow = '';
        }
    }

    if (hamburgerBtn) hamburgerBtn.addEventListener('click', openSidebar);
    if (closeSidebarBtn) closeSidebarBtn.addEventListener('click', closeSidebar);
    if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeSidebar);

    // Close sidebar on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeSidebar();
    });

    // Mobile sidebar links: close on click
    document.querySelectorAll('.sidebar-widget .nav-link').forEach(link => {
        link.addEventListener('click', function() {
            setTimeout(closeSidebar, 100);
        });
    });
});