<?php
/**
 * Plugin Name: AI Chatbot for WooCommerce
 * Plugin URI: https://chatbotagent.biz
 * Description: AI-powered product search for WooCommerce - v8.0.8
 * Version: 8.0.8
 * Requires Plugins: woocommerce
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: chatbotagent.biz
 * Author URI: https://chatbotagent.biz
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-chatbot-for-woocommerce
 */

if (!defined('ABSPATH')) exit;

define('WCAI_VERSION', '8.0.8');
define('WCAI_SAAS_URL', 'https://chatbotagent.biz/wp-json/wc-ai/v1');
define('WCAI_BATCH_SIZE', 20);
define('WCAI_PATH', plugin_dir_path(__FILE__));
define('WCAI_URL',  plugin_dir_url(__FILE__));
define('WCAI_UPDATE_SLUG', 'ai-chatbot-woocommerce');
define('WCAI_UPDATE_MANIFEST', 'https://chatbotagent.biz/wp-content/uploads/client-updates/ai-chatbot-woocommerce.json');
define('WCAI_UPDATE_ZIP', 'https://chatbotagent.biz/wp-content/uploads/client-updates/ai-chatbot-woocommerce.zip');

function wcai_has_user_consent() {
    if (!get_option('wcai_consent_enabled', 1)) return true;
    if (function_exists('wp_has_consent') && !wp_has_consent('marketing')) return false;
    if (defined('COOKIEBOT_ACTIVE') && function_exists('have_consent') && !have_consent('marketing')) return false;
    if (!empty($_COOKIE['borlabs-cookie'])) {
        $consent = json_decode(wp_unslash($_COOKIE['borlabs-cookie']), true);
        if (is_array($consent) && empty($consent['statistics'])) return false;
    }
    return true;
}

function wcai_anonymize_email($email) {
    if (empty($email)) return '';
    $parts = explode('@', $email);
    if (count($parts) !== 2) return '';
    $name = $parts[0];
    $domain = $parts[1];
    if (strlen($name) <= 3) $name = substr($name, 0, 1) . '***';
    else $name = substr($name, 0, 2) . '***' . substr($name, -1);
    return $name . '@' . $domain;
}

function wcai_hash_for_analytics($value) {
    if (empty($value)) return '';
    return hash('sha256', $value . wp_salt('secure_auth'));
}

function wcai_normalize_domain($domain) {
    $domain = strtolower(trim($domain));
    $domain = preg_replace('/:\d+$/', '', $domain);
    $domain = preg_replace('/^www\./', '', $domain);
    $domain = preg_replace('#^https?://#', '', $domain);
    return rtrim($domain, '/');
}

function wcai_get_site_hash() {
    $url  = strtolower(home_url());
    $salt = defined('AUTH_SALT') ? AUTH_SALT : 'default_salt';
    return hash('sha256', $url . $salt);
}

function wcai_get_active_currency() {
    if (class_exists('WOOCS')) {
        $woocs = $GLOBALS['WOOCS'];
        if (!empty($woocs->current_currency)) return $woocs->current_currency;
    }
    if (!empty($_COOKIE['woocs_current_currency'])) {
        return sanitize_text_field(wp_unslash($_COOKIE['woocs_current_currency']));
    }
    if (function_exists('wmc_get_current_currency')) {
        $c = wmc_get_current_currency();
        if ($c) return $c;
    }
    if (!empty($_COOKIE['wmc_currency'])) {
        return sanitize_text_field(wp_unslash($_COOKIE['wmc_currency']));
    }
    return get_woocommerce_currency();
}

function wcai_get_currency_rate($currency) {
    if (class_exists('WOOCS')) {
        $woocs = $GLOBALS['WOOCS'];
        if (!empty($woocs->current_currency['rate'])) {
            return (float)$woocs->current_currency['rate'];
        }
    }
    if (function_exists('wmc_get_currency_rate')) {
        $rate = wmc_get_currency_rate($currency);
        if ($rate) return (float)$rate;
    }
    return 1.0;
}

function wcai_get_currency_symbol($currency) {
    $symbols = [
        'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'CAD' => 'CA$',
        'AUD' => 'A$', 'JPY' => '¥', 'CNY' => '¥', 'RUB' => '₽',
        'INR' => '₹', 'BRL' => 'R$', 'MXN' => 'MX$', 'CHF' => 'CHF',
        'PLN' => 'zł', 'SEK' => 'kr', 'NOK' => 'kr', 'DKK' => 'kr',
        'ILS' => '₪', 'TRY' => '₺', 'NZD' => 'NZ$', 'ZAR' => 'R',
        'SGD' => 'S$', 'HKD' => 'HK$', 'KRW' => '₩', 'UAH' => '₴',
        'CZK' => 'Kč', 'THB' => '฿', 'MYR' => 'RM', 'PHP' => '₱',
        'IDR' => 'Rp', 'VND' => '₫', 'AED' => 'د.إ', 'SAR' => '﷼',
    ];
    return $symbols[$currency] ?? $currency;
}

function wcai_convert_price($price, $from_currency = 'USD', $to_currency = null) {
    if (!$to_currency) $to_currency = wcai_get_active_currency();
    if ($from_currency === $to_currency) return $price;
    $rate = wcai_get_currency_rate($to_currency);
    return $price * $rate;
}

function wcai_get_real_ip() {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']));
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return sanitize_text_field(wp_unslash($_SERVER['HTTP_X_REAL_IP']));
    }
    return sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
}

function wcai_tokenize($text) {
    $text  = strtolower(trim($text));
    $text  = preg_replace('/[^a-z0-9\s]/', ' ', $text);
    $words = preg_split('/\s+/', $text);
    return array_values(array_filter($words, function($w) { return strlen($w) > 1; }));
}

function wcai_ngrams($text, $n = 3) {
    $text = strtolower(preg_replace('/[^a-z0-9]/', '', $text));
    $len  = strlen($text);
    if ($len < $n) return [];
    $out  = [];
    for ($i = 0; $i <= $len - $n; $i++) $out[] = substr($text, $i, $n);
    return array_unique($out);
}

function wcai_get_brand($product_id) {
    if (!class_exists('WooCommerce')) return '';
    $product = wc_get_product($product_id);
    if (!$product || is_wp_error($product)) return '';
    if (!method_exists($product, 'get_attributes')) return '';
    $attributes = $product->get_attributes();
    if (empty($attributes) || !is_array($attributes)) return '';
    foreach ($attributes as $key => $attr) {
        if (!is_object($attr)) continue;
        if (!method_exists($attr, 'is_taxonomy')) continue;
        if (strpos($key, 'brand') !== false) {
            if ($attr->is_taxonomy()) {
                $terms = wp_get_post_terms($product_id, $key, ['fields' => 'names']);
                if (is_wp_error($terms) || empty($terms) || !is_array($terms)) return '';
                return $terms[0];
            } else {
                if (method_exists($attr, 'get_options')) {
                    $opts = $attr->get_options();
                    if (!empty($opts) && is_array($opts)) return $opts[0];
                }
                return '';
            }
        }
    }
    $terms = wp_get_post_terms($product_id, 'pa_brand', ['fields' => 'names']);
    if (is_wp_error($terms) || empty($terms) || !is_array($terms)) return '';
    return $terms[0];
}

register_activation_hook(__FILE__, 'wcai_install');
function wcai_install() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $p       = $wpdb->prefix;
    $pt = $p . 'wcai_products';
    $tt = $p . 'wcai_terms';

    $wpdb->query($wpdb->prepare("CREATE TABLE IF NOT EXISTS %i (
        product_id  BIGINT PRIMARY KEY,
        title       VARCHAR(255),
        sku         VARCHAR(100),
        brand       VARCHAR(100),
        category    VARCHAR(100),
        description TEXT,
        material    VARCHAR(100),
        colors      INT DEFAULT 0,
        stitches    INT DEFAULT 0,
        difficulty  VARCHAR(50),
        available_sizes TEXT,
        search_sizes TEXT,
        size_stock_data TEXT,
        price       DECIMAL(10,2),
        price_max   DECIMAL(10,2),
        price_display VARCHAR(50),
        sale_price  DECIMAL(10,2),
        regular_price DECIMAL(10,2),
        stock_status VARCHAR(20),
        sales_count INT DEFAULT 0,
        rating      FLOAT DEFAULT 0,
        rating_count INT DEFAULT 0,
        is_featured  TINYINT(1) DEFAULT 0,
        is_bestseller TINYINT(1) DEFAULT 0,
        is_on_sale   TINYINT(1) DEFAULT 0,
        image_url   TEXT,
        permalink   TEXT,
        created_at  DATETIME,
        updated_at  DATETIME,
        INDEX idx_brand    (brand),
        INDEX idx_category (category),
        INDEX idx_price    (price),
        INDEX idx_price_max (price_max),
        INDEX idx_sales    (sales_count),
        INDEX idx_rating   (rating),
        INDEX idx_stock    (stock_status),
        INDEX idx_featured (is_featured),
        INDEX idx_sale     (is_on_sale)
    ) $charset", $pt));

    $cols = $wpdb->get_col($wpdb->prepare("SHOW COLUMNS FROM %i", $pt));
    if (!in_array('regular_price', $cols)) {
        $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD COLUMN regular_price DECIMAL(10,2) AFTER price", $pt));
    }
    if (!in_array('price_max', $cols)) {
        $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD COLUMN price_max DECIMAL(10,2) AFTER price", $pt));
    }

    $wpdb->query($wpdb->prepare("CREATE TABLE IF NOT EXISTS %i (
        term        VARCHAR(100) NOT NULL,
        product_id  BIGINT NOT NULL,
        weight      INT DEFAULT 1,
        term_type   VARCHAR(20) DEFAULT 'word',
        PRIMARY KEY (term, product_id),
        INDEX idx_product (product_id),
        INDEX idx_weight  (weight)
    ) $charset", $tt));

    $wpdb->query($wpdb->prepare("CREATE TABLE IF NOT EXISTS %i (
        id               BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_query       TEXT,
        bot_reply        TEXT,
        products_data    LONGTEXT,
        credits_used     INT DEFAULT 0,
        credits_remaining INT DEFAULT 0,
        products_count   INT DEFAULT 0,
        search_time      DECIMAL(5,3),
        user_email_hash  VARCHAR(64) DEFAULT '',
        user_ip_hash     VARCHAR(64) DEFAULT '',
        lead_converted   TINYINT(1) DEFAULT 0,
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created (created_at),
        INDEX idx_email_hash (user_email_hash),
        INDEX idx_ip_hash (user_ip_hash),
        INDEX idx_lead (lead_converted)
    ) $charset", $p . 'wcai_logs'));

    delete_option('wcai_index_offset');
    delete_option('wcai_index_total');
    delete_option('wcai_index_status');
}

register_deactivation_hook(__FILE__, 'wcai_deactivate');
function wcai_deactivate() {
    wp_clear_scheduled_hook('wcai_cron_index_batch');
    wp_clear_scheduled_hook('wcai_cron_delete_old_logs');
    delete_option('wcai_index_offset');
    delete_option('wcai_index_status');
}

add_action('wcai_cron_delete_old_logs', 'wcai_delete_old_logs');
function wcai_delete_old_logs() {
    global $wpdb;
    $table = $wpdb->prefix . 'wcai_logs';
    $days = (int)get_option('wcai_log_retention_days', 90);
    if ($days > 0) {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM %i WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $table, $days
        ));
    }
}

if (!wp_next_scheduled('wcai_cron_delete_old_logs')) {
    wp_schedule_event(time(), 'daily', 'wcai_cron_delete_old_logs');
}

function wcai_activate_license($license_key) {
    $domain    = wcai_normalize_domain(sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'] ?? '')));
    $site_hash = wcai_get_site_hash();

    $response = wp_remote_post(WCAI_SAAS_URL . '/activate', [
        'body'    => json_encode(['license_key' => $license_key, 'domain' => $domain, 'site_hash' => $site_hash]),
        'headers' => ['Content-Type' => 'application/json'],
        'timeout' => 15,
        'sslverify' => true,
    ]);

    if (is_wp_error($response)) return ['valid' => false, 'message' => $response->get_error_message()];

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!empty($data['valid'])) {
        update_option('wcai_license_key',  $license_key);
        update_option('wcai_license_data', $data);
        update_option('wcai_domain',       $domain);
        update_option('wcai_site_hash',    $site_hash);
        set_transient('wcai_license_cache', $data, 6 * HOUR_IN_SECONDS);
    }
    return $data;
}

function wcai_get_license_status() {
    $cached = get_transient('wcai_license_cache');
    if ($cached) return $cached;

    $license_key = get_option('wcai_license_key', '');
    if (empty($license_key)) return ['valid' => false, 'message' => 'No license activated'];

    global $wpdb;
    if (!$wpdb->check_connection()) {
        error_log("WCAI: MySQL connection lost, reconnecting");
        $wpdb->db_connect();
    }

    $response = wp_remote_post(WCAI_SAAS_URL . '/license', [
        'body'    => json_encode(['license_key' => $license_key, 'site_hash' => wcai_get_site_hash()]),
        'headers' => ['Content-Type' => 'application/json'],
        'timeout' => 15,
        'sslverify' => true,
    ]);

    if (!is_wp_error($response)) {
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($data['valid'])) {
            set_transient('wcai_license_cache', $data, 6 * HOUR_IN_SECONDS);
            update_option('wcai_license_data', $data);
            return $data;
        }
    } else {
        error_log("WCAI License check error: " . $response->get_error_message());
    }
    return ['valid' => false, 'message' => 'License check failed'];
}

function wcai_is_license_active() {
    $s = wcai_get_license_status();
    return !empty($s['valid']) && !empty($s['credits']) && $s['credits'] > 0 && strtotime($s['expires_at']) > time();
}

function wcai_index_product($product_id) {
    global $wpdb;
    if (!class_exists('WC_Product')) return;

    $product = wc_get_product($product_id);
    if (!$product) return;

    $pt = $wpdb->prefix . 'wcai_products';
    $tt = $wpdb->prefix . 'wcai_terms';

    $cats     = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'names']);
    $tags     = wp_get_post_terms($product_id, 'product_tag', ['fields' => 'names']);
    $brand    = wcai_get_brand($product_id);
    $img_id   = $product->get_image_id();
    $sales    = (int)(get_post_meta($product_id, 'total_sales', true) ?: 0);
    $created  = $product->get_date_created() ? $product->get_date_created()->date('Y-m-d H:i:s') : current_time('mysql');

    if ($product->is_type('variable')) {
        $min_price = (float)$product->get_variation_price('min');
        $max_price = (float)$product->get_variation_price('max');
        if ($min_price == $max_price) {
            $price = $min_price; $price_max = $max_price;
            $price_display = number_format($min_price, 2);
        } else {
            $price = $min_price; $price_max = $max_price;
            $price_display = number_format($min_price, 2) . ' – ' . number_format($max_price, 2);
        }
    } else {
        $price = (float)$product->get_price();
        $price_max = $price;
        $price_display = number_format($price, 2);
    }

    $available_sizes = [];
    if (method_exists($product, 'get_attributes')) {
        $attributes = $product->get_attributes();
        foreach ($attributes as $attr_name => $attr) {
            if (strpos($attr_name, 'size') !== false ||
                strpos($attr_name, 'размер') !== false ||
                strpos($attr_name, 'pa_size') !== false) {
                if (method_exists($attr, 'get_options')) {
                    $options = $attr->get_options();
                    if (!empty($options) && is_array($options)) {
                        $available_sizes = array_values($options);
                        break;
                    }
                }
            }
        }
    }

    if (empty($available_sizes) && $product->is_type('variable')) {
        $variations = $product->get_children();
        $sizes = [];
        foreach ($variations as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) continue;
            $attributes = $variation->get_attributes();
            foreach ($attributes as $attr_name => $value) {
                if ((strpos($attr_name, 'size') !== false || strpos($attr_name, 'размер') !== false) && !empty($value)) {
                    if (!in_array($value, $sizes)) $sizes[] = $value;
                }
            }
        }
        $available_sizes = $sizes;
    }

    $material = get_post_meta($product_id, '_material', true);
    if (empty($material)) $material = get_post_meta($product_id, 'pa_material', true);
    if (empty($material) && !empty($brand)) $material = $brand;

    $colors = (int)get_post_meta($product_id, '_colors', true);
    $stitches = (int)get_post_meta($product_id, '_stitches', true);
    $difficulty = get_post_meta($product_id, '_difficulty', true);
    if (empty($difficulty)) $difficulty = get_post_meta($product_id, 'pa_difficulty', true);

    $search_sizes = array_values(array_unique(array_map('strtolower', $available_sizes)));

    $replace_result = $wpdb->replace($pt, [
        'product_id'   => $product_id,
        'title'        => $product->get_name(),
        'sku'          => $product->get_sku(),
        'brand'        => $brand,
        'category'     => !empty($cats) ? $cats[0] : '',
        'description'  => wp_strip_all_tags($product->get_description() . ' ' . $product->get_short_description()),
        'material'     => $material,
        'colors'       => $colors,
        'stitches'     => $stitches,
        'difficulty'   => $difficulty,
        'available_sizes' => !empty($available_sizes) ? json_encode($available_sizes) : null,
        'search_sizes' => !empty($search_sizes) ? json_encode($search_sizes) : null,
        'size_stock_data' => null,
        'price'        => $price,
        'regular_price'=> (float)$product->get_regular_price(),
        'price_max'    => $price_max,
        'price_display'=> $price_display,
        'sale_price'   => (float)$product->get_sale_price(),
        'stock_status' => $product->get_stock_status(),
        'sales_count'  => $sales,
        'rating'       => (float)$product->get_average_rating(),
        'rating_count' => (int)$product->get_rating_count(),
        'is_featured'  => $product->is_featured() ? 1 : 0,
        'is_bestseller'=> $sales > 50 ? 1 : 0,
        'is_on_sale'   => $product->is_on_sale() ? 1 : 0,
        'image_url'    => $img_id ? wp_get_attachment_image_url($img_id, 'medium') : '',
        'permalink'    => get_permalink($product_id),
        'created_at'   => $created,
        'updated_at'   => current_time('mysql'),
    ]);

    if ($replace_result === false) {
        error_log('WCAI: replace FAILED for product ' . $product_id . ' — ' . $wpdb->last_error);
        return;
    }

    $wpdb->delete($tt, ['product_id' => $product_id]);

    $title_lower = strtolower($product->get_name());
    $sku_lower   = strtolower($product->get_sku());
    $brand_lower = strtolower($brand);
    $cats_lower  = array_map('strtolower', $cats);
    $tags_lower  = array_map('strtolower', $tags);
    $material_lower = strtolower($material);

    $description_raw = trim(($product->get_description() ?: '') . ' ' . ($product->get_short_description() ?: ''));
    $description_lower = strtolower(wp_strip_all_tags($description_raw));

    $terms = [];

    $words = wcai_tokenize($title_lower);
    foreach ($words as $w) {
        $terms[$w] = ($terms[$w] ?? 0) + 10;
        if (strlen($w) >= 4) {
            foreach (wcai_ngrams($w, 3) as $ng) $terms[$ng] = ($terms[$ng] ?? 0) + 5;
        }
    }

    if ($sku_lower) {
        $terms[$sku_lower] = ($terms[$sku_lower] ?? 0) + 20;
        foreach (wcai_ngrams($sku_lower, 2) as $ng) $terms[$ng] = ($terms[$ng] ?? 0) + 10;
    }

    if ($brand_lower) $terms[$brand_lower] = ($terms[$brand_lower] ?? 0) + 15;

    foreach ($cats_lower as $cat) {
        if (!$cat) continue;
        $terms[$cat] = ($terms[$cat] ?? 0) + 5;
        foreach (wcai_tokenize($cat) as $cw) {
            if (strlen($cw) > 2) $terms[$cw] = ($terms[$cw] ?? 0) + 3;
        }
    }

    foreach ($tags_lower as $tag) {
        if ($tag) $terms[$tag] = ($terms[$tag] ?? 0) + 3;
    }

    if ($material_lower) {
        $terms[$material_lower] = ($terms[$material_lower] ?? 0) + 12;
        foreach (wcai_tokenize($material_lower) as $mw) {
            if (strlen($mw) > 2) $terms[$mw] = ($terms[$mw] ?? 0) + 5;
        }
    }

    if ($difficulty) $terms[strtolower($difficulty)] = ($terms[strtolower($difficulty)] ?? 0) + 10;

    if ($description_lower) {
        $desc_words = wcai_tokenize($description_lower);
        $desc_words = array_slice(array_unique($desc_words), 0, 900);
        foreach ($desc_words as $dw) {
            if (strlen($dw) < 3) continue;
            $terms[$dw] = ($terms[$dw] ?? 0) + 2;
        }
    }

    $all_meta = get_post_meta($product_id);
    $exclude_keys = [
        '_edit_lock', '_edit_last', '_sku', '_price', '_regular_price',
        '_sale_price', '_stock', '_stock_status', '_product_version',
        '_thumbnail_id', '_wp_old_slug', '_wp_old_date',
        '_wc_average_rating', '_wc_rating_count', '_wc_review_count',
        '_downloadable', '_virtual', '_manage_stock', '_backorders',
        '_sold_individually', '_tax_status', '_tax_class',
        '_recurring', '_subscription_price', '_subscription_period',
        '_subscription_period_interval', '_subscription_length',
        '_subscription_sign_up_fee', '_subscription_trial_period',
        '_subscription_trial_length', '_material', '_colors', '_stitches', '_difficulty'
    ];
    foreach ($all_meta as $key => $values) {
        if (in_array($key, $exclude_keys)) continue;
        if (strpos($key, '_') === 0 && !in_array($key, $exclude_keys)) continue;
        foreach ($values as $value) {
            if (is_string($value) && strlen($value) > 3) {
                $words = wcai_tokenize($value);
                foreach ($words as $word) {
                    if (strlen($word) > 2) $terms[$word] = ($terms[$word] ?? 0) + 5;
                }
            }
        }
    }

    if (!empty($available_sizes)) {
        foreach ($available_sizes as $size) {
            $size_lower = strtolower(trim($size));
            if (strlen($size_lower) > 1) {
                $terms[$size_lower] = ($terms[$size_lower] ?? 0) + 25;
                if (strlen($size_lower) >= 3) {
                    foreach (wcai_ngrams($size_lower, 2) as $ng) $terms[$ng] = ($terms[$ng] ?? 0) + 10;
                    foreach (wcai_ngrams($size_lower, 3) as $ng) $terms[$ng] = ($terms[$ng] ?? 0) + 8;
                }
                preg_match_all('/\d+(?:\.\d+)?/', $size_lower, $numbers);
                if (!empty($numbers[0])) {
                    foreach ($numbers[0] as $num) {
                        if (strlen($num) > 0) $terms[$num] = ($terms[$num] ?? 0) + 15;
                    }
                }
            }
        }
    }

    foreach ($terms as $term => $weight) {
        if (strlen((string)$term) < 2 || strlen((string)$term) > 100) continue;
        $term_result = $wpdb->replace($tt, [
            'term'       => $term,
            'product_id' => $product_id,
            'weight'     => $weight,
            'term_type'  => 'word',
        ]);
        if ($term_result === false) {
            error_log('WCAI: term replace FAILED for product ' . $product_id . ' term="' . $term . '" — ' . $wpdb->last_error);
        }
    }
}

