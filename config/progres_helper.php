<?php
/**
 * config/progres_helper.php
 * Helper resmi untuk manajemen alur tahapan progres skripsi dan deteksi kemiripan judul.
 */

if (!function_exists('get_tahapan_skripsi')) {
    /**
     * Definisi master 7 tahapan skripsi bertingkat (Sequential Thesis Pipeline).
     */
    function get_tahapan_skripsi() {
        return [
            1 => [
                'level'     => 1,
                'kode'      => 'acc_judul',
                'label'     => 'Pengajuan & ACC Judul',
                'singkat'   => 'ACC Judul',
                'nama_bab'  => 'Pengajuan & ACC Judul Skripsi',
                'progres'   => 15,
                'deskripsi' => 'Pengesahan topik dan judul resmi skripsi oleh dosen pembimbing'
            ],
            2 => [
                'level'     => 2,
                'kode'      => 'acc_bab',
                'label'     => 'Bab 1: Pendahuluan',
                'singkat'   => 'Bab 1',
                'nama_bab'  => 'Bab 1: Pendahuluan',
                'progres'   => 30,
                'deskripsi' => 'Latar belakang masalah, rumusan masalah, tujuan, dan batasan penelitian'
            ],
            3 => [
                'level'     => 3,
                'kode'      => 'acc_bab',
                'label'     => 'Bab 2: Tinjauan Pustaka',
                'singkat'   => 'Bab 2',
                'nama_bab'  => 'Bab 2: Tinjauan Pustaka',
                'progres'   => 45,
                'deskripsi' => 'Landasan teori, penelitian terdahulu, dan kerangka pemikiran'
            ],
            4 => [
                'level'     => 4,
                'kode'      => 'acc_bab',
                'label'     => 'Bab 3: Metodologi Penelitian',
                'singkat'   => 'Bab 3',
                'nama_bab'  => 'Bab 3: Metodologi Penelitian',
                'progres'   => 60,
                'deskripsi' => 'Metode pengumpulan data, analisis kebutuhan, dan perancangan sistem'
            ],
            5 => [
                'level'     => 5,
                'kode'      => 'acc_bab',
                'label'     => 'Bab 4: Hasil & Pembahasan',
                'singkat'   => 'Bab 4',
                'nama_bab'  => 'Bab 4: Hasil & Pembahasan',
                'progres'   => 75,
                'deskripsi' => 'Implementasi sistem, pengujian fungsi, dan analisis hasil temuan'
            ],
            6 => [
                'level'     => 6,
                'kode'      => 'acc_bab',
                'label'     => 'Bab 5: Kesimpulan & Saran',
                'singkat'   => 'Bab 5',
                'nama_bab'  => 'Bab 5: Kesimpulan & Saran',
                'progres'   => 90,
                'deskripsi' => 'Rangkuman kesimpulan penelitian dan saran pengembangan ke depan'
            ],
            7 => [
                'level'     => 7,
                'kode'      => 'acc_sidang',
                'label'     => 'ACC Siap Sidang Skripsi',
                'singkat'   => 'Siap Sidang',
                'nama_bab'  => 'Naskah Lengkap / Siap Sidang',
                'progres'   => 100,
                'deskripsi' => 'Pengesahan draf naskah lengkap tuntas dan siap dijadwalkan sidang sarjana'
            ]
        ];
    }
}

