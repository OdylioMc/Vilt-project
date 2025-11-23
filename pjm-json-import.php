<?php
/**
 * pjM JSON importer — simplified (no ParentId)
 *
 * - Does NOT expect ParentId/ParentProductId in JSON
 * - Matches by SKU first (updates product or variation)
 * - If no SKU found: finds parent by _pjm_product_id and:
 *     - if parent is variable and Afmeting given -> match variation by attribute and update it
 *     - else update/create the parent product
 * - Thumbnail handling unchanged
 *
 * Usage (CLI):
 *   PJM_TOKEN=yourtoken php pj m-json-import.php
 *
 * IMPORTANT: place outside public_html and protect token.
 */

$EXPECTED_TOKEN = getenv('PJM_TOKEN') ?: 'a8f3c2b9d6e14f3b9a1c2d3e4f5a6b7c'; // prefer env var

// Accept token from CLI (--token=...) or from GET (web) or env
$token = '';
if (PHP_SAPI === 'cli') {
    foreach ($argv as $a) {
        if (strpos($a, '--token=') === 0) {
            $token = substr($a, strlen('--token='));
            break;
        }
    }
    if (empty($token)) $token = getenv('PJM_TOKEN') ?: '';
} else {
    $token = isset($_GET['token']) ? $_GET['token'] : (getenv('PJM_TOKEN') ?: '');
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

// bootstrap WP
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

// helper: find post by _pjm_product_id
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

// media helpers (unchanged)
function media_from_localfile($relativePath, $filenamePrefix = 'pjm_thumb_', $post_parent = 0) {
    $upload_dir = wp_get_upload_dir();
    $full = trailingslashit($upload_dir['basedir']) . 'pjm-data/' . ltrim($relativePath, '/');
    if (!file_exists($full)) return 0;

    // Detect mime/type from file
    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $full);
            finfo_close($finfo);
        }
    }
    if (empty($mime) && function_exists('getimagesize')) {
        $info = @getimagesize($full);
        if ($info && !empty($info['mime'])) $mime = $info['mime'];
    }
    // Ensure extension matches mime or fallback to existing extension
    $ext = '.' . pathinfo($full, PATHINFO_EXTENSION);
    $ext = strtolower($ext);
    if (strpos($mime, 'jpeg') !== false) $ext = '.jpg';
    elseif ($mime === 'image/png') $ext = '.png';
    elseif ($mime === 'image/gif') $ext = '.gif';
    elseif ($mime === 'image/webp') $ext = '.webp';

    // Use the existing file path directly, but copy it into WP uploads area if it's not already there.
    // If the file already resides in WP uploads (it does in your case /wp-content/uploads/pjm-data/...), we can attach it.
    $wp_file = $full;

    // Build attachment post record data
    $filetype = wp_check_filetype( $wp_file, null );
    $attachment = [
        'post_mime_type' => $filetype['type'] ? $filetype['type'] : ($mime ?: 'image/png'),
        'post_title'     => sanitize_file_name( basename( $wp_file ) ),
        'post_content'   => '',
        'post_status'    => 'inherit'
    ];

    // Insert attachment; pass $post_parent to set the parent post if we have product post id
    $attach_id = wp_insert_attachment( $attachment, $wp_file, $post_parent );
    if ( is_wp_error($attach_id) || !$attach_id ) return 0;

    require_once( ABSPATH . 'wp-admin/includes/image.php' );
    $attach_data = wp_generate_attachment_metadata( $attach_id, $wp_file );
    wp_update_attachment_metadata( $attach_id, $attach_data );

    // Ensure guid uses upload URL (sometimes wp_insert_attachment sets guid properly)
    // set_post_thumbnail will create the _thumbnail_id meta for the product

    return $attach_id;
}
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