add_action('wcai_cron_index_batch', 'wcai_cron_index_batch_handler');
if (!function_exists('wcai_cron_index_batch_handler')) {
    function wcai_cron_index_batch_handler() {
        global $wpdb;
        $status = get_option('wcai_index_status', '');
        if ($status === 'done') return;

        $offset = (int)get_option('wcai_index_offset', 0);
        $total  = (int)get_option('wcai_index_total', 0);

        if ($total === 0) {
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}wcai_products");
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}wcai_terms");
            $total = (int)$wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE post_type='product' AND post_status='publish'"
            );
            update_option('wcai_index_total', $total);
            update_option('wcai_index_status', 'running');
            update_option('wcai_index_offset', 0);
        }

        if ($total === 0) {
            update_option('wcai_index_status', 'done');
            return;
        }

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type='product' AND post_status='publish'
             ORDER BY ID ASC LIMIT %d OFFSET %d",
            20, $offset
        ));

        if (empty($ids)) {
            update_option('wcai_index_status', 'done');
            delete_option('wcai_index_offset');
            update_option('wcai_attribute_schema', wcai_detect_attribute_schema());
            return;
        }

        foreach ($ids as $pid) {
            wcai_index_product($pid);
            clean_post_cache($pid);
        }

        $offset += count($ids);
        update_option('wcai_index_offset', $offset);

        if ($offset < $total) {
            as_schedule_single_action(time() + 1, 'wcai_cron_index_batch');
        } else {
            update_option('wcai_index_status', 'done');
            delete_option('wcai_index_offset');
            update_option('wcai_attribute_schema', wcai_detect_attribute_schema());
        }
    }
}

function wcai_start_background_index() {
    wp_clear_scheduled_hook('wcai_cron_index_batch');
    delete_option('wcai_index_status');
    update_option('wcai_index_offset', 0);
    wcai_cron_index_batch_handler();
}

add_action('woocommerce_update_product', function ($product_id) {
    if (get_option('wcai_index_status') === 'done') wcai_index_product($product_id);
}, 10, 1);

add_action('save_post_product', function ($product_id, $post) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($product_id)) return;
    if ($post->post_status !== 'publish') return;
    if (get_option('wcai_index_status') === 'done') wcai_index_product($product_id);
}, 10, 2);

add_action('woocommerce_delete_product', function ($product_id) {
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'wcai_products', ['product_id' => $product_id]);
    $wpdb->delete($wpdb->prefix . 'wcai_terms',    ['product_id' => $product_id]);
});

add_action('woocommerce_product_set_stock', function ($product) {
    if (get_option('wcai_index_status') === 'done') wcai_index_product($product->get_id());
});

function wcai_detect_attribute_schema() {
    global $wpdb;
    $pt = $wpdb->prefix . 'wcai_products';

    $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i", $pt));
    if ($total === 0) return [];

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT stitches, colors, difficulty, material, available_sizes FROM %i LIMIT 500",
        $pt
    ));
    if (empty($rows)) return [];

    $schema = [];
    $stitches = [];
    foreach ($rows as $r) {
        $v = (int)$r->stitches;
        if ($v > 0) $stitches[] = $v;
    }
    if (!empty($stitches)) {
        $schema['stitches'] = ['type' => 'integer', 'min' => min($stitches), 'max' => max($stitches)];
    }

    $colors = [];
    foreach ($rows as $r) {
        $v = (int)$r->colors;
        if ($v > 0) $colors[] = $v;
    }
    if (!empty($colors)) {
        $schema['colors'] = ['type' => 'integer', 'min' => min($colors), 'max' => max($colors)];
    }

    $diff = [];
    foreach ($rows as $r) {
        if (!empty($r->difficulty)) $diff[] = $r->difficulty;
    }
    if (!empty($diff)) {
        $schema['difficulty'] = ['type' => 'enum', 'values' => array_values(array_unique($diff))];
    }

    $mat = [];
    foreach ($rows as $r) {
        if (!empty($r->material)) $mat[] = $r->material;
    }
    if (!empty($mat)) {
        $schema['material'] = ['type' => 'string', 'examples' => array_slice(array_values(array_unique($mat)), 0, 10)];
    }

    $has_sizes = false;
    foreach ($rows as $r) {
        if (!empty($r->available_sizes) && $r->available_sizes !== 'null') { $has_sizes = true; break; }
    }
    if ($has_sizes) $schema['available_sizes'] = ['type' => 'array'];

    $meta_keys = $wpdb->get_col($wpdb->prepare("
        SELECT DISTINCT meta_key
        FROM {$wpdb->postmeta}
        WHERE meta_key NOT LIKE %s
          AND meta_key NOT LIKE %s
          AND meta_key NOT LIKE %s
          AND meta_key NOT LIKE %s
          AND meta_key NOT LIKE %s
          AND meta_key NOT LIKE %s
          AND meta_key NOT LIKE %s
          AND meta_key NOT LIKE %s
        LIMIT 30
    ", '\_%', 'pa\_%', '%\_id', '%\_name', 'field\_%', 'wc\_%', 'xts-%', 'xts\_%'));

    $extra = [];
    foreach ($meta_keys as $key) {
        $sample = $wpdb->get_col($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = %s AND meta_value != '' LIMIT 20",
            $key
        ));
        if (empty($sample)) continue;
        $all_numeric = true;
        foreach ($sample as $v) { if (!is_numeric($v)) { $all_numeric = false; break; } }
        $extra[$key] = $all_numeric
            ? ['type' => 'number', 'examples' => array_slice($sample, 0, 5)]
            : ['type' => 'string', 'examples' => array_slice(array_values(array_unique($sample)), 0, 5)];
    }
    if (!empty($extra)) $schema['extra_fields'] = $extra;

    $schema['catalog_size'] = $total;
    return $schema;
}

function wcai_search(
    $search_text, $limit = 100, $offset = 0,
    $min_price = null, $max_price = null, $sort_by = null,
    $category = null, $color = null, $size = null, $brand = null,
    $in_stock = null, $on_sale = null, $material = null
) {
    global $wpdb;
    $pt = $wpdb->prefix . 'wcai_products';
    $tt = $wpdb->prefix . 'wcai_terms';

    $limit = (int)$limit;
    $offset = max(0, (int)$offset);

    $has_filters = !empty($category) || !empty($color) || !empty($size) ||
                   !empty($brand) || $min_price !== null || $max_price !== null ||
                   $in_stock === true || $on_sale === true;

    if (empty($search_text) && !$has_filters) {
        return ['products' => [], 'count' => 0, 'has_more' => false, 'offset' => 0, 'next_offset' => null, 'limit' => $limit];
    }

    $search_clean = trim($search_text);
    $search_clean = preg_replace('/^SKU:\s*/i', '', $search_clean);

    $stopwords = ['and', 'or', 'the', 'a', 'an', 'in', 'on', 'at', 'to', 'for', 'of', 'is', 'are', 'am', 'be', 'pes', 'pet', 'pcs'];
    $words = preg_split('/\s+/', mb_strtolower($search_clean));
    $words = array_filter($words, function($w) use ($stopwords) {
        return strlen($w) > 2 && !in_array($w, $stopwords);
    });
    $words = array_values($words);

    if (empty($words) && !$has_filters) {
        return ['products' => [], 'count' => 0, 'has_more' => false, 'offset' => 0, 'next_offset' => null, 'limit' => $limit];
    }

    $where_conditions = [];
    $where_params = [];

    if (!empty($category)) {
        $where_conditions[] = "LOWER(category) LIKE LOWER(%s)";
        $where_params[] = '%' . $wpdb->esc_like($category) . '%';
    }
    if (!empty($brand)) {
        $where_conditions[] = "LOWER(brand) LIKE LOWER(%s)";
        $where_params[] = '%' . $wpdb->esc_like($brand) . '%';
    }
    if (!empty($material)) {
        $where_conditions[] = "LOWER(material) LIKE LOWER(%s)";
        $where_params[] = '%' . $wpdb->esc_like($material) . '%';
    }
    if ($min_price !== null && $min_price > 0) {
        $where_conditions[] = "price_max >= %f";
        $where_params[] = (float)$min_price;
    }
    if ($max_price !== null && $max_price > 0) {
        $where_conditions[] = "price <= %f";
        $where_params[] = (float)$max_price;
    }
    if ($in_stock === true) $where_conditions[] = "stock_status = 'instock'";
    if ($on_sale === true) $where_conditions[] = "is_on_sale = 1";

    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    $is_phrase_search = (strpos($search_text, ' ') !== false && count($words) > 1);

    $all_product_ids = [];
    $seen_ids = [];

    if (!empty($search_clean)) {
        $title_like = '%' . $wpdb->esc_like($search_clean) . '%';
        $sql = "SELECT product_id FROM $pt WHERE title LIKE %s";
        if (!empty($where_clause)) $sql .= " AND " . substr($where_clause, 6);
        $params = array_merge([$title_like], $where_params);
        $results = $wpdb->get_results($wpdb->prepare($sql, $params));
        if ($results) {
            foreach ($results as $r) {
                $id = (int)$r->product_id;
                if (!in_array($id, $seen_ids)) { $seen_ids[] = $id; $all_product_ids[] = $id; }
            }
        }
    }
    if (empty($search_clean) && $has_filters) {
        $sql = "SELECT product_id FROM $pt";
        if (!empty($where_clause)) $sql .= " " . $where_clause;
        $sql .= " ORDER BY sales_count DESC LIMIT 200";
        $results = !empty($where_params)
            ? $wpdb->get_results($wpdb->prepare($sql, $where_params))
            : $wpdb->get_results($sql);
        if ($results) {
            foreach ($results as $r) {
                $id = (int)$r->product_id;
                if (!in_array($id, $seen_ids)) { $seen_ids[] = $id; $all_product_ids[] = $id; }
            }
        }
    }
    if (!empty($words)) {
        $words = array_slice($words, 0, 10);
        $term_placeholders = implode(',', array_fill(0, count($words), '%s'));
        $total_words = count($words);
        $min_matches = $is_phrase_search ? $total_words : 1;

        $sql = "SELECT DISTINCT $tt.product_id
                FROM $tt
                INNER JOIN $pt ON $pt.product_id = $tt.product_id
                WHERE $tt.term IN ($term_placeholders)";
        if (!empty($where_clause)) $sql .= " AND " . substr($where_clause, 6);
        $sql .= " GROUP BY $tt.product_id HAVING COUNT(DISTINCT $tt.term) >= %d";

        $params = array_merge($words, $where_params, [$min_matches]);
        $results = $wpdb->get_results($wpdb->prepare($sql, $params));
        if ($results) {
            foreach ($results as $r) {
                $id = (int)$r->product_id;
                if (!in_array($id, $seen_ids)) { $seen_ids[] = $id; $all_product_ids[] = $id; }
            }
        }
    }

    if (empty($all_product_ids) && $is_phrase_search && !empty($words)) {
        $words = array_slice($words, 0, 10);
        $term_placeholders = implode(',', array_fill(0, count($words), '%s'));
        $sql = "SELECT DISTINCT $tt.product_id
                FROM $tt
                INNER JOIN $pt ON $pt.product_id = $tt.product_id
                WHERE $tt.term IN ($term_placeholders)";
        if (!empty($where_clause)) $sql .= " AND " . substr($where_clause, 6);
        $params = array_merge($words, $where_params);
        $results = $wpdb->get_results($wpdb->prepare($sql, $params));
        if ($results) {
            foreach ($results as $r) {
                $id = (int)$r->product_id;
                if (!in_array($id, $seen_ids)) { $seen_ids[] = $id; $all_product_ids[] = $id; }
            }
        }
    }

    if (empty($all_product_ids) && !empty($words)) {
        $title_conditions = [];
        $title_params = [];
        foreach ($words as $word) {
            $title_conditions[] = "title LIKE %s";
            $title_params[] = '%' . $wpdb->esc_like($word) . '%';
        }
        $title_sql = implode(' OR ', $title_conditions);
        $sql = "SELECT product_id FROM $pt WHERE ($title_sql)";
        if (!empty($where_clause)) $sql .= " AND " . substr($where_clause, 6);
        $params = array_merge($title_params, $where_params);
        $results = $wpdb->get_results($wpdb->prepare($sql, $params));
        if ($results) {
            foreach ($results as $r) {
                $id = (int)$r->product_id;
                if (!in_array($id, $seen_ids)) { $seen_ids[] = $id; $all_product_ids[] = $id; }
            }
        }
    }

    $real_total = count($all_product_ids);

    if (empty($sort_by) || $sort_by === 'popularity') {
        $search_clean_lower = strtolower(trim($search_text));
        usort($all_product_ids, function($a, $b) use ($wpdb, $pt, $search_clean_lower) {
            $a_title = strtolower($wpdb->get_var($wpdb->prepare("SELECT title FROM $pt WHERE product_id = %d", $a)));
            $b_title = strtolower($wpdb->get_var($wpdb->prepare("SELECT title FROM $pt WHERE product_id = %d", $b)));
            $a_exact = strpos($a_title, $search_clean_lower) !== false;
            $b_exact = strpos($b_title, $search_clean_lower) !== false;
            if ($a_exact && !$b_exact) return -1;
            if (!$a_exact && $b_exact) return 1;
            $a_sales = (int)$wpdb->get_var($wpdb->prepare("SELECT sales_count FROM $pt WHERE product_id = %d", $a));
            $b_sales = (int)$wpdb->get_var($wpdb->prepare("SELECT sales_count FROM $pt WHERE product_id = %d", $b));
            return $b_sales - $a_sales;
        });
    }

    $page_product_ids = array_slice($all_product_ids, $offset, $limit);

    $products = [];
    if (!empty($page_product_ids)) {
        $placeholders = implode(',', array_fill(0, count($page_product_ids), '%d'));
        $order = implode(',', array_map('intval', $page_product_ids));
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $pt WHERE product_id IN ($placeholders) ORDER BY FIELD(product_id, $order)",
            $page_product_ids
        ));
        $products = $results ?: [];
    }

    $has_more = ($offset + count($page_product_ids)) < $real_total;

    return [
        'products' => $products,
        'count' => $real_total,
        'has_more' => $has_more,
        'offset' => $offset,
        'next_offset' => $has_more ? $offset + count($page_product_ids) : null,
        'limit' => $limit,
    ];
}

function wcai_format_products($results, $override_currency = null) {
    global $wpdb;
    $currency = $override_currency ?: wcai_get_active_currency();
    $symbol = wcai_get_currency_symbol($currency);
    $rate = wcai_get_currency_rate($currency);

    $out = [];
    $seen = [];

    foreach ($results as $p) {
        if (isset($seen[$p->product_id])) continue;
        $seen[$p->product_id] = true;
        $product_obj = wc_get_product($p->product_id);
        $type = $product_obj ? $product_obj->get_type() : 'simple';

        $base_price = (float)$p->price;
        $base_price_max = (float)$p->price_max;
        $converted_price = $base_price * $rate;
        $converted_price_max = $base_price_max * $rate;

        if ($p->price_display && strpos($p->price_display, ' – ') !== false) {
            $parts = explode(' – ', $p->price_display);
            $min = (float)$parts[0] * $rate;
            $max = (float)$parts[1] * $rate;
            $price_display = $symbol . ' ' . number_format($min, 2) . ' – ' . $symbol . ' ' . number_format($max, 2);
        } else {
            $price_display = $symbol . ' ' . number_format($converted_price, 2);
        }

        $available_sizes = [];
        $sizes_data = $p->available_sizes ?? null;
        if ($sizes_data) {
            try {
                $sizes = json_decode($sizes_data, true);
                if (is_array($sizes)) {
                    foreach ($sizes as $size) {
                        if (is_numeric($size)) {
                            $term = get_term((int)$size);
                            if ($term && !is_wp_error($term) && $term->name) $available_sizes[] = $term->name;
                            else $available_sizes[] = $size;
                        } else {
                            $available_sizes[] = $size;
                        }
                    }
                }
            } catch (Exception $e) { $available_sizes = []; }
        }

        $description = $p->description ?? '';
        if (!empty($available_sizes)) {
            $description = '📏 SIZES: ' . implode(', ', $available_sizes) . '. ' . $description;
        }

        $out[] = [
            'id'                => $p->product_id,
            'name'              => $p->title ?: 'Product',
            'type'              => $type,
            'sku'               => $p->sku,
            'price'             => $price_display,
            'price_numeric'     => $converted_price,
            'price_max_numeric' => $converted_price_max,
            'price_raw'         => $base_price,
            'sale_price'        => (float)$p->sale_price * $rate,
            'price_original'    => (float)$p->regular_price * $rate,
            'regular_price'     => (float)$p->regular_price * $rate,
            'image'             => $p->image_url ?: (function_exists('wc_placeholder_img_src') ? wc_placeholder_img_src('medium') : ''),
            'url'               => $p->permalink,
            'in_stock'          => $p->stock_status === 'instock',
            'sales_count'       => (int)$p->sales_count,
            'rating'            => (float)$p->rating,
            'rating_count'      => (int)$p->rating_count,
            'is_bestseller'     => (bool)$p->is_bestseller,
            'is_featured'       => (bool)$p->is_featured,
            'is_on_sale'        => (bool)$p->is_on_sale,
            'brand'             => $p->brand,
            'category'          => $p->category,
            'description'       => $description,
            'available_sizes'   => $available_sizes,
            'material'          => $p->material ?? '',
            'colors'            => (int)($p->colors ?? 0),
            'stitches'          => (int)($p->stitches ?? 0),
            'difficulty'        => $p->difficulty ?? '',
            'currency'          => $currency,
            'exchange_rate'     => $rate,
        ];
    }
    return $out;
}


