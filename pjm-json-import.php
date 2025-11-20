<?php
/**
 * pjM JSON importer — local files support
 *
 * - Supports ThumbnailPath in products.json: reads file from wp_get_upload_dir()['basedir'] . '/pjm-data/' . ThumbnailPath
 * - Creates media attachments and sets as featured image.
 * - Supports CLI (--token=...) and web ?token=...
 *
 * IMPORTANT: set $EXPECTED_TOKEN to a long secret before running.
 * Move this file outside public_html after testing and update REMOTE_IMPORT_PATH in the workflow.
 */

$EXPECTED_TOKEN = 'a8f3c2b9d6e14f3b9a1c2d3e4f5a6b7c'; // <-- set your token

// Accept token from CLI (--token=...) or from GET (web)
$token = '';
if (PHP_SAPI === 'cli') {
    foreach ($argv as $a) {
        if (strpos($a, '--token=') === 0) {
            $token = substr($a, strlen('--token='));
            break;
        }
    }
} else {
    $token = isset($_GET['token']) ? $_GET['token'] : '';
}

if ($token !== $EXPECTED_TOKEN) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        echo "Forbidden: invalid token\n";
    } else {
        fwrite(STDERR, "Forbidden: invalid token\n");
    }
    exit(1);
}

// Find wp-load.php and bootstrap WP
$wp_load = __DIR__ . '/../../wp-load.php';
if (!file_exists($wp_load)) {
    $wp_load_alt = __DIR__ . '/../wp-load.php';
    if (file_exists($wp_load_alt)) $wp_load = $wp_load_alt;
}
if (!file_exists($wp_load)) {
    $msg = "ERROR: cannot find wp-load.php. Adjust \$wp_load path in the script.\n";
    if (PHP_SAPI === 'cli') { fwrite(STDERR, $msg); } else { echo $msg; }
    exit(1);
}
require_once $wp_load;

if (!function_exists('wp_get_upload_dir')) {
    $msg = "ERROR: WordPress functions not available after requiring wp-load.php\n";
    if (PHP_SAPI === 'cli') { fwrite(STDERR, $msg); } else { echo $msg; }
    exit(1);
}

// locate JSON file
$upload_dir = wp_get_upload_dir();
$json_path = trailingslashit( $upload_dir['basedir'] ) . 'pjm-data/products.json';

if (!file_exists($json_path)) {
    $msg = "ERROR: products.json not found at: $json_path\n";
    if (PHP_SAPI === 'cli') { fwrite(STDERR, $msg); } else { echo $msg; }
    exit(1);
}

$contents = file_get_contents($json_path);
if ($contents === false) {
    $msg = "ERROR: failed to read products.json\n";
    if (PHP_SAPI === 'cli') { fwrite(STDERR, $msg); } else { echo $msg; }
    exit(1);
}
if (substr($contents, 0, 3) === "\xEF\xBB\xBF") { $contents = substr($contents, 3); } // strip BOM
$contents = ltrim($contents);

$data = json_decode($contents, true);
if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
    $msg = "ERROR: invalid JSON or decode error. json_last_error_msg(): " . json_last_error_msg() . "\n";
    if (PHP_SAPI === 'cli') { fwrite(STDERR, $msg); } else { echo $msg; }
    exit(1);
}

// helper: find existing WP post by mapping meta _pjm_product_id
function find_post_by_pjm_id($pjmId) {
    $args = [
        'post_type' => function_exists('wc_get_product') ? 'product' : 'post',
        'meta_key'  => '_pjm_product_id',
        'meta_value'=> (string)$pjmId,
        'posts_per_page' => 1,
        'fields' => 'ids'
    ];
    $q = new WP_Query($args);
    if ($q && !empty($q->posts)) return $q->posts[0];
    return false;
}

// helper: import media from a local file inside uploads/pjm-data/
function media_from_localfile($relativePath, $filenamePrefix = 'pjm_thumb_') {
    $upload_dir = wp_get_upload_dir();
    $full = trailingslashit($upload_dir['basedir']) . 'pjm-data/' . ltrim($relativePath, '/');
    if (!file_exists($full)) return 0;
    $contents = file_get_contents($full);
    if ($contents === false) return 0;
    $ext = '.' . pathinfo($full, PATHINFO_EXTENSION);
    $filename = $filenamePrefix . time() . $ext;
    $upload = wp_upload_bits($filename, null, $contents);
    if ($upload['error']) return 0;
    $filetype = wp_check_filetype( $upload['file'], null );
    $attachment = [
        'post_mime_type' => $filetype['type'],
        'post_title'     => sanitize_file_name( basename( $upload['file'] ) ),
        'post_content'   => '',
        'post_status'    => 'inherit'
    ];
    $attach_id = wp_insert_attachment( $attachment, $upload['file'] );
    require_once( ABSPATH . 'wp-admin/includes/image.php' );
    $attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
    wp_update_attachment_metadata( $attach_id, $attach_data );
    return $attach_id;
}