// price helper (same as before)
function update_price_for_sku_or_variations( $sku, $price, $post_id = 0 ) {
    $price_clean = number_format( (float) $price, 2, '.', '' );

    if ( function_exists( 'wc_get_product' ) ) {
        if ( ! empty( $sku ) ) {
            $found_id = wc_get_product_id_by_sku( $sku );
            if ( $found_id ) {
                $product = wc_get_product( $found_id );
                if ( $product ) {
                    $product->set_regular_price( $price_clean );
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

        if ( $post_id ) {
            $parent = wc_get_product( $post_id );
            if ( $parent ) {
                $parent->set_regular_price( $price_clean );
                $parent->save();
            } else {
                update_post_meta( $post_id, '_regular_price', (string) $price_clean );
                update_post_meta( $post_id, '_price', (string) $price_clean );
            }
            return true;
        }

        return false;
    } else {
        if ( $post_id ) {
            update_post_meta( $post_id, '_regular_price', (string) $price_clean );
            update_post_meta( $post_id, '_price', (string) $price_clean );
            return true;
        }
        return false;
    }
}

function set_product_sku( $post_id, $sku ) {
    if ( empty( $sku ) || ! $post_id ) return false;
    if ( function_exists( 'wc_get_product' ) ) {
        $p = wc_get_product( $post_id );
        if ( $p ) {
            $current = $p->get_sku();
            if ( $current !== $sku ) {
                try {
                    $p->set_sku( $sku );
                    $p->save();
                } catch ( Exception $e ) {
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

// find variation by attribute (pa_afmeting or fallback to custom attribute)
function find_variation_by_attribute( $parent_id, $attr_value ) {
    if ( empty( $parent_id ) || empty( $attr_value ) ) return false;
    $args = [
        'post_type'      => 'product_variation',
        'post_parent'    => $parent_id,
        'posts_per_page' => -1,
        'post_status'    => array( 'publish','private' ),
        'fields'         => 'ids'
    ];
    $vars = get_posts( $args );
    foreach ( $vars as $vid ) {
        $v = wc_get_product( $vid );
        if ( ! $v ) continue;
        $vAttr = $v->get_attribute( 'pa_afmeting' );
        if ( empty( $vAttr ) ) {
            $atts = $v->get_attributes();
            foreach ( $atts as $k => $a ) {
                if ( is_string( $k ) ) {
                    $val = $v->get_attribute( $k );
                    if ( ! empty( $val ) && strcasecmp( trim( $val ), trim( $attr_value ) ) === 0 ) {
                        return $vid;
                    }
                }
            }
        } else {
            if ( strcasecmp( trim($vAttr), trim($attr_value) ) === 0 ) {
                return $vid;
            }
        }
    }
    return false;
}

// create variation helper (keeps attribute key attribute_pa_afmeting)
function create_variation_with_attribute( $parent_id, $attr_value, $sku = '', $price = null ) {
    $variation = new WC_Product_Variation();
    $variation->set_parent_id( $parent_id );
    if ( $sku ) {
        try { $variation->set_sku( $sku ); } catch ( Exception $e ) { /* ignore */ }
    }
    $vid = $variation->save();
    if ( ! $vid ) return 0;
    update_post_meta( $vid, 'attribute_pa_afmeting', $attr_value );
    if ( $price !== null && $price !== '' ) {
        $price_clean = number_format( (float) $price, 2, '.', '' );
        $vobj = wc_get_product( $vid );
        if ( $vobj ) {
            $vobj->set_regular_price($price_clean);
            $vobj->set_price($price_clean);
            $vobj->save();
        } else {
            update_post_meta($vid, '_regular_price', (string)$price_clean);
            update_post_meta($vid, '_price', (string)$price_clean);
        }
    }
    return $vid;
}

// Loop items and create/update posts/variations
$created = 0;
$updated = 0;
$errors = [];

foreach ($data as $item) {
    $pjmId = isset($item['ProductId']) ? $item['ProductId'] : (isset($item['id']) ? $item['id'] : null);
    if (!$pjmId) { $errors[] = "Skipping item with no ProductId"; continue; }

    $title = $item['Name'] ?? $item['name'] ?? 'No title';
    $content = $item['Description'] ?? $item['description'] ?? '';
    $price = array_key_exists('Price',$item) ? $item['Price'] : (isset($item['price']) ? $item['price'] : null);
    $isActive = isset($item['IsActive']) ? (bool)$item['IsActive'] : true;
    $sku = isset($item['SKU']) ? trim($item['SKU']) : '';
    $afmeting = isset($item['Afmeting']) ? trim($item['Afmeting']) : (isset($item['afmeting']) ? trim($item['afmeting']) : '');

    // 1) Prefer SKU matching (works for parent or variation)
    $sku_target_id = '';
    if (!empty($sku) && function_exists('wc_get_product_id_by_sku')) {
        $sku_target_id = wc_get_product_id_by_sku($sku);
    }

    if ($sku_target_id) {
        // Update the SKU target product/variation
        if ($price !== null) update_price_for_sku_or_variations($sku, $price, 0);
        // Ensure _pjm_product_id is set on the parent mapping for this pjmid (if appropriate)
        $updated++;
        $new_post_id = $sku_target_id;
    } else {
        // 2) No SKU found — find parent by _pjm_product_id
        $post_id = find_post_by_pjm_id($pjmId);

        if ($post_id) {
            // If parent is variable and afmeting provided, try to find/update variation by attribute
            $parent_product = function_exists('wc_get_product') ? wc_get_product($post_id) : null;
            $is_variable = ($parent_product && $parent_product->get_type() === 'variable');

            if ($is_variable && !empty($afmeting)) {
                $var_id = find_variation_by_attribute($post_id, $afmeting);
                if ($var_id) {
                    if ($price !== null) {
                        $vobj = wc_get_product($var_id);
                        if ($vobj) {
                            $price_clean = number_format( (float) $price, 2, '.', '' );
                            $vobj->set_regular_price($price_clean);
                            $vobj->set_price($price_clean);
                            $vobj->save();
                            update_post_meta($var_id, '_regular_price', (string)$price_clean);
                            update_post_meta($var_id, '_price', (string)$price_clean);
                            if (function_exists('wc_delete_product_transients')) wc_delete_product_transients($var_id);
                        }
                    }
                    if (!empty($sku)) set_product_sku($var_id, $sku);
                    $updated++;
                    $new_post_id = $var_id;
                } else {
                    // variation not found: create it under parent
                    $created_var_id = create_variation_with_attribute($post_id, $afmeting, $sku, $price);
                    if ($created_var_id) {
                        $created++;
                        $new_post_id = $created_var_id;
                    } else {
                        $errors[] = "Failed to create variation for parent {$pjmId} afmeting={$afmeting}";
                        continue;
                    }
                }
            } else {
                // Update parent product (title/content/status) and price
                $postarr = [
                    'ID' => $post_id,
                    'post_title' => wp_strip_all_tags($title),
                    'post_content' => $content,
                    'post_status' => $isActive ? 'publish' : 'draft',
                ];
                wp_update_post($postarr);
                update_post_meta($post_id, '_pjm_product_id', (string)$pjmId);
                if (!empty($sku)) set_product_sku($post_id, $sku);
                if ($price !== null) update_price_for_sku_or_variations($sku, $price, $post_id);
                $updated++;
                $new_post_id = $post_id;
            }
        } else {
            // Create parent product (no parent mapping found)
            $postarr = [
                'post_title' => wp_strip_all_tags($title),
                'post_content' => $content,
                'post_status' => $isActive ? 'publish' : 'draft',
                'post_type' => function_exists('wc_get_product') ? 'product' : 'post'
            ];
            $new_id = wp_insert_post($postarr);
            if (is_wp_error($new_id) || !$new_id) { $errors[] = "Failed to insert product $pjmId ($title)"; continue; }
            update_post_meta($new_id, '_pjm_product_id', (string)$pjmId);
            if (!empty($sku)) set_product_sku($new_id, $sku);
            if ($price !== null) update_price_for_sku_or_variations($sku, $price, $new_id);
            $created++;
            $new_post_id = $new_id;
        }
    }

    // thumbnails (verbeterd)
    $thumbSet = false;
    if (!empty($item['ThumbnailPath'])) {
        // geef $new_post_id door zodat media_from_localfile het attachment als child kan aanmaken
        $aid = media_from_localfile($item['ThumbnailPath'], 'pjm_' . $pjmId . '_', $new_post_id);
        if ($aid && is_int($aid) && $aid > 0) {
            set_post_thumbnail($new_post_id, $aid);
            $thumbSet = true;
        } else {
            error_log("pjm-import: failed to import thumbnail for ProductId={$pjmId}, path={$item['ThumbnailPath']}");
        }
    } elseif (!empty($item['ThumbnailDataUri'])) {
        $aid = media_from_base64($item['ThumbnailDataUri'], 'pjm_' . $pjmId . '_');
        if ($aid && is_int($aid) && $aid > 0) {
            set_post_thumbnail($new_post_id, $aid);
            $thumbSet = true;
        } else {
            error_log("pjm-import: failed to import thumbnail (datauri) for ProductId={$pjmId}");
        }
    } elseif (!empty($item['ThumbnailBase64']) && !empty($item['ThumbnailContentType'])) {
        $dataUri = 'data:' . $item['ThumbnailContentType'] . ';base64,' . $item['ThumbnailBase64'];
        $aid = media_from_base64($dataUri, 'pjm_' . $pjmId . '_');
        if ($aid && is_int($aid) && $aid > 0) {
            set_post_thumbnail($new_post_id, $aid);
            $thumbSet = true;
        } else {
            error_log("pjm-import: failed to import thumbnail (base64) for ProductId={$pjmId}");
        }
}

// regenerate lookups
if (function_exists('wc_update_product_lookup_tables')) {
    wc_update_product_lookup_tables();
    if (PHP_SAPI === 'cli') fwrite(STDOUT, "Regenerated product lookup tables.\n");
    else echo "Regenerated product lookup tables.<br />\n";
}

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