add_action('rest_api_init', function () {
    register_rest_route('wc-ai/v1', '/search', [
        'methods' => 'POST',
        'callback' => function ($req) {
            $params = $req->get_json_params();
            $search_text = $params['search_text'] ?? '';
            $offset = max(0, (int)($params['offset'] ?? 0));
            $limit = min((int)($params['limit'] ?? 100), 200);
            $min_price = isset($params['min_price']) ? (float)$params['min_price'] : null;
            $max_price = isset($params['max_price']) ? (float)$params['max_price'] : null;
            $sort_by = $params['sort_by'] ?? null;
            $currency = isset($params['currency']) ? strtoupper(sanitize_text_field($params['currency'])) : null;
            $category = $params['category'] ?? null;
            $color = $params['color'] ?? null;
            $size = $params['size'] ?? null;
            $brand = $params['brand'] ?? null;
            $in_stock = isset($params['in_stock']) ? (bool)$params['in_stock'] : null;
            $on_sale = isset($params['on_sale']) ? (bool)$params['on_sale'] : null;
            $material = $params['material'] ?? null;

            $has_filters = !empty($category) || !empty($color) || !empty($size) ||
                           !empty($brand) || $min_price || $max_price || $in_stock || $on_sale;

            if (empty($search_text) && !$has_filters) {
                return new WP_REST_Response(['products' => [], 'count' => 0, 'has_more' => false, 'offset' => 0, 'next_offset' => null], 200);
            }

            $t = microtime(true);
            $r = wcai_search($search_text, $limit, $offset, $min_price, $max_price, $sort_by,
                $category, $color, $size, $brand, $in_stock, $on_sale, $material);

            return new WP_REST_Response([
                'products' => wcai_format_products($r['products'], $currency),
                'count' => $r['count'],
                'has_more' => $r['has_more'],
                'offset' => $r['offset'],
                'next_offset' => $r['next_offset'],
                'search_time' => round(microtime(true) - $t, 4),
            ], 200);
        },
        'permission_callback' => function ($req) {
            $key = $req->get_header('X-License-Key');
            $hash = $req->get_header('X-Site-Hash');
            return $key === get_option('wcai_license_key') && $hash === get_option('wcai_site_hash');
        },
    ]);

    register_rest_route('wc-ai/v1', '/search-batch', [
        'methods' => 'POST',
        'callback' => function ($req) {
            $params = $req->get_json_params();
            $keywords = isset($params['keywords']) ? (array)$params['keywords'] : [];
            $limit_per_keyword = min((int)($params['limit_per_keyword'] ?? 10), 20);
            $min_price = isset($params['min_price']) ? (float)$params['min_price'] : null;
            $max_price = isset($params['max_price']) ? (float)$params['max_price'] : null;
            $sort_by = $params['sort_by'] ?? null;
            $currency = isset($params['currency']) ? strtoupper(sanitize_text_field($params['currency'])) : null;
            $category = $params['category'] ?? null;
            $color = $params['color'] ?? null;
            $size = $params['size'] ?? null;
            $brand = $params['brand'] ?? null;
            $in_stock = isset($params['in_stock']) ? (bool)$params['in_stock'] : null;
            $on_sale = isset($params['on_sale']) ? (bool)$params['on_sale'] : null;
            $material = $params['material'] ?? null;

            if (empty($keywords)) return new WP_REST_Response(['groups' => []], 200);
            $keywords = array_slice($keywords, 0, 10);

            $groups = [];
            $seen_all_ids = [];
            $has_any_filter = !empty($category) || !empty($color) || !empty($size) ||
                  !empty($brand) || !empty($material) ||
                  $min_price !== null || $max_price !== null ||
                  $in_stock === true || $on_sale === true;

            foreach ($keywords as $keyword) {
                $keyword = trim((string)$keyword);
                if ($keyword === '' && !$has_any_filter) continue;

                $result = wcai_search($keyword, $limit_per_keyword, 0, $min_price, $max_price, $sort_by,
                    $category, $color, $size, $brand, $in_stock, $on_sale, $material);

                $group_products = [];
                foreach ($result['products'] as $p) {
                    if (!isset($seen_all_ids[$p->product_id])) {
                        $seen_all_ids[$p->product_id] = true;
                        $group_products[] = $p;
                    }
                }

                $formatted = wcai_format_products($group_products, $currency);
                $groups[] = ['keyword' => $keyword, 'products' => $formatted, 'count' => count($formatted)];
            }

            return new WP_REST_Response(['groups' => $groups], 200);
        },
        'permission_callback' => function ($req) {
            $key = $req->get_header('X-License-Key');
            $hash = $req->get_header('X-Site-Hash');
            return $key === get_option('wcai_license_key') && $hash === get_option('wcai_site_hash');
        },
    ]);

    register_rest_route('wc-ai/v1', '/products', [
        'methods' => 'POST',
        'callback' => function ($req) {
            $params = $req->get_json_params();
            $ids = isset($params['ids']) ? (array) $params['ids'] : [];
            $ids = array_map('absint', $ids);
            $ids = array_values(array_unique($ids));
            $ids = array_slice($ids, 0, 20);

            if (empty($ids)) return new WP_REST_Response(['products' => []], 200);

            global $wpdb;
            $pt = $wpdb->prefix . 'wcai_products';
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$pt} WHERE product_id IN ({$placeholders})", $ids
            ));

            $by_id = [];
            foreach ($results as $row) $by_id[(int) $row->product_id] = $row;

            $ordered_results = [];
            foreach ($ids as $id) if (isset($by_id[$id])) $ordered_results[] = $by_id[$id];

            return new WP_REST_Response(['products' => wcai_format_products($ordered_results)], 200);
        },
        'permission_callback' => function ($req) {
            $key = $req->get_header('X-License-Key');
            $hash = $req->get_header('X-Site-Hash');
            if (empty($key) || empty($hash)) return false;
            return hash_equals((string) get_option('wcai_license_key'), (string) $key)
                && hash_equals((string) get_option('wcai_site_hash'), (string) $hash);
        }
    ]);

    register_rest_route('wc-ai/v1', '/product-details', [
        'methods' => 'POST',
        'callback' => function ($req) {
            $params = $req->get_json_params();
            $product_id = (int)($params['product_id'] ?? 0);
            if (empty($product_id)) return new WP_REST_Response(['error' => 'No product_id'], 400);

            global $wpdb;
            $pt = $wpdb->prefix . 'wcai_products';
            $product = $wpdb->get_row($wpdb->prepare("SELECT * FROM $pt WHERE product_id = %d", $product_id));
            if (!$product) return new WP_REST_Response(['error' => 'Product not found'], 404);

            $formatted = wcai_format_products([$product]);
            return new WP_REST_Response($formatted[0] ?? [], 200);
        },
        'permission_callback' => function ($req) {
            $key = $req->get_header('X-License-Key');
            $hash = $req->get_header('X-Site-Hash');
            return hash_equals((string) get_option('wcai_license_key'), (string) $key) &&
                   hash_equals((string) get_option('wcai_site_hash'), (string) $hash);
        }
    ]);

    register_rest_route('wc-ai/v1', '/order-status', [
        'methods' => 'POST',
        'callback' => function ($req) {
            $params = $req->get_json_params();
            $order_id_raw = sanitize_text_field($params['order_id'] ?? '');
            $order_id = preg_replace('/[^0-9]/', '', $order_id_raw);
            $email = sanitize_email($params['email'] ?? '');

            if (empty($order_id) || empty($email)) {
                return new WP_REST_Response(['success' => false, 'error' => 'Order ID is required'], 400);
            }

            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $now = time();

            $ip_block_key = 'wcai_order_ip_blocked_' . md5($ip);
            $email_block_key = 'wcai_order_email_blocked_' . md5(strtolower($email));

            $ip_block_time = (int) get_option($ip_block_key);
            if ($ip_block_time && ($now - $ip_block_time) < 86400) {
                return new WP_REST_Response(['success' => false, 'error' => 'Too many attempts. Please try again in 24 hours or contact support.'], 429);
            }
            if ($ip_block_time) delete_option($ip_block_key);

            $email_block_time = (int) get_option($email_block_key);
            if ($email_block_time && ($now - $email_block_time) < 86400) {
                return new WP_REST_Response(['success' => false, 'error' => 'Too many attempts for this email. Please try again in 24 hours or contact support.'], 429);
            }
            if ($email_block_time) delete_option($email_block_key);

            $ip_rate_key = 'wcai_order_ip_attempts_' . md5($ip);
            $email_rate_key = 'wcai_order_email_attempts_' . md5(strtolower($email));

            $ip_data = get_option($ip_rate_key);
            $email_data = get_option($email_rate_key);

            if ($ip_data && ($now - $ip_data['time']) > 900) { delete_option($ip_rate_key); $ip_data = null; }
            if ($email_data && ($now - $email_data['time']) > 900) { delete_option($email_rate_key); $email_data = null; }

            $ip_attempts = $ip_data ? $ip_data['count'] : 0;
            $email_attempts = $email_data ? $email_data['count'] : 0;

            global $wpdb;
            $order = wc_get_order($order_id);

            if (!$order) {
                $order_id_int = (int)$order_id;
                $post = $wpdb->get_row($wpdb->prepare("
                    SELECT ID, post_status, post_type, post_date
                    FROM {$wpdb->posts}
                    WHERE ID = %d AND post_type = 'shop_order'
                ", $order_id_int));

                if (!$post) {
                    $ip_attempts++; $email_attempts++;
                    update_option($ip_rate_key, ['count' => $ip_attempts, 'time' => $now]);
                    update_option($email_rate_key, ['count' => $email_attempts, 'time' => $now]);

                    if ($ip_attempts >= 10) {
                        update_option($ip_block_key, $now);
                        return new WP_REST_Response(['success' => false, 'error' => 'Too many attempts. Please try again in 24 hours or contact support.'], 429);
                    }
                    if ($email_attempts >= 3) {
                        update_option($email_block_key, $now);
                        return new WP_REST_Response(['success' => false, 'error' => 'Too many attempts for this email. Please try again in 24 hours or contact support.'], 429);
                    }
                    return new WP_REST_Response(['success' => false, 'error' => 'Order not found.'], 404);
                }

                $billing_email = get_post_meta($post->ID, '_billing_email', true);
                if (strtolower($billing_email) !== strtolower($email)) {
                    $ip_attempts++; $email_attempts++;
                    update_option($ip_rate_key, ['count' => $ip_attempts, 'time' => $now]);
                    update_option($email_rate_key, ['count' => $email_attempts, 'time' => $now]);

                    if ($ip_attempts >= 10) {
                        update_option($ip_block_key, $now);
                        return new WP_REST_Response(['success' => false, 'error' => 'Too many attempts. Please try again in 24 hours or contact support.'], 429);
                    }
                    if ($email_attempts >= 3) {
                        update_option($email_block_key, $now);
                        return new WP_REST_Response(['success' => false, 'error' => 'Too many attempts for this email. Please try again in 24 hours or contact support.'], 429);
                    }
                    return new WP_REST_Response(['success' => false, 'error' => 'Order not found for this email.'], 404);
                }

                delete_option($ip_rate_key);
                delete_option($email_rate_key);

                $status = get_post_meta($post->ID, '_status', true) ?: $post->post_status;
                $status = str_replace('wc-', '', $status);
                $total = get_post_meta($post->ID, '_order_total', true);
                $currency_code = get_post_meta($post->ID, '_order_currency', true);
                $tracking_number = get_post_meta($post->ID, '_tracking_number', true);
                $tracking_url = get_post_meta($post->ID, '_tracking_url', true);

                $status_labels = [
                    'pending' => '⏳ Pending payment', 'processing' => '🔄 Processing',
                    'completed' => '✅ Completed', 'on-hold' => '⏸️ On hold',
                    'cancelled' => '❌ Cancelled', 'refunded' => '💰 Refunded',
                    'failed' => '❌ Failed', 'checkout-draft' => '📝 Draft'
                ];

                $response = [
                    'success' => true, 'status' => $status,
                    'status_label' => $status_labels[$status] ?? $status,
                    'date' => $post->post_date,
                    'total' => $total . ' ' . ($currency_code ?: 'USD'),
                    'items' => 0,
                ];

                if (!empty($tracking_number)) {
                    $response['tracking_number'] = $tracking_number;
                    if (!empty($tracking_url)) $response['tracking_url'] = $tracking_url;
                }
                return new WP_REST_Response($response, 200);
            }

            if (strtolower($order->get_billing_email()) !== strtolower($email)) {
                $ip_attempts++; $email_attempts++;
                update_option($ip_rate_key, ['count' => $ip_attempts, 'time' => $now]);
                update_option($email_rate_key, ['count' => $email_attempts, 'time' => $now]);

                if ($ip_attempts >= 10) {
                    update_option($ip_block_key, $now);
                    return new WP_REST_Response(['success' => false, 'error' => 'Too many attempts. Please try again in 24 hours or contact support.'], 429);
                }
                if ($email_attempts >= 3) {
                    update_option($email_block_key, $now);
                    return new WP_REST_Response(['success' => false, 'error' => 'Too many attempts for this email. Please try again in 24 hours or contact support.'], 429);
                }
                return new WP_REST_Response(['success' => false, 'error' => 'Order not found for this email.'], 404);
            }

            delete_option($ip_rate_key);
            delete_option($email_rate_key);

            $tracking_number = $order->get_meta('_tracking_number', true);
            if (empty($tracking_number)) $tracking_number = $order->get_meta('tracking_number', true);
            if (empty($tracking_number)) $tracking_number = $order->get_meta('_wc_shipment_tracking_number', true);

            $tracking_url = '';
            if (!empty($tracking_number)) {
                $tracking_url = $order->get_meta('_tracking_url', true);
                if (empty($tracking_url)) $tracking_url = $order->get_meta('tracking_url', true);
            }

            $status_labels = [
                'pending' => '⏳ Pending payment', 'processing' => '🔄 Processing',
                'completed' => '✅ Completed', 'on-hold' => '⏸️ On hold',
                'cancelled' => '❌ Cancelled', 'refunded' => '💰 Refunded',
                'failed' => '❌ Failed', 'checkout-draft' => '📝 Draft'
            ];

            $response = [
                'success' => true,
                'status' => $order->get_status(),
                'status_label' => $status_labels[$order->get_status()] ?? $order->get_status(),
                'date' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i') : '',
                'total' => $order->get_total() . ' ' . $order->get_currency(),
                'items' => count($order->get_items()),
            ];

            if (!empty($tracking_number)) {
                $response['tracking_number'] = $tracking_number;
                if (!empty($tracking_url)) $response['tracking_url'] = $tracking_url;
            }

            return new WP_REST_Response($response, 200);
        },
        'permission_callback' => function ($req) {
            $key = $req->get_header('X-License-Key');
            $hash = $req->get_header('X-Site-Hash');
            return $key === get_option('wcai_license_key') && $hash === get_option('wcai_site_hash');
        },
    ]);

    register_rest_route('wc-ai/v1', '/delivery-status', [
        'methods' => 'POST',
        'callback' => function ($req) {
            $params = $req->get_json_params();
            $order_id = sanitize_text_field($params['order_id'] ?? '');
            $order_id = preg_replace('/[^0-9]/', '', $order_id);
            $email = sanitize_email($params['email'] ?? '');

            if (empty($order_id) || empty($email)) {
                return new WP_REST_Response(['success' => false, 'error' => 'Order ID and email are required'], 400);
            }

            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $now = time();

            $ip_rate_key = 'wcai_delivery_ip_attempts_' . md5($ip);
            $email_rate_key = 'wcai_delivery_email_attempts_' . md5(strtolower($email));

            $ip_data = get_option($ip_rate_key);
            $email_data = get_option($email_rate_key);

            if ($ip_data && ($now - $ip_data['time']) > 900) { delete_option($ip_rate_key); $ip_data = null; }
            if ($email_data && ($now - $email_data['time']) > 900) { delete_option($email_rate_key); $email_data = null; }

            $ip_attempts = $ip_data ? $ip_data['count'] : 0;
            $email_attempts = $email_data ? $email_data['count'] : 0;

            $ip_block_key = 'wcai_delivery_ip_blocked_' . md5($ip);
            $email_block_key = 'wcai_delivery_email_blocked_' . md5(strtolower($email));

            $ip_block_time = (int) get_option($ip_block_key);
            if ($ip_block_time && ($now - $ip_block_time) < 86400) {
                return new WP_REST_Response(['success' => false, 'error' => 'Too many attempts. Please try again in 24 hours.'], 429);
            }

            $email_block_time = (int) get_option($email_block_key);
            if ($email_block_time && ($now - $email_block_time) < 86400) {
                return new WP_REST_Response(['success' => false, 'error' => 'Too many attempts for this email. Please try again in 24 hours.'], 429);
            }

            $order = wc_get_order($order_id);

            if (!$order) {
                $ip_attempts++; $email_attempts++;
                update_option($ip_rate_key, ['count' => $ip_attempts, 'time' => $now]);
                update_option($email_rate_key, ['count' => $email_attempts, 'time' => $now]);

                if ($ip_attempts >= 10) {
                    update_option($ip_block_key, $now);
                    return new WP_REST_Response(['success' => false, 'error' => 'Too many attempts. Please try again in 24 hours.'], 429);
                }
                if ($email_attempts >= 3) {
                    update_option($email_block_key, $now);
                    return new WP_REST_Response(['success' => false, 'error' => 'Too many attempts for this email. Please try again in 24 hours.'], 429);
                }
                return new WP_REST_Response(['success' => false, 'error' => 'Order not found.'], 404);
            }

            if (strtolower($order->get_billing_email()) !== strtolower($email)) {
                $ip_attempts++; $email_attempts++;
                update_option($ip_rate_key, ['count' => $ip_attempts, 'time' => $now]);
                update_option($email_rate_key, ['count' => $email_attempts, 'time' => $now]);

                if ($ip_attempts >= 10) {
                    update_option($ip_block_key, $now);
                    return new WP_REST_Response(['success' => false, 'error' => 'Too many attempts. Please try again in 24 hours.'], 429);
                }
                if ($email_attempts >= 3) {
                    update_option($email_block_key, $now);
                    return new WP_REST_Response(['success' => false, 'error' => 'Too many attempts for this email. Please try again in 24 hours.'], 429);
                }
                return new WP_REST_Response(['success' => false, 'error' => 'Order not found for this email.'], 404);
            }

            delete_option($ip_rate_key);
            delete_option($email_rate_key);

            $status_labels = [
                'pending' => 'Pending', 'processing' => 'Processing',
                'shipped' => 'Shipped', 'in_transit' => 'In Transit',
                'out_for_delivery' => 'Out for Delivery', 'delivered' => 'Delivered',
                'cancelled' => 'Cancelled', 'failed' => 'Failed'
            ];

            $status = $order->get_status();

            $tracking_number = '';
            $tracking_fields = [
                '_tracking_number', 'tracking_number', '_wc_shipment_tracking_number',
                '_wc_shipment_tracking_numbers', '_shipment_tracking_number',
                '_wcast_tracking_number', '_aftership_tracking_number',
                '_order_tracking_number', '_fedex_tracking_number', '_ups_tracking_number',
                '_dhl_tracking_number', '_usps_tracking_number', '_tracking_number_fedex',
                '_tracking_number_ups', '_tracking_number_dhl', '_tracking_number_usps',
                '_tracking_code', '_tracking_numbers', 'tracking_numbers',
                '_shipping_tracking_number', 'shipping_tracking_number',
                '_tracking', 'tracking', '_wc_shipment_tracking_items',
                '_shipment_tracking', '_wcfm_shipment_tracking', '_wcfm_tracking_number',
                '_ywot_tracking_code', '_ywot_carrier',
            ];

            foreach ($tracking_fields as $field) {
                $val = $order->get_meta($field, true);
                if (!empty($val) && !is_array($val)) { $tracking_number = $val; break; }
                if (is_array($val) && !empty($val)) {
                    if (isset($val[0]['tracking_number'])) $tracking_number = $val[0]['tracking_number'];
                    elseif (isset($val[0])) $tracking_number = $val[0];
                    break;
                }
            }

            $carrier = '';
            $carrier_fields = [
                '_shipping_carrier', 'shipping_carrier', '_carrier', 'carrier',
                '_shipping_method', 'shipping_method', '_delivery_carrier',
                '_fedex_services', '_ups_services', '_dhl_services', '_usps_services',
                '_wc_shipment_tracking_carrier', '_shipment_tracking_carrier',
                '_wcast_carrier', '_wcfm_carrier', '_ywot_carrier',
            ];
            foreach ($carrier_fields as $field) {
                $val = $order->get_meta($field, true);
                if (!empty($val) && !is_array($val)) { $carrier = $val; break; }
                if (is_array($val) && !empty($val)) {
                    if (isset($val[0]['carrier'])) $carrier = $val[0]['carrier'];
                    elseif (isset($val[0])) $carrier = $val[0];
                    break;
                }
            }

            $tracking_url = '';
            $url_fields = [
                '_tracking_url', 'tracking_url', '_wc_shipment_tracking_url',
                '_shipment_tracking_url', '_aftership_tracking_url',
                '_fedex_tracking_url', '_ups_tracking_url', '_dhl_tracking_url', '_usps_tracking_url',
            ];
            foreach ($url_fields as $field) {
                $val = $order->get_meta($field, true);
                if (!empty($val)) { $tracking_url = $val; break; }
            }

            if (empty($tracking_url) && !empty($tracking_number)) {
                $carrier_lower = strtolower($carrier);
                if (strpos($carrier_lower, 'fedex') !== false) $tracking_url = 'https://www.fedex.com/tracking?trackingnumber=' . $tracking_number;
                elseif (strpos($carrier_lower, 'ups') !== false) $tracking_url = 'https://www.ups.com/track?tracknumber=' . $tracking_number;
                elseif (strpos($carrier_lower, 'dhl') !== false) $tracking_url = 'https://www.dhl.com/en/express/tracking.html?AWB=' . $tracking_number;
                elseif (strpos($carrier_lower, 'usps') !== false) $tracking_url = 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . $tracking_number;
            }

            $estimated_delivery = '';
            $estimated_fields = [
                '_estimated_delivery', 'estimated_delivery', '_estimated_delivery_date',
                'estimated_delivery_date', '_delivery_date', 'delivery_date',
                '_expected_delivery', 'expected_delivery', '_estimated_delivery_time',
                'estimated_delivery_time', '_delivery_estimate', 'delivery_estimate',
            ];
            foreach ($estimated_fields as $field) {
                $val = $order->get_meta($field, true);
                if (!empty($val)) { $estimated_delivery = $val; break; }
            }

            $delivery_address = '';
            if ($order->get_shipping_address_1()) {
                $delivery_address = $order->get_shipping_address_1();
                if ($order->get_shipping_address_2()) $delivery_address .= ', ' . $order->get_shipping_address_2();
                if ($order->get_shipping_city()) $delivery_address .= ', ' . $order->get_shipping_city();
                if ($order->get_shipping_country()) $delivery_address .= ', ' . $order->get_shipping_country();
            }

            $response = [
                'success' => true,
                'status' => $status,
                'status_label' => $status_labels[$status] ?? ucfirst($status),
                'estimated_delivery' => $estimated_delivery ?: null,
                'tracking_number' => $tracking_number ?: null,
                'tracking_url' => $tracking_url ?: null,
                'carrier' => $carrier ?: null,
                'delivery_address' => $delivery_address ?: null,
                'last_update' => $order->get_date_modified() ? $order->get_date_modified()->date('Y-m-d H:i') : ($order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i') : ''),
                'delivery_date' => $status === 'completed' && $order->get_date_modified() ? $order->get_date_modified()->date('Y-m-d H:i') : null
            ];

            return new WP_REST_Response($response, 200);
        },
        'permission_callback' => function ($req) {
            $key = $req->get_header('X-License-Key');
            $hash = $req->get_header('X-Site-Hash');
            return $key === get_option('wcai_license_key') && $hash === get_option('wcai_site_hash');
        },
    ]);

    register_rest_route('wc-ai/v1', '/catalog-info', [
        'methods' => 'POST',
        'callback' => function ($req) {
            global $wpdb;
            $pt = $wpdb->prefix . 'wcai_products';

            $brands     = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT brand    FROM %i WHERE brand    != '' LIMIT 50", $pt));
            $categories = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT category FROM %i WHERE category != '' LIMIT 50", $pt));
            $materials  = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT material FROM %i WHERE material != '' LIMIT 30", $pt));

            $catalog_info = '';
            if (!empty($brands))     $catalog_info .= "Brands: "     . implode(', ', $brands)     . "\n";
            if (!empty($categories)) $catalog_info .= "Categories: " . implode(', ', $categories) . "\n";
            if (!empty($materials))  $catalog_info .= "Materials: "  . implode(', ', $materials)  . "\n";

            $title_rows = $wpdb->get_col($wpdb->prepare("SELECT title FROM %i WHERE title != '' LIMIT 2000", $pt));

            if (!empty($title_rows)) {
                $stopwords = [
                    'the', 'and', 'for', 'with', 'from', 'into', 'onto', 'that', 'this',
                    'you', 'your', 'our', 'are', 'was', 'were', 'has', 'have', 'had',
                    'design', 'designs', 'file', 'files', 'machine', 'embroidery',
                    'instant', 'download', 'downloads', 'logo', 'style', 'digital',
                    'product', 'products', 'high', 'quality', 'easy', 'best', 'new',
                    'free', 'set', 'pack', 'patterns', 'pattern', 'stitch', 'stitches',
                    'colour', 'color', 'colours', 'colors', 'sheet', 'chart', 'included',
                    'inch', 'inches', 'size', 'sizes', 'sized', 'available', 'choose',
                    'format', 'formats', 'archive', 'zip', 'info', 'specification',
                ];
                $word_freq = [];
                foreach ($title_rows as $title) {
                    $words = preg_split('/[\s\-_\/]+/u', strtolower($title), -1, PREG_SPLIT_NO_EMPTY);
                    foreach ($words as $w) {
                        $w = preg_replace('/[^a-z0-9]/', '', $w);
                        if (mb_strlen($w, 'UTF-8') < 4) continue;
                        if (is_numeric($w)) continue;
                        if (in_array($w, $stopwords, true)) continue;
                        $word_freq[$w] = ($word_freq[$w] ?? 0) + 1;
                    }
                }
                arsort($word_freq);
                $top_words = [];
                foreach ($word_freq as $word => $count) {
                    if ($count < 3) break;
                    $top_words[] = $word;
                    if (count($top_words) >= 100) break;
                }
                if (!empty($top_words)) $catalog_info .= "Top title words: " . implode(', ', $top_words) . "\n";
            }

            $catalog_info = apply_filters('wcai_catalog_info', $catalog_info);
            return new WP_REST_Response(['catalog_info' => $catalog_info], 200);
        },
        'permission_callback' => function ($req) {
            $key  = $req->get_header('X-License-Key');
            $hash = $req->get_header('X-Site-Hash');
            return hash_equals((string) get_option('wcai_license_key'), (string) $key)
                && hash_equals((string) get_option('wcai_site_hash'),  (string) $hash);
        },
    ]);
});


add_action('wp_ajax_wcai_send', 'wcai_ajax_send_with_tracking', 5);
add_action('wp_ajax_nopriv_wcai_send', 'wcai_ajax_send_with_tracking', 5);

function wcai_ajax_send_with_tracking() {
    $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
    $message = wp_kses($message, []);

    if (preg_match('/<\?php|echo\s+|print_r|var_dump|function\s*\(/', $message)) {
        wp_send_json_error(['message' => 'Invalid characters in message']);
        return;
    }
    if ($message === '') {
        wp_send_json_error(['message' => 'Message cannot be empty']);
        return;
    }
    if (strlen($message) > 5000) {
        wp_send_json_error(['message' => 'Message too long (max 5000 characters)']);
        return;
    }
    if (!wcai_has_user_consent()) {
        wp_send_json_error(['message' => 'Please accept the privacy consent to continue.']);
        return;
    }

    $ip = wcai_get_real_ip();
    $rate_key = 'wcai_rate_' . md5($ip);
    $attempts = get_transient($rate_key);
    if ($attempts !== false && (int)$attempts >= 10) {
        error_log('Rate limit exceeded for IP: ' . $ip);
        wp_send_json_error(['message' => 'Too many requests. Please wait 1 minute.']);
        return;
    }
    set_transient($rate_key, ((int)$attempts + 1), 60);

    $history_raw = wp_unslash($_POST['history'] ?? '[]');
    $history = json_decode($history_raw, true);
    if (!is_array($history)) $history = [];

    $license_key = get_option('wcai_license_key', '');
    if (empty($license_key)) {
        wp_send_json_success([
            'reply' => '⚠️ License key not found. Please activate the plugin.',
            'products' => [], 'products_count' => 0
        ]);
        return;
    }

    $client_currency = strtoupper(sanitize_text_field(wp_unslash($_POST['currency'] ?? '')));
    $start_time = microtime(true);
    $site_hash = wcai_get_site_hash();
    $currency = $client_currency ?: wcai_get_active_currency();
    $rate = wcai_get_currency_rate($currency);
    $symbol = wcai_get_currency_symbol($currency);

    global $wpdb;
    $pt = $wpdb->prefix . 'wcai_products';
    $brands = $wpdb->get_col("SELECT DISTINCT brand FROM $pt WHERE brand != '' LIMIT 50");
    $categories = $wpdb->get_col("SELECT DISTINCT category FROM $pt WHERE category != '' LIMIT 50");
    $materials = $wpdb->get_col("SELECT DISTINCT material FROM $pt WHERE material != '' LIMIT 30");

    $catalog_info = '';
    if (!empty($brands)) $catalog_info .= "Brands: " . implode(', ', $brands) . "\n";
    if (!empty($categories)) $catalog_info .= "Categories: " . implode(', ', $categories) . "\n";
    if (!empty($materials)) $catalog_info .= "Materials: " . implode(', ', $materials) . "\n";
    $catalog_info = apply_filters('wcai_catalog_info', $catalog_info);

    $response = wp_remote_post(WCAI_SAAS_URL . '/chat', [
        'headers' => [
            'Authorization' => 'Bearer ' . $license_key,
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode([
            'message' => $message,
            'history' => $history,
            'site_hash' => $site_hash,
            'domain' => get_option('wcai_domain', ''),
            'merchant_prompt' => get_option('wcai_merchant_prompt', ''),
            'catalog_info' => $catalog_info,
            'context' => [
                'store_name' => get_option('wcai_store_name', get_bloginfo('name')),
                'currency' => $currency,
                'exchange_rate' => $rate,
                'symbol' => $symbol,
            ],
        ]),
        'timeout' => 120,
        'sslverify' => true,
    ]);

    $search_time = microtime(true) - $start_time;

    if (is_wp_error($response)) {
        wp_send_json_success([
            'reply' => '⚠️ Error connecting to server: ' . $response->get_error_message(),
            'products' => [], 'products_count' => 0
        ]);
        return;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($response_code !== 200) {
        wp_send_json_success([
            'reply' => '⚠️ Server error (HTTP ' . $response_code . ')',
            'products' => [], 'products_count' => 0
        ]);
        return;
    }

    $result = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        wp_send_json_success([
            'reply' => '⚠️ Invalid response from server',
            'products' => [], 'products_count' => 0
        ]);
        return;
    }

    if (!empty($result['error'])) {
        wp_send_json_success([
            'reply' => '⚠️ ' . esc_html($result['error']),
            'plain_reply' => $result['error'],
            'products' => [], 'products_count' => 0,
            'strategy' => 'error',
            'credits_remaining' => $result['credits_remaining'] ?? 0,
        ]);
        return;
    }

    $reply = $result['reply'] ?? 'No response';
    $plain_reply = $result['plain_reply'] ?? wp_strip_all_tags($reply);
    $products = $result['products'] ?? [];
    $products_count = $result['products_count'] ?? count($products);
    $credits_remaining = $result['credits_remaining'] ?? 0;
    $credits_used = $result['credits_used'] ?? 0;
    $strategy = $result['strategy'] ?? 'ai_response';

    if (!empty($products) && $rate != 1.0) {
        foreach ($products as &$p) {
            if (isset($p['price_numeric'])) {
                $converted = $p['price_numeric'] * $rate;
                $symbol_local = wcai_get_currency_symbol($currency);
                $p['price'] = $symbol_local . ' ' . number_format($converted, 2);
                $p['price_numeric'] = $converted;
            }
        }
        unset($p);
    }

    $reply = preg_replace('/<think>.*?<\/think>/s', '', $reply);
    $reply = preg_replace('/<｜｜DSML｜｜tool_calls>.*$/s', '', $reply);
    $reply = trim($reply);

    if ($products_count === 0 && !empty($message) && $strategy === 'error') {
        try {
            $local_search = wcai_search($message, 5);
            if (!empty($local_search['products'])) {
                $products = wcai_format_products($local_search['products']);
                $products_count = count($products);
                $strategy = 'local_fallback';
            }
        } catch (Exception $e) {
            error_log('Local search error: ' . $e->getMessage());
        }
    }

    if (empty(trim(wp_strip_all_tags($reply))) && $products_count > 0) {
        $reply = $products_count == 1
            ? "Here's the product you asked for:"
            : "I found {$products_count} products matching your request. Check the cards below!";
    }

    if (empty($reply) || strlen($reply) < 3) {
        if ($products_count == 1) $reply = "Here's the product you asked for:";
        elseif ($products_count > 1) $reply = "I found {$products_count} products matching your request. Check the cards below!";
        else $reply = "Let me find exactly what you need!";
    }

    $plain_reply = trim(wp_strip_all_tags($plain_reply));

    if (is_array($products) && !empty($products)) {
        $seen = []; $unique = [];
        foreach ($products as $p) {
            $id = $p['id'] ?? null;
            if ($id && !isset($seen[$id])) { $seen[$id] = true; $unique[] = $p; }
        }
        $products = $unique;
        $products_count = count($products);
    }

    try {
        wcai_log_query_with_tracking($message, $plain_reply, $products, $credits_used, $credits_remaining, $search_time, $reply);
    } catch (Exception $e) {
        error_log('Log error: ' . $e->getMessage());
    }

    wp_send_json_success([
        'reply' => $reply,
        'plain_reply' => $plain_reply,
        'products' => $products,
        'products_count' => $products_count,
        'strategy' => $strategy,
        'credits_remaining' => $credits_remaining,
    ]);
}

function wcai_log_query_with_tracking($query, $reply, $products, $credits_used, $credits_remaining, $search_time, $reply_html = '') {
    global $wpdb;
    $table = $wpdb->prefix . 'wcai_logs';

    // Логируем товары только если в HTML-ответе есть грид
    if (!empty($reply_html) && strpos($reply_html, 'wcai-product-grid') === false) {
        $products = [];
    }

    $products_count = count($products);

    $session_id = '';
    if (!empty($_POST['session_id'])) {
        $sid = sanitize_text_field(wp_unslash($_POST['session_id']));
        if (preg_match('/^[a-f0-9\-]{20,64}$/i', $sid)) $session_id = $sid;
    }

    $columns = $wpdb->get_col($wpdb->prepare("SHOW COLUMNS FROM %i", $table));

    $data = [
        'user_query'       => wp_kses_post($query),
        'bot_reply'        => wp_kses_post($reply),
        'products_data'    => json_encode($products),
        'credits_used'     => (int)$credits_used,
        'credits_remaining'=> (int)$credits_remaining,
        'products_count'   => $products_count,
        'search_time'      => (float)$search_time,
        'lead_converted'   => 0,
        'created_at'       => current_time('mysql')
    ];

    if (in_array('session_id', $columns)) $data['session_id'] = $session_id;

    if (in_array('user_email_hash', $columns)) {
        $data['user_email_hash'] = '';
        $data['user_ip_hash'] = '';
    } elseif (in_array('user_email', $columns)) {
        $data['user_email'] = '';
        $data['user_ip'] = '';
    }

    $wpdb->insert($table, $data);
}

add_action('wp_ajax_wcai_send', 'wcai_local_sku_intercept', 1);
add_action('wp_ajax_nopriv_wcai_send', 'wcai_local_sku_intercept', 1);

function wcai_local_sku_intercept() {
    $message = sanitize_text_field(wp_unslash($_POST['message'] ?? ''));
    if ($message === '') return;

    $message_trimmed = trim($message);
    $message_trimmed = preg_replace('/^(sku|артикул|код|code|article)\s*[:#-]?\s*/iu', '', $message_trimmed);
    $message_trimmed = trim($message_trimmed);

    $is_sku = (
        mb_strlen($message_trimmed, 'UTF-8') >= 3 &&
        mb_strlen($message_trimmed, 'UTF-8') <= 25 &&
        preg_match('/^[A-Z0-9][A-Z0-9\-_.]*$/i', $message_trimmed) &&
        preg_match('/[0-9]/', $message_trimmed) &&
        !preg_match('/\s/', $message_trimmed)
    );

    if (!$is_sku) return;

    $product_id = wc_get_product_id_by_sku($message_trimmed);
    if (!$product_id) wcai_local_sku_not_found($message_trimmed);

    global $wpdb;
    $pt = $wpdb->prefix . 'wcai_products';

    $raw_product = $wpdb->get_row($wpdb->prepare("SELECT * FROM $pt WHERE product_id = %d", $product_id));
    if (!$raw_product) wcai_local_sku_not_found($message_trimmed);

    $currency = strtoupper(sanitize_text_field(wp_unslash($_POST['currency'] ?? '')));
    if (empty($currency)) $currency = wcai_get_active_currency();
    $symbol = wcai_get_currency_symbol($currency);

    $products = wcai_format_products([$raw_product], $currency);
    $products = array_slice($products, 0, 1);
    $reply_html = wcai_local_sku_format_grid($products, $symbol);

    try {
        $wpdb->insert($wpdb->prefix . 'wcai_logs', [
            'user_query'       => $message_trimmed,
            'bot_reply'        => '',
            'products_data'    => json_encode($products),
            'credits_used'     => 0,
            'credits_remaining'=> 0,
            'products_count'   => count($products),
            'search_time'      => 0,
            'lead_converted'   => 0,
            'created_at'       => current_time('mysql'),
        ]);
    } catch (Exception $e) { error_log('WCAI SKU log error: ' . $e->getMessage()); }

    wp_send_json_success([
        'reply' => $reply_html,
        'plain_reply' => '',
        'products' => $products,
        'products_count' => count($products),
        'strategy' => 'local_sku',
        'credits_remaining' => 0,
    ]);
    exit;
}

function wcai_local_sku_not_found($sku) {
    $msg = wcai_local_sku_not_found_message($sku);

    global $wpdb;
    try {
        $wpdb->insert($wpdb->prefix . 'wcai_logs', [
            'user_query'       => $sku,
            'bot_reply'        => $msg,
            'products_data'    => '[]',
            'credits_used'     => 0,
            'credits_remaining'=> 0,
            'products_count'   => 0,
            'search_time'      => 0,
            'lead_converted'   => 0,
            'created_at'       => current_time('mysql'),
        ]);
    } catch (Exception $e) { error_log('WCAI SKU log error: ' . $e->getMessage()); }

    $html = '<div style="padding:10px 14px;background:#f0fdf4;border-radius:12px;border-left:4px solid #22c55e;color:#166534;margin-bottom:8px;">'
        . nl2br(esc_html($msg)) . '</div>';

    wp_send_json_success([
        'reply' => $html,
        'plain_reply' => $msg,
        'products' => [],
        'products_count' => 0,
        'strategy' => 'local_sku_not_found',
        'credits_remaining' => 0,
    ]);
    exit;
}

function wcai_local_sku_not_found_message($sku) {
    $msg = '';
    if (isset($_POST['message'])) {
        $input = (string) wp_unslash($_POST['message']);
        if (preg_match('/[\x{4E00}-\x{9FFF}]/u', $input)) $msg = '未找到代码为 ' . $sku . ' 的产品。';
        elseif (preg_match('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $input)) $msg = 'コード ' . $sku . ' の商品が見つかりませんでした。';
        elseif (preg_match('/[\x{AC00}-\x{D7AF}]/u', $input)) $msg = '코드 ' . $sku . ' 에 해당하는 상품을 찾을 수 없습니다.';
        elseif (preg_match('/[а-яё]/iu', $input)) $msg = 'Товар с кодом ' . $sku . ' не найден.';
        elseif (preg_match('/[äöüß]/i', $input)) $msg = 'Produkt mit Code ' . $sku . ' nicht gefunden.';
        elseif (preg_match('/[àâçéèêëîïôûù]/i', $input)) $msg = 'Produit avec le code ' . $sku . ' introuvable.';
        elseif (preg_match('/[áéíóúñ]/i', $input)) $msg = 'Producto con código ' . $sku . ' no encontrado.';
        elseif (preg_match('/[ãõç]/i', $input)) $msg = 'Produto com código ' . $sku . ' não encontrado.';
        elseif (preg_match('/[àèéìòù]/i', $input)) $msg = 'Prodotto con codice ' . $sku . ' non trovato.';
        else $msg = 'Product with code ' . $sku . ' not found.';
    }
    if (empty($msg)) $msg = 'Product with code ' . $sku . ' not found.';
    return $msg;
}

function wcai_local_sku_format_grid($products, $symbol) {
    if (empty($products)) return '';

    $buy_text = '🛒 Buy';
    $choose_text = '🛒 Choose options';
    $show_more_text = get_option('wcai_show_more_open_text', '📖 Show more...');

    $gallery_id = 'sku_gallery_' . uniqid();
    $container_id = 'product-details-' . $gallery_id;

    $html = '<div class="wcai-product-grid" data-gallery-id="' . esc_attr($gallery_id) . '" style="display:flex;flex-wrap:nowrap;overflow-x:auto;gap:16px;padding:12px 0;margin:8px 0;scroll-behavior:smooth;">';

    foreach ($products as $p) {
        $product_id = $p['id'] ?? 0;
        $name = $p['name'] ?? 'Product';
        $image = $p['image'] ?? '';
        $url = $p['url'] ?? '#';
        $rating = $p['rating'] ?? 0;
        $rating_count = $p['rating_count'] ?? 0;
        $price = $p['price'] ?? ($symbol . ' ' . number_format($p['price_numeric'] ?? 0, 2));

        $is_variable = ($p['type'] ?? 'simple') === 'variable';
        $buy_url = $is_variable ? $url : add_query_arg('add-to-cart', $product_id, $url);
        $btn_text = $is_variable ? $choose_text : $buy_text;

        $sale_price = isset($p['sale_price']) ? (float)$p['sale_price'] : 0;
        $regular_price = isset($p['regular_price']) ? (float)$p['regular_price'] : 0;

        if ($sale_price > 0 && $regular_price > 0 && $sale_price < $regular_price) {
            $discount = round((1 - ($sale_price / $regular_price)) * 100);
            $price_html = '<span style="text-decoration:line-through;color:#9ca3af;font-size:12px;">'
                . $symbol . ' ' . number_format($regular_price, 2)
                . '</span> <span style="color:#dc2626;font-weight:700;">'
                . $symbol . ' ' . number_format($sale_price, 2) . '</span>';
            $sale_badge = '<div style="position:absolute;top:8px;right:8px;background:#dc2626;color:#fff;font-size:10px;font-weight:700;padding:2px 10px;border-radius:10px;z-index:2;">-' . $discount . '%</div>';
        } else {
            $price_html = $price;
            $sale_badge = '';
        }

        $html .= '
        <div style="flex:0 0 auto;width:180px;background:#fff;border-radius:12px;border:1px solid #e5e7eb;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.06);position:relative;">
            ' . $sale_badge . '
            <img src="' . esc_url($image) . '" alt="' . esc_attr($name) . '" loading="lazy" style="width:100%;height:140px;object-fit:cover;border-bottom:1px solid #e5e7eb;">
            <div style="padding:10px 12px;">
                <div style="font-size:12px;font-weight:600;color:#1f2937;line-height:1.3;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;height:32px;">' . esc_html($name) . '</div>
                <div style="font-size:13px;font-weight:700;margin-top:4px;">' . $price_html . '</div>
                <div style="font-size:11px;color:#6b7280;">⭐ ' . number_format($rating, 1) . ' (' . number_format($rating_count) . ')</div>
                <div style="display:flex;gap:6px;margin-top:8px;">
                    <a href="' . esc_url($buy_url) . '" target="_blank" rel="noopener noreferrer" style="flex:1;text-align:center;background:#22c55e;color:#fff;text-decoration:none;font-size:11px;padding:6px 0;border-radius:20px;font-weight:600;">' . $btn_text . '</a>
                </div>
                <button class="wcai-show-details" data-product-id="' . esc_attr($product_id) . '" data-product-name="' . esc_attr($name) . '" data-container-id="' . esc_attr($container_id) . '" style="width:100%;margin-top:6px;background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;border-radius:20px;padding:5px 0;font-size:11px;cursor:pointer;font-weight:500;">' . esc_html($show_more_text) . '</button>
            </div>
        </div>';
    }

    $html .= '</div>';
    $html .= '<div id="' . esc_attr($container_id) . '" style="display:none;background:#f9fafb;border-radius:12px;padding:16px;margin:12px 0;border:1px solid #e5e7eb;font-size:14px;line-height:1.6;"></div>';

    return $html;
}

add_action('wp_ajax_wcai_start_index', function () {
    if (!current_user_can('manage_options')) wp_send_json_error();
    wcai_start_background_index();
    wp_send_json_success(['message' => 'Indexing started']);
});

add_action('wp_ajax_wcai_force_index_now', function () {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    @set_time_limit(600);
    @ini_set('memory_limit', '1024M');
    delete_option('wcai_index_status');
    delete_option('wcai_index_offset');
    delete_option('wcai_index_total');
    wcai_start_background_index();
    wp_send_json_success(['message' => 'Force indexing started']);
});

add_action('wp_ajax_wcai_index_progress', function() {
    global $wpdb;
    if (!check_ajax_referer('wcai_index_nonce', '_ajax_nonce', false)) {
        wp_send_json_error(['message' => 'Nonce failed']);
        return;
    }

    $status = get_option('wcai_index_status', 'idle');
    if ($status === 'running') {
        @set_time_limit(20);
        wcai_cron_index_batch_handler();
    }

    $pt = $wpdb->prefix . 'wcai_products';
    $indexed = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i", $pt));
    $status = get_option('wcai_index_status', 'idle');
    $total = (int)get_option('wcai_index_total', 0);
    $percent = ($total > 0) ? round(($indexed / $total) * 100) : 0;
    if ($status === 'done') $percent = 100;

    wp_send_json_success([
        'indexed' => $indexed,
        'total' => $total ?: $indexed,
        'status' => $status,
        'percent' => $percent
    ]);
});

add_action('wp_ajax_nopriv_wcai_index_progress', function() {
    global $wpdb;
    if (!check_ajax_referer('wcai_index_nonce', '_ajax_nonce', false)) {
        wp_send_json_error(['message' => 'Nonce failed']);
        return;
    }

    $status = get_option('wcai_index_status', 'idle');
    if ($status === 'running') {
        @set_time_limit(20);
        wcai_cron_index_batch_handler();
    }

    $pt = $wpdb->prefix . 'wcai_products';
    $indexed = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i", $pt));
    $status = get_option('wcai_index_status', 'idle');
    $total = (int)get_option('wcai_index_total', 0);
    $percent = ($total > 0) ? round(($indexed / $total) * 100) : 0;
    if ($status === 'done') $percent = 100;

    wp_send_json_success([
        'indexed' => $indexed,
        'total' => $total ?: $indexed,
        'status' => $status,
        'percent' => $percent
    ]);
});

add_action('woocommerce_thankyou', 'wcai_convert_leads_on_order', 999, 1);
add_action('woocommerce_order_status_completed', 'wcai_convert_leads_on_order', 999, 1);
add_action('woocommerce_order_status_processing', 'wcai_convert_leads_on_order', 999, 1);

function wcai_convert_leads_on_order($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    if ($order->get_meta('_wcai_lead_processed') === 'yes') {
        error_log("WCAI: Order #$order_id lead already processed. Skipping.");
        return;
    }
    $order->update_meta_data('_wcai_lead_processed', 'yes');
    $order->save();

    global $wpdb;
    $table = $wpdb->prefix . 'wcai_logs';
    $gdpr_enabled = (int)get_option('wcai_gdpr_enabled', 1);

    $email_hash = '';
    $ip_hash = '';

    if ($gdpr_enabled) {
        $email = $order->get_billing_email();
        if (!empty($email)) $email_hash = wcai_hash_for_analytics($email);

        $ip = $order->get_customer_ip_address() ?: ($_SERVER['REMOTE_ADDR'] ?? '');
        if (!empty($ip)) $ip_hash = wcai_hash_for_analytics($ip);
    }

    $converted = 0;

    if (!empty($email_hash)) {
        $converted = $wpdb->update($table, ['lead_converted' => 1],
            ['user_email_hash' => $email_hash, 'lead_converted' => 0]);
    }
    if ($converted == 0 && !empty($ip_hash)) {
        $converted = $wpdb->update($table, ['lead_converted' => 1],
            ['user_ip_hash' => $ip_hash, 'lead_converted' => 0]);
    }

    if ($converted == 0) {
        $has_chat_history = 0;
        if (!empty($email_hash) || !empty($ip_hash)) {
            $where = []; $params = [];
            if (!empty($email_hash)) { $where[] = "user_email_hash = %s"; $params[] = $email_hash; }
            if (!empty($ip_hash)) { $where[] = "user_ip_hash = %s"; $params[] = $ip_hash; }
            if (!empty($where)) {
                $where_sql = implode(' OR ', $where);
                $params[] = 'ORDER #%';
                $has_chat_history = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table WHERE ($where_sql) AND user_query NOT LIKE %s AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)",
                    $params
                ));
            }
        }

        if ($has_chat_history > 0) {
            $wpdb->insert($table, [
                'user_query' => 'ORDER #' . $order_id . ' (purchase)',
                'bot_reply' => 'Customer completed purchase',
                'products_data' => json_encode([]),
                'credits_used' => 0, 'credits_remaining' => 0,
                'products_count' => 0, 'search_time' => 0,
                'user_email_hash' => $email_hash, 'user_ip_hash' => $ip_hash,
                'lead_converted' => 1, 'created_at' => current_time('mysql')
            ]);
        }
    }
}

function wcai_extra_security_check($message) {
    $blocked = [
        'http.request', 'cloudflare', 'waf', 'firewall', 'worker',
        'uri.path', 'http.referer', 'cf.client.bot',
        'contains "', 'not http.referer',
        'fetch(', 'XMLHttpRequest', 'async function',
        '<?php', '<?=', '?>', 'phpinfo',
        'eval(', 'system(', 'exec(', 'shell_exec',
        'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ', 'DROP ',
        '<script', 'javascript:', 'onerror=',
        'curl -', 'wget -', 'nc -', 'telnet ',
        '/wp-content/', '/etc/passwd', '/proc/',
    ];
    $lower = strtolower($message);
    foreach ($blocked as $word) {
        if (strpos($lower, strtolower($word)) !== false) return false;
    }
    $special = 0;
    $chars = ['{', '}', '(', ')', '=', ';', '.', ':', '/', '\\', '"', "'"];
    for ($i = 0; $i < strlen($message); $i++) {
        if (in_array($message[$i], $chars)) $special++;
    }
    if ($special > 20) return false;
    return true;
}

add_action('init', function() {
    if (isset($_POST['action']) && $_POST['action'] === 'wcai_send') {
        $message = $_POST['message'] ?? '';
        if (!wcai_extra_security_check($message)) {
            wp_send_json_error(['message' => '⚠️ Your message contains too many special characters. Please simplify your question.']);
            exit;
        }
    }
}, 1);


add_action('admin_menu', function () {
    add_menu_page('AI Chatbot', '🤖 AI Chatbot', 'manage_options', 'wcai', 'wcai_page_license', 'dashicons-format-chat', 56);
    add_submenu_page('wcai', 'License',   '🔑 License',   'manage_options', 'wcai',          'wcai_page_license');
    add_submenu_page('wcai', 'Index',     '📊 Index',     'manage_options', 'wcai-index',    'wcai_page_index');
    add_submenu_page('wcai', 'Settings',  '⚙️ Settings',  'manage_options', 'wcai-settings', 'wcai_page_settings');
    add_submenu_page('wcai', 'Design',    '🎨 Design',    'manage_options', 'wcai-design',   'wcai_page_design');
    add_submenu_page('wcai', 'Chat Logs', '📋 Request Data', 'manage_options', 'wcai-logs',  'wcai_page_logs');
    add_submenu_page('wcai', 'Analytics', '📈 Analytics', 'manage_options', 'wcai-analytics','wcai_render_analytics_page');
    add_submenu_page('wcai', 'GDPR',      '🔐 GDPR',      'manage_options', 'wcai-gdpr',     'wcai_page_gdpr');
    add_submenu_page('wcai', 'GDPR Consent Settings', '🔐 GDPR Consent', 'manage_options', 'wcai-gdpr-consent', 'wcai_render_gdpr_consent_page');
    add_submenu_page('wcai', 'Diagnostics', '🔧 Diagnostics', 'manage_options', 'wcai-diagnostics', 'wcai_page_diagnostics');
});


add_action('admin_enqueue_scripts', function($hook) {
    if (strpos($hook, 'wcai') === false) return;
    wp_enqueue_script('wcai-admin', WCAI_URL . 'assets/admin.js', ['jquery'], WCAI_VERSION, true);
});


function wcai_page_license() {
    delete_transient('wcai_license_cache');
    $key    = get_option('wcai_license_key', '');
    $status = wcai_get_license_status();
    $domain = wcai_normalize_domain(sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'] ?? '')));

    if (isset($_POST['activate_license']) && check_admin_referer('wcai_license_action')) {
        $result = wcai_activate_license(sanitize_text_field(wp_unslash($_POST['license_key'] ?? '')));
        if (!empty($result['valid'])) {
            delete_transient('wcai_license_cache');
            $status = wcai_get_license_status();
            echo '<div class="notice notice-success"><p>License activated! Credits: ' . esc_html($result['credits']) . '. <a href="' . esc_url(admin_url('admin.php?page=wcai-index')) . '">Go to Index →</a></p></div>';
            echo '<script>setTimeout(function(){ window.location.reload(); }, 500);</script>';
        } else {
            echo '<div class="notice notice-error"><p>' . esc_html($result['message'] ?? 'Invalid license') . '</p></div>';
        }
    }

    if (isset($_POST['deactivate_license']) && check_admin_referer('wcai_license_action')) {
        foreach (['wcai_license_key','wcai_license_data','wcai_domain','wcai_site_hash'] as $opt) delete_option($opt);
        delete_transient('wcai_license_cache');
        $key = ''; $status = ['valid' => false];
        echo '<div class="notice notice-success"><p>License deactivated.</p></div>';
        echo '<script>setTimeout(function(){ window.location.reload(); }, 500);</script>';
    }
    ?>
    <div class="wrap">
        <h1>License</h1>
        <div style="background:#eff6ff;border-left:4px solid #3b82f6;padding:14px 18px;border-radius:10px;margin:16px 0;font-size:13px;color:#1e40af;">
            <strong>v<?php echo esc_html(WCAI_VERSION); ?></strong> · Full product details: sizes, formats, material, colors, stitches, difficulty
            <br><strong>🔥 Multi-currency:</strong> WOOCS, WMC support · Real-time conversion · Symbol detection
            <br><strong>🔥 Real counts:</strong> Shows EXACT number of products in store
        </div>

        <?php if (!empty($key) && !empty($status['valid'])): ?>
        <div style="background:#f0fdf4;border-left:4px solid #22c55e;padding:20px;border-radius:10px;margin:16px 0;">
            <h3 style="margin:0 0 12px;">License Active</h3>
            <table class="form-table" style="margin:0;">
                <tr><th style="padding:6px 0;">Key</th><td><code><?php echo esc_html($key); ?></code></td></tr>
                <tr><th style="padding:6px 0;">Domain</th><td><?php echo esc_html($domain); ?></td></tr>
                <tr><th style="padding:6px 0;">Credits</th><td><strong><?php echo esc_html(number_format((int)(get_option('wcai_license_data', [])['credits'] ?? 0))); ?></strong></td></tr>
                <tr><th style="padding:6px 0;">Expires</th><td><?php echo esc_html($status['expires_at'] ?? '—'); ?></td></tr>
            </table>
            <div style="margin-top:14px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <form method="post" style="display:inline-block;">
                    <?php wp_nonce_field('wcai_license_action'); ?>
                    <button name="deactivate_license" class="button" style="background:#dc2626;color:#fff;border:none;">Deactivate</button>
                </form>
                <a href="https://chatbotagent.biz/my-account/" target="_blank"
                   style="background:#4F46E5;color:#fff;border:none;border-radius:6px;padding:6px 20px;text-decoration:none;font-weight:600;display:inline-block;font-size:13px;">
                    ⭐ Add Credits
                </a>
            </div>
        </div>
        <?php else: ?>
        <div style="background:#fef3c7;border-left:4px solid #f59e0b;padding:18px;border-radius:10px;margin:16px 0;">
            <p style="margin:0 0 6px;"><strong>No active license</strong></p>
            <p style="margin:0;font-size:13px;color:#78350f;">Your domain: <code><?php echo esc_html($domain); ?></code></p>
        </div>
        <form method="post" style="margin-top:16px;">
            <?php wp_nonce_field('wcai_license_action'); ?>
            <table class="form-table">
                <tr><th>License Key</th><td><input type="text" name="license_key" class="regular-text" placeholder="WC-AI-XXXXXXXXXXXXXX"></td></tr>
            </table>
            <button name="activate_license" class="button button-primary">Activate</button>
        </form>
        <?php endif; ?>
    </div>
    <?php
}


function wcai_page_index() {
    global $wpdb;
    $pt = $wpdb->prefix . 'wcai_products';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['wcai_start']) && check_admin_referer('wcai_index_form')) {
            delete_option('wcai_index_offset');
            delete_option('wcai_index_status');
            delete_option('wcai_index_total');
            update_option('wcai_index_status', 'running');
            update_option('wcai_index_offset', 0);
            update_option('wcai_index_total', 0);
            as_schedule_single_action(time(), 'wcai_cron_index_batch');
            wcai_cron_index_batch_handler();
        }
        if (isset($_POST['wcai_force']) && check_admin_referer('wcai_index_form')) {
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}wcai_products");
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}wcai_terms");
            delete_option('wcai_index_offset');
            delete_option('wcai_index_status');
            delete_option('wcai_index_total');
            update_option('wcai_index_status', 'running');
            update_option('wcai_index_offset', 0);
            update_option('wcai_index_total', 0);
            as_schedule_single_action(time(), 'wcai_cron_index_batch');
            wcai_cron_index_batch_handler();
        }
    }

    $indexed = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i", $pt));
    $status  = get_option('wcai_index_status', 'idle');
    $total   = (int)get_option('wcai_index_total', 0);
    $percent = ($total > 0) ? round(($indexed / $total) * 100) : 0;
    if ($status === 'done') $percent = 100;
    ?>
    <div class="wrap">
        <h1>📊 Product Index</h1>

        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:40px;margin:20px 0;text-align:center;">
            <div style="font-size:56px;font-weight:700;color:#4f46e5;margin-bottom:8px;font-family:monospace;">
                <span id="wcai-indexed"><?php echo esc_html(number_format($indexed)); ?></span> / <span id="wcai-total"><?php echo esc_html(number_format($total ?: $indexed)); ?></span>
            </div>
            <div style="font-size:18px;margin-bottom:24px;height:28px;display:flex;align-items:center;justify-content:center;">
                <span id="wcai-status-text">
                    <?php
                    if ($status === 'done') echo '✅ Done!';
                    elseif ($status === 'running') echo '⏳ Running... (' . esc_html($percent) . '%)';
                    else echo '⚪ Idle';
                    ?>
                </span>
            </div>
            <form method="post" style="display:inline;">
                <?php wp_nonce_field('wcai_index_form'); ?>
                <button type="submit" name="wcai_start" style="background:#4f46e5;color:#fff;border:none;border-radius:8px;padding:12px 32px;font-size:16px;font-weight:600;cursor:pointer;margin:0 8px;">▶️ Start</button>
            </form>
            <form method="post" style="display:inline;">
                <?php wp_nonce_field('wcai_index_form'); ?>
                <button type="submit" name="wcai_force" style="background:#f59e0b;color:#fff;border:none;border-radius:8px;padding:12px 32px;font-size:16px;font-weight:600;cursor:pointer;margin:0 8px;">🔥 Force</button>
            </form>
        </div>

        <div style="background:#eff6ff;border-left:4px solid #3b82f6;border-radius:10px;padding:14px 18px;margin:20px 0;font-size:13px;color:#1e40af;line-height:1.6;">
            <strong>ℹ️ How to use:</strong> Click "Start" - numbers update smoothly. Can close this page.
        </div>
    </div>

    <script>
    window.wcaiIndexData = {
        ajaxUrl: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
        nonce: '<?php echo esc_js(wp_create_nonce('wcai_index_nonce')); ?>',
        initialStatus: '<?php echo esc_js($status); ?>'
    };
    </script>
    <?php
}

