        <!-- Tutup tag main dan row -->
    </main>
    </div>
</div>

<!-- Modal Global Pratinjau Dokumen PDF -->
<div class="modal fade" id="modalPreviewPdfGlobal" tabindex="-1" aria-labelledby="modalPreviewPdfTitle" aria-hidden="true" style="z-index: 1080;">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable" style="max-width: 960px;">
        <div class="modal-content border-0 shadow-lg rounded-3 overflow-hidden">
            <div class="modal-header bg-dark text-white py-2 px-3">
                <div class="d-flex align-items-center gap-2 overflow-hidden me-auto">
                    <i class="bi bi-file-earmark-pdf-fill text-danger fs-5"></i>
                    <h6 class="modal-title fw-bold text-truncate mb-0" id="modalPreviewPdfTitle" style="max-width: 65vw;">Pratinjau Dokumen</h6>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <a href="#" id="btnModalPreviewNewTab" target="_blank" class="btn btn-sm btn-outline-light py-1 px-2.5 rounded-pill d-inline-flex align-items-center gap-1" title="Buka Dokumen di Tab Baru / Layar Penuh">
                        <i class="bi bi-box-arrow-up-right"></i><span class="d-none d-sm-inline">Layar Penuh</span>
                    </a>
                    <a href="#" id="btnModalPreviewDownload" class="btn btn-sm btn-danger py-1 px-2.5 rounded-pill d-inline-flex align-items-center gap-1" download title="Unduh Berkas PDF">
                        <i class="bi bi-download"></i><span class="d-none d-sm-inline">Unduh PDF</span>
                    </a>
                    <button type="button" class="btn-close btn-close-white ms-1" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
            </div>
            <div class="modal-body p-0 position-relative bg-light" style="min-height: 72vh;">
                <div id="pdfLoadingSpinner" class="position-absolute top-50 start-50 translate-middle text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Memuat PDF...</span>
                    </div>
                    <p class="small text-muted mt-2 mb-0">Memuat berkas PDF...</p>
                </div>
                <iframe id="iframePdfPreview" src="about:blank" style="width: 100%; height: 75vh; border: 0;" onload="var sp = document.getElementById('pdfLoadingSpinner'); if(sp) sp.style.display='none';"></iframe>
            </div>
        </div>
    </div>
</div>

<script>
window.bukaPreviewPdf = function(url, title) {
    const modalEl = document.getElementById('modalPreviewPdfGlobal');
    if (!modalEl) {
        window.open(url, '_blank');
        return;
    }
    
    const titleEl = document.getElementById('modalPreviewPdfTitle');
    if (titleEl) titleEl.textContent = title || 'Pratinjau Dokumen PDF';
    
    const newTabBtn = document.getElementById('btnModalPreviewNewTab');
    if (newTabBtn) newTabBtn.href = url;
    
    const dlBtn = document.getElementById('btnModalPreviewDownload');
    if (dlBtn) dlBtn.href = url + (url.includes('?') ? '&' : '?') + 'download=1';
    
    const spinner = document.getElementById('pdfLoadingSpinner');
    if (spinner) spinner.style.display = 'block';
    
    const iframe = document.getElementById('iframePdfPreview');
    if (iframe) {
        iframe.src = url;
    }
    
    const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
    bsModal.show();
};

document.addEventListener('DOMContentLoaded', function() {
    const modalEl = document.getElementById('modalPreviewPdfGlobal');
    if (modalEl) {
        modalEl.addEventListener('show.bs.modal', function() {
            setTimeout(function() {
                const backdrops = document.querySelectorAll('.modal-backdrop');
                if (backdrops.length > 1) {
                    backdrops[backdrops.length - 1].style.zIndex = '1075';
                }
            }, 10);
        });

        modalEl.addEventListener('hidden.bs.modal', function() {
            const iframe = document.getElementById('iframePdfPreview');
            if (iframe) iframe.src = 'about:blank';
            if (document.querySelectorAll('.modal.show').length > 0) {
                document.body.classList.add('modal-open');
            }
        });
    }
});
</script>

<footer class="footer mt-auto py-3 border-top text-center small footer-academic">
    <div class="container">
        <span>&copy; <?= date('Y'); ?> Tim Pengembang Web Dasar. Sistem Bimbingan Skripsi.</span>
    </div>
</footer>

<!-- Bootstrap Bundle with Popper JS via CDN -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/script.js?v=<?= time(); ?>"></script>
</body>
</html>
