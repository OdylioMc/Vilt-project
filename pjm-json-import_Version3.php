<?php
/**
 * Plugin Name: PJM JSON Products (simple)
 * Description: Lees JSON productfiles uit wp-content/uploads/pjm-data/ en toon via shortcode [pjm_products]
 * Version: 0.2
 * Author: You
 */

defined('ABSPATH') or die();

function pjm_get_data_dir() {
    $uploads = wp_upload_dir();
    // map waar je de export naartoe uploadt: wp-content/uploads/pjm-data/
    return trailingslashit( $uploads['basedir'] ) . 'pjm-data/';
}

function pjm_scan_products() {
    $dir = pjm_get_data_dir();
    if (!is_dir($dir)) return array();

    $files = glob($dir . 'product_*.json');
    $items = array();
    foreach ($files as $f) {
        $json = file_get_contents($f);
        $obj = json_decode($json, true);
        if ($obj) $items[] = $obj;
    }
    usort($items, function($a,$b){ return $a['id'] <=> $b['id']; });
    return $items;
}

function pjm_products_shortcode($atts) {
    $atts = shortcode_atts( array(
        'limit' => 0
    ), $atts, 'pjm_products' );

    $products = pjm_scan_products();
    if ($atts['limit'] && is_numeric($atts['limit'])) {
        $products = array_slice($products, 0, intval($atts['limit']));
    }

    $uploads = wp_upload_dir();
    $baseurl = trailingslashit($uploads['baseurl']) . 'pjm-data/';

    ob_start();
    echo '<div class="pjm-products">';
    foreach ($products as $p) {
        $id = esc_html($p['id']);
        $title = esc_html($p['title'] ?? '');
        $desc = wp_kses_post($p['description'] ?? '');
        $price = esc_html($p['price'] ?? '');
        // image file is expected in images/thumb_ID.ext
        $imgUrl = $baseurl . 'images/thumb_' . $id . '.jpg';
        echo '<div class="pjm-product">';
        echo '<div class="pjm-thumb"><img src="' . esc_url($imgUrl) . '" alt="' . $title . '" style="max-width:150px;"/></div>';
        echo '<div class="pjm-meta"><h3>' . $title . '</h3>';
        echo '<div class="pjm-desc">' . $desc . '</div>';
        echo '<div class="pjm-price">' . $price . '</div></div>';
        echo '</div>';
    }
    echo '</div>';
    return ob_get_clean();
}
add_shortcode('pjm_products', 'pjm_products_shortcode');