function wcai_page_settings() {
    if (isset($_POST['save']) && check_admin_referer('wcai_settings_action')) {
        $merchant_prompt = sanitize_textarea_field(wp_unslash($_POST['merchant_prompt'] ?? ''));
        $max_chars = 4000;
        if (mb_strlen($merchant_prompt, 'UTF-8') > $max_chars) {
            $merchant_prompt = mb_substr($merchant_prompt, 0, $max_chars, 'UTF-8');
            echo '<div class="notice notice-warning"><p>⚠️ Instructions truncated to 4000 characters.</p></div>';
        }
        update_option('wcai_merchant_prompt', $merchant_prompt);
        update_option('wcai_welcome_message', sanitize_text_field(wp_unslash($_POST['welcome_message'] ?? '')));
        update_option('wcai_store_name', sanitize_text_field(wp_unslash($_POST['store_name'] ?? '')));
        update_option('wcai_show_chatbot', isset($_POST['show_chatbot']) ? 1 : 0);
        echo '<div class="notice notice-success"><p>✅ Settings saved!</p></div>';
    }

    $merchant_prompt = get_option('wcai_merchant_prompt', '');
    $welcome_message = get_option('wcai_welcome_message', 'Hi! How can I help you find products today?');
    $store_name = get_option('wcai_store_name', get_bloginfo('name'));
    $show_chatbot = get_option('wcai_show_chatbot', 1);
    ?>
    <div class="wrap">
        <h1>⚙️ Settings</h1>
        <div style="background:#f0fdf4;border-left:4px solid #22c55e;padding:14px 18px;border-radius:10px;margin:16px 0;font-size:13px;">
            <strong>📝 Store Instructions</strong> — max <strong>4000 characters</strong>
        </div>
        <form method="post">
            <?php wp_nonce_field('wcai_settings_action'); ?>
            <table class="form-table">
                <tr><th>Store Name</th><td><input type="text" name="store_name" value="<?php echo esc_attr($store_name); ?>" class="regular-text"></td></tr>
                <tr><th>Welcome Message</th><td><input type="text" name="welcome_message" value="<?php echo esc_attr($welcome_message); ?>" class="regular-text"></td></tr>
                <tr><th>Show Chatbot</th><td>
                    <label><input type="checkbox" name="show_chatbot" value="1" <?php checked($show_chatbot, 1); ?>> Enable chatbot on site</label>
                </td></tr>
                <tr><th>Store Instructions</th><td>
                    <textarea name="merchant_prompt" rows="10" style="width:100%;max-width:600px;font-family:monospace;font-size:13px;" maxlength="4000"><?php echo esc_textarea($merchant_prompt); ?></textarea>
                </td></tr>
            </table>
            <button name="save" class="button button-primary">💾 Save Settings</button>
        </form>
    </div>
    <?php
}

