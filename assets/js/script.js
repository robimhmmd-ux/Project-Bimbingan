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

    // Inisialisasi interceptor modal konfirmasi modern
    initModernConfirmInterceptors();
});


/**
 * Tampilkan dialog konfirmasi modern dengan SweetAlert2 (jika tersedia)
 * atau fallback ke Bootstrap Modal (#modalModernConfirm)
 */
window.showModernConfirm = function(options) {
    options = options || {};
    const title = options.title || 'Konfirmasi Tindakan';
    const text = options.text || 'Apakah Anda yakin ingin melanjutkan tindakan ini?';
    const icon = options.icon || (options.isDanger ? 'warning' : 'question');
    const confirmText = options.confirmText || 'Ya, Lanjutkan';
    const cancelText = options.cancelText || 'Batal';
    const isDanger = options.isDanger || (icon === 'danger' || icon === 'warning' || /keluar|hapus|batal|lepas/i.test(text));
    const onConfirm = options.onConfirm || function() {};
    const onCancel = options.onCancel || function() {};

    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';

    // 1. Prioritaskan SweetAlert2 jika CDN berhasil dimuat
    if (typeof Swal !== 'undefined') {
        const swalIcon = (icon === 'danger') ? 'warning' : icon;
        Swal.fire({
            title: title,
            text: text,
            icon: swalIcon,
            showCancelButton: true,
            confirmButtonText: confirmText,
            cancelButtonText: cancelText,
            confirmButtonColor: isDanger ? '#ef4444' : '#00AA13',
            cancelButtonColor: isDark ? '#334155' : '#94a3b8',
            reverseButtons: true,
            background: isDark ? '#16243b' : '#ffffff',
            color: isDark ? '#f1f5f9' : '#0f172a',
            customClass: {
                popup: 'modern-swal-popup'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                onConfirm();
            } else {
                onCancel();
            }
        });
        return;
    }

    // 2. Fallback ke Bootstrap Modal (#modalModernConfirm)
    const modalEl = document.getElementById('modalModernConfirm');
    if (modalEl && typeof bootstrap !== 'undefined') {
        const titleEl = document.getElementById('modalModernConfirmLabel');
        const textEl = document.getElementById('modernConfirmText');
        const okBtn = document.getElementById('modernConfirmOkBtn');
        const iconBox = document.getElementById('modernConfirmIconBox');
        const iconEl = document.getElementById('modernConfirmIcon');

        if (titleEl) titleEl.textContent = title;
        if (textEl) textEl.textContent = text;
        if (okBtn) {
            okBtn.innerHTML = isDanger ? `<i class="bi bi-exclamation-triangle me-1"></i> ${confirmText}` : `<i class="bi bi-check2-circle me-1"></i> ${confirmText}`;
            okBtn.className = isDanger ? 'btn btn-confirm-logout px-4 py-2' : 'btn btn-confirm-action px-4 py-2';
        }
        if (iconBox && iconEl) {
            if (isDanger) {
                iconBox.className = 'confirm-icon-box logout-icon-box mx-auto mb-3';
                iconEl.className = 'bi bi-exclamation-triangle-fill fs-1 text-danger';
            } else {
                iconBox.className = 'confirm-icon-box default-icon-box mx-auto mb-3';
                iconEl.className = 'bi bi-question-circle-fill fs-1 text-success';
            }
        }

        const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);

        // Pasang handler tombol OK (bersihkan event lama via klon node)
        const newOkBtn = okBtn.cloneNode(true);
        okBtn.parentNode.replaceChild(newOkBtn, okBtn);
        newOkBtn.addEventListener('click', function() {
            bsModal.hide();
            onConfirm();
        });

        bsModal.show();
        return;
    }

    // 3. Fallback terakhir jika modal HTML tidak ada di DOM
    if (window.confirm(text)) {
        onConfirm();
    } else {
        onCancel();
    }
};