// existing base64 handler (kept) — in case you ever embed base64/data-uri
function media_from_base64($dataUri, $filenamePrefix = 'pjm_thumb_') {
    if (preg_match('#^data:(.+?);base64,(.+)$#', $dataUri, $m)) {
        $mime = $m[1];
        $b64 = $m[2];
    } elseif ($dataUri && !strpos($dataUri, 'base64,')) {
        $b64 = $dataUri;
        $mime = '';
    } else {
        return 0;
    }
    $binary = base64_decode($b64);
    if ($binary === false) return 0;

    $ext = '.bin';
    if ($mime === 'image/jpeg' || $mime === 'image/jpg') $ext = '.jpg';
    elseif ($mime === 'image/png') $ext = '.png';
    elseif ($mime === 'image/gif') $ext = '.gif';

    $time = time();
    $filename = $filenamePrefix . $time . $ext;
    $upload = wp_upload_bits($filename, null, $binary);
    if ($upload['error']) return 0;

    $filetype = wp_check_filetype( $upload['file'], null );
    $attachment = [
        'post_mime_type' => $filetype['type'],
        'post_title'     => sanitize_file_name( basename( $upload['file'] ) ),
        'post_content'   => '',
        'post_status'    => 'inherit'
    ];
    $attach_id = wp_insert_attachment( $attachment, $upload['file'] );
    require_once( ABSPATH . 'wp-admin/includes/image.php' );
    $attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
    wp_update_attachment_metadata( $attach_id, $attach_data );
    return $attach_id;
}

/**
 * Update price helper
 *
 * Tries to:
 *  - find product/variation by SKU and update that product's price
 *  - if not found and $post_id supplied, update all variations of the parent product
 *  - fallback to updating parent product price
 *
 * Uses WooCommerce CRUD if available to keep lookup tables in sync.
 */
function update_price_for_sku_or_variations( $sku, $price, $post_id = 0 ) {
    $price_clean = number_format( (float) $price, 2, '.', '' );

    if ( function_exists( 'wc_get_product' ) ) {
        // 1) Try SKU (works for both variations and parent products)
        if ( ! empty( $sku ) ) {
            $found_id = wc_get_product_id_by_sku( $sku );
            if ( $found_id ) {
                $product = wc_get_product( $found_id );
                if ( $product ) {
                    $product->set_regular_price( $price_clean );
                    // optional: set_price too
                    // $product->set_price( $price_clean );
                    $product->save();
                    update_post_meta( $found_id, '_regular_price', (string) $price_clean );
                    update_post_meta( $found_id, '_price', (string) $price_clean );
                    if ( function_exists( 'wc_delete_product_transients' ) ) {
                        wc_delete_product_transients( $found_id );
                    }
                    return true;
                }
            }
        }

        // 2) If parent post id provided: update all variations
        if ( $post_id ) {
            $args = [
                'post_type'      => 'product_variation',
                'posts_per_page' => -1,
                'post_status'    => array( 'publish', 'private' ),
                'post_parent'    => $post_id,
                'fields'         => 'ids',
            ];
            $variations = get_posts( $args );
            if ( ! empty( $variations ) ) {
                foreach ( $variations as $vid ) {
                    $vp = wc_get_product( $vid );
                    if ( $vp ) {
                        $vp->set_regular_price( $price_clean );
                        // $vp->set_price( $price_clean );
                        $vp->save();
                        update_post_meta( $vid, '_regular_price', (string) $price_clean );
                        update_post_meta( $vid, '_price', (string) $price_clean );
                        if ( function_exists( 'wc_delete_product_transients' ) ) {
                            wc_delete_product_transients( $vid );
                        }
                    }
                }
                return true;
            }
        }

        // 3) Fallback: update parent
        if ( $post_id ) {
            $parent = wc_get_product( $post_id );
            if ( $parent ) {
                $parent->set_regular_price( $price_clean );
                // $parent->set_price( $price_clean );
                $parent->save();
            } else {
                update_post_meta( $post_id, '_regular_price', (string) $price_clean );
                update_post_meta( $post_id, '_price', (string) $price_clean );
            }
            return true;
        }

        return false;
    } else {
        // No WooCommerce: fallback to postmeta on parent
        if ( $post_id ) {
            update_post_meta( $post_id, '_regular_price', (string) $price_clean );
            update_post_meta( $post_id, '_price', (string) $price_clean );
            return true;
        }
        return false;
    }
}

/**
 * Set SKU on a product (prefer WC CRUD). If the SKU maps to a different product (variation),
 * that product will be updated via update_price_for_sku_or_variations when price runs.
 */