if (!function_exists('get_progres_mahasiswa')) {
    /**
     * Menghitung status tahapan dan progres otomatis mahasiswa saat ini.
     * Mencegah lompat tahapan (misal lompat ke Bab 1 atau Sidang padahal belum punya judul).
     */
    function get_progres_mahasiswa($pdo, $mahasiswa_id) {
        $tahapan_master = get_tahapan_skripsi();

        $st_mhs = $pdo->prepare("SELECT * FROM mahasiswa WHERE id = :mid LIMIT 1");
        $st_mhs->execute([':mid' => $mahasiswa_id]);
        $mhs = $st_mhs->fetch();

        if (!$mhs) {
            return null;
        }

        $judul_bersih = trim($mhs['judul_skripsi'] ?? '');
        $has_judul_text = (!empty($judul_bersih) && !in_array(strtolower($judul_bersih), ['belum ditentukan', 'judul belum diatur', 'judul belum ditentukan', '-', 'null']));

        // Cek riwayat pengajuan usulan judul terbaru beserta dokumen pendukungnya
        $st_pj = $pdo->prepare("
            SELECT * FROM pengajuan_bimbingan 
            WHERE mahasiswa_id = :mid AND (
                bab_pembahasan LIKE '%Judul%' 
                OR bab_pembahasan = 'Pengajuan Judul Skripsi' 
                OR bab_pembahasan = 'Pengajuan & ACC Judul Skripsi'
            )
            ORDER BY id DESC LIMIT 1
        ");
        $st_pj->execute([':mid' => $mahasiswa_id]);
        $pengajuan_judul = $st_pj->fetch();

        // Ambil riwayat logbook ACC resmi
        $st_lb = $pdo->prepare("
            SELECT lb.status_bab, lb.catatan_hasil, p.bab_pembahasan, lb.tanggal_acc 
            FROM logbook_bimbingan lb
            LEFT JOIN pengajuan_bimbingan p ON lb.bimbingan_id = p.id
            WHERE lb.mahasiswa_id = :mid
            ORDER BY lb.id ASC
        ");
        $st_lb->execute([':mid' => $mahasiswa_id]);
        $logs = $st_lb->fetchAll();

        // Status penyelesaian per level (Sequential Thesis Pipeline)
        $selesai_level = [
            1 => false, // Judul
            2 => false, // Bab 1
            3 => false, // Bab 2
            4 => false, // Bab 3
            5 => false, // Bab 4
            6 => false, // Bab 5
            7 => false  // Sidang
        ];

        // Validasi pengesahan logbook resmi:
        foreach ($logs as $log) {
            $sbab   = $log['status_bab'] ?? '';
            $bpem   = $log['bab_pembahasan'] ?? '';
            $chasil = $log['catatan_hasil'] ?? '';

            // Tahap 1: HANYA SELESAI jika dosen telah menerbitkan pengesahan ACC Judul
            if ($sbab === 'acc_judul') {
                $selesai_level[1] = true;
            }

            // Kompatibilitas data legacy: jika ada logbook bab dan judul teks sudah ada
            if ($sbab === 'acc_bab' && $has_judul_text) {
                $selesai_level[1] = true;
            }

            // Bab 1 s.d. 5 HANYA BISA DIAKUI JIKA TAHAP 1 (JUDUL) SUDAH RESMI DI-ACC
            if ($selesai_level[1]) {
                if ($sbab === 'acc_bab') {
                    if (stripos($bpem, 'Bab 1') !== false || stripos($chasil, 'Bab 1') !== false) {
                        $selesai_level[2] = true;
                    }
                    if ($selesai_level[2] && (stripos($bpem, 'Bab 2') !== false || stripos($chasil, 'Bab 2') !== false)) {
                        $selesai_level[3] = true;
                    }
                    if ($selesai_level[3] && (stripos($bpem, 'Bab 3') !== false || stripos($chasil, 'Bab 3') !== false)) {
                        $selesai_level[4] = true;
                    }
                    if ($selesai_level[4] && (stripos($bpem, 'Bab 4') !== false || stripos($chasil, 'Bab 4') !== false)) {
                        $selesai_level[5] = true;
                    }
                    if ($selesai_level[5] && (stripos($bpem, 'Bab 5') !== false || stripos($chasil, 'Bab 5') !== false)) {
                        $selesai_level[6] = true;
                    }
                }

                if ($sbab === 'acc_sidang' && $selesai_level[6]) {
                    $selesai_level[7] = true;
                }
            }
        }

        // Khusus jika status kelulusan sudah siap_sidang atau lulus
        if (in_array($mhs['status_kelulusan'] ?? '', ['siap_sidang', 'lulus'])) {
            for ($i = 1; $i <= 7; $i++) {
                $selesai_level[$i] = true;
            }
        }

        // Tentukan Level Saat Ini (tahap pertama yang belum selesai)
        $current_level = 1;
        for ($i = 1; $i <= 7; $i++) {
            if (!$selesai_level[$i]) {
                $current_level = $i;
                break;
            }
            if ($i === 7 && $selesai_level[7]) {
                $current_level = 8; // Tuntas seluruh tahap
            }
        }

        // Hitung persentase progres saat ini
        if ($current_level === 1) {
            $progres_persen = 0; // Belum ACC judul (0%)
        } elseif ($current_level <= 7) {
            $progres_persen = $tahapan_master[$current_level - 1]['progres'];
        } else {
            $progres_persen = 100;
        }

        // Progres jika tahap saat ini di-ACC
        $progres_jika_acc = ($current_level <= 7) ? $tahapan_master[$current_level]['progres'] : 100;

        // Susun status tahapan untuk antarmuka UI
        $tahapan_ui = [];
        foreach ($tahapan_master as $lvl => $info) {
            if ($selesai_level[$lvl]) {
                $st = 'selesai';  // Hijau: Sudah tuntas di-ACC
            } elseif ($lvl === $current_level) {
                $st = 'aktif';    // Biru: Sedang dalam tahap evaluasi ini
            } else {
                $st = 'terkunci'; // Abu-abu: Belum dapat dibuka / diedit
            }
            $tahapan_ui[$lvl] = array_merge($info, ['status_ui' => $st]);
        }

        // Tentukan judul yang diajukan mahasiswa (usulan atau judul resmi)
        $judul_diajukan = $has_judul_text ? $judul_bersih : '';
        if (empty($judul_diajukan) && $pengajuan_judul) {
            $catatan_pj = $pengajuan_judul['catatan_mahasiswa'] ?? '';
            // Coba ekstrak judul dari catatan pengajuan mahasiswa
            if (preg_match('/Usulan Judul[^:]*:(.+)/i', $catatan_pj, $m)) {
                $judul_diajukan = trim($m[1]);
            }
        }

        $has_submitted_document = !empty($pengajuan_judul['file_draft']) || !empty($pengajuan_judul['link_drive']);

        return [
            'mahasiswa'              => $mhs,
            'has_judul'              => $has_judul_text,
            'is_judul_acc'           => $selesai_level[1],
            'judul_resmi'            => $selesai_level[1] ? $judul_bersih : '',
            'judul_diajukan'         => $judul_diajukan,
            'current_level'          => $current_level,
            'tahap_saat_ini'         => ($current_level <= 7) ? $tahapan_master[$current_level] : null,
            'progres_persen'         => $progres_persen,
            'progres_jika_acc'       => $progres_jika_acc,
            'tahapan_ui'             => $tahapan_ui,
            'selesai_level'          => $selesai_level,
            'pengajuan_judul'        => $pengajuan_judul ?: null,
            'has_submitted_document' => $has_submitted_document,
            'has_submitted_judul'    => (!empty($pengajuan_judul) || !empty($judul_diajukan)),
            'is_tuntas'              => ($current_level > 7)
        ];
    }
}

if (!function_exists('hitung_kemiripan_judul')) {
    /**
     * Menghitung nilai kemiripan judul skripsi (0 - 100%) berdasarkan analisis teks & kata kunci.
     */
    function hitung_kemiripan_judul($input_judul, $existing_judul) {
        $stop_words = [
            'dan', 'atau', 'pada', 'di', 'ke', 'dari', 'untuk', 'dengan', 'dalam', 
            'yang', 'oleh', 'berbasis', 'menggunakan', 'studi', 'kasus', 'terhadap',
            'analisis', 'analisa', 'implementasi', 'rancang', 'bangun', 'sistem',
            'aplikasi', 'pengembangan', 'metode', 'algoritma', 'tahun', 'sebagai',
            'berdasarkan', 'upaya', 'tinjauan', 'perancangan', 'pembuatan'
        ];

        $clean1 = strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', ' ', $input_judul)));
        $clean2 = strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', ' ', $existing_judul)));

        if (empty($clean1) || empty($clean2)) {
            return [
                'skor' => 0,
                'kata_kunci_sama' => [],
                'sim_text' => 0,
                'jaccard' => 0
            ];
        }

        // 1. Similar text (karakter & urutan kata)
        similar_text($clean1, $clean2, $sim_text);

        // 2. Tokenized Jaccard pada kata kunci substantif
        $words1 = array_diff(array_filter(explode(' ', $clean1)), $stop_words);
        $words2 = array_diff(array_filter(explode(' ', $clean2)), $stop_words);

        $intersect = array_intersect($words1, $words2);
        $union = array_unique(array_merge($words1, $words2));

        $jaccard = 0;
        if (count($union) > 0) {
            $jaccard = (count($intersect) / count($union)) * 100;
        }

        // Skor komposit (35% urutan teks + 65% kemiripan kata kunci objek/metode)
        $final_score = round((0.35 * $sim_text) + (0.65 * $jaccard), 1);
        if ($final_score > 100) $final_score = 100;

        return [
            'skor'            => $final_score,
            'kata_kunci_sama' => array_values(array_unique($intersect)),
            'sim_text'        => round($sim_text, 1),
            'jaccard'         => round($jaccard, 1)
        ];
    }
}

if (!function_exists('cari_duplikasi_judul')) {
    /**
     * Membandingkan satu judul input dengan seluruh judul yang terdaftar di repositori.
     */
    function cari_duplikasi_judul($pdo, $input_judul, $exclude_mahasiswa_id = null) {
        $input_bersih = trim($input_judul);
        if (empty($input_bersih)) {
            return [];
        }

        $sql = "
            SELECT m.id, m.nim, m.nama_lengkap, m.judul_skripsi, m.status_kelulusan,
                   d.nama_dosen AS pembimbing
            FROM mahasiswa m
            LEFT JOIN dosen d ON m.dosen_pembimbing_id = d.id
            WHERE m.judul_skripsi IS NOT NULL 
              AND m.judul_skripsi != '' 
              AND m.judul_skripsi != 'Belum Ditentukan'
        ";
        $params = [];
        if ($exclude_mahasiswa_id) {
            $sql .= " AND m.id != :ex_id";
            $params[':ex_id'] = $exclude_mahasiswa_id;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $all_titles = $stmt->fetchAll();

        $hasil = [];
        foreach ($all_titles as $item) {
            $analisis = hitung_kemiripan_judul($input_bersih, $item['judul_skripsi']);
            
            // Masukkan data hasil uji
            $hasil[] = [
                'mahasiswa_id'    => $item['id'],
                'nim'             => $item['nim'],
                'nama_lengkap'    => $item['nama_lengkap'],
                'judul_skripsi'   => $item['judul_skripsi'],
                'status_kelulusan'=> $item['status_kelulusan'],
                'pembimbing'      => $item['pembimbing'] ?? 'Belum Ditentukan',
                'skor'            => $analisis['skor'],
                'kata_kunci_sama' => $analisis['kata_kunci_sama']
            ];
        }

        // Urutkan dari skor kemiripan tertinggi ke terendah
        usort($hasil, function($a, $b) {
            if ($a['skor'] == $b['skor']) return 0;
            return ($a['skor'] > $b['skor']) ? -1 : 1;
        });

        return $hasil;
    }
}