/**
 * Otomatis mengganti semua pemanggilan browser native confirm()
 * pada link atau form yang memiliki onclick/onsubmit="return confirm(...)"
 */
function initModernConfirmInterceptors() {
    function extractMsg(attrValue) {
        if (!attrValue) return null;
        // Tangkap isi teks di dalam confirm('...') atau confirm("...")
        const m = attrValue.match(/confirm\s*\(\s*(['"`])((?:\.|(?!).)*)\s*\)/);
        return m ? m[2].replace(/\'/g, "'").replace(/\"/g, '"') : null;
    }

    // A. Intercept tag <a> dengan onclick="return confirm(...)"
    document.querySelectorAll('a[onclick*="confirm("]').forEach(function(link) {
        const raw = link.getAttribute('onclick');
        const msg = extractMsg(raw);
        if (msg) {
            link.removeAttribute('onclick');
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const targetUrl = link.href;
                const isDanger = /hapus|batal|keluar|lepas|tolak/i.test(msg);
                let btnLabel = 'Ya, Lanjutkan';
                if (/hapus/i.test(msg)) btnLabel = 'Ya, Hapus';
                else if (/batal/i.test(msg)) btnLabel = 'Ya, Batalkan';
                else if (/lepas/i.test(msg)) btnLabel = 'Ya, Lepaskan';

                window.showModernConfirm({
                    title: 'Konfirmasi Aksi',
                    text: msg,
                    isDanger: isDanger,
                    confirmText: btnLabel,
                    onConfirm: function() {
                        window.location.href = targetUrl;
                    }
                });
            });
        }
    });

    // B. Intercept tag <button> bertipe submit / button dengan onclick="return confirm(...)"
    document.querySelectorAll('button[onclick*="confirm("], input[type="submit"][onclick*="confirm("]').forEach(function(btn) {
        const raw = btn.getAttribute('onclick');
        const msg = extractMsg(raw);
        if (msg) {
            btn.removeAttribute('onclick');
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const form = btn.closest('form');
                const isDanger = /hapus|batal|keluar|lepas|tolak/i.test(msg);
                let btnLabel = 'Ya, Lanjutkan';
                if (/hapus/i.test(msg)) btnLabel = 'Ya, Hapus';
                else if (/batal/i.test(msg)) btnLabel = 'Ya, Batalkan';
                else if (/setuju|terima|siap|daftar/i.test(msg)) btnLabel = 'Ya, Setuju';

                window.showModernConfirm({
                    title: 'Konfirmasi Aksi',
                    text: msg,
                    isDanger: isDanger,
                    confirmText: btnLabel,
                    onConfirm: function() {
                        if (form) {
                            if (btn.name) {
                                const hidden = document.createElement('input');
                                hidden.type = 'hidden';
                                hidden.name = btn.name;
                                hidden.value = btn.value;
                                form.appendChild(hidden);
                            }
                            form.submit();
                        }
                    }
                });
            });
        }
    });

    // C. Intercept tag <form> dengan onsubmit="return confirm(...)"
    document.querySelectorAll('form[onsubmit*="confirm("]').forEach(function(form) {
        const raw = form.getAttribute('onsubmit');
        const msg = extractMsg(raw);
        if (msg) {
            form.removeAttribute('onsubmit');
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const isDanger = /hapus|batal|keluar|lepas|tolak/i.test(msg);
                let btnLabel = 'Ya, Lanjutkan';
                if (/hapus/i.test(msg)) btnLabel = 'Ya, Hapus';
                else if (/batal/i.test(msg)) btnLabel = 'Ya, Batalkan';
                else if (/setuju|terima|siap/i.test(msg)) btnLabel = 'Ya, Lanjutkan';

                window.showModernConfirm({
                    title: 'Konfirmasi Aksi',
                    text: msg,
                    isDanger: isDanger,
                    confirmText: btnLabel,
                    onConfirm: function() {
                        form.submit();
                    }
                });
            });
        }
    });
}