function wcai_get_design_defaults() {
    return [
        'primary_color' => '#4F46E5', 'secondary_color' => '#4338CA',
        'border_radius' => 16, 'widget_position' => 'right',
        'bubble_style' => 'rounded', 'glass_effect' => 0,
        'font_family' => 'system', 'button_text' => 'Search',
        'button_icon' => '💬', 'header_title' => '',
        'bg_color' => '#f9fafb', 'bot_msg_bg' => '#ffffff',
        'bot_msg_text' => '#1f2937', 'user_msg_text' => '#ffffff',
        'widget_size' => 'standard', 'shadow_intensity' => 'medium',
        'animation_style' => 'scale', 'show_online_badge' => 1, 'dark_mode' => 0,
    ];
}

function wcai_design_font_stack($font_family) {
    switch ($font_family) {
        case 'inter':   return "'Inter', system-ui, -apple-system, sans-serif";
        case 'poppins': return "'Poppins', system-ui, -apple-system, sans-serif";
        case 'georgia': return "Georgia, 'Times New Roman', serif";
        case 'arial':   return "Arial, Helvetica, sans-serif";
        default:        return "system-ui, -apple-system, BlinkMacSystemFont, sans-serif";
    }
}

function wcai_design_shadow($intensity) {
    switch ($intensity) {
        case 'light':  return '0 4px 20px rgba(0,0,0,.10)';
        case 'strong': return '0 25px 80px rgba(0,0,0,.42)';
        default:       return '0 20px 60px rgba(0,0,0,.25)';
    }
}

function wcai_design_box_dimensions($size) {
    switch ($size) {
        case 'compact': return ['width' => 360, 'min_h' => 220, 'max_h' => '50vh'];
        case 'large':   return ['width' => 480, 'min_h' => 300, 'max_h' => '68vh'];
        default:        return ['width' => 420, 'min_h' => 260, 'max_h' => '58vh'];
    }
}

function wcai_page_design() {
    $defaults = wcai_get_design_defaults();

    if (isset($_POST['save']) && check_admin_referer('wcai_design_action')) {
        update_option('wcai_design', [
            'primary_color'     => sanitize_hex_color(wp_unslash($_POST['primary_color'] ?? $defaults['primary_color'])),
            'secondary_color'   => sanitize_hex_color(wp_unslash($_POST['secondary_color'] ?? $defaults['secondary_color'])),
            'border_radius'     => (int) wp_unslash($_POST['border_radius'] ?? 16),
            'widget_position'   => sanitize_text_field(wp_unslash($_POST['widget_position'] ?? 'right')),
            'bubble_style'      => sanitize_text_field(wp_unslash($_POST['bubble_style'] ?? 'rounded')),
            'glass_effect'      => isset($_POST['glass_effect']) ? 1 : 0,
            'font_family'       => sanitize_text_field(wp_unslash($_POST['font_family'] ?? 'system')),
            'button_text'       => sanitize_text_field(wp_unslash($_POST['button_text'] ?? 'Search')),
            'button_icon'       => sanitize_text_field(wp_unslash($_POST['button_icon'] ?? '💬')),
            'header_title'      => sanitize_text_field(wp_unslash($_POST['header_title'] ?? '')),
            'bg_color'          => sanitize_hex_color(wp_unslash($_POST['bg_color'] ?? $defaults['bg_color'])),
            'bot_msg_bg'        => sanitize_hex_color(wp_unslash($_POST['bot_msg_bg'] ?? $defaults['bot_msg_bg'])),
            'bot_msg_text'      => sanitize_hex_color(wp_unslash($_POST['bot_msg_text'] ?? $defaults['bot_msg_text'])),
            'user_msg_text'     => sanitize_hex_color(wp_unslash($_POST['user_msg_text'] ?? $defaults['user_msg_text'])),
            'widget_size'       => sanitize_text_field(wp_unslash($_POST['widget_size'] ?? 'standard')),
            'shadow_intensity'  => sanitize_text_field(wp_unslash($_POST['shadow_intensity'] ?? 'medium')),
            'animation_style'   => sanitize_text_field(wp_unslash($_POST['animation_style'] ?? 'scale')),
            'show_online_badge' => isset($_POST['show_online_badge']) ? 1 : 0,
            'dark_mode'         => isset($_POST['dark_mode']) ? 1 : 0,
        ]);
        update_option('wcai_show_more_open_text', sanitize_text_field(wp_unslash($_POST['wcai_show_more_open_text'] ?? '📖 Show more...')));
        update_option('wcai_show_more_close_text', sanitize_text_field(wp_unslash($_POST['wcai_show_more_close_text'] ?? '📖 Hide details')));
        update_option('wcai_buy_button_text', sanitize_text_field(wp_unslash($_POST['wcai_buy_button_text'] ?? '🛒 Buy')));
        update_option('wcai_choose_options_button_text', sanitize_text_field(wp_unslash($_POST['wcai_choose_options_button_text'] ?? '🛒 Choose options')));
        update_option('wcai_view_button_text', sanitize_text_field(wp_unslash($_POST['wcai_view_button_text'] ?? 'View')));
        update_option('wcai_clear_button_text', sanitize_text_field(wp_unslash($_POST['wcai_clear_button_text'] ?? 'Clear')));
        update_option('wcai_input_placeholder', sanitize_text_field(wp_unslash($_POST['wcai_input_placeholder'] ?? 'Ask about products…')));
        echo '<div class="notice notice-success"><p>Design saved!</p></div>';
    }

    $d = array_merge($defaults, (array)get_option('wcai_design', []));
    $open_text = get_option('wcai_show_more_open_text', '📖 Show more...');
    $close_text = get_option('wcai_show_more_close_text', '📖 Hide details');
    $buy_text = get_option('wcai_buy_button_text', '🛒 Buy');
    $choose_options_text = get_option('wcai_choose_options_button_text', '🛒 Choose options');
    $view_text = get_option('wcai_view_button_text', 'View');
    $clear_text = get_option('wcai_clear_button_text', 'Clear');
    $input_placeholder = get_option('wcai_input_placeholder', 'Ask about products…');
    ?>
    <div class="wrap">
        <h1>Design</h1>
        <form method="post">
            <?php wp_nonce_field('wcai_design_action'); ?>
            <h2 class="title" style="margin-top:24px;">Colors</h2>
            <table class="form-table">
                <tr><th>Primary Color</th><td><input type="color" name="primary_color" value="<?php echo esc_attr($d['primary_color']); ?>"></td></tr>
                <tr><th>Secondary Color</th><td><input type="color" name="secondary_color" value="<?php echo esc_attr($d['secondary_color']); ?>"></td></tr>
                <tr><th>Chat Background</th><td><input type="color" name="bg_color" value="<?php echo esc_attr($d['bg_color']); ?>"></td></tr>
                <tr><th>Bot Bubble Background</th><td><input type="color" name="bot_msg_bg" value="<?php echo esc_attr($d['bot_msg_bg']); ?>"></td></tr>
                <tr><th>Bot Bubble Text Color</th><td><input type="color" name="bot_msg_text" value="<?php echo esc_attr($d['bot_msg_text']); ?>"></td></tr>
                <tr><th>User Bubble Text Color</th><td><input type="color" name="user_msg_text" value="<?php echo esc_attr($d['user_msg_text']); ?>"></td></tr>
                <tr><th>Dark Mode</th><td><input type="checkbox" name="dark_mode" value="1" <?php checked($d['dark_mode'],1); ?>></td></tr>
            </table>
            <h2 class="title" style="margin-top:24px;">Shape &amp; Layout</h2>
            <table class="form-table">
                <tr><th>Border Radius (px)</th><td><input type="number" name="border_radius" value="<?php echo esc_attr($d['border_radius']); ?>" min="0" max="50" style="width:80px;"></td></tr>
                <tr><th>Widget Position</th><td>
                    <select name="widget_position">
                        <option value="right" <?php selected($d['widget_position'],'right'); ?>>Right</option>
                        <option value="left"  <?php selected($d['widget_position'],'left');  ?>>Left</option>
                    </select>
                </td></tr>
                <tr><th>Widget Size</th><td>
                    <select name="widget_size">
                        <option value="compact"  <?php selected($d['widget_size'],'compact');  ?>>Compact</option>
                        <option value="standard" <?php selected($d['widget_size'],'standard'); ?>>Standard</option>
                        <option value="large"    <?php selected($d['widget_size'],'large');    ?>>Large</option>
                    </select>
                </td></tr>
                <tr><th>Bubble Style</th><td>
                    <select name="bubble_style">
                        <option value="rounded" <?php selected($d['bubble_style'],'rounded'); ?>>Rounded</option>
                        <option value="pill"    <?php selected($d['bubble_style'],'pill');    ?>>Pill</option>
                        <option value="sharp"   <?php selected($d['bubble_style'],'sharp');   ?>>Sharp</option>
                    </select>
                </td></tr>
                <tr><th>Shadow Intensity</th><td>
                    <select name="shadow_intensity">
                        <option value="light"  <?php selected($d['shadow_intensity'],'light');  ?>>Light</option>
                        <option value="medium" <?php selected($d['shadow_intensity'],'medium'); ?>>Medium</option>
                        <option value="strong" <?php selected($d['shadow_intensity'],'strong'); ?>>Strong</option>
                    </select>
                </td></tr>
                <tr><th>Open Animation</th><td>
                    <select name="animation_style">
                        <option value="scale" <?php selected($d['animation_style'],'scale'); ?>>Scale in</option>
                        <option value="slide" <?php selected($d['animation_style'],'slide'); ?>>Slide up</option>
                        <option value="fade"  <?php selected($d['animation_style'],'fade');  ?>>Fade in</option>
                        <option value="none"  <?php selected($d['animation_style'],'none');  ?>>None</option>
                    </select>
                </td></tr>
                <tr><th>Glass Effect</th><td><input type="checkbox" name="glass_effect" value="1" <?php checked($d['glass_effect'],1); ?>></td></tr>
            </table>
            <h2 class="title" style="margin-top:24px;">Typography &amp; Text</h2>
            <table class="form-table">
                <tr><th>Font</th><td>
                    <select name="font_family">
                        <option value="system"  <?php selected($d['font_family'],'system');  ?>>System UI (default)</option>
                        <option value="inter"   <?php selected($d['font_family'],'inter');   ?>>Inter</option>
                        <option value="poppins" <?php selected($d['font_family'],'poppins'); ?>>Poppins</option>
                        <option value="arial"   <?php selected($d['font_family'],'arial');   ?>>Arial</option>
                        <option value="georgia" <?php selected($d['font_family'],'georgia'); ?>>Georgia (serif)</option>
                    </select>
                </td></tr>
                <tr><th>Header Title</th><td><input type="text" name="header_title" value="<?php echo esc_attr($d['header_title']); ?>" class="regular-text" placeholder="<?php echo esc_attr(get_option('wcai_store_name', get_bloginfo('name'))); ?>"></td></tr>
                <tr><th>Toggle Button Text</th><td><input type="text" name="button_text" value="<?php echo esc_attr($d['button_text']); ?>" class="regular-text"></td></tr>
                <tr><th>Toggle Button Icon</th><td><input type="text" name="button_icon" value="<?php echo esc_attr($d['button_icon']); ?>" style="width:70px;text-align:center;"></td></tr>
                <tr><th>Show "Online" Badge</th><td><input type="checkbox" name="show_online_badge" value="1" <?php checked($d['show_online_badge'],1); ?>></td></tr>
            </table>
            <h2 class="title" style="margin-top:24px;">Product Details Button</h2>
            <table class="form-table">
                <tr><th>Open Button Text</th><td><input type="text" name="wcai_show_more_open_text" value="<?php echo esc_attr($open_text); ?>" class="regular-text" style="width:300px;"></td></tr>
                <tr><th>Close Button Text</th><td><input type="text" name="wcai_show_more_close_text" value="<?php echo esc_attr($close_text); ?>" class="regular-text" style="width:300px;"></td></tr>
            </table>
            <h2 class="title" style="margin-top:24px;">Product Buttons</h2>
            <table class="form-table">
                <tr><th>Buy Button Text</th><td><input type="text" name="wcai_buy_button_text" value="<?php echo esc_attr($buy_text); ?>" class="regular-text" style="width:300px;"></td></tr>
                <tr><th>Choose Options Button Text</th><td><input type="text" name="wcai_choose_options_button_text" value="<?php echo esc_attr($choose_options_text); ?>" class="regular-text" style="width:300px;"></td></tr>
                <tr><th>View Button Text</th><td><input type="text" name="wcai_view_button_text" value="<?php echo esc_attr($view_text); ?>" class="regular-text" style="width:300px;"></td></tr>
            </table>
            <h2 class="title" style="margin-top:24px;">Chat Controls</h2>
            <table class="form-table">
                <tr><th>Clear Button Text</th><td><input type="text" name="wcai_clear_button_text" value="<?php echo esc_attr($clear_text); ?>" class="regular-text" style="width:300px;"></td></tr>
                <tr><th>Input Placeholder</th><td><input type="text" name="wcai_input_placeholder" value="<?php echo esc_attr($input_placeholder); ?>" class="regular-text" style="width:300px;"></td></tr>
            </table>
            <button name="save" class="button button-primary">Save Design</button>
        </form>
    </div>
    <?php
}

