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
    // FITUR TOGGLE SIDEBAR (SINKRONISASI EVENT PENDUKUNG)
    // =========================================================================
    const backdrop = document.getElementById('sidebarBackdrop');

    // Klik backdrop mobile untuk menutup sidebar
    if (backdrop) {
        backdrop.addEventListener('click', function () {
            if (typeof window.toggleSidebarMenu === 'function') {
                window.toggleSidebarMenu();
            } else {
                document.body.classList.remove('sidebar-open-mobile');
            }
        });
    }

    // Auto tutup sidebar mobile saat salah satu link navigasi diklik
    document.querySelectorAll('.sidebar .nav-link').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth < 768) {
                document.body.classList.remove('sidebar-open-mobile');
                const icon = document.getElementById('sidebarToggleIcon');
                if (icon) icon.className = 'bi bi-list fs-3 text-white';
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
    });
});
