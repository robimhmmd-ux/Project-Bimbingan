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
});