function wcai_page_logs() {
    global $wpdb;
    $table = $wpdb->prefix . 'wcai_logs';

    $view_session = isset($_GET['session']) ? sanitize_text_field($_GET['session']) : '';

    if ($view_session) {
        $cols = $wpdb->get_col($wpdb->prepare("SHOW COLUMNS FROM %i", $table));
        if (in_array('session_id', $cols)) {
            if (isset($_POST['delete_session']) && check_admin_referer('wcai_delete_session_' . $view_session)) {
                $deleted = $wpdb->delete($table, ['session_id' => $view_session]);
                wp_safe_redirect(admin_url('admin.php?page=wcai-logs&deleted=' . (int)$deleted));
                exit;
            }

            $session_logs = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM %i WHERE session_id = %s ORDER BY id ASC",
                $table, $view_session
            ));

            $session_total = count($session_logs);
            $first_time = !empty($session_logs) ? $session_logs[0]->created_at : '—';
            $last_time  = !empty($session_logs) ? end($session_logs)->created_at : '—';
            ?>
            <div class="wrap">
                <h1>👤 Session: <code><?php echo esc_html(substr($view_session, 0, 8)); ?>…</code></h1>
                <p><a href="<?php echo esc_url(admin_url('admin.php?page=wcai-logs')); ?>" class="button">← Back to all logs</a></p>
                <?php if (isset($_GET['deleted'])): ?>
                    <div class="notice notice-success"><p>✅ Deleted <?php echo (int)$_GET['deleted']; ?> records.</p></div>
                <?php endif; ?>
                <?php if (empty($session_logs)): ?>
                    <div class="notice notice-warning"><p>No queries for this session.</p></div>
                <?php else: ?>
                    <div style="background:#eff6ff;border-left:4px solid #3b82f6;padding:14px 18px;border-radius:10px;margin:16px 0;">
                        <strong>Session summary:</strong>
                        <ul style="margin:8px 0 0 20px;">
                            <li>Total queries: <strong><?php echo esc_html($session_total); ?></strong></li>
                            <li>First query: <strong><?php echo esc_html($first_time); ?></strong></li>
                            <li>Last query: <strong><?php echo esc_html($last_time); ?></strong></li>
                        </ul>
                    </div>
                    <table class="wp-list-table widefat fixed striped">
                        <thead><tr>
                            <th width="50">ID</th>
                            <th width="150">Time</th>
                            <th>User Query</th>
                            <th>Bot Reply</th>
                            <th width="80">Products</th>
                            <th width="70">Credits</th>
                            <th width="60">Lead</th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($session_logs as $log): ?>
                            <tr>
                                <td><?php echo esc_html($log->id); ?></td>
                                <td style="font-size:12px;"><?php echo esc_html($log->created_at); ?></td>
                                <td><?php echo esc_html($log->user_query); ?></td>
                                <td><?php echo esc_html(wp_trim_words($log->bot_reply, 14)); ?></td>
                                <td><?php
                                    $c = is_array(json_decode($log->products_data, true)) ? count(json_decode($log->products_data, true)) : 0;
                                    echo $c ? '<span style="background:#4ade80;padding:2px 8px;border-radius:12px;font-size:12px;font-weight:bold;">' . esc_html($c) . '</span>' : '—';
                                ?></td>
                                <td style="font-size:12px;color:#6b7280;"><?php echo esc_html($log->credits_remaining); ?></td>
                                <td style="text-align:center;">
                                    <?php if (!empty($log->lead_converted)): ?>
                                        <span title="Customer purchased after chatting" style="font-size:16px;">✅</span>
                                    <?php else: ?>
                                        <span style="color:#9ca3af;font-size:11px;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div style="margin-top:20px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete all queries for this user?');">
                            <?php wp_nonce_field('wcai_delete_session_' . $view_session); ?>
                            <input type="hidden" name="delete_session" value="1">
                            <button type="submit" class="button" style="background:#dc2626;color:#fff;border:none;padding:8px 20px;border-radius:4px;cursor:pointer;font-weight:600;">
                                🗑️ Delete all data for this user (<?php echo esc_html($session_total); ?>)
                            </button>
                        </form>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('wcai_export_session_csv_action'); ?>
                            <input type="hidden" name="wcai_export_session_csv" value="1">
                            <input type="hidden" name="session_id" value="<?php echo esc_attr($view_session); ?>">
                            <button type="submit" class="button" style="background:#4F46E5;color:#fff;border:none;padding:8px 20px;border-radius:4px;cursor:pointer;font-weight:600;">
                                📥 Export CSV (<?php echo esc_html($session_total); ?>)
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
            <?php
            return;
        }
    }

    if (isset($_GET['delete_log']) && check_admin_referer('delete_log_' . (int)$_GET['delete_log'])) {
        $wpdb->delete($table, ['id' => (int) $_GET['delete_log']]);
        echo '<div class="notice notice-success"><p>✅ Log deleted!</p></div>';
    }

    if (isset($_POST['delete_all']) && check_admin_referer('wcai_logs_action')) {
        $wpdb->query($wpdb->prepare("TRUNCATE TABLE %i", $table));
        echo '<div class="notice notice-success"><p>Logs cleared.</p></div>';
    }

    $per_page = 20;
    $page     = max(1, (int) wp_unslash($_GET['paged'] ?? 1));
    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

    $where_sql = '';
    $where_params = [];
    if ($search !== '') {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where_sql = "WHERE user_query LIKE %s OR bot_reply LIKE %s";
        $where_params[] = $like;
        $where_params[] = $like;
    }

    $count_sql = "SELECT COUNT(*) FROM %i $where_sql";
    $count_params = array_merge([$table], $where_params);
    $total = (int)$wpdb->get_var($wpdb->prepare($count_sql, $count_params));

    $logs_sql = "SELECT * FROM %i $where_sql ORDER BY id DESC LIMIT %d OFFSET %d";
    $logs_params = array_merge([$table], $where_params, [$per_page, ($page - 1) * $per_page]);
    $logs = $wpdb->get_results($wpdb->prepare($logs_sql, $logs_params));
    ?>
    <div class="wrap">
        <h1>Chat Logs <span style="font-size:14px;font-weight:normal;color:#6b7280;"><?php echo esc_html(number_format($total)); ?> total</span></h1>
        <div style="background:#eff6ff;border-left:4px solid #3b82f6;border-radius:10px;padding:12px 14px;margin:12px 0;font-size:12px;">
            <strong>🔒 GDPR Notice:</strong> Email and IP data is stored as SHA-256 hashes for analytics only.
        </div>
        <div style="margin:16px 0; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <form method="get" style="display:inline; margin-right:6px;">
                <input type="hidden" name="page" value="wcai-logs">
                <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search queries and replies…" style="padding:6px 12px;border-radius:4px;border:1px solid #d1d5db;width:260px;">
                <button type="submit" class="button" style="padding:6px 16px;">🔍 Search</button>
                <?php if ($search !== ''): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wcai-logs')); ?>" class="button" style="padding:6px 12px;">✕ Clear</a>
                <?php endif; ?>
            </form>
            <form method="post" style="display:inline;" onsubmit="return confirm('Delete all logs?');">
                <?php wp_nonce_field('wcai_logs_action'); ?>
                <button name="delete_all" class="button" style="background:#dc2626;color:#fff;border:none;padding:8px 20px;border-radius:4px;cursor:pointer;font-weight:600;">
                    🗑️ Delete All (<?php echo number_format($total); ?>)
                </button>
            </form>
            <form method="post" style="display:inline;">
                <?php wp_nonce_field('wcai_export_csv_action'); ?>
                <input type="hidden" name="wcai_export_csv" value="1">
                <select name="export_filter" style="padding:6px 10px;border-radius:4px;border:1px solid #d1d5db;">
                    <option value="all">All logs (<?php echo number_format($total); ?>)</option>
                    <option value="success">Successful only</option>
                    <option value="failed">Failed only</option>
                    <option value="with_products">With products only</option>
                    <option value="empty">Empty (0 products) only</option>
                </select>
                <select name="export_period" style="padding:6px 10px;border-radius:4px;border:1px solid #d1d5db;">
                    <option value="all">All time</option>
                    <option value="7">Last 7 days</option>
                    <option value="30">Last 30 days</option>
                    <option value="90">Last 90 days</option>
                </select>
                <button type="submit" class="button" style="background:#4F46E5;color:#fff;border:none;padding:8px 20px;border-radius:4px;cursor:pointer;font-weight:600;">
                    📥 Export CSV
                </button>
            </form>
        </div>

        <?php if ($search !== ''): ?>
            <div style="background:#eff6ff;border-left:4px solid #3b82f6;padding:10px 14px;border-radius:8px;margin:12px 0;font-size:13px;">
                Showing <strong><?php echo esc_html(number_format($total)); ?></strong> result(s) for: <code><?php echo esc_html($search); ?></code>
            </div>
        <?php endif; ?>

        <?php if (empty($logs)): ?>
        <div class="notice notice-info"><p>No logs yet. Send a message to the chat to see them.</p></div>
        <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr>
                <th width="50">ID</th>
                <th width="150">Time</th>
                <th>User Query</th>
                <th>Bot Reply</th>
                <th width="80">Products</th>
                <th width="70">Credits</th>
                <th width="60">Lead</th>
                <th width="60">Delete</th>
            </tr></thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
            <tr>
                <td><?php echo esc_html($log->id); ?></td>
                <td style="font-size:12px;"><?php echo esc_html($log->created_at); ?></td>
                <td title="<?php echo esc_attr($log->user_query); ?>"><?php echo esc_html(wp_trim_words($log->user_query, 10)); ?></td>
                <td title="<?php echo esc_attr($log->bot_reply); ?>"><?php echo esc_html(wp_trim_words($log->bot_reply, 14)); ?></td>
                <td>
                    <?php
                    $c = is_array(json_decode($log->products_data, true)) ? count(json_decode($log->products_data, true)) : 0;
                    echo $c ? '<span style="background:#4ade80;padding:2px 8px;border-radius:12px;font-size:12px;font-weight:bold;">' . esc_html($c) . '</span>' : '—';
                    ?>
                </td>
                <td style="font-size:12px;color:#6b7280;"><?php echo esc_html($log->credits_remaining); ?></td>
                <td style="text-align:center;">
                    <?php if (!empty($log->lead_converted)): ?>
                        <span title="Customer purchased after chatting" style="font-size:16px;">✅</span>
                    <?php else: ?>
                        <span style="color:#9ca3af;font-size:11px;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('delete_log', $log->id), 'delete_log_' . $log->id)); ?>" onclick="return confirm('Delete this log?')" style="color:#dc2626;text-decoration:none;">🗑️</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div style="margin-top:16px;">
            <?php
            $pagination_args = [
                'total'     => ceil($total / $per_page),
                'current'   => $page,
                'base'      => add_query_arg('paged', '%#%'),
                'format'    => '',
                'prev_text' => '«',
                'next_text' => '»',
            ];
            if ($search !== '') $pagination_args['add_args'] = ['s' => $search];
            echo wp_kses_post(paginate_links($pagination_args));
            ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

