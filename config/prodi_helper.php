<?php
// config/prodi_helper.php - Helper Sentral Manajemen Kategori Program Studi (Prodi)

/**
 * Mengembalikan daftar Program Studi resmi beserta metadata jenjang dan warna tema badge.
 *
 * @return array<string, array{jenjang: string, fakultas: string, badge_class: string}>
 */
function get_daftar_prodi(): array {
    return [
        'Teknik Informatika' => [
            'jenjang'     => 'S1',
            'fakultas'    => 'Fakultas Ilmu Komputer',
            'badge_class' => 'bg-primary-subtle text-primary border-primary-subtle'
        ],
        'Sistem Informasi' => [
            'jenjang'     => 'S1',
            'fakultas'    => 'Fakultas Ilmu Komputer',
            'badge_class' => 'bg-info-subtle text-info-emphasis border-info-subtle'
        ],
        'Rekayasa Perangkat Lunak' => [
            'jenjang'     => 'S1',
            'fakultas'    => 'Fakultas Ilmu Komputer',
            'badge_class' => 'bg-indigo-subtle text-primary border-indigo-subtle'
        ],
        'Manajemen Informatika' => [
            'jenjang'     => 'D3',
            'fakultas'    => 'Fakultas Ilmu Komputer',
            'badge_class' => 'bg-success-subtle text-success border-success-subtle'
        ],
        'Komputerisasi Akuntansi' => [
            'jenjang'     => 'D3',
            'fakultas'    => 'Fakultas Ekonomi & Komputer',
            'badge_class' => 'bg-warning-subtle text-warning-emphasis border-warning-subtle'
        ]
    ];
}

/**
 * Validasi dan normalisasi nama program studi ke daftar baku.
 */
function sanitize_prodi(?string $prodi): string {
    $prodi = trim($prodi ?? '');
    $daftar = get_daftar_prodi();
    if (isset($daftar[$prodi])) {
        return $prodi;
    }
    return 'Teknik Informatika';
}

/**
 * Render elemen HTML badge program studi yang rapi dan konsisten.
 */
function render_badge_prodi(?string $prodi, string $extra_class = ''): string {
    $nama_prodi = sanitize_prodi($prodi);
    $daftar = get_daftar_prodi();
    $meta = $daftar[$nama_prodi] ?? [
        'jenjang'     => 'S1',
        'badge_class' => 'bg-secondary-subtle text-secondary border-secondary-subtle'
    ];

    $badge_class = $meta['badge_class'];
    $jenjang = htmlspecialchars($meta['jenjang']);
    $nama_clean = htmlspecialchars($nama_prodi);

    return '<span class="badge ' . $badge_class . ' border ' . $extra_class . ' d-inline-flex align-items-center gap-1 font-monospace" style="font-weight: 500; font-size: 0.72rem;">'
        . '<span class="badge bg-white text-dark py-0 px-1 border" style="font-size: 0.65rem;">' . $jenjang . '</span>'
        . '<span>' . $nama_clean . '</span>'
        . '</span>';
}
