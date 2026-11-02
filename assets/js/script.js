/**
 * assets/js/script.js - Skrip pendukung antarmuka
 */
document.addEventListener('DOMContentLoaded', function () {
    // Inisialisasi popover/tooltip jika dibutuhkan
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Auto dismiss alert setelah 4 detik (opsional untuk alert yang dismissible)
    setTimeout(function () {
        var alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(function (alert) {
            var bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 4000);

    // =========================================================================
    // FITUR TOGGLE SIDEBAR (BISA DIBUKA & DITUTUP + PERSISTENSI STATE)
    // =========================================================================
    const toggleBtn = document.getElementById('sidebarToggleBtn');
    const toggleIcon = document.getElementById('sidebarToggleIcon');
    const backdrop = document.getElementById('sidebarBackdrop');

    function updateSidebarIcon() {
        const isCollapsed = document.body.classList.contains('sidebar-collapsed') || document.documentElement.classList.contains('sidebar-collapsed');
        const isMobileOpen = document.body.classList.contains('sidebar-open-mobile');
        
        if (toggleIcon) {
            if (window.innerWidth < 768) {
                toggleIcon.className = isMobileOpen ? 'bi bi-x-lg fs-5' : 'bi bi-list fs-4';
            } else {
                toggleIcon.className = isCollapsed ? 'bi bi-layout-sidebar-inset fs-5' : 'bi bi-layout-sidebar fs-5';
            }
        }
    }

    // Pastikan sinkronisasi awal saat DOM siap
    if (window.innerWidth >= 768 && localStorage.getItem('sidebar_collapsed') === 'true') {
        document.body.classList.add('sidebar-collapsed');
        document.documentElement.classList.add('sidebar-collapsed');
    }
    updateSidebarIcon();

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (window.innerWidth < 768) {
                // Di mode mobile: buka/tutup off-canvas drawer
                document.body.classList.toggle('sidebar-open-mobile');
            } else {
                // Di mode desktop: toggle collapse & simpan state
                const isNowCollapsed = !document.body.classList.contains('sidebar-collapsed');
                if (isNowCollapsed) {
                    document.body.classList.add('sidebar-collapsed');
                    document.documentElement.classList.add('sidebar-collapsed');
                    localStorage.setItem('sidebar_collapsed', 'true');
                } else {
                    document.body.classList.remove('sidebar-collapsed');
                    document.documentElement.classList.remove('sidebar-collapsed');
                    localStorage.setItem('sidebar_collapsed', 'false');
                }
            }
            updateSidebarIcon();
        });
    }

    // Klik backdrop mobile untuk menutup sidebar
    if (backdrop) {
        backdrop.addEventListener('click', function () {
            document.body.classList.remove('sidebar-open-mobile');
            updateSidebarIcon();
        });
    }

    // Auto tutup sidebar mobile saat link navigasi diklik
    document.querySelectorAll('.sidebar .nav-link').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth < 768) {
                document.body.classList.remove('sidebar-open-mobile');
                updateSidebarIcon();
            }
        });
    });

    // Responsif resize handler
    window.addEventListener('resize', function () {
        if (window.innerWidth >= 768) {
            document.body.classList.remove('sidebar-open-mobile');
            if (localStorage.getItem('sidebar_collapsed') === 'true') {
                document.body.classList.add('sidebar-collapsed');
                document.documentElement.classList.add('sidebar-collapsed');
            } else {
                document.body.classList.remove('sidebar-collapsed');
                document.documentElement.classList.remove('sidebar-collapsed');
            }
        }
        updateSidebarIcon();
    });
});