function set_product_sku( $post_id, $sku ) {
    if ( empty( $sku ) || ! $post_id ) return false;
    if ( function_exists( 'wc_get_product' ) ) {
        $p = wc_get_product( $post_id );
        if ( $p ) {
            // Only set SKU if empty or differs (avoid accidental overwrite of variation SKU)
            $current = $p->get_sku();
            if ( $current !== $sku ) {
                try {
                    $p->set_sku( $sku );
                    $p->save();
                } catch ( Exception $e ) {
                    // fallback to postmeta if set_sku fails (unique constraint, etc.)
                    update_post_meta( $post_id, '_sku', $sku );
                    return true;
                }
            }
            return true;
        }
    }
    update_post_meta( $post_id, '_sku', $sku );
    return true;
}

// Loop items and create/update posts
$created = 0;
$updated = 0;
$errors = [];

foreach ($data as $item) {
    $pjmId = isset($item['ProductId']) ? $item['ProductId'] : (isset($item['id']) ? $item['id'] : null);
    if (!$pjmId) { $errors[] = "Skipping item with no ProductId"; continue; }

    $title = $item['Name'] ?? $item['name'] ?? 'No title';
    $content = $item['Description'] ?? $item['description'] ?? '';
    $price = isset($item['Price']) ? $item['Price'] : null;
    $isActive = isset($item['IsActive']) ? (bool)$item['IsActive'] : true;
    $sku = isset($item['SKU']) ? trim($item['SKU']) : '';

    $post_id = find_post_by_pjm_id($pjmId);

    if ($post_id) {
        $postarr = [
            'ID' => $post_id,
            'post_title' => wp_strip_all_tags($title),
            'post_content' => $content,
            'post_status' => $isActive ? 'publish' : 'draft',
        ];
        wp_update_post($postarr);

        // ensure WP meta _pjm_product_id exists
        update_post_meta($post_id, '_pjm_product_id', (string)$pjmId);

        // set SKU if present
        if (!empty($sku)) {
            set_product_sku( $post_id, $sku );
        }

        // set price using robust helper (will update variation if SKU matches or all variations)
        if ($price !== null) {
            update_price_for_sku_or_variations( $sku, $price, $post_id );
        } else {
            // no price in JSON - leave as is or set to empty if desired
        }

        $updated++;
        $new_post_id = $post_id;
    } else {
        $postarr = [
            'post_title' => wp_strip_all_tags($title),
            'post_content' => $content,
            'post_status' => $isActive ? 'publish' : 'draft',
            'post_type' => function_exists('wc_get_product') ? 'product' : 'post'
        ];
        $new_id = wp_insert_post($postarr);
        if (is_wp_error($new_id) || !$new_id) { $errors[] = "Failed to insert product $pjmId ($title)"; continue; }

        // ensure WP meta _pjm_product_id exists
        update_post_meta($new_id, '_pjm_product_id', (string)$pjmId);

        // set SKU if present
        if (!empty($sku)) {
            set_product_sku( $new_id, $sku );
        }

        // set price using robust helper
        if ($price !== null) {
            update_price_for_sku_or_variations( $sku, $price, $new_id );
        }

        $created++;
        $new_post_id = $new_id;
    }

    // thumbnail handling: prefer local file path if present
    $thumbSet = false;
    if (!empty($item['ThumbnailPath'])) {
        $aid = media_from_localfile($item['ThumbnailPath'], 'pjm_' . $pjmId . '_');
        if ($aid) { set_post_thumbnail($new_post_id, $aid); $thumbSet = true; }
    } elseif (!empty($item['ThumbnailDataUri'])) {
        $aid = media_from_base64($item['ThumbnailDataUri'], 'pjm_' . $pjmId . '_');
        if ($aid) { set_post_thumbnail($new_post_id, $aid); $thumbSet = true; }
    } elseif (!empty($item['ThumbnailBase64']) && !empty($item['ThumbnailContentType'])) {
        $dataUri = 'data:' . $item['ThumbnailContentType'] . ';base64,' . $item['ThumbnailBase64'];
        $aid = media_from_base64($dataUri, 'pjm_' . $pjmId . '_');
        if ($aid) { set_post_thumbnail($new_post_id, $aid); $thumbSet = true; }
    }

    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, "Processed ProductId={$pjmId} -> post_id={$new_post_id}" . ($thumbSet ? " (thumb)" : "") . "\n");
    } else {
        echo "Processed ProductId={$pjmId} -> post_id={$new_post_id}" . ($thumbSet ? " (thumb)" : "") . "<br />\n";
    }
}

// summary
$summary = "Summary: created={$created}, updated={$updated}, errors=" . count($errors) . "\n";
if (PHP_SAPI === 'cli') {
    fwrite(STDOUT, $summary);
    if (!empty($errors)) { foreach ($errors as $e) fwrite(STDOUT, "ERR: $e\n"); }
} else {
    echo nl2br(htmlspecialchars($summary));
    if (!empty($errors)) { echo "<pre>Errors:\n" . htmlspecialchars(implode("\n", $errors)) . "</pre>"; }
}

if (PHP_SAPI !== 'cli') echo "<br />Done.\n";
exit(0);
