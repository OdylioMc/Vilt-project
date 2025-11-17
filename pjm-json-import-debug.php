<?php
/**
 * Debug-importer: geeft per product rapport over thumbnail import en koppeling.
 * Tijdelijk: upload/plaats in wp-content/uploads en run met ?token=... of via CLI --token=...
 *
 * Zet $EXPECTED_TOKEN op jouw token.
 */

$EXPECTED_TOKEN = 'a8f3c2b9d6e14f3b9a1c2d3e4f5a6b7c'; // <-- zet jouw token

// token uit CLI of GET
$token = '';
if (PHP_SAPI === 'cli') {
    foreach ($argv as $a) {
        if (strpos($a, '--token=') === 0) { $token = substr($a, 8); break; }
    }
} else {
    $token = $_GET['token'] ?? '';
}
if ($token !== $EXPECTED_TOKEN) {
    if (PHP_SAPI !== 'cli') { http_response_code(403); echo "Forbidden: invalid token\n"; }
    else { fwrite(STDERR, "Forbidden: invalid token\n"); }
    exit(1);
}

// bootstrap WP
$wp_load = __DIR__ . '/../../wp-load.php';
if (!file_exists($wp_load)) {
    $wp_load_alt = __DIR__ . '/../wp-load.php';
    if (file_exists($wp_load_alt)) $wp_load = $wp_load_alt;
}
if (!file_exists($wp_load)) { echo "ERROR: cannot find wp-load.php\n"; exit(1); }
require_once $wp_load;

$upload_dir = wp_get_upload_dir();
$json_path = trailingslashit($upload_dir['basedir']) . 'pjm-data/products.json';
echo "DEBUG: json_path={$json_path}\n";
if (!file_exists($json_path)) { echo "ERROR: products.json not found\n"; exit(1); }
$contents = file_get_contents($json_path);
if ($contents === false) { echo "ERROR: cannot read products.json\n"; exit(1); }
if (substr($contents,0,3) === "\xEF\xBB\xBF") $contents = substr($contents,3);
$contents = ltrim($contents);
$data = json_decode($contents, true);
if ($data === null) { echo "ERROR: json decode failed: " . json_last_error_msg() . "\n"; exit(1); }

function media_from_localfile_debug($relativePath, $prefix='dbg_') {
    $upload_dir = wp_get_upload_dir();
    $full = trailingslashit($upload_dir['basedir']) . 'pjm-data/' . ltrim($relativePath, '/');
    $out = [
        'exists' => file_exists($full),
        'fullpath' => $full,
        'wp_upload_bits' => null,
        'attach_id' => 0,
        'attach_guid' => '',
        'error' => ''
    ];
    if (!$out['exists']) return $out;
    $bin = file_get_contents($full);
    if ($bin === false) { $out['error'] = 'file read failed'; return $out; }
    $ext = '.' . pathinfo($full, PATHINFO_EXTENSION);
    $filename = $prefix . time() . $ext;
    $upload = wp_upload_bits($filename, null, $bin);
    $out['wp_upload_bits'] = $upload;
    if (!empty($upload['error'])) { $out['error'] = 'wp_upload_bits error: ' . $upload['error']; return $out; }
    $filetype = wp_check_filetype( $upload['file'], null );
    $attachment = [
        'post_mime_type' => $filetype['type'] ?? '',
        'post_title'     => sanitize_file_name( basename( $upload['file'] ) ),
        'post_content'   => '',
        'post_status'    => 'inherit'
    ];
    $aid = wp_insert_attachment($attachment, $upload['file']);
    if (is_wp_error($aid) || !$aid) { $out['error'] = 'wp_insert_attachment failed'; return $out; }
    require_once( ABSPATH . 'wp-admin/includes/image.php' );
    $meta = wp_generate_attachment_metadata($aid, $upload['file']);
    wp_update_attachment_metadata($aid, $meta);
    $out['attach_id'] = $aid;
    $out['attach_guid'] = get_permalink($aid);
    return $out;
}

// Loop en debug
foreach ($data as $item) {
    $pjmId = $item['ProductId'] ?? $item['id'] ?? '(no id)';
    echo "=== ProductId={$pjmId} ===\n";
    $thumbPath = $item['ThumbnailPath'] ?? null;
    if (!$thumbPath) {
        echo " no ThumbnailPath in JSON\n";
        continue;
    }
    echo " ThumbnailPath='{$thumbPath}'\n";
    $res = media_from_localfile_debug($thumbPath, 'pjm_dbg_');
    echo " file exists: " . ($res['exists'] ? 'yes' : 'no') . "\n";
    echo " fullpath: " . ($res['fullpath'] ?? '') . "\n";
    if ($res['wp_upload_bits']) {
        echo " wp_upload_bits: file=" . ($res['wp_upload_bits']['file'] ?? '') . " url=" . ($res['wp_upload_bits']['url'] ?? '') . "\n";
    }
    if ($res['attach_id']) {
        echo " attach_id: " . $res['attach_id'] . " guid: " . $res['attach_guid'] . "\n";
        // find the WP product post id
        $q = new WP_Query([
            'post_type' => function_exists('wc_get_product') ? 'product' : 'post',
            'meta_key'  => '_pjm_product_id',
            'meta_value'=> (string)$pjmId,
            'posts_per_page' => 1,
            'fields' => 'ids'
        ]);
        $post_id = ($q && !empty($q->posts)) ? $q->posts[0] : 0;
        echo " mapped post_id: " . ($post_id ?: '(not found)') . "\n";
        if ($post_id) {
            $ok = set_post_thumbnail($post_id, $res['attach_id']);
            echo " set_post_thumbnail returned: " . ($ok ? 'true' : 'false') . "\n";
            $thumb_meta = get_post_meta($post_id, '_thumbnail_id', true);
            echo " _thumbnail_id meta now: " . ($thumb_meta ?: '(empty)') . "\n";
        }
    } else {
        echo " error: " . ($res['error'] ?: '(none)') . "\n";
    }
    echo "\n";
}

echo "DEBUG RUN FINISHED\n";
exit(0);