function wcai_render_analytics_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'wcai_logs';

    $totals = $wpdb->get_row($wpdb->prepare("
        SELECT
            COUNT(*) as total_requests,
            SUM(CASE WHEN products_count > 0 THEN 1 ELSE 0 END) as successful_searches,
            SUM(credits_used) as total_credits,
            AVG(search_time) as avg_response_time
        FROM %i
    ", $table));

    $leads = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE lead_converted = 1", $table));
    $conversion_rate = ($totals && $totals->total_requests > 0 && $leads > 0) ? round(($leads / $totals->total_requests) * 100, 1) : 0;

    $real_orders = 0;
    if (class_exists('WooCommerce')) {
        $orders = wc_get_orders(['limit' => -1, 'return' => 'ids', 'status' => ['completed', 'processing']]);
        $real_orders = count($orders);
    }

    $top_queries = $wpdb->get_results($wpdb->prepare("
        SELECT user_query, COUNT(*) as count, AVG(products_count) as avg_products
        FROM %i WHERE user_query != '' AND user_query NOT LIKE %s
        GROUP BY user_query ORDER BY count DESC LIMIT 15
    ", $table, 'ORDER #%'));

    $empty_queries = $wpdb->get_results($wpdb->prepare("
        SELECT user_query, COUNT(*) as count
        FROM %i WHERE products_count = 0 AND user_query != '' AND user_query NOT LIKE %s
        GROUP BY user_query ORDER BY count DESC LIMIT 15
    ", $table, 'ORDER #%'));

    $daily_stats = $wpdb->get_results($wpdb->prepare("
        SELECT DATE(created_at) as date, COUNT(*) as requests, SUM(credits_used) as credits
        FROM %i WHERE created_at > DATE_SUB(NOW(), INTERVAL 14 DAY)
        GROUP BY DATE(created_at) ORDER BY date DESC
    ", $table));
    ?>
    <div class="wrap">
        <h1>Search Analytics</h1>
        <?php if ($totals && $totals->total_requests > 0): ?>
        <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:16px;margin:20px 0;">
            <div style="background:#fff;padding:16px 20px;border-radius:12px;border:1px solid #e5e7eb;">
                <div style="font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase;">Total Searches</div>
                <div style="font-size:24px;font-weight:700;color:#1f2937;margin-top:4px;"><?php echo esc_html(number_format($totals->total_requests ?? 0)); ?></div>
            </div>
            <div style="background:#fff;padding:16px 20px;border-radius:12px;border:1px solid #e5e7eb;">
                <div style="font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase;">With Products</div>
                <div style="font-size:24px;font-weight:700;color:#22c55e;margin-top:4px;"><?php echo esc_html(number_format($totals->successful_searches ?? 0)); ?></div>
            </div>
            <div style="background:#fff;padding:16px 20px;border-radius:12px;border:1px solid #e5e7eb;">
                <div style="font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase;">Credits Used</div>
                <div style="font-size:24px;font-weight:700;color:#4F46E5;margin-top:4px;"><?php echo esc_html(number_format($totals->total_credits ?? 0)); ?></div>
            </div>
            <div style="background:#fff;padding:16px 20px;border-radius:12px;border:1px solid #e5e7eb;">
                <div style="font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase;">Avg Response</div>
                <div style="font-size:24px;font-weight:700;color:#f59e0b;margin-top:4px;"><?php echo esc_html(number_format($totals->avg_response_time ?? 0, 2)); ?>s</div>
            </div>
            <div style="background:#fff;padding:16px 20px;border-radius:12px;border:1px solid #e5e7eb;<?php echo $leads > 0 ? 'border-color:#22c55e;' : ''; ?>">
                <div style="font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase;">🎯 Leads</div>
                <div style="font-size:24px;font-weight:700;color:<?php echo $leads > 0 ? '#22c55e' : '#9ca3af'; ?>;margin-top:4px;"><?php echo esc_html(number_format($leads)); ?></div>
                <div style="font-size:12px;color:#6b7280;margin-top:4px;">📊 Conversion: <?php echo esc_html($conversion_rate); ?>%</div>
            </div>
        </div>
        <?php if ($top_queries): ?>
            <div style="background:#fff;padding:16px 20px;border-radius:12px;border:1px solid #e5e7eb;margin:20px 0;">
                <h3 style="margin:0 0 12px;font-size:14px;color:#374151;">What Customers Are Searching For</h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th>Search Query</th><th width="120">Count</th><th width="120">Avg Products</th></tr></thead>
                    <tbody>
                        <?php foreach ($top_queries as $q): ?>
                            <tr>
                                <td><strong><?php echo esc_html($q->user_query); ?></strong></td>
                                <td><strong><?php echo esc_html(number_format($q->count)); ?></strong></td>
                                <td><?php echo esc_html(number_format(round($q->avg_products, 1))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <div style="text-align:center;margin:24px 0;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=wcai-logs')); ?>" style="display:inline-block;background:#4F46E5;color:#fff;text-decoration:none;padding:10px 28px;border-radius:8px;font-size:14px;font-weight:600;">📋 View Full Data</a>
        </div>
        <?php if ($empty_queries): ?>
            <div style="background:#fff;padding:16px 20px;border-radius:12px;border:1px solid #e5e7eb;margin:20px 0;">
                <h3 style="margin:0 0 12px;font-size:14px;color:#dc2626;">Customers Are Looking For These (But You Don't Have Them!)</h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th>Search Query</th><th width="120">Count</th></tr></thead>
                    <tbody>
                        <?php foreach ($empty_queries as $q): ?>
                            <tr><td><strong><?php echo esc_html($q->user_query); ?></strong></td><td><strong><?php echo esc_html(number_format($q->count)); ?></strong></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php if ($daily_stats): ?>
            <div style="background:#fff;padding:16px 20px;border-radius:12px;border:1px solid #e5e7eb;margin:20px 0;">
                <h3 style="margin:0 0 12px;font-size:14px;color:#374151;">Daily Activity (Last 14 Days)</h3>
                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(80px, 1fr));gap:6px;">
                    <?php foreach ($daily_stats as $day): ?>
                        <div style="background:#f9fafb;padding:8px;border-radius:6px;text-align:center;">
                            <div style="font-size:10px;color:#6b7280;"><?php echo esc_html(gmdate('M j', strtotime($day->date))); ?></div>
                            <div style="font-size:16px;font-weight:700;color:#1f2937;"><?php echo esc_html(number_format($day->requests)); ?></div>
                            <div style="font-size:9px;color:#6b7280;"><?php echo esc_html(number_format($day->credits)); ?> cr</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php else: ?>
            <div class="notice notice-info"><p>No search data yet. Once customers start using the chat, analytics will appear here.</p></div>
        <?php endif; ?>
        <div style="margin:20px 0;"><a href="<?php echo esc_url(admin_url('admin.php?page=wcai')); ?>" class="button">← Back to License</a></div>
    </div>
    <?php
}

function wcai_page_gdpr() {
    if (isset($_POST['save']) && check_admin_referer('wcai_gdpr_action')) {
        update_option('wcai_log_retention_days', (int)wp_unslash($_POST['log_retention_days'] ?? 90));
        update_option('wcai_gdpr_enabled', isset($_POST['gdpr_enabled']) ? 1 : 0);
        echo '<div class="notice notice-success"><p>GDPR settings saved!</p></div>';
    }
    $retention = (int)get_option('wcai_log_retention_days', 90);
    $gdpr_enabled = (int)get_option('wcai_gdpr_enabled', 1);

    global $wpdb;
    $table = $wpdb->prefix . 'wcai_logs';
    $total_logs = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i", $table));
    $old_logs = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM %i WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
        $table, $retention
    ));
    ?>
    <div class="wrap">
        <h1>🔐 GDPR & Privacy Settings</h1>
        <div style="background:#f0fdf4;border-left:4px solid #22c55e;padding:14px 18px;border-radius:10px;margin:16px 0;font-size:13px;">
            <strong>✅ v<?php echo esc_html(WCAI_VERSION); ?></strong> · All personal data is hashed · No emails/IPs sent to SaaS · Auto-delete old logs
        </div>
        <form method="post">
            <?php wp_nonce_field('wcai_gdpr_action'); ?>
            <table class="form-table">
                <tr><th>Enable GDPR Mode</th><td>
                    <input type="checkbox" name="gdpr_enabled" value="1" <?php checked($gdpr_enabled, 1); ?>>
                    <p class="description">Hashes all personal data before storing</p>
                </td></tr>
                <tr><th>Log Retention Period (days)</th><td>
                    <input type="number" name="log_retention_days" value="<?php echo esc_attr($retention); ?>" min="1" max="730">
                </td></tr>
            </table>
            <button name="save" class="button button-primary">Save GDPR Settings</button>
        </form>
        <h2 style="margin-top:24px;">📊 Data Overview</h2>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin:16px 0;">
            <div style="background:#fff;padding:16px;border:1px solid #e5e7eb;border-radius:12px;">
                <div style="font-size:12px;color:#6b7280;font-weight:600;">Total Logs Stored</div>
                <div style="font-size:24px;font-weight:700;margin-top:4px;"><?php echo esc_html(number_format($total_logs)); ?></div>
            </div>
            <div style="background:#fff;padding:16px;border:1px solid #e5e7eb;border-radius:12px;">
                <div style="font-size:12px;color:#6b7280;font-weight:600;">Ready for Deletion</div>
                <div style="font-size:24px;font-weight:700;color:#f59e0b;margin-top:4px;"><?php echo esc_html(number_format($old_logs)); ?></div>
            </div>
            <div style="background:#fff;padding:16px;border:1px solid #e5e7eb;border-radius:12px;">
                <div style="font-size:12px;color:#6b7280;font-weight:600;">Next Auto-Cleanup</div>
                <div style="font-size:14px;font-weight:700;margin-top:4px;">Daily (midnight)</div>
            </div>
        </div>
        <h2 style="margin-top:24px;">🔒 What's Protected</h2>
        <ul style="font-size:13px;color:#374151;line-height:1.8;">
            <li>✅ Email addresses are <strong>hashed with SHA-256</strong>, never stored in plain text</li>
            <li>✅ IP addresses are <strong>hashed</strong>, not used for tracking</li>
            <li>✅ <strong>No personal data sent to external SaaS</strong> - only domain hash</li>
            <li>✅ Old logs <strong>auto-delete</strong> after retention period</li>
            <li>✅ Chat history stored <strong>locally only in localStorage</strong></li>
        </ul>
    </div>
    <?php
}

function wcai_render_gdpr_consent_page() {
    if (isset($_POST['save_gdpr_consent']) && check_admin_referer('wcai_gdpr_consent_action')) {
        update_option('wcai_consent_enabled', isset($_POST['consent_enabled']) ? 1 : 0);
        update_option('wcai_consent_icon', sanitize_text_field(wp_unslash($_POST['consent_icon'] ?? '🔒')));
        update_option('wcai_consent_title', sanitize_text_field(wp_unslash($_POST['consent_title'] ?? 'Privacy Consent')));
        update_option('wcai_consent_text', wp_kses_post(wp_unslash($_POST['consent_text'] ?? '')));
        update_option('wcai_consent_checkbox_label', wp_kses_post(wp_unslash($_POST['consent_checkbox_label'] ?? '')));
        update_option('wcai_consent_button_text', sanitize_text_field(wp_unslash($_POST['consent_button_text'] ?? 'Continue to Chat →')));
        update_option('wcai_consent_footer_text', wp_kses_post(wp_unslash($_POST['consent_footer_text'] ?? '')));
        update_option('wcai_consent_learn_more_text', sanitize_text_field(wp_unslash($_POST['consent_learn_more_text'] ?? 'Learn more →')));
        update_option('wcai_consent_learn_more_url', esc_url_raw(wp_unslash($_POST['consent_learn_more_url'] ?? '#')));
        echo '<div class="notice notice-success"><p>✅ GDPR Consent settings saved!</p></div>';
    }

    $icon = get_option('wcai_consent_icon', '🔒');
    $title = get_option('wcai_consent_title', 'Privacy Consent');
    $text = get_option('wcai_consent_text', 'This chat uses AI to help you find products. By continuing, you agree to the processing of your messages.');
    $checkbox_label = get_option('wcai_consent_checkbox_label', '<strong>I agree</strong> to the processing of my messages for product search and recommendations.');
    $button_text = get_option('wcai_consent_button_text', 'Continue to Chat →');
    $footer_text = get_option('wcai_consent_footer_text', 'Your data is <strong>encrypted</strong> and never shared with third parties.');
    $learn_more_text = get_option('wcai_consent_learn_more_text', 'Learn more →');
    $learn_more_url = get_option('wcai_consent_learn_more_url', '#');
    $consent_enabled = (int)get_option('wcai_consent_enabled', 1);
    ?>
    <div class="wrap">
        <h1>🔐 GDPR Consent Settings</h1>
        <p style="color:#6b7280;font-size:14px;">Customize the consent banner that appears inside the chat widget.</p>
        <form method="post">
            <?php wp_nonce_field('wcai_gdpr_consent_action'); ?>
            <div style="background:#fff;padding:16px 20px;border-radius:12px;border:1px solid #e5e7eb;margin-top:20px;">
                <label style="display:flex;align-items:center;gap:10px;font-weight:600;cursor:pointer;">
                    <input type="checkbox" name="consent_enabled" value="1" <?php checked($consent_enabled, 1); ?> style="width:18px;height:18px;">
                    Enable GDPR Consent Banner
                </label>
                <p class="description" style="margin:8px 0 0 28px;">
                    When disabled, the consent banner will not be shown and consent will not be required.
                </p>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px;">
                <div style="background:#fff;padding:20px;border-radius:12px;border:1px solid #e5e7eb;">
                    <h3 style="margin-top:0;">📝 Text Settings</h3>
                    <table class="form-table" style="margin:0;">
                        <tr><th style="width:140px;">Icon</th><td><input type="text" name="consent_icon" value="<?php echo esc_attr($icon); ?>" style="width:60px;text-align:center;font-size:24px;"></td></tr>
                        <tr><th>Title</th><td><input type="text" name="consent_title" value="<?php echo esc_attr($title); ?>" class="regular-text"></td></tr>
                        <tr><th>Description Text</th><td><textarea name="consent_text" rows="3" class="large-text"><?php echo esc_textarea($text); ?></textarea></td></tr>
                        <tr><th>Checkbox Label</th><td><textarea name="consent_checkbox_label" rows="2" class="large-text"><?php echo esc_textarea($checkbox_label); ?></textarea></td></tr>
                        <tr><th>Button Text</th><td><input type="text" name="consent_button_text" value="<?php echo esc_attr($button_text); ?>" class="regular-text"></td></tr>
                        <tr><th>Footer Text</th><td><textarea name="consent_footer_text" rows="3" class="large-text"><?php echo esc_textarea($footer_text); ?></textarea></td></tr>
                    </table>
                </div>
                <div style="background:#fff;padding:20px;border-radius:12px;border:1px solid #e5e7eb;">
                    <h3 style="margin-top:0;">🔗 "Learn more" Link</h3>
                    <table class="form-table" style="margin:0;">
                        <tr><th style="width:140px;">Link Text</th><td><input type="text" name="consent_learn_more_text" value="<?php echo esc_attr($learn_more_text); ?>" class="regular-text"></td></tr>
                        <tr><th>Link URL</th><td>
                            <input type="url" name="consent_learn_more_url" value="<?php echo esc_attr($learn_more_url); ?>" class="regular-text" placeholder="https://example.com/privacy-policy">
                        </td></tr>
                    </table>
                    <div style="margin-top:20px;padding:16px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb;">
                        <h4 style="margin:0 0 8px;font-size:14px;">📱 Preview</h4>
                        <div style="background:#f3f4f6;border-radius:8px;padding:16px;font-size:13px;font-family:system-ui;text-align:center;">
                            <div style="font-size:32px;"><?php echo esc_html($icon); ?></div>
                            <div style="font-weight:700;margin:4px 0;"><?php echo esc_html($title); ?></div>
                            <div style="color:#6b7280;font-size:12px;margin-bottom:8px;"><?php echo wp_kses_post($text); ?></div>
                            <div style="background:#fff;border-radius:8px;padding:8px 12px;text-align:left;border:1px solid #e5e7eb;margin:8px 0;">
                                <input type="checkbox" checked disabled style="margin-right:8px;"> <?php echo wp_kses_post($checkbox_label); ?>
                            </div>
                            <div style="background:#4F46E5;color:#fff;padding:8px;border-radius:20px;margin:8px 0;opacity:0.7;"><?php echo esc_html($button_text); ?></div>
                            <div style="font-size:10px;color:#9ca3af;margin-top:8px;"><?php echo wp_kses_post($footer_text); ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div style="margin-top:20px;">
                <button name="save_gdpr_consent" class="button button-primary" style="padding:8px 32px;">💾 Save GDPR Consent Settings</button>
            </div>
        </form>
    </div>
    <?php
}

function wcai_page_diagnostics() {
    $results = [];
    $overall = true;

    $php_version = phpversion();
    $php_ok = version_compare($php_version, '7.4', '>=');
    $results['PHP Version'] = ['value' => $php_version, 'ok' => $php_ok, 'message' => $php_ok ? 'OK' : 'Minimum 7.4 required'];
    if (!$php_ok) $overall = false;

    $wp_version = get_bloginfo('version');
    $wp_ok = version_compare($wp_version, '5.8', '>=');
    $results['WordPress Version'] = ['value' => $wp_version, 'ok' => $wp_ok, 'message' => $wp_ok ? 'OK' : 'Minimum 5.8 required'];
    if (!$wp_ok) $overall = false;

    $wc_ok = class_exists('WooCommerce');
    $wc_version = $wc_ok ? WC()->version : 'Not installed';
    $results['WooCommerce'] = ['value' => $wc_version, 'ok' => $wc_ok, 'message' => $wc_ok ? 'OK' : 'WooCommerce not active!'];
    if (!$wc_ok) $overall = false;

    $memory_limit = ini_get('memory_limit');
    $memory_bytes = wp_convert_hr_to_bytes($memory_limit);
    $memory_ok = $memory_bytes >= 134217728;
    $results['Memory Limit'] = ['value' => $memory_limit, 'ok' => $memory_ok, 'message' => $memory_ok ? 'OK' : 'Minimum 128M recommended'];

    $max_exec = ini_get('max_execution_time');
    $exec_ok = $max_exec >= 30 || $max_exec == 0;
    $results['Max Execution Time'] = ['value' => $max_exec . 's', 'ok' => $exec_ok, 'message' => $exec_ok ? 'OK' : 'Minimum 30s recommended'];

    global $wpdb;
    $mysql_version = $wpdb->get_var("SELECT VERSION()");
    $mysql_ok = version_compare($mysql_version, '5.6', '>=');
    $results['MySQL Version'] = ['value' => $mysql_version, 'ok' => $mysql_ok, 'message' => $mysql_ok ? 'OK' : 'Minimum 5.6 required'];
    if (!$mysql_ok) $overall = false;

    $tables_ok = true;
    $tables_list = [];
    foreach (['wcai_products', 'wcai_terms', 'wcai_logs'] as $t) {
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->prefix . $t));
        if ($exists) {
            $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}{$t}");
            $tables_list[] = "✅ $t ($count rows)";
        } else {
            $tables_list[] = "❌ $t MISSING";
            $tables_ok = false;
        }
    }
    $results['Database Tables'] = ['value' => implode(' | ', $tables_list), 'ok' => $tables_ok, 'message' => $tables_ok ? 'All tables present' : 'Some tables missing!'];
    if (!$tables_ok) $overall = false;

    $license = wcai_get_license_status();
    $license_ok = !empty($license['valid']);
    $results['License'] = ['value' => $license_ok ? 'Active (Credits: ' . ($license['credits'] ?? '?') . ')' : 'Inactive', 'ok' => $license_ok, 'message' => $license_ok ? 'OK' : ($license['message'] ?? 'No license')];

    $curl_ok = function_exists('curl_version');
    $curl_version = $curl_ok ? curl_version()['version'] : 'Not available';
    $results['cURL'] = ['value' => $curl_version, 'ok' => $curl_ok, 'message' => $curl_ok ? 'OK' : 'cURL not available!'];

    $json_ok = function_exists('json_encode') && function_exists('json_decode');
    $results['JSON Extension'] = ['value' => $json_ok ? 'Available' : 'MISSING', 'ok' => $json_ok, 'message' => $json_ok ? 'OK' : 'JSON extension required!'];
    if (!$json_ok) $overall = false;

    $theme = wp_get_theme();
    $results['Active Theme'] = ['value' => $theme->get('Name') . ' v' . $theme->get('Version'), 'ok' => true, 'message' => 'Info'];

    $plugins = get_option('active_plugins');
    $results['Active Plugins'] = ['value' => count($plugins) . ' plugins', 'ok' => true, 'message' => 'Info'];

    $index_status = get_option('wcai_index_status', 'idle');
    $indexed = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i", $wpdb->prefix . 'wcai_products'));
    $results['Product Index'] = ['value' => "$indexed products indexed ($index_status)", 'ok' => $indexed > 0, 'message' => $indexed > 0 ? 'OK' : 'No products indexed!'];
    ?>
    <div class="wrap">
        <h1>🔧 System Diagnostics</h1>
        <div style="background:<?php echo $overall ? '#f0fdf4' : '#fef2f2'; ?>;border-left:4px solid <?php echo $overall ? '#22c55e' : '#dc2626'; ?>;padding:16px 20px;border-radius:10px;margin:16px 0;">
            <strong style="font-size:16px;"><?php echo $overall ? '✅ All systems OK' : '❌ Issues detected'; ?></strong>
        </div>
        <table class="wp-list-table widefat fixed striped" style="margin-top:16px;">
            <thead><tr><th width="200">Check</th><th>Value</th><th width="100">Status</th><th>Notes</th></tr></thead>
            <tbody>
                <?php foreach ($results as $name => $r): ?>
                <tr>
                    <td><strong><?php echo esc_html($name); ?></strong></td>
                    <td style="word-break:break-all;"><?php echo esc_html($r['value']); ?></td>
                    <td><span style="background:<?php echo $r['ok'] ? '#dcfce7' : '#fee2e2'; ?>;color:<?php echo $r['ok'] ? '#166534' : '#991b1b'; ?>;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;"><?php echo $r['ok'] ? '✅' : '❌'; ?></span></td>
                    <td style="font-size:12px;color:<?php echo $r['ok'] ? '#6b7280' : '#dc2626'; ?>;"><?php echo esc_html($r['message']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div style="margin:20px 0;">
            <button onclick="wcaiCopyReport()" class="button button-primary">📋 Copy Report</button>
            <span id="copy-msg" style="display:none;color:#22c55e;font-weight:600;line-height:30px;">✅ Copied!</span>
        </div>
        <textarea id="diagnostic-report" readonly style="width:100%;height:200px;font-family:monospace;font-size:12px;margin-top:10px;display:none;"><?php
            echo "=== AI Chatbot Diagnostic Report ===\n";
            echo "Site: " . home_url() . "\n";
            echo "Date: " . current_time('Y-m-d H:i:s') . "\n\n";
            foreach ($results as $name => $r) {
                echo str_pad($name . ':', 25) . $r['value'] . ' [' . ($r['ok'] ? 'OK' : 'FAIL') . "]\n";
            }
        ?></textarea>
    </div>
    <?php
}

add_action('admin_init', 'wcai_export_logs_csv');
function wcai_export_logs_csv() {
    if (empty($_POST['wcai_export_csv'])) return;
    if (!current_user_can('manage_options')) return;
    if (!check_admin_referer('wcai_export_csv_action')) return;

    global $wpdb;
    $table = $wpdb->prefix . 'wcai_logs';
    $filter = sanitize_text_field($_POST['export_filter'] ?? 'all');
    $period = sanitize_text_field($_POST['export_period'] ?? 'all');
    $where = [];

    switch ($filter) {
        case 'success': case 'with_products': $where[] = 'products_count > 0'; break;
        case 'failed': case 'empty': $where[] = 'products_count = 0'; break;
    }
    if ($period !== 'all') {
        $days = (int)$period;
        if ($days > 0) $where[] = $wpdb->prepare('created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)', $days);
    }
    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    $rows = $wpdb->get_results("SELECT * FROM $table $where_sql ORDER BY id ASC", ARRAY_A);
    if (empty($rows)) wp_die('No data to export. <a href="' . admin_url('admin.php?page=wcai-logs') . '">← Back</a>');

    $filename = 'wcai-logs-' . date('Y-m-d-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['ID', 'Time', 'User Query', 'Bot Reply', 'Products', 'Credits', 'Lead'], ';');

    foreach ($rows as $row) {
        $bot_reply = wp_strip_all_tags($row['bot_reply'] ?? '');
        $bot_reply = trim(preg_replace('/\s+/', ' ', $bot_reply));
        fputcsv($output, [
            $row['id'] ?? '', $row['created_at'] ?? '', $row['user_query'] ?? '',
            $bot_reply, $row['products_count'] ?? 0, $row['credits_used'] ?? 0,
            !empty($row['lead_converted']) ? 'Yes' : 'No',
        ], ';');
    }
    fclose($output);
    exit;
}

add_action('admin_init', 'wcai_export_session_csv');
function wcai_export_session_csv() {
    if (empty($_POST['wcai_export_session_csv'])) return;
    if (!current_user_can('manage_options')) return;
    if (!check_admin_referer('wcai_export_session_csv_action')) return;

    $session_id = wcai_validate_session_id(wp_unslash($_POST['session_id'] ?? ''));
    if (empty($session_id)) wp_die('Invalid session ID.');

    global $wpdb;
    $table = $wpdb->prefix . 'wcai_logs';
    $cols = $wpdb->get_col($wpdb->prepare("SHOW COLUMNS FROM %i", $table));
    if (!in_array('session_id', $cols)) wp_die('Session tracking not enabled.');

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM %i WHERE session_id = %s ORDER BY id ASC", $table, $session_id
    ), ARRAY_A);
    if (empty($rows)) wp_die('No data for this session.');

    $filename = 'wcai-session-' . substr($session_id, 0, 8) . '-' . date('Y-m-d-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['ID', 'Time', 'User Query', 'Bot Reply', 'Products', 'Credits', 'Lead'], ';');
    foreach ($rows as $row) {
        $bot_reply = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($row['bot_reply'] ?? '')));
        fputcsv($output, [
            $row['id'] ?? '', $row['created_at'] ?? '', $row['user_query'] ?? '',
            $bot_reply, $row['products_count'] ?? 0, $row['credits_used'] ?? 0,
            !empty($row['lead_converted']) ? 'Yes' : 'No',
        ], ';');
    }
    fclose($output);
    exit;
}

function wcai_validate_session_id($sid) {
    $sid = sanitize_text_field($sid);
    if (preg_match('/^[a-f0-9\-]{20,64}$/i', $sid)) return $sid;
    return '';
}

add_action('admin_footer', 'wcai_add_session_column_js');
function wcai_add_session_column_js() {
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'wcai-logs') === false) return;

    global $wpdb;
    $table = $wpdb->prefix . 'wcai_logs';
    $cols = $wpdb->get_col($wpdb->prepare("SHOW COLUMNS FROM %i", $table));
    if (!in_array('session_id', $cols)) return;

    $page = max(1, (int) wp_unslash($_GET['paged'] ?? 1));
    $per_page = 20;
    $logs = $wpdb->get_results($wpdb->prepare(
        "SELECT id, session_id FROM %i ORDER BY id DESC LIMIT %d OFFSET %d",
        $table, $per_page, ($page - 1) * $per_page
    ));

    $sessions = [];
    foreach ($logs as $log) $sessions[(int)$log->id] = $log->session_id;
    ?>
    <script>
    window.wcaiSessionData = {
        sessions: <?php echo json_encode($sessions); ?>,
        logsUrl: <?php echo json_encode(admin_url('admin.php?page=wcai-logs')); ?>
    };
    </script>
    <?php
}

add_action('plugins_loaded', function () {
    global $wpdb;
    $table = $wpdb->prefix . 'wcai_logs';
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if (!$exists) return;

    $cols = $wpdb->get_col($wpdb->prepare("SHOW COLUMNS FROM %i", $table));
    if (!in_array('session_id', $cols)) {
        $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD COLUMN session_id VARCHAR(64) DEFAULT '' AFTER search_time", $table));
        $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD INDEX idx_session (session_id)", $table));
    }
});

add_action('admin_init', function () {
    if (get_option('wcai_db_version') === WCAI_VERSION) return;
    global $wpdb;
    $pt = $wpdb->prefix . 'wcai_products';
    $cols = $wpdb->get_col("SHOW COLUMNS FROM {$pt}");
    if (empty($cols)) return;

    $needed = [
        'description'     => "TEXT AFTER category",
        'material'        => "VARCHAR(100) AFTER description",
        'colors'          => "INT DEFAULT 0 AFTER material",
        'stitches'        => "INT DEFAULT 0 AFTER stitches_dummy",
        'difficulty'      => "VARCHAR(50) AFTER stitches",
        'available_sizes' => "TEXT AFTER difficulty",
        'search_sizes'    => "TEXT AFTER available_sizes",
        'size_stock_data' => "TEXT AFTER search_sizes",
        'price_display'   => "VARCHAR(50) AFTER price_max",
    ];
    unset($needed['stitches']); // уже есть

    foreach ($needed as $col => $def) {
        if (!in_array($col, $cols)) {
            $wpdb->query("ALTER TABLE {$pt} ADD COLUMN {$col} {$def}");
        }
    }
    update_option('wcai_db_version', WCAI_VERSION);
});

function wcai_fetch_update_manifest() {
    $cache_key = 'wcai_update_manifest';
    $cached = get_transient($cache_key);
    if ($cached !== false) return $cached;

    $response = wp_remote_get(WCAI_UPDATE_MANIFEST, ['timeout' => 10, 'sslverify' => true]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        set_transient($cache_key, [], 30 * MINUTE_IN_SECONDS);
        return [];
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data) || empty($data['version'])) {
        set_transient($cache_key, [], 30 * MINUTE_IN_SECONDS);
        return [];
    }
    set_transient($cache_key, $data, 6 * HOUR_IN_SECONDS);
    return $data;
}

add_filter('pre_set_site_transient_update_plugins', function ($transient) {
    if (empty($transient->checked)) return $transient;
    $plugin_file = plugin_basename(__FILE__);
    if (!isset($transient->checked[$plugin_file])) return $transient;

    $current_version = $transient->checked[$plugin_file];
    $manifest = wcai_fetch_update_manifest();
    if (!$manifest || empty($manifest['version'])) return $transient;

    if (version_compare($current_version, $manifest['version'], '<')) {
        $obj = new stdClass();
        $obj->slug         = WCAI_UPDATE_SLUG;
        $obj->plugin       = $plugin_file;
        $obj->new_version  = $manifest['version'];
        $obj->url          = $manifest['homepage'] ?? 'https://chatbotagent.biz';
        $obj->package      = $manifest['download_url'] ?? '';
        $obj->requires     = $manifest['requires'] ?? '';
        $obj->tested       = $manifest['tested'] ?? '';
        $obj->requires_php = $manifest['requires_php'] ?? '';
        $obj->icons        = $manifest['icons'] ?? [];
        $transient->response[$plugin_file] = $obj;
    }
    return $transient;
});

add_filter('plugins_api', function ($result, $action, $args) {
    if ($action !== 'plugin_information') return $result;
    if (empty($args->slug) || $args->slug !== WCAI_UPDATE_SLUG) return $result;

    $manifest = wcai_fetch_update_manifest();
    if (!$manifest) return $result;

    $obj = new stdClass();
    $obj->name          = 'AI Chatbot for WooCommerce';
    $obj->slug          = WCAI_UPDATE_SLUG;
    $obj->version       = $manifest['version'] ?? '';
    $obj->author        = '<a href="https://chatbotagent.biz">chatbotagent.biz</a>';
    $obj->homepage      = $manifest['homepage'] ?? 'https://chatbotagent.biz';
    $obj->requires      = $manifest['requires'] ?? '';
    $obj->tested        = $manifest['tested'] ?? '';
    $obj->requires_php  = $manifest['requires_php'] ?? '';
    $obj->last_updated  = $manifest['last_updated'] ?? '';
    $obj->download_link = $manifest['download_url'] ?? '';
    $obj->sections      = $manifest['sections'] ?? [
        'description' => 'AI-powered product search for WooCommerce.',
        'changelog'   => 'See plugin homepage.',
    ];
    return $obj;
}, 10, 3);

add_filter('upgrader_source_selection', function ($source, $remote_source, $upgrader, $hook_extra) {
    if (empty($hook_extra['plugin'])) return $source;
    $plugin_file = plugin_basename(__FILE__);
    if ($hook_extra['plugin'] !== $plugin_file) return $source;

    global $wp_filesystem;
    $desired_slug = WCAI_UPDATE_SLUG;
    $source = trailingslashit($source);
    $basename = basename($source);
    if ($basename === $desired_slug) return $source;

    $new_source = trailingslashit($remote_source) . $desired_slug . '/';
    if ($wp_filesystem && $wp_filesystem->move($source, $new_source, true)) {
        return trailingslashit($new_source);
    }
    return $source;
}, 10, 4);

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $response = wp_remote_head(WCAI_UPDATE_ZIP, ['timeout' => 10, 'sslverify' => false]);
    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) == 200) {
        $links[] = sprintf('<a href="%s" target="_blank">⬇ Download Update</a>', esc_url(WCAI_UPDATE_ZIP));
    }
    return $links;
});

