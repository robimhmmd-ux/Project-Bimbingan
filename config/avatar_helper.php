<?php
/**
 * config/avatar_helper.php
 * Helper universal untuk render foto profil / avatar pengguna (Mahasiswa, Dosen, Admin).
 * Kompatibel 100% dengan Base64 Data URI (Serverless / Vercel) dan file lokal legacy.
 */

if (!function_exists('get_avatar_url')) {
    function get_avatar_url($foto) {
        if (empty($foto) || $foto === 'default.png') {
            return null;
        }
        // Base64 Data URI atau External URL
        if (str_starts_with($foto, 'data:image/') || str_starts_with($foto, 'http://') || str_starts_with($foto, 'https://')) {
            return $foto;
        }
        // File lokal legacy
        $fname = basename($foto);
        if (file_exists(__DIR__ . '/../assets/uploads/profile/' . $fname)) {
            $prefix = file_exists('assets/uploads/profile/' . $fname) ? 'assets/uploads/profile/' : '../assets/uploads/profile/';
            return $prefix . htmlspecialchars($fname);
        }
        if (file_exists(__DIR__ . '/../assets/uploads/' . $fname)) {
            $prefix = file_exists('assets/uploads/' . $fname) ? 'assets/uploads/' : '../assets/uploads/';
            return $prefix . htmlspecialchars($fname);
        }
        return null;
    }
}

if (!function_exists('render_avatar')) {
    function render_avatar($foto, $nama, $size = 40, $classes = '', $role_color = 'primary') {
        $url = get_avatar_url($foto);
        $initial = strtoupper(substr(trim($nama ?: 'U'), 0, 1));
        $fontSize = max(0.75, round($size * 0.025, 2));

        if ($url) {
            return sprintf(
                '<img src="%s" class="rounded-circle border shadow-sm %s" width="%d" height="%d" style="object-fit:cover; width:%dpx; height:%dpx;" alt="%s">',
                htmlspecialchars($url),
                htmlspecialchars($classes),
                $size, $size, $size, $size,
                htmlspecialchars($nama)
            );
        }

        return sprintf(
            '<div class="rounded-circle d-inline-flex align-items-center justify-content-center fw-bold bg-%s text-white shadow-sm %s" style="width:%dpx; height:%dpx; font-size:%.2frem; flex-shrink:0;">%s</div>',
            htmlspecialchars($role_color),
            htmlspecialchars($classes),
            $size, $size,
            $fontSize,
            htmlspecialchars($initial)
        );
    }
}