add_action('wp_footer', 'wcai_widget');
function wcai_widget() {
    if (!get_option('wcai_show_chatbot', 1)) return;
    if (!get_option('wcai_license_key') || !wcai_is_license_active()) return;

    $defaults = wcai_get_design_defaults();
    $d        = array_merge($defaults, (array)get_option('wcai_design', []));
    $pos      = $d['widget_position'] === 'left' ? 'left' : 'right';
    $glass    = $d['glass_effect'] ? 'blur(40px) saturate(200%)' : 'none';
    $br       = (int)$d['border_radius'];
    $pc       = esc_attr($d['primary_color']);
    $sc       = esc_attr($d['secondary_color']);
    $dark     = !empty($d['dark_mode']);
    $box_bg   = $dark ? '#1f2937' : '#ffffff';
    $bg_color = $dark ? '#111827' : $d['bg_color'];
    $bot_bg   = $dark ? '#374151' : $d['bot_msg_bg'];
    $bot_text = $dark ? '#f3f4f6' : $d['bot_msg_text'];
    $user_text= $d['user_msg_text'];
    $foot_bg  = $dark ? '#1f2937' : '#ffffff';
    $input_bg = $dark ? '#374151' : '#ffffff';
    $input_text=$dark ? '#f3f4f6' : '#111827';
    $input_border=$dark ? '#4b5563' : '#e5e7eb';

    $font_stack   = wcai_design_font_stack($d['font_family']);
    $shadow       = wcai_design_shadow($d['shadow_intensity']);
    if ($d['glass_effect']) $shadow = '0 8px 32px rgba(31, 38, 135, .2)';
    $dims         = wcai_design_box_dimensions($d['widget_size']);
    $header_title = $d['header_title'] !== '' ? $d['header_title'] : get_option('wcai_store_name', get_bloginfo('name'));
    $button_text  = $d['button_text'] !== '' ? $d['button_text'] : 'Search';
    $button_icon  = $d['button_icon'];
    $online_badge = !empty($d['show_online_badge']);

    wp_enqueue_style('wcai-widget', WCAI_URL . 'assets/widget.css', [], WCAI_VERSION);
    wp_add_inline_style('wcai-widget', sprintf(
        ':root{--wcai-primary:%s;--wcai-secondary:%s;--wcai-radius:%dpx;--wcai-font:%s;--wcai-box-bg:%s;--wcai-bg:%s;--wcai-bot-bg:%s;--wcai-bot-text:%s;--wcai-user-text:%s;--wcai-foot-bg:%s;--wcai-input-bg:%s;--wcai-input-text:%s;--wcai-input-border:%s;--wcai-shadow:%s;--wcai-glass:%s;--wcai-width:%dpx;--wcai-min-h:%dpx;--wcai-max-h:%s;}',
        $pc, $sc, $br, $font_stack, $box_bg, $bg_color, $bot_bg, $bot_text, $user_text,
        $foot_bg, $input_bg, $input_text, $input_border, $shadow, $glass,
        $dims['width'], $dims['min_h'], $dims['max_h']
    ));

    wp_enqueue_script('wcai-widget', WCAI_URL . 'assets/widget.js', [], WCAI_VERSION, true);
    wp_localize_script('wcai-widget', 'WCAI_CONFIG', [
        'ajaxUrl'      => admin_url('admin-ajax.php'),
        'nonce'        => wp_create_nonce('wcai_nonce'),
        'welcome'      => get_option('wcai_welcome_message', 'Hi! How can I help you find products today?'),
        'currency'     => wcai_get_active_currency(),
        'defaultCurrency' => get_woocommerce_currency(),
        'font'         => $font_stack,
        'licenseKey'   => get_option('wcai_license_key', ''),
        'siteHash'     => wcai_get_site_hash(),
        'domain'       => wcai_normalize_domain(home_url()),
        'showMoreOpen' => get_option('wcai_show_more_open_text', '📖 Show more...'),
        'showMoreClose'=> get_option('wcai_show_more_close_text', '📖 Hide details'),
    ]);
    ?>
    <button id="wcai-btn" data-pos="<?php echo esc_attr($pos); ?>">
        <?php if ($button_icon !== ''): ?><span><?php echo esc_html($button_icon); ?></span><?php endif; ?>
        <span><?php echo esc_html($button_text); ?></span>
    </button>

    <div id="wcai-box" data-pos="<?php echo esc_attr($pos); ?>">
        <div class="wcai-head">
            <span class="wcai-head-title">
                <span><?php echo esc_html($header_title); ?></span>
                <?php if ($online_badge): ?>
                <span class="wcai-online-dot" title="Online"></span>
                <span class="wcai-online-label">Online</span>
                <?php endif; ?>
            </span>
            <div class="wcai-head-btns">
                <button id="wcai-clear" title="Clear history" style="font-size:14px!important;"><?php echo esc_html(get_option('wcai_clear_button_text', 'Clear')); ?></button>
                <button id="wcai-close" title="Close">×</button>
            </div>
        </div>
        <div class="wcai-msgs" id="wcai-msgs"></div>
        <div class="wcai-foot">
            <input type="text" id="wcai-input" placeholder="<?php echo esc_attr(get_option('wcai_input_placeholder', 'Ask about products…')); ?>">
            <button id="wcai-send">Send</button>
        </div>
    </div>
    <?php
}

add_shortcode('ai_chatbot', 'wcai_shortcode');
function wcai_shortcode() {
    if (!get_option('wcai_show_chatbot', 1)) return '<p style="color:#6b7280;font-size:14px;">🤖 Chatbot is currently disabled.</p>';
    if (!get_option('wcai_license_key') || !wcai_is_license_active()) return '<p style="color:#dc2626;font-size:14px;">AI Chatbot is not activated.</p>';

    $defaults = wcai_get_design_defaults();
    $d   = array_merge($defaults, (array)get_option('wcai_design', []));
    $pc  = esc_attr($d['primary_color']);
    $sc  = esc_attr($d['secondary_color']);
    $br  = (int)$d['border_radius'];
    $dark        = !empty($d['dark_mode']);
    $box_bg      = $dark ? '#1f2937' : '#ffffff';
    $bg_color    = $dark ? '#111827' : $d['bg_color'];
    $bot_bg      = $dark ? '#374151' : $d['bot_msg_bg'];
    $bot_text    = $dark ? '#f3f4f6' : $d['bot_msg_text'];
    $user_text   = $d['user_msg_text'];
    $font_stack  = wcai_design_font_stack($d['font_family']);
    $shadow      = wcai_design_shadow($d['shadow_intensity']);
    if ($d['glass_effect']) $shadow = '0 8px 32px rgba(31, 38, 135, .2)';
    $header_title = $d['header_title'] !== '' ? $d['header_title'] : get_option('wcai_store_name', get_bloginfo('name'));
    $online_badge = !empty($d['show_online_badge']);

    wp_enqueue_style('wcai-widget', WCAI_URL . 'assets/widget.css', [], WCAI_VERSION);
    wp_enqueue_script('wcai-widget', WCAI_URL . 'assets/widget.js', [], WCAI_VERSION, true);

    ob_start();
    ?>
    <div class="wcai-sc-wrap" style="width:100%;max-width:440px;margin:20px auto;font-family:<?php echo esc_attr($font_stack); ?>;">
        <div style="display:flex;flex-direction:column;background:<?php echo esc_attr($box_bg); ?>;border-radius:<?php echo esc_attr($br); ?>px;box-shadow:<?php echo esc_attr($shadow); ?>;overflow:hidden;border:1px solid #e5e7eb;height:520px;">
            <div style="background:<?php echo esc_attr($pc); ?>;color:#fff;padding:13px 16px;display:flex;justify-content:space-between;align-items:center;font-weight:600;flex-shrink:0;">
                <span style="display:flex;align-items:center;gap:7px;">
                    <span><?php echo esc_html($header_title); ?></span>
                    <?php if ($online_badge): ?>
                    <span style="width:8px;height:8px;border-radius:50%;background:#4ade80;box-shadow:0 0 0 2px rgba(255,255,255,.4);flex-shrink:0;"></span>
                    <span style="font-size:11px;font-weight:400;opacity:.85;">Online</span>
                    <?php endif; ?>
                </span>
                <button id="wcai-clear" style="background:none;border:none;color:#fff;cursor:pointer;font-size:13px;padding:3px 8px;border-radius:6px;"><?php echo esc_html(get_option('wcai_clear_button_text', 'Clear')); ?></button>
            </div>
            <div id="wcai-msgs" style="flex:1;overflow-y:auto;padding:14px;background:<?php echo esc_attr($bg_color); ?>;display:flex;flex-direction:column;gap:10px;"></div>
            <div style="display:flex;padding:10px;border-top:1px solid #e5e7eb;background:<?php echo esc_attr($box_bg); ?>;gap:7px;flex-shrink:0;">
                <input id="wcai-input" type="text" placeholder="<?php echo esc_attr(get_option('wcai_input_placeholder', 'Ask about products…')); ?>" style="flex:1;padding:9px 13px;border:1px solid #e5e7eb;background:#fff;color:#111827;border-radius:22px;outline:none;font-size:14px;font-family:<?php echo esc_attr($font_stack); ?>;">
                <button id="wcai-send" style="background:<?php echo esc_attr($pc); ?>;color:#fff;border:none;border-radius:22px;padding:8px 18px;cursor:pointer;font-weight:600;font-size:14px;">Send</button>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

add_action('wp_footer', function() {
    if (!get_option('wcai_license_key') || !wcai_is_license_active()) return;
    if (!get_option('wcai_consent_enabled', 1)) return;

    $icon = get_option('wcai_consent_icon', '🔒');
    $title = get_option('wcai_consent_title', 'Privacy Consent');
    $text = get_option('wcai_consent_text', 'This chat uses AI to help you find products. By continuing, you agree to the processing of your messages.');
    $checkbox_label = get_option('wcai_consent_checkbox_label', '<strong>I agree</strong> to the processing of my messages.');
    $button_text = get_option('wcai_consent_button_text', 'Continue to Chat →');
    $footer_text = get_option('wcai_consent_footer_text', 'Your data is <strong>encrypted</strong> and never shared.');
    $learn_more_text = get_option('wcai_consent_learn_more_text', 'Learn more →');
    $learn_more_url = get_option('wcai_consent_learn_more_url', '#');
    ?>
    <style>
    .wcai-consent-overlay{position:absolute!important;top:0;left:0;right:0;bottom:0;background:rgba(255,255,255,.98);backdrop-filter:blur(10px);z-index:99999999;display:flex;flex-direction:column;justify-content:center;align-items:center;padding:20px;border-radius:inherit;animation:wcaiConsentFadeIn .3s ease-out;box-sizing:border-box;overflow:hidden}
    .wcai-consent-overlay.hidden{display:none!important}
    .wcai-consent-card{background:#fff;border-radius:16px;padding:24px 20px;max-width:360px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.12);text-align:center;box-sizing:border-box}
    .wcai-consent-card .wcai-consent-icon{font-size:42px;margin-bottom:8px;display:block;line-height:1}
    .wcai-consent-card h3{margin:0 0 6px;font-size:17px;font-weight:700;color:#1f2937}
    .wcai-consent-card .wcai-consent-text{margin:0 0 14px;font-size:13px;line-height:1.5;color:#6b7280}
    .wcai-consent-card .wcai-consent-text a{color:#4F46E5;text-decoration:underline;font-weight:500}
    .wcai-consent-card .wcai-consent-checkbox-wrap{display:flex;align-items:flex-start;gap:10px;text-align:left;margin:12px 0 16px;padding:10px 12px;background:#f9fafb;border-radius:10px;border:1px solid #e5e7eb;cursor:pointer}
    .wcai-consent-card .wcai-consent-checkbox-wrap:hover{background:#f3f4f6}
    .wcai-consent-card .wcai-consent-checkbox-wrap input[type="checkbox"]{width:18px;height:18px;flex-shrink:0;margin-top:1px;accent-color:#4F46E5;cursor:pointer}
    .wcai-consent-card .wcai-consent-checkbox-wrap label{font-size:13px;color:#374151;cursor:pointer;line-height:1.4}
    .wcai-consent-btn{background:#4F46E5;color:#fff;border:none;border-radius:30px;padding:11px 28px;font-size:14px;font-weight:600;cursor:pointer;transition:all .25s;width:100%;opacity:.4;pointer-events:none;box-sizing:border-box}
    .wcai-consent-btn.active{opacity:1;pointer-events:auto}
    .wcai-consent-btn.active:hover{background:#4338CA;transform:translateY(-2px);box-shadow:0 8px 25px rgba(79,70,229,.3)}
    .wcai-consent-small{font-size:11px;color:#9ca3af;margin-top:10px;line-height:1.4}
    @keyframes wcaiConsentFadeIn{from{opacity:0;transform:scale(.96)}to{opacity:1;transform:scale(1)}}
    #wcai-box{position:fixed!important;overflow:hidden!important}
    #wcai-box.wcai-open{display:flex!important}
    </style>
    <script>
    (function() {
        var CONSENT_KEY = 'wcai_gdpr_consent_given';
        var CONSENT_VERSION = '1.0';
        var consentData = {
            icon: <?php echo json_encode($icon); ?>,
            title: <?php echo json_encode($title); ?>,
            text: <?php echo json_encode($text); ?>,
            checkboxLabel: <?php echo json_encode($checkbox_label); ?>,
            buttonText: <?php echo json_encode($button_text); ?>,
            footerText: <?php echo json_encode($footer_text); ?>,
            learnMoreText: <?php echo json_encode($learn_more_text); ?>,
            learnMoreUrl: <?php echo json_encode($learn_more_url); ?>
        };

        function hasUserConsent() {
            try {
                var saved = localStorage.getItem(CONSENT_KEY);
                if (!saved) return false;
                var data = JSON.parse(saved);
                return data.version === CONSENT_VERSION && data.consent === true;
            } catch(e) { return false; }
        }
        function setUserConsent() {
            localStorage.setItem(CONSENT_KEY, JSON.stringify({
                consent: true, version: CONSENT_VERSION, timestamp: new Date().toISOString()
            }));
        }
        function removeConsentOverlay() {
            var overlay = document.querySelector('.wcai-consent-overlay');
            if (overlay) {
                overlay.classList.add('hidden');
                setTimeout(function() { if (overlay.parentNode) overlay.parentNode.removeChild(overlay); }, 400);
            }
        }
        function createConsentBanner(chatBox) {
            if (!chatBox || document.querySelector('.wcai-consent-overlay') || hasUserConsent()) return;
            chatBox.style.position = 'relative';

            var overlay = document.createElement('div');
            overlay.className = 'wcai-consent-overlay';

            var learnMoreHtml = '';
            if (consentData.learnMoreUrl && consentData.learnMoreUrl !== '#') {
                learnMoreHtml = '<a href="' + consentData.learnMoreUrl + '" target="_blank">' + consentData.learnMoreText + '</a>';
            } else {
                learnMoreHtml = '<a href="#" onclick="alert(\'Your data is hashed and stored locally.\'); return false;">' + consentData.learnMoreText + '</a>';
            }

            overlay.innerHTML =
                '<div class="wcai-consent-card">' +
                    '<span class="wcai-consent-icon">' + consentData.icon + '</span>' +
                    '<h3>' + consentData.title + '</h3>' +
                    '<p class="wcai-consent-text">' + consentData.text + ' ' + learnMoreHtml + '</p>' +
                    '<div class="wcai-consent-checkbox-wrap">' +
                        '<input type="checkbox" id="wcai-consent-checkbox">' +
                        '<label for="wcai-consent-checkbox">' + consentData.checkboxLabel + '</label>' +
                    '</div>' +
                    '<button class="wcai-consent-btn" id="wcai-consent-btn" disabled>' + consentData.buttonText + '</button>' +
                    '<div class="wcai-consent-small">' + consentData.footerText + '</div>' +
                '</div>';

            chatBox.appendChild(overlay);

            var checkbox = document.getElementById('wcai-consent-checkbox');
            var consentBtn = document.getElementById('wcai-consent-btn');
            if (!checkbox || !consentBtn) return;

            checkbox.addEventListener('change', function() {
                if (this.checked) {
                    consentBtn.classList.add('active');
                    consentBtn.disabled = false;
                } else {
                    consentBtn.classList.remove('active');
                    consentBtn.disabled = true;
                }
            });
            var wrap = document.querySelector('.wcai-consent-checkbox-wrap');
            if (wrap) {
                wrap.addEventListener('click', function(e) {
                    if (e.target.tagName !== 'INPUT') {
                        checkbox.checked = !checkbox.checked;
                        checkbox.dispatchEvent(new Event('change'));
                    }
                });
            }
            consentBtn.addEventListener('click', function() {
                if (!checkbox.checked) return;
                setUserConsent();
                removeConsentOverlay();
            });
            if (hasUserConsent()) removeConsentOverlay();
        }
        function watchChatOpen() {
            var chatBox = document.getElementById('wcai-box');
            if (!chatBox) return;
            var observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                        var isOpen = chatBox.classList.contains('wcai-open');
                        if (isOpen && !hasUserConsent()) {
                            setTimeout(function() { createConsentBanner(chatBox); }, 50);
                        } else if (isOpen && hasUserConsent()) {
                            removeConsentOverlay();
                        }
                    }
                });
            });
            observer.observe(chatBox, { attributes: true, attributeFilter: ['class'] });
        }
        document.addEventListener('DOMContentLoaded', function() {
            var checkInterval = setInterval(function() {
                var chatBox = document.getElementById('wcai-box');
                if (chatBox) {
                    clearInterval(checkInterval);
                    watchChatOpen();
                    if (chatBox.classList.contains('wcai-open') && !hasUserConsent()) {
                        setTimeout(function() { createConsentBanner(chatBox); }, 100);
                    }
                }
            }, 200);
            var btnInterval = setInterval(function() {
                var btn = document.getElementById('wcai-btn');
                if (btn) {
                    clearInterval(btnInterval);
                    btn.addEventListener('click', function() {
                        setTimeout(function() {
                            var box = document.getElementById('wcai-box');
                            if (box && !hasUserConsent()) createConsentBanner(box);
                        }, 150);
                    });
                }
            }, 300);
        });
    })();
    </script>
    <?php
}, 999);

add_action('wp_footer', 'wcai_glass_overlay_output', 99999);
function wcai_glass_overlay_output() {
    if (!get_option('wcai_show_chatbot', 1)) return;
    if (!get_option('wcai_license_key') || !wcai_is_license_active()) return;

    $design = array_merge(wcai_get_design_defaults(), (array)get_option('wcai_design', []));
    if (empty($design['glass_effect'])) return;
    ?>
    <style>
    body.wcai-glass-active::before{content:'';position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.15);z-index:9999998;pointer-events:none;backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);opacity:0;transition:opacity .3s ease;}
    body.wcai-glass-active.wcai-chat-open::before{opacity:1;}
    </style>
    <script>
    (function() {
        'use strict';
        function activateGlass() {
            var box = document.getElementById('wcai-box');
            var btn = document.getElementById('wcai-btn');
            var closeBtn = document.getElementById('wcai-close');
            if (!box) return false;
            document.body.classList.add('wcai-glass-active');
            function openChat() { document.body.classList.add('wcai-chat-open'); }
            function closeChat() { document.body.classList.remove('wcai-chat-open'); }
            if (btn && !btn.dataset.wcaiGlassBound) {
                btn.dataset.wcaiGlassBound = '1';
                btn.addEventListener('click', openChat);
            }
            if (closeBtn && !closeBtn.dataset.wcaiGlassBound) {
                closeBtn.dataset.wcaiGlassBound = '1';
                closeBtn.addEventListener('click', closeChat);
            }
            if (box.classList.contains('wcai-open')) openChat();
            return true;
        }
        if (!activateGlass()) {
            var observer = new MutationObserver(function() { if (activateGlass()) observer.disconnect(); });
            observer.observe(document.body, { childList: true, subtree: true });
            var attempts = 0;
            var interval = setInterval(function() {
                if (activateGlass() || ++attempts > 50) clearInterval(interval);
            }, 200);
        }
    })();
    </script>
    <?php
}

add_action('wp', function () {
    if (!function_exists('is_checkout') || !is_checkout()) return;
    if (is_wc_endpoint_url('order-received')) return;
    remove_action('wp_footer', 'wcai_widget');
});

add_filter('wcai_catalog_info', 'wcai_enrich_catalog_info', 10, 1);
function wcai_enrich_catalog_info($catalog_info) {
    global $wpdb;
    $postmeta = $wpdb->postmeta;
    $attributes = $wpdb->get_col($wpdb->prepare("
        SELECT DISTINCT meta_key FROM {$postmeta}
        WHERE meta_key LIKE %s AND meta_key NOT LIKE %s AND meta_key NOT LIKE %s
        LIMIT 50
    ", 'pa\_%', '%\_%\_id', '%\_%\_name'));

    $attribute_names = [];
    foreach ($attributes as $key) {
        $clean = preg_replace('/^pa_/', '', $key);
        $clean = trim(str_replace('_', ' ', $clean));
        if ($clean !== '' && strlen($clean) <= 40) $attribute_names[] = $clean;
    }
    $attribute_names = array_values(array_unique($attribute_names));
    if (!empty($attribute_names)) $catalog_info .= "Attributes: " . implode(', ', $attribute_names) . "\n";
    return $catalog_info;
}

add_filter('wcai_catalog_info', 'wcai_enrich_catalog_info_variations', 20, 1);
function wcai_enrich_catalog_info_variations($catalog_info) {
    global $wpdb;
    $pt = $wpdb->prefix . 'wcai_products';
    $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i", $pt));
    if ($total > 0) $catalog_info .= "Total indexed products: {$total}\n";

    $price_range = $wpdb->get_row($wpdb->prepare("SELECT MIN(price) as min_price, MAX(price_max) as max_price FROM %i", $pt));
    if ($price_range && $price_range->min_price !== null && $price_range->max_price !== null) {
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';
        $catalog_info .= "Price range: " . round($price_range->min_price, 2) . " – " . round($price_range->max_price, 2) . " {$currency}\n";
    }
    $top_brands = $wpdb->get_col($wpdb->prepare("SELECT brand FROM %i WHERE brand != '' GROUP BY brand ORDER BY COUNT(*) DESC LIMIT 10", $pt));
    if (!empty($top_brands)) $catalog_info .= "Top brands: " . implode(', ', $top_brands) . "\n";

    $top_categories = $wpdb->get_col($wpdb->prepare("SELECT category FROM %i WHERE category != '' GROUP BY category ORDER BY COUNT(*) DESC LIMIT 10", $pt));
    if (!empty($top_categories)) $catalog_info .= "Top categories: " . implode(', ', $top_categories) . "\n";
    return $catalog_info;
}