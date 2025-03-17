<?php
/**
 * Plugin Name: TavTheme Simple Multi Currency for WooCommerce
 * Plugin URI: https://tavtheme.com/en/product/tav-simple-currency/
 * Description: A simple multi-currency plugin for WooCommerce that allows each product to have its own currency and shows prices in the user's local currency.
 * Version: 1.0.0
 * Author: TavTheme
 * Author URI: https://tavtheme.com/
 * Text Domain: tavtheme-simple-multi-currency-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 4.0
 * WC tested up to: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('TAV_SIMPLE_CURRENCY_VERSION', '1.0.0');
define('TAV_SIMPLE_CURRENCY_FILE', __FILE__);
define('TAV_SIMPLE_CURRENCY_PATH', plugin_dir_path(__FILE__));
define('TAV_SIMPLE_CURRENCY_URL', plugin_dir_url(__FILE__));

class TAV_Simple_Currency {
    private static $instance = null;
    private $current_currency = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
        
        // Force currency to be applied early
        add_action('wp', array($this, 'force_apply_currency'));
        
        // Check and fix currency settings on every page load
        add_action('init', array($this, 'check_currency_settings'), 5);
        
        // Load text domain for translations
        add_action('plugins_loaded', array($this, 'load_textdomain'));
    }

    /**
     * Load plugin text domain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'tavtheme-simple-multi-currency-for-woocommerce',
            false,
            dirname(plugin_basename(TAV_SIMPLE_CURRENCY_FILE)) . '/languages'
        );
    }

    public function init() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Initialize session if not started
        if (!session_id()) {
            session_start();
        }
        
        // Set EUR as the base currency - IMPORTANT: This is the main setting
        $base_currency = 'EUR';
        
        // Override WooCommerce default currency to always use EUR as base
        update_option('woocommerce_currency', $base_currency);
        
        // Get current currency from session if set
        if (isset($_SESSION['tav_currency'])) {
            $this->current_currency = $_SESSION['tav_currency'];
        } elseif (function_exists('WC') && WC()->session && WC()->session->get('chosen_currency')) {
            // Try to get from WooCommerce session as fallback
            $this->current_currency = WC()->session->get('chosen_currency');
            $_SESSION['tav_currency'] = $this->current_currency;
        } elseif (is_product()) {
            // If we're on a product page, use that product's currency
            global $post;
            $product_id = $post->ID;
            $product_currency = get_post_meta($product_id, '_product_currency', true);
            
            if ($product_currency) {
                $this->current_currency = $product_currency;
                $_SESSION['tav_currency'] = $product_currency;
                if (function_exists('WC') && WC()->session) {
                    WC()->session->set('chosen_currency', $product_currency);
                }
            }
        }
        
        // If still no currency, use the base currency (EUR)
        if (!$this->current_currency) {
            $this->current_currency = $base_currency;
            $_SESSION['tav_currency'] = $base_currency;
            if (function_exists('WC') && WC()->session) {
                WC()->session->set('chosen_currency', $base_currency);
            }
        }
        
        // Debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Initialized with currency: " . $this->current_currency . " (Base currency: " . $base_currency . ")");
        }

        // Add product fields
        add_action('woocommerce_product_options_pricing', array($this, 'add_currency_fields'));
        add_action('woocommerce_process_product_meta', array($this, 'save_currency_fields'));

        // Remove currency switcher since each product will use its own currency
        // add_action('wp_footer', array($this, 'add_currency_switcher'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Handle currency switching
        add_action('wp_ajax_tav_switch_currency', array($this, 'ajax_switch_currency'));
        add_action('wp_ajax_nopriv_tav_switch_currency', array($this, 'ajax_switch_currency'));

        // Override WooCommerce currency
        add_filter('woocommerce_currency', array($this, 'change_woocommerce_currency'), 999);

        // Modify prices
        add_filter('woocommerce_product_get_price', array($this, 'modify_price'), 10, 2);
        add_filter('woocommerce_product_get_regular_price', array($this, 'modify_price'), 10, 2);
        add_filter('woocommerce_product_get_sale_price', array($this, 'modify_price'), 10, 2);
        add_filter('woocommerce_get_price_html', array($this, 'modify_price_html'), 10, 2);
        
        // Modify cart item prices
        add_filter('woocommerce_cart_item_price', array($this, 'modify_cart_item_price'), 10, 3);
        add_filter('woocommerce_cart_item_subtotal', array($this, 'modify_cart_item_subtotal'), 10, 3);
        
        // Add currency info to order
        add_action('woocommerce_checkout_create_order', array($this, 'add_currency_to_order'), 10, 2);
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_order_currency'), 10, 1);
        
        // Additional hooks to ensure currency is applied everywhere
        add_filter('woocommerce_calculated_total', array($this, 'modify_cart_total'), 10, 2);
        add_filter('woocommerce_cart_totals_order_total_html', array($this, 'modify_cart_total_html'), 10, 1);
        add_filter('woocommerce_get_variation_price', array($this, 'modify_variation_price'), 10, 4);
        add_filter('woocommerce_get_variation_prices', array($this, 'modify_variation_prices'), 10, 3);
        add_filter('woocommerce_available_payment_gateways', array($this, 'update_payment_gateways_currency'), 10, 1);
        
        // Additional hooks for cart and checkout
        add_filter('woocommerce_cart_subtotal', array($this, 'modify_cart_subtotal'), 10, 3);
        add_filter('woocommerce_cart_contents_total', array($this, 'modify_cart_contents_total'), 10, 1);
        add_filter('woocommerce_cart_shipping_method_full_label', array($this, 'modify_shipping_label'), 10, 2);
        add_filter('woocommerce_cart_tax_totals', array($this, 'modify_tax_totals'), 10, 2);
        add_filter('woocommerce_coupon_get_discount_amount', array($this, 'modify_coupon_amount'), 10, 5);
        
        // Ensure mini cart is updated
        add_filter('woocommerce_add_to_cart_fragments', array($this, 'update_mini_cart_fragments'), 10, 1);
        
        // Ensure correct currency in emails
        add_filter('woocommerce_email_order_items_table', array($this, 'modify_email_order_items'), 10, 2);
        
        // Ensure correct currency in order review
        add_filter('woocommerce_review_order_before_payment', array($this, 'ensure_checkout_currency'), 10);
        add_filter('woocommerce_checkout_update_order_review', array($this, 'update_checkout_on_currency_change'), 10, 1);
        
        // Fix for cart totals display
        add_filter('woocommerce_get_formatted_order_total', array($this, 'modify_formatted_order_total'), 10, 2);
        add_filter('woocommerce_price_format', array($this, 'modify_price_format'), 10, 2);
        add_filter('formatted_woocommerce_price', array($this, 'modify_formatted_price'), 10, 5);
        add_filter('wc_price', array($this, 'modify_wc_price'), 10, 3);
        add_filter('woocommerce_price_html', array($this, 'ensure_price_html_currency'), 10, 2);
        
        // Override cart total rows
        add_filter('woocommerce_cart_total', array($this, 'modify_cart_total_display'), 10, 1);
        add_action('wp_footer', array($this, 'add_cart_currency_fix_script'));
        
        // Fix admin product page currency labels
        add_filter('woocommerce_currency_symbol', array($this, 'modify_currency_symbol'), 10, 2);
        
        // Fix payment gateway currency
        add_filter('woocommerce_order_get_currency', array($this, 'modify_order_currency'), 10, 2);
        add_filter('woocommerce_paypal_args', array($this, 'modify_paypal_args'), 10, 2);
        add_filter('woocommerce_gateway_title', array($this, 'modify_gateway_title'), 10, 2);
        
        // Enhanced payment gateway support
        add_filter('woocommerce_get_order_currency', array($this, 'modify_get_order_currency'), 999, 2);
        add_filter('woocommerce_before_checkout_process', array($this, 'before_checkout_process'), 10);
        add_filter('woocommerce_checkout_posted_data', array($this, 'modify_checkout_posted_data'), 10, 1);
        
        // Support for specific payment gateways
        add_filter('woocommerce_zarinpal_args', array($this, 'modify_zarinpal_args'), 10, 2);
        add_filter('woocommerce_mellat_args', array($this, 'modify_mellat_args'), 10, 2);
        add_filter('woocommerce_saman_args', array($this, 'modify_saman_args'), 10, 2);
        add_filter('woocommerce_parsian_args', array($this, 'modify_parsian_args'), 10, 2);
        add_filter('woocommerce_mabna_args', array($this, 'modify_mabna_args'), 10, 2);
        add_filter('woocommerce_idpay_args', array($this, 'modify_idpay_args'), 10, 2);
        add_filter('woocommerce_zibal_args', array($this, 'modify_zibal_args'), 10, 2);
        add_filter('woocommerce_nextpay_args', array($this, 'modify_nextpay_args'), 10, 2);
        add_filter('woocommerce_payping_args', array($this, 'modify_payping_args'), 10, 2);
        
        // International payment gateways support
        add_filter('woocommerce_mollie_args', array($this, 'modify_mollie_args'), 10, 2);
        add_filter('woocommerce_mollie_payment_parameters', array($this, 'modify_mollie_payment_parameters'), 10, 2);
        add_filter('mollie-payments-for-woocommerce_order_request_data', array($this, 'modify_mollie_order_request_data'), 10, 2);
        add_filter('mollie_wc_gateway_payment_object_data', array($this, 'modify_mollie_payment_object_data'), 10, 3);
        
        // Additional international gateways
        add_filter('woocommerce_stripe_request_body', array($this, 'modify_stripe_request_body'), 10, 2);
        add_filter('wc_stripe_payment_intent_params', array($this, 'modify_stripe_payment_intent_params'), 10, 2);
        add_filter('woocommerce_braintree_transaction_data', array($this, 'modify_braintree_transaction_data'), 10, 3);
        add_filter('woocommerce_square_payment_request', array($this, 'modify_square_payment_request'), 10, 3);
        add_filter('woocommerce_amazon_payments_args', array($this, 'modify_amazon_payments_args'), 10, 2);
        add_filter('woocommerce_klarna_payments_args', array($this, 'modify_klarna_payments_args'), 10, 2);
        
        // Force currency in checkout
        add_action('woocommerce_checkout_init', array($this, 'force_checkout_currency'), 5);
        
        // Force Mollie to use the correct currency
        add_filter('woocommerce_currency', array($this, 'change_woocommerce_currency'), 999);
        add_filter('option_woocommerce_currency', array($this, 'force_woocommerce_currency_option'), 999);
        
        // Debug - log currency information
        add_action('woocommerce_before_checkout_process', array($this, 'log_currency_debug_info'), 10);
        
        // Handle product currency on add to cart
        add_filter('woocommerce_add_to_cart', array($this, 'handle_product_currency_on_add_to_cart'), 10, 6);
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_product_currency_to_cart_item'), 10, 3);
        add_filter('woocommerce_get_cart_item_from_session', array($this, 'get_cart_item_from_session'), 10, 2);
        
        // Add filter to prevent infinite AJAX refreshes
        add_filter('woocommerce_update_order_review_fragments', array($this, 'prevent_infinite_refresh'), 10, 1);
        
        // Prevent adding products with different currencies to cart
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_cart_currency'), 10, 3);
        
        // Add notice about product currency
        add_action('woocommerce_before_add_to_cart_button', array($this, 'display_product_currency_notice'));
        
        // Add IP-based currency detection
        add_action('wp_enqueue_scripts', array($this, 'enqueue_geolocation_scripts'));
        add_action('wp_ajax_tav_get_user_currency', array($this, 'ajax_get_user_currency'));
        add_action('wp_ajax_nopriv_tav_get_user_currency', array($this, 'ajax_get_user_currency'));
    }

    public function enqueue_scripts() {
        wp_enqueue_script('jquery');
        
        // Register and enqueue our custom JS
        wp_register_script('tav-currency-switcher', TAV_SIMPLE_CURRENCY_URL . 'js/currency-switcher.js', array('jquery'), TAV_SIMPLE_CURRENCY_VERSION, true);
        
        // Create the js file if it doesn't exist
        $js_dir = TAV_SIMPLE_CURRENCY_PATH . 'js';
        if (!file_exists($js_dir)) {
            mkdir($js_dir, 0755, true);
        }
        
        // Enqueue the script
        wp_enqueue_script('tav-currency-switcher');
        
        // Add variables for the AJAX request
        wp_localize_script('tav-currency-switcher', 'tav_currency_vars', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tav_switch_currency_nonce')
        ));
        
        // Enqueue frontend CSS
        wp_enqueue_style('tav-currency-frontend', TAV_SIMPLE_CURRENCY_URL . 'assets/css/frontend.css', array(), TAV_SIMPLE_CURRENCY_VERSION);
        
        // Add admin CSS if in admin
        if (is_admin()) {
            wp_enqueue_style('tav-currency-admin', TAV_SIMPLE_CURRENCY_URL . 'assets/css/admin.css', array(), TAV_SIMPLE_CURRENCY_VERSION);
        }
    }

    public function ajax_switch_currency() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'tav_switch_currency_nonce')) {
            wp_send_json_error('Invalid nonce');
            exit;
        }
        
        // Get currency
        if (isset($_POST['currency'])) {
            $currency = sanitize_text_field($_POST['currency']);
            
            // Validate currency
            $rates = $this->get_exchange_rates();
            if (isset($rates[$currency])) {
                // Set session
                $_SESSION['tav_currency'] = $currency;
                $this->current_currency = $currency;
                
                // Debug log
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("Currency switched to: " . $currency);
                }
                
                // Clear WooCommerce cart cache
                if (function_exists('WC')) {
                    WC()->session->set('chosen_currency', $currency);
                    
                    // Force clear all currency-related caches
                    $this->clear_currency_caches($currency);
                    
                    // Recalculate totals
                    WC()->cart->calculate_totals();
                }
                
                wp_send_json_success();
                exit;
            }
        }
        
        wp_send_json_error('Invalid currency');
        exit;
    }

    public function add_currency_switcher() {
        $rates = $this->get_exchange_rates();
        
        // If we don't have a current currency set, try to get it from the cart
        if (!$this->current_currency && function_exists('WC') && WC()->cart && !WC()->cart->is_empty()) {
            $cart_items = WC()->cart->get_cart();
            foreach ($cart_items as $cart_item) {
                if (isset($cart_item['product_currency'])) {
                    $this->current_currency = $cart_item['product_currency'];
                    $_SESSION['tav_currency'] = $cart_item['product_currency'];
                    if (function_exists('WC') && WC()->session) {
                        WC()->session->set('chosen_currency', $cart_item['product_currency']);
                    }
                    break;
                }
            }
        }
        
        // If still no currency, use the first product's currency
        if (!$this->current_currency && function_exists('WC') && WC()->cart && !WC()->cart->is_empty()) {
            $cart_items = WC()->cart->get_cart();
            foreach ($cart_items as $cart_item) {
                $product_id = $cart_item['product_id'];
                $product_currency = get_post_meta($product_id, '_product_currency', true);
                if ($product_currency) {
                    $this->current_currency = $product_currency;
                    $_SESSION['tav_currency'] = $product_currency;
                    if (function_exists('WC') && WC()->session) {
                        WC()->session->set('chosen_currency', $product_currency);
                    }
                    break;
                }
            }
        }
        
        // If still no currency, use the WooCommerce default
        if (!$this->current_currency) {
            $this->current_currency = get_woocommerce_currency();
            $_SESSION['tav_currency'] = get_woocommerce_currency();
            if (function_exists('WC') && WC()->session) {
                WC()->session->set('chosen_currency', get_woocommerce_currency());
            }
        }
        
        $current_currency = $this->current_currency;
        
        echo '<div class="tav-currency-switcher">';
        echo '<select>';
        
        foreach ($rates as $code => $rate) {
            $selected = ($code === $current_currency) ? 'selected' : '';
            echo '<option value="' . esc_attr($code) . '" ' . $selected . '>' . esc_html($code) . '</option>';
        }
        
        echo '</select>';
        echo '</div>';
    }

    public function change_woocommerce_currency($currency) {
        if (is_admin() && !wp_doing_ajax()) {
            // In admin, show the base currency (EUR) unless we're in an AJAX call
            return 'EUR';
        }
        
        return $this->current_currency;
    }

    public function add_currency_fields() {
        global $post;
        
        echo '<div class="options_group">';
        
        // Currency field
        woocommerce_wp_select(
            array(
                'id' => '_product_currency',
                'label' => __('Product Currency', 'tavtheme-simple-multi-currency-for-woocommerce'),
                'description' => __('Select the currency for this product. This product can only be purchased in this currency.', 'tavtheme-simple-multi-currency-for-woocommerce'),
                'desc_tip' => true,
                'options' => $this->get_currency_options(),
                'value' => get_post_meta($post->ID, '_product_currency', true)
            )
        );
        
        // Base price field
        woocommerce_wp_text_input(
            array(
                'id' => '_base_price',
                'label' => __('Base Price', 'tavtheme-simple-multi-currency-for-woocommerce'),
                'description' => __('Enter the base price in the selected currency. This will be the actual price used for this product.', 'tavtheme-simple-multi-currency-for-woocommerce'),
                'desc_tip' => true,
                'type' => 'text',
                'data_type' => 'price',
                'value' => get_post_meta($post->ID, '_base_price', true)
            )
        );
        
        echo '</div>';
    }

    public function save_currency_fields($post_id) {
        if (isset($_POST['_product_currency'])) {
            update_post_meta($post_id, '_product_currency', sanitize_text_field($_POST['_product_currency']));
        }
        if (isset($_POST['_base_price'])) {
            update_post_meta($post_id, '_base_price', (float)$_POST['_base_price']);
        }
    }

    public function modify_price($price, $product) {
        if (is_admin() && !wp_doing_ajax()) {
            return $price;
        }

        $product_id = $product->get_id();
        $base_currency = get_post_meta($product_id, '_product_currency', true);
        $base_price = get_post_meta($product_id, '_base_price', true);

        if (!$base_currency || !$base_price) {
            return $price;
        }

        // If we don't have a current currency set, use the product's currency
        if (!$this->current_currency) {
            $this->current_currency = $base_currency;
            $_SESSION['tav_currency'] = $base_currency;
        }

        // If the current currency matches the product's currency, return the base price
        if ($this->current_currency === $base_currency) {
            return $base_price;
        }

        // Otherwise convert from base currency to current currency
        return $this->convert_price($base_price, $base_currency, $this->current_currency);
    }

    public function modify_price_html($price_html, $product) {
        if (is_admin() && !wp_doing_ajax()) {
            return $price_html;
        }

        $product_id = $product->get_id();
        $base_currency = get_post_meta($product_id, '_product_currency', true);
        $base_price = get_post_meta($product_id, '_base_price', true);

        if (!$base_currency || !$base_price) {
            return $price_html;
        }

        // Get user's currency from session if available (set by geolocation script)
        $user_currency = isset($_SESSION['tav_user_currency']) ? $_SESSION['tav_user_currency'] : null;
        
        // If no user currency detected, try to detect it now
        if (!$user_currency) {
            $user_currency = $this->detect_user_currency();
            
            // If still no currency, use the base currency (EUR)
            if (!$user_currency) {
                $user_currency = 'EUR';
            }
        }
        
        // Format the base price in the product's currency
        $formatted_base_price = $this->format_price($base_price, $base_currency);
        
        // Only show converted price if user currency is different from product currency
        if ($user_currency !== $base_currency) {
            $converted_price = $this->convert_price($base_price, $base_currency, $user_currency);
            $formatted_converted_price = $this->format_price($converted_price, $user_currency);
            
            return sprintf(
                '%s <small class="user-currency-price">(%s)</small>',
                $formatted_base_price,
                $formatted_converted_price
            );
        } else {
            // If same currency, just show the base price without parentheses
            return $formatted_base_price;
        }
    }
    
    // Add a function to detect user currency based on IP
    private function detect_user_currency() {
        // Check if we already have it in session
        if (isset($_SESSION['tav_user_currency'])) {
            return $_SESSION['tav_user_currency'];
        }
        
        // Try to get user IP
        $user_ip = $this->get_user_ip();
        
        // If we have an IP, try to get currency from IP
        if ($user_ip) {
            // Use a free IP geolocation service
            $api_url = 'https://ipapi.co/' . $user_ip . '/json/';
            $response = wp_remote_get($api_url);
            
            if (!is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)) {
                $data = json_decode(wp_remote_retrieve_body($response), true);
                
                if (isset($data['currency'])) {
                    // Store in session for future use
                    $_SESSION['tav_user_currency'] = $data['currency'];
                    $_SESSION['tav_user_country'] = isset($data['country_name']) ? $data['country_name'] : '';
                    
                    // Debug log
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("Detected user currency from IP: " . $data['currency'] . " (Country: " . $_SESSION['tav_user_country'] . ")");
                    }
                    
                    return $data['currency'];
                }
            }
        }
        
        // If we couldn't detect, return null
        return null;
    }
    
    // Helper function to get user IP
    private function get_user_ip() {
        // Check for various server variables that might contain the IP
        $ip_keys = array(
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        );
        
        foreach ($ip_keys as $key) {
            if (isset($_SERVER[$key]) && filter_var($_SERVER[$key], FILTER_VALIDATE_IP)) {
                return $_SERVER[$key];
            }
        }
        
        // Default to REMOTE_ADDR if nothing else works
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    }

    public function modify_cart_item_price($price, $cart_item, $cart_item_key) {
        // If the cart item has product_currency and base_price stored
        if (isset($cart_item['product_currency']) && isset($cart_item['base_price'])) {
            $base_currency = $cart_item['product_currency'];
            $base_price = $cart_item['base_price'];
            
            // Use the product's currency
            if (!$this->current_currency) {
                $this->current_currency = $base_currency;
                $_SESSION['tav_currency'] = $base_currency;
            }
            
            // If the current currency matches the product's currency, format the base price
            if ($this->current_currency === $base_currency) {
                return $this->format_price($base_price, $base_currency);
            }
            
            // Otherwise convert and format
            $converted_price = $this->convert_price($base_price, $base_currency, $this->current_currency);
            return $this->format_price($converted_price, $this->current_currency);
        }
        
        // Fallback to the product meta
        $product_id = $cart_item['product_id'];
        $base_currency = get_post_meta($product_id, '_product_currency', true);
        $base_price = get_post_meta($product_id, '_base_price', true);

        if (!$base_currency || !$base_price) {
            return $price;
        }
        
        // Use the product's currency
        if (!$this->current_currency) {
            $this->current_currency = $base_currency;
            $_SESSION['tav_currency'] = $base_currency;
        }
        
        // If the current currency matches the product's currency, format the base price
        if ($this->current_currency === $base_currency) {
            return $this->format_price($base_price, $base_currency);
        }
        
        // Otherwise convert and format
        $converted_price = $this->convert_price($base_price, $base_currency, $this->current_currency);
        return $this->format_price($converted_price, $this->current_currency);
    }

    public function modify_cart_item_subtotal($subtotal, $cart_item, $cart_item_key) {
        $product_id = $cart_item['product_id'];
        $base_currency = get_post_meta($product_id, '_product_currency', true);
        $base_price = get_post_meta($product_id, '_base_price', true);

        if (!$base_currency || !$base_price) {
            return $subtotal;
        }

        $converted_price = $this->convert_price($base_price, $base_currency, $this->current_currency);
        $quantity = $cart_item['quantity'];
        
        return $this->format_price($converted_price * $quantity, $this->current_currency);
    }

    public function add_currency_to_order($order, $data) {
        // Set the order currency
        $order->set_currency($this->current_currency);
        $order->update_meta_data('_order_currency', $this->current_currency);
        
        // Store original product currencies and prices
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $base_currency = get_post_meta($product_id, '_product_currency', true);
            $base_price = get_post_meta($product_id, '_base_price', true);
            
            if ($base_currency && $base_price) {
                $item->add_meta_data('_base_currency', $base_currency);
                $item->add_meta_data('_base_price', $base_price);
                
                // Convert the price to the current currency
                $converted_price = $this->convert_price($base_price, $base_currency, $this->current_currency);
                $item->add_meta_data('_converted_price', $converted_price);
                
                // Update the item price to the converted price
                $item->set_subtotal($converted_price);
                $item->set_total($converted_price);
            }
        }
        
        // Force recalculation of totals
        $order->calculate_totals();
        
        // Debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Order created with currency: " . $order->get_currency());
            error_log("Order total: " . $order->get_total());
        }
    }

    public function display_order_currency($order) {
        $currency = $order->get_meta('_order_currency');
        if ($currency) {
            echo '<p><strong>Order Currency:</strong> ' . esc_html($currency) . '</p>';
        }
    }

    private function get_exchange_rates() {
        // Base currency is EUR
        $base_currency = 'EUR';
        
        // Check if we have cached rates
        $rates = get_transient('tav_exchange_rates');
        
        if (false === $rates) {
            // Default rates (relative to EUR)
            $rates = array(
                'EUR' => 1.0,
                'USD' => 1.08,
                'GBP' => 0.85,
                'JPY' => 160.0,
                'CAD' => 1.47,
                'AUD' => 1.63,
                'CHF' => 0.98,
                'CNY' => 7.82,
                'SEK' => 11.27,
                'NZD' => 1.77,
                'MXN' => 20.14,
                'SGD' => 1.45,
                'HKD' => 8.44,
                'NOK' => 11.65,
                'KRW' => 1470.0,
                'TRY' => 34.85,
                'RUB' => 98.0,
                'INR' => 90.0,
                'BRL' => 5.45,
                'ZAR' => 20.0,
                'DKK' => 7.46,
                'PLN' => 4.32,
                'THB' => 39.0,
                'IDR' => 17000.0,
                'HUF' => 390.0,
                'CZK' => 25.0,
                'ILS' => 4.0,
                'CLP' => 1000.0,
                'PHP' => 61.0,
                'AED' => 3.97,
                'COP' => 4300.0,
                'SAR' => 4.05,
                'MYR' => 5.0,
                'RON' => 4.97,
                'IRR' => 45000.0
            );
            
            // Try to get updated rates from API
            $response = wp_remote_get('https://open.er-api.com/v6/latest/EUR');
            
            if (!is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)) {
                $api_rates = json_decode(wp_remote_retrieve_body($response), true);
                
                if (isset($api_rates['rates']) && !empty($api_rates['rates'])) {
                    // Update our rates with the API data
                    foreach ($api_rates['rates'] as $currency => $rate) {
                        $rates[$currency] = $rate;
                    }
                    
                    // Cache for 12 hours
                    set_transient('tav_exchange_rates', $rates, 12 * HOUR_IN_SECONDS);
                }
            }
        }
        
        return $rates;
    }

    private function format_price($price, $currency) {
        $format = $this->get_currency_formats()[$currency] ?? ['symbol' => $currency, 'position' => 'right_space'];
        $price = number_format($price, 2, '.', ',');
        
        switch ($format['position']) {
            case 'left':
                return $format['symbol'] . $price;
            case 'right':
                return $price . $format['symbol'];
            case 'left_space':
                return $format['symbol'] . ' ' . $price;
            case 'right_space':
                return $price . ' ' . $format['symbol'];
            default:
                return $format['symbol'] . $price;
        }
    }

    private function get_currency_formats() {
        return array(
            // European Currencies
            'EUR' => array('symbol' => '€', 'position' => 'right_space'),
            'GBP' => array('symbol' => '£', 'position' => 'left'),
            'CHF' => array('symbol' => 'CHF', 'position' => 'right_space'),
            'SEK' => array('symbol' => 'kr', 'position' => 'right_space'),
            'NOK' => array('symbol' => 'kr', 'position' => 'right_space'),
            'DKK' => array('symbol' => 'kr', 'position' => 'right_space'),
            'PLN' => array('symbol' => 'zł', 'position' => 'right_space'),
            'CZK' => array('symbol' => 'Kč', 'position' => 'right_space'),
            'HUF' => array('symbol' => 'Ft', 'position' => 'right_space'),
            'RON' => array('symbol' => 'lei', 'position' => 'right_space'),
            'BGN' => array('symbol' => 'лв', 'position' => 'right_space'),
            'HRK' => array('symbol' => 'kn', 'position' => 'right_space'),
            'ISK' => array('symbol' => 'kr', 'position' => 'right_space'),
            
            // American Currencies
            'USD' => array('symbol' => '$', 'position' => 'left'),
            'CAD' => array('symbol' => 'C$', 'position' => 'left'),
            'MXN' => array('symbol' => 'MX$', 'position' => 'left'),
            'BRL' => array('symbol' => 'R$', 'position' => 'left'),
            'ARS' => array('symbol' => 'AR$', 'position' => 'left'),
            'CLP' => array('symbol' => 'CLP$', 'position' => 'left'),
            'COP' => array('symbol' => 'COL$', 'position' => 'left'),
            'PEN' => array('symbol' => 'S/.', 'position' => 'left'),
            'UYU' => array('symbol' => '$U', 'position' => 'left')
        );
    }

    private function convert_price($price, $from_currency, $to_currency) {
        // If currencies are the same, no conversion needed
        if ($from_currency === $to_currency) {
            return $price;
        }
        
        // Get exchange rates
        $rates = $this->get_exchange_rates();
        
        // Convert to EUR first (our base currency)
        $eur_amount = $price / $rates[$from_currency];
        
        // Then convert from EUR to target currency
        return $eur_amount * $rates[$to_currency];
    }

    public function woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p><?php _e('TavTheme Simple Multi Currency requires WooCommerce to be installed and activated.', 'tavtheme-simple-multi-currency-for-woocommerce'); ?></p>
        </div>
        <?php
    }

    public function modify_cart_total($total, $cart) {
        // No need to modify if we're in admin
        if (is_admin() && !wp_doing_ajax()) {
            return $total;
        }
        
        // If cart is empty, return original total
        if (empty($cart->cart_contents)) {
            return $total;
        }
        
        // Recalculate total based on converted prices
        $new_total = 0;
        foreach ($cart->cart_contents as $cart_item_key => $cart_item) {
            $product_id = $cart_item['product_id'];
            $base_currency = get_post_meta($product_id, '_product_currency', true);
            $base_price = get_post_meta($product_id, '_base_price', true);
            
            if ($base_currency && $base_price) {
                $converted_price = $this->convert_price($base_price, $base_currency, $this->current_currency);
                $new_total += $converted_price * $cart_item['quantity'];
            } else {
                // If no custom currency/price, use the original price
                $new_total += $cart_item['line_total'];
            }
        }
        
        // Add shipping if applicable
        if ($cart->shipping_total > 0) {
            $new_total += $cart->shipping_total;
        }
        
        // Add taxes if applicable
        if ($cart->tax_total > 0) {
            $new_total += $cart->tax_total;
        }
        
        return $new_total;
    }
    
    public function modify_cart_total_html($total_html) {
        // No need to modify if we're in admin
        if (is_admin() && !wp_doing_ajax()) {
            return $total_html;
        }
        
        // Get cart total
        $cart = WC()->cart;
        if (!$cart) {
            return $total_html;
        }
        
        $total = $this->modify_cart_total($cart->total, $cart);
        
        // Format the total with current currency
        return $this->format_price($total, $this->current_currency);
    }
    
    public function modify_variation_price($price, $product, $min_or_max, $display) {
        if (is_admin() && !wp_doing_ajax()) {
            return $price;
        }
        
        $product_id = $product->get_id();
        $base_currency = get_post_meta($product_id, '_product_currency', true);
        $base_price = get_post_meta($product_id, '_base_price', true);
        
        if (!$base_currency || !$base_price) {
            return $price;
        }
        
        return $this->convert_price($base_price, $base_currency, $this->current_currency);
    }
    
    public function modify_variation_prices($prices_array, $product, $for_display) {
        if (is_admin() && !wp_doing_ajax()) {
            return $prices_array;
        }
        
        $product_id = $product->get_id();
        
        foreach ($prices_array as $price_type => $variation_prices) {
            foreach ($variation_prices as $variation_id => $price) {
                $base_currency = get_post_meta($variation_id, '_product_currency', true);
                $base_price = get_post_meta($variation_id, '_base_price', true);
                
                if ($base_currency && $base_price) {
                    $prices_array[$price_type][$variation_id] = $this->convert_price($base_price, $base_currency, $this->current_currency);
                }
            }
        }
        
        return $prices_array;
    }
    
    public function update_payment_gateways_currency($available_gateways) {
        if (is_admin() && !wp_doing_ajax()) {
            return $available_gateways;
        }
        
        // Check if current currency is supported by Mollie
        if (isset($available_gateways['mollie_wc_gateway_ideal']) && !$this->is_currency_supported_by_mollie($this->current_currency)) {
            // If not supported, convert to EUR for Mollie gateways
            $mollie_gateways = array(
                'mollie_wc_gateway_ideal',
                'mollie_wc_gateway_creditcard',
                'mollie_wc_gateway_bancontact',
                'mollie_wc_gateway_sofort',
                'mollie_wc_gateway_banktransfer'
            );
            
            foreach ($mollie_gateways as $gateway_id) {
                if (isset($available_gateways[$gateway_id])) {
                    // Add a note about currency conversion
                    $available_gateways[$gateway_id]->title .= ' (Converted to EUR)';
                    
                    // Debug log
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("Currency " . $this->current_currency . " not supported by Mollie, will convert to EUR");
                    }
                }
            }
        }
        
        return $available_gateways;
    }

    public function modify_cart_subtotal($cart_subtotal, $compound, $cart) {
        if (is_admin() && !wp_doing_ajax()) {
            return $cart_subtotal;
        }
        
        $subtotal = 0;
        
        // Calculate subtotal based on converted prices
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product_id = $cart_item['product_id'];
            $base_currency = get_post_meta($product_id, '_product_currency', true);
            $base_price = get_post_meta($product_id, '_base_price', true);
            
            if ($base_currency && $base_price) {
                $converted_price = $this->convert_price($base_price, $base_currency, $this->current_currency);
                $subtotal += $converted_price * $cart_item['quantity'];
            } else {
                $subtotal += $cart_item['line_subtotal'];
            }
        }
        
        return $this->format_price($subtotal, $this->current_currency);
    }
    
    public function modify_cart_contents_total($cart_contents_total) {
        if (is_admin() && !wp_doing_ajax()) {
            return $cart_contents_total;
        }
        
        $cart = WC()->cart;
        if (!$cart) {
            return $cart_contents_total;
        }
        
        $total = 0;
        
        // Calculate total based on converted prices
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product_id = $cart_item['product_id'];
            $base_currency = get_post_meta($product_id, '_product_currency', true);
            $base_price = get_post_meta($product_id, '_base_price', true);
            
            if ($base_currency && $base_price) {
                $converted_price = $this->convert_price($base_price, $base_currency, $this->current_currency);
                $total += $converted_price * $cart_item['quantity'];
            } else {
                $total += $cart_item['line_total'];
            }
        }
        
        return $this->format_price($total, $this->current_currency);
    }
    
    public function modify_shipping_label($label, $method) {
        if (is_admin() && !wp_doing_ajax()) {
            return $label;
        }
        
        // Extract the cost part from the label
        $cost_pattern = '/: (.+)$/';
        if (preg_match($cost_pattern, $label, $matches)) {
            $cost_text = $matches[1];
            $cost_value = preg_replace('/[^0-9.,]/', '', $cost_text);
            
            if (is_numeric($cost_value)) {
                $formatted_cost = $this->format_price($cost_value, $this->current_currency);
                $label = preg_replace($cost_pattern, ': ' . $formatted_cost, $label);
            }
        }
        
        return $label;
    }
    
    public function modify_tax_totals($tax_totals, $cart) {
        if (is_admin() && !wp_doing_ajax()) {
            return $tax_totals;
        }
        
        foreach ($tax_totals as $code => $tax) {
            $amount = $tax->amount;
            $tax->formatted_amount = $this->format_price($amount, $this->current_currency);
        }
        
        return $tax_totals;
    }
    
    public function modify_coupon_amount($discount, $discounting_amount, $cart_item, $single, $coupon) {
        if (is_admin() && !wp_doing_ajax()) {
            return $discount;
        }
        
        // For percentage discounts, no need to convert
        if ($coupon->is_type('percent')) {
            return $discount;
        }
        
        // For fixed cart and fixed product discounts, convert the amount
        $coupon_currency = get_option('woocommerce_currency');
        $coupon_amount = $coupon->get_amount();
        
        return $this->convert_price($coupon_amount, $coupon_currency, $this->current_currency);
    }
    
    public function update_mini_cart_fragments($fragments) {
        if (is_admin() && !wp_doing_ajax()) {
            return $fragments;
        }
        
        // Get mini cart
        ob_start();
        woocommerce_mini_cart();
        $mini_cart = ob_get_clean();
        
        // Add mini cart to fragments
        $fragments['div.widget_shopping_cart_content'] = '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>';
        
        // Update cart total amount
        $cart = WC()->cart;
        if ($cart) {
            $total = $this->modify_cart_total($cart->total, $cart);
            $fragments['span.woocommerce-Price-amount'] = '<span class="woocommerce-Price-amount amount">' . $this->format_price($total, $this->current_currency) . '</span>';
        }
        
        return $fragments;
    }
    
    public function modify_email_order_items($order_items_table, $order) {
        // Get order currency
        $order_currency = $order->get_currency();
        
        // Replace currency symbols in the table
        $formats = $this->get_currency_formats();
        foreach ($formats as $currency_code => $format) {
            $symbol = $format['symbol'];
            if ($currency_code === $order_currency) {
                // Keep the correct currency symbol
                continue;
            }
            
            // Replace other currency symbols with the order currency symbol
            $order_format = isset($formats[$order_currency]) ? $formats[$order_currency] : array('symbol' => $order_currency);
            $order_items_table = str_replace($symbol, $order_format['symbol'], $order_items_table);
        }
        
        return $order_items_table;
    }
    
    public function ensure_checkout_currency() {
        // Make sure the checkout form uses the correct currency
        echo '<script type="text/javascript">
            jQuery(document).ready(function($) {
                // Update currency in checkout
                $(document.body).trigger("update_checkout");
            });
        </script>';
    }
    
    public function update_checkout_on_currency_change($post_data) {
        // Force recalculation of totals when currency changes
        WC()->cart->calculate_totals();
        return $post_data;
    }

    public function modify_formatted_order_total($formatted_total, $order) {
        if (is_admin() && !wp_doing_ajax()) {
            return $formatted_total;
        }
        
        $total = $order->get_total();
        $currency = $order->get_currency();
        
        if ($currency !== $this->current_currency) {
            // Convert the total to current currency
            $total = $this->convert_price($total, $currency, $this->current_currency);
            return $this->format_price($total, $this->current_currency);
        }
        
        return $formatted_total;
    }
    
    public function modify_price_format($format, $currency_pos) {
        if (is_admin() && !wp_doing_ajax()) {
            return $format;
        }
        
        $currency_format = $this->get_currency_formats()[$this->current_currency] ?? null;
        
        if ($currency_format) {
            switch ($currency_format['position']) {
                case 'left':
                    return '%1$s%2$s';
                case 'right':
                    return '%2$s%1$s';
                case 'left_space':
                    return '%1$s&nbsp;%2$s';
                case 'right_space':
                    return '%2$s&nbsp;%1$s';
                default:
                    return $format;
            }
        }
        
        return $format;
    }
    
    public function modify_formatted_price($formatted_price, $price, $decimals, $decimal_separator, $thousand_separator) {
        if (is_admin() && !wp_doing_ajax()) {
            return $formatted_price;
        }
        
        // Ensure price is numeric
        if (!is_numeric($price)) {
            $price = (float) preg_replace('/[^0-9.]/', '', $price);
        }
        
        // Format with current currency
        return $this->format_price($price, $this->current_currency);
    }
    
    public function modify_wc_price($return, $price, $args) {
        if (is_admin() && !wp_doing_ajax()) {
            return $return;
        }
        
        // If we're in the cart or checkout, ensure correct currency
        if (is_cart() || is_checkout()) {
            // Ensure price is numeric
            if (!is_numeric($price)) {
                $price = (float) preg_replace('/[^0-9.]/', '', $price);
            }
            return $this->format_price($price, $this->current_currency);
        }
        
        return $return;
    }
    
    public function ensure_price_html_currency($price_html, $product) {
        if (is_admin() && !wp_doing_ajax()) {
            return $price_html;
        }
        
        // If we're in cart or checkout, ensure the currency symbol is correct
        if (is_cart() || is_checkout()) {
            $formats = $this->get_currency_formats();
            foreach ($formats as $code => $format) {
                if ($code !== $this->current_currency) {
                    $symbol = $format['symbol'];
                    $current_symbol = $formats[$this->current_currency]['symbol'] ?? $this->current_currency;
                    $price_html = str_replace($symbol, $current_symbol, $price_html);
                }
            }
        }
        
        return $price_html;
    }
    
    public function modify_cart_total_display($total) {
        if (is_admin() && !wp_doing_ajax()) {
            return $total;
        }
        
        $cart = WC()->cart;
        if (!$cart) {
            return $total;
        }
        
        $cart_total = $this->modify_cart_total($cart->total, $cart);
        return $this->format_price($cart_total, $this->current_currency);
    }
    
    public function add_cart_currency_fix_script() {
        if (!is_cart() && !is_checkout()) {
            return;
        }
        
        $current_symbol = $this->get_currency_formats()[$this->current_currency]['symbol'] ?? $this->current_currency;
        
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Function to replace all currency symbols in cart/checkout
            function replaceCurrencySymbols() {
                // Get all currency symbols we need to replace
                var currencyFormats = <?php echo json_encode($this->get_currency_formats()); ?>;
                var currentSymbol = <?php echo json_encode($current_symbol); ?>;
                var currentCurrency = <?php echo json_encode($this->current_currency); ?>;
                
                // Replace all currency symbols in price elements
                $('.woocommerce-Price-currencySymbol').each(function() {
                    $(this).text(currentSymbol);
                });
                
                // Replace currency in labels
                $('label').each(function() {
                    var text = $(this).text();
                    if (text.indexOf('(€)') !== -1) {
                        $(this).text(text.replace('(€)', '(' + currentSymbol + ')'));
                    }
                });
                
                // Add a data attribute to the body to track the current currency
                $('body').attr('data-current-currency', currentCurrency);
            }
            
            // Run on page load
            replaceCurrencySymbols();
            
            // Run when cart/checkout is updated
            $(document.body).on('updated_cart_totals updated_checkout', function() {
                replaceCurrencySymbols();
            });
            
            // Prevent infinite AJAX refreshes
            var lastCurrency = '<?php echo esc_js($this->current_currency); ?>';
            var refreshCount = 0;
            
            $(document.body).on('updated_checkout', function() {
                var currentCurrency = $('.tav-currency-stable').data('currency');
                
                // If we've detected multiple refreshes with the same currency, stop the cycle
                if (currentCurrency === lastCurrency) {
                    refreshCount++;
                    if (refreshCount > 3) {
                        // Stop any pending AJAX requests
                        $.ajax({
                            url: wc_checkout_params.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'tav_stop_refresh',
                                nonce: '<?php echo wp_create_nonce('tav_stop_refresh_nonce'); ?>'
                            }
                        });
                        
                        console.log('Stopped potential infinite refresh loop');
                        refreshCount = 0;
                    }
                } else {
                    lastCurrency = currentCurrency;
                    refreshCount = 0;
                }
            });
        });
        </script>
        <?php
        
        // Add a hidden div to track currency stability
        echo '<div class="tav-currency-stable" data-currency="' . esc_attr($this->current_currency) . '" style="display:none;"></div>';
    }

    public function modify_currency_symbol($currency_symbol, $currency) {
        // If we're in admin, don't modify
        if (is_admin() && !wp_doing_ajax()) {
            return $currency_symbol;
        }
        
        // Get the symbol for the current currency
        $formats = $this->get_currency_formats();
        if (isset($formats[$this->current_currency])) {
            return $formats[$this->current_currency]['symbol'];
        }
        
        return $currency_symbol;
    }
    
    public function modify_order_currency($currency, $order) {
        // If we're in checkout, use the current currency
        if (is_checkout() && !is_wc_endpoint_url()) {
            return $this->current_currency;
        }
        
        return $currency;
    }
    
    public function modify_paypal_args($args, $order) {
        // Ensure PayPal uses the correct currency
        if (isset($args['currency_code'])) {
            $args['currency_code'] = $this->current_currency;
        }
        
        return $args;
    }
    
    public function modify_gateway_title($title, $gateway_id) {
        // Add currency info to gateway title
        return $title . ' (' . $this->current_currency . ')';
    }

    public function modify_get_order_currency($currency, $order) {
        // If we're in checkout, always use the current currency
        if (is_checkout() || is_wc_endpoint_url('order-pay')) {
            return $this->current_currency;
        }
        
        return $currency;
    }
    
    public function before_checkout_process() {
        // Force WooCommerce to use our currency
        add_filter('option_woocommerce_currency', array($this, 'force_woocommerce_currency_option'), 999);
    }
    
    public function force_woocommerce_currency_option($value) {
        // Always return EUR as the base currency for WooCommerce settings
        return 'EUR';
    }
    
    public function modify_checkout_posted_data($data) {
        // Add currency information to the posted data
        $data['currency'] = $this->current_currency;
        return $data;
    }
    
    public function force_checkout_currency() {
        // Force the currency at checkout initialization
        global $wp;
        
        if (isset($wp->query_vars['order-pay'])) {
            $order_id = $wp->query_vars['order-pay'];
            $order = wc_get_order($order_id);
            if ($order) {
                // Get the currency from the order
                $order_currency = $order->get_currency();
                
                // Set the current currency to match the order
                $this->current_currency = $order_currency;
                $_SESSION['tav_currency'] = $order_currency;
                
                // Debug log
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("Order pay - Setting currency to: " . $order_currency);
                }
            }
        }
        
        // Set the global currency
        add_filter('option_woocommerce_currency', array($this, 'force_woocommerce_currency_option'), 999);
    }
    
    // Generic payment gateway args modifier
    private function modify_generic_gateway_args($args, $order) {
        if (isset($args['currency'])) {
            $args['currency'] = $this->current_currency;
        }
        
        if (isset($args['currency_code'])) {
            $args['currency_code'] = $this->current_currency;
        }
        
        // Update amount based on the current currency if needed
        if (isset($args['amount'])) {
            $order_currency = $order->get_currency();
            if ($order_currency !== $this->current_currency) {
                $amount = $args['amount'];
                $converted_amount = $this->convert_price($amount, $order_currency, $this->current_currency);
                $args['amount'] = $converted_amount;
            }
        }
        
        return $args;
    }
    
    // Specific payment gateway support
    public function modify_zarinpal_args($args, $order) {
        return $this->modify_generic_gateway_args($args, $order);
    }
    
    public function modify_mellat_args($args, $order) {
        return $this->modify_generic_gateway_args($args, $order);
    }
    
    public function modify_saman_args($args, $order) {
        return $this->modify_generic_gateway_args($args, $order);
    }
    
    public function modify_parsian_args($args, $order) {
        return $this->modify_generic_gateway_args($args, $order);
    }
    
    public function modify_mabna_args($args, $order) {
        return $this->modify_generic_gateway_args($args, $order);
    }
    
    public function modify_idpay_args($args, $order) {
        return $this->modify_generic_gateway_args($args, $order);
    }
    
    public function modify_zibal_args($args, $order) {
        return $this->modify_generic_gateway_args($args, $order);
    }
    
    public function modify_nextpay_args($args, $order) {
        return $this->modify_generic_gateway_args($args, $order);
    }
    
    public function modify_payping_args($args, $order) {
        return $this->modify_generic_gateway_args($args, $order);
    }

    // International payment gateway support
    public function modify_mollie_args($args, $order) {
        return $this->modify_generic_gateway_args($args, $order);
    }
    
    public function modify_mollie_payment_parameters($parameters, $order) {
        // Debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Original Mollie parameters: " . print_r($parameters, true));
            error_log("Current currency: " . $this->current_currency);
        }
        
        // Check if current currency is supported by Mollie
        if (!$this->is_currency_supported_by_mollie($this->current_currency)) {
            // Convert to EUR for Mollie
            if (isset($parameters['amount']['currency'])) {
                $parameters['amount']['currency'] = 'EUR';
            }
            
            // Update amount based on the current currency if needed
            if (isset($parameters['amount']['value'])) {
                // Get the total in the current currency
                if (is_a($order, 'WC_Order')) {
                    $total = $order->get_total();
                    
                    // Convert to EUR
                    $converted_total = $this->convert_price($total, $this->current_currency, 'EUR');
                    
                    // Format the amount according to Mollie requirements (string with 2 decimals)
                    $parameters['amount']['value'] = number_format($converted_total, 2, '.', '');
                    
                    // Debug log
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("Converting from " . $this->current_currency . " to EUR: " . $total . " -> " . $converted_total);
                    }
                } else {
                    $cart = WC()->cart;
                    if ($cart) {
                        $total = $cart->get_total('edit');
                        
                        // Convert to EUR
                        $converted_total = $this->convert_price($total, $this->current_currency, 'EUR');
                        
                        // Format the amount according to Mollie requirements (string with 2 decimals)
                        $parameters['amount']['value'] = number_format($converted_total, 2, '.', '');
                        
                        // Debug log
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log("Converting cart total from " . $this->current_currency . " to EUR: " . $total . " -> " . $converted_total);
                        }
                    }
                }
            }
        } else {
            // Currency is supported, use it directly
            if (isset($parameters['amount']['currency'])) {
                $parameters['amount']['currency'] = $this->current_currency;
            }
            
            // Update amount based on the current currency if needed
            if (isset($parameters['amount']['value'])) {
                // Get the total in the current currency directly
                if (is_a($order, 'WC_Order')) {
                    $total = $order->get_total();
                    // Format the amount according to Mollie requirements (string with 2 decimals)
                    $parameters['amount']['value'] = number_format($total, 2, '.', '');
                    
                    // Debug log
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("Using direct amount for " . $this->current_currency . ": " . $total);
                    }
                } else {
                    $cart = WC()->cart;
                    if ($cart) {
                        $total = $cart->get_total('edit');
                        // Format the amount according to Mollie requirements (string with 2 decimals)
                        $parameters['amount']['value'] = number_format($total, 2, '.', '');
                        
                        // Debug log
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log("Using direct cart amount for " . $this->current_currency . ": " . $total);
                        }
                    }
                }
            }
        }
        
        // Debug log the final parameters
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Modified Mollie parameters: " . print_r($parameters, true));
        }
        
        return $parameters;
    }
    
    public function modify_mollie_order_request_data($data, $order) {
        // Check if current currency is supported by Mollie
        if (!$this->is_currency_supported_by_mollie($this->current_currency)) {
            // Convert to EUR for Mollie
            $data['amount']['currency'] = 'EUR';
            
            // Get the total in the current currency
            $total = $order->get_total();
            
            // Convert to EUR
            $converted_total = $this->convert_price($total, $this->current_currency, 'EUR');
            
            // Format according to Mollie requirements
            $data['amount']['value'] = number_format($converted_total, 2, '.', '');
            
            // Update line items if present
            if (isset($data['lines']) && is_array($data['lines'])) {
                foreach ($data['lines'] as &$line) {
                    if (isset($line['amount']['currency'])) {
                        $line['amount']['currency'] = 'EUR';
                    }
                    
                    if (isset($line['amount']['value'])) {
                        $line_amount = floatval($line['amount']['value']);
                        $converted_line_amount = $this->convert_price($line_amount, $this->current_currency, 'EUR');
                        $line['amount']['value'] = number_format($converted_line_amount, 2, '.', '');
                    }
                }
            }
        } else {
            // Currency is supported, use it directly
            $data['amount']['currency'] = $this->current_currency;
            
            // Get the total in the current currency
            $total = $order->get_total();
            
            // Format according to Mollie requirements
            $data['amount']['value'] = number_format($total, 2, '.', '');
            
            // Update line items if present
            if (isset($data['lines']) && is_array($data['lines'])) {
                foreach ($data['lines'] as &$line) {
                    if (isset($line['amount']['currency'])) {
                        $line['amount']['currency'] = $this->current_currency;
                    }
                }
            }
        }
        
        return $data;
    }
    
    public function modify_mollie_payment_object_data($data, $payment_object, $order) {
        // Check if current currency is supported by Mollie
        if (!$this->is_currency_supported_by_mollie($this->current_currency)) {
            // Convert to EUR for Mollie
            $data['amount']['currency'] = 'EUR';
            
            // Get the total in the current currency
            $total = $order->get_total();
            
            // Convert to EUR
            $converted_total = $this->convert_price($total, $this->current_currency, 'EUR');
            
            // Format according to Mollie requirements
            $data['amount']['value'] = number_format($converted_total, 2, '.', '');
            
            // Debug log
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("Converting payment object amount from " . $this->current_currency . " to EUR: " . $total . " -> " . $converted_total);
            }
        } else {
            // Currency is supported, use it directly
            $data['amount']['currency'] = $this->current_currency;
            
            // Get the total in the current currency
            $total = $order->get_total();
            
            // Format according to Mollie requirements
            $data['amount']['value'] = number_format($total, 2, '.', '');
        }
        
        return $data;
    }
    
    // Stripe payment gateway support
    public function modify_stripe_request_body($request, $order) {
        // Ensure currency is set correctly
        if (isset($request['currency'])) {
            $request['currency'] = strtolower($this->current_currency);
        }
        
        // Update amount based on the current currency if needed
        if (isset($request['amount'])) {
            $order_currency = $order->get_currency();
            if ($order_currency !== $this->current_currency) {
                $amount = $request['amount'] / 100; // Stripe uses cents
                $converted_amount = $this->convert_price($amount, $order_currency, $this->current_currency);
                $request['amount'] = round($converted_amount * 100); // Convert back to cents
            }
        }
        
        return $request;
    }
    
    public function modify_stripe_payment_intent_params($params, $order) {
        // Ensure currency is set correctly
        if (isset($params['currency'])) {
            $params['currency'] = strtolower($this->current_currency);
        }
        
        // Update amount based on the current currency if needed
        if (isset($params['amount'])) {
            $order_currency = $order->get_currency();
            if ($order_currency !== $this->current_currency) {
                $amount = $params['amount'] / 100; // Stripe uses cents
                $converted_amount = $this->convert_price($amount, $order_currency, $this->current_currency);
                $params['amount'] = round($converted_amount * 100); // Convert back to cents
            }
        }
        
        return $params;
    }
    
    // Braintree payment gateway support
    public function modify_braintree_transaction_data($transaction_data, $order, $gateway) {
        // Ensure currency is set correctly
        if (isset($transaction_data['currency_iso_code'])) {
            $transaction_data['currency_iso_code'] = $this->current_currency;
        }
        
        // Update amount based on the current currency if needed
        if (isset($transaction_data['amount'])) {
            $order_currency = $order->get_currency();
            if ($order_currency !== $this->current_currency) {
                $amount = $transaction_data['amount'];
                $converted_amount = $this->convert_price($amount, $order_currency, $this->current_currency);
                $transaction_data['amount'] = number_format($converted_amount, 2, '.', '');
            }
        }
        
        return $transaction_data;
    }
    
    // Square payment gateway support
    public function modify_square_payment_request($request, $order, $gateway) {
        // Ensure currency is set correctly
        if (isset($request['currency'])) {
            $request['currency'] = $this->current_currency;
        }
        
        // Update amount based on the current currency if needed
        if (isset($request['amount'])) {
            $order_currency = $order->get_currency();
            if ($order_currency !== $this->current_currency) {
                $amount = $request['amount'] / 100; // Square uses cents
                $converted_amount = $this->convert_price($amount, $order_currency, $this->current_currency);
                $request['amount'] = round($converted_amount * 100); // Convert back to cents
            }
        }
        
        return $request;
    }
    
    // Amazon Payments support
    public function modify_amazon_payments_args($args, $order) {
        // Ensure currency is set correctly
        if (isset($args['currency_code'])) {
            $args['currency_code'] = $this->current_currency;
        }
        
        // Update amount based on the current currency if needed
        if (isset($args['amount'])) {
            $order_currency = $order->get_currency();
            if ($order_currency !== $this->current_currency) {
                $amount = $args['amount'];
                $converted_amount = $this->convert_price($amount, $order_currency, $this->current_currency);
                $args['amount'] = number_format($converted_amount, 2, '.', '');
            }
        }
        
        return $args;
    }
    
    // Klarna Payments support
    public function modify_klarna_payments_args($args, $order) {
        // Ensure currency is set correctly
        if (isset($args['purchase_currency'])) {
            $args['purchase_currency'] = $this->current_currency;
        }
        
        // Update amount based on the current currency if needed
        if (isset($args['order_amount'])) {
            $order_currency = $order->get_currency();
            if ($order_currency !== $this->current_currency) {
                $amount = $args['order_amount'] / 100; // Klarna uses cents
                $converted_amount = $this->convert_price($amount, $order_currency, $this->current_currency);
                $args['order_amount'] = round($converted_amount * 100); // Convert back to cents
            }
        }
        
        // Update line items if present
        if (isset($args['order_lines']) && is_array($args['order_lines'])) {
            foreach ($args['order_lines'] as &$line) {
                if (isset($line['unit_price'])) {
                    $order_currency = $order->get_currency();
                    if ($order_currency !== $this->current_currency) {
                        $amount = $line['unit_price'] / 100; // Klarna uses cents
                        $converted_amount = $this->convert_price($amount, $order_currency, $this->current_currency);
                        $line['unit_price'] = round($converted_amount * 100); // Convert back to cents
                    }
                }
                
                if (isset($line['total_amount'])) {
                    $order_currency = $order->get_currency();
                    if ($order_currency !== $this->current_currency) {
                        $amount = $line['total_amount'] / 100; // Klarna uses cents
                        $converted_amount = $this->convert_price($amount, $order_currency, $this->current_currency);
                        $line['total_amount'] = round($converted_amount * 100); // Convert back to cents
                    }
                }
            }
        }
        
        return $args;
    }

    public function log_currency_debug_info() {
        // Only log if WP_DEBUG is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $log_message = "Current currency: " . $this->current_currency . "\n";
            $log_message .= "WooCommerce currency: " . get_woocommerce_currency() . "\n";
            $log_message .= "Cart total: " . WC()->cart->get_total() . "\n";
            
            // Log to debug.log
            error_log($log_message);
        }
    }

    public function handle_product_currency_on_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
        // When a product is added to cart, set the session currency to the product's currency
        $product_currency = get_post_meta($product_id, '_product_currency', true);
        
        if ($product_currency) {
            $_SESSION['tav_currency'] = $product_currency;
            $this->current_currency = $product_currency;
            
            // Also store in WooCommerce session
            if (function_exists('WC') && WC()->session) {
                WC()->session->set('chosen_currency', $product_currency);
            }
            
            // Debug log
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("Product added to cart with currency: " . $product_currency);
            }
            
            // Force WooCommerce to recalculate totals
            if (function_exists('WC') && WC()->cart) {
                WC()->cart->calculate_totals();
            }
        }
        
        return $cart_item_key;
    }
    
    public function add_product_currency_to_cart_item($cart_item_data, $product_id, $variation_id) {
        // Store the product's currency in the cart item data
        $product_currency = get_post_meta($product_id, '_product_currency', true);
        $base_price = get_post_meta($product_id, '_base_price', true);
        
        if ($product_currency && $base_price) {
            $cart_item_data['product_currency'] = $product_currency;
            $cart_item_data['base_price'] = $base_price;
            
            // Set the session currency to match the product
            $_SESSION['tav_currency'] = $product_currency;
            $this->current_currency = $product_currency;
        }
        
        return $cart_item_data;
    }
    
    public function get_cart_item_from_session($cart_item, $values) {
        // Restore product currency from session data
        if (isset($values['product_currency'])) {
            $cart_item['product_currency'] = $values['product_currency'];
            
            // Set the current currency to match the product in cart
            $_SESSION['tav_currency'] = $values['product_currency'];
            $this->current_currency = $values['product_currency'];
        }
        
        if (isset($values['base_price'])) {
            $cart_item['base_price'] = $values['base_price'];
        }
        
        return $cart_item;
    }

    // Add this new method to prevent infinite AJAX refreshes
    public function prevent_infinite_refresh($fragments) {
        // Add a custom fragment to indicate the currency is stable
        $fragments['div.tav-currency-stable'] = '<div class="tav-currency-stable" data-currency="' . esc_attr($this->current_currency) . '"></div>';
        
        // Debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Adding currency stable fragment for: " . $this->current_currency);
        }
        
        return $fragments;
    }

    // Add this new method to check if a currency is supported by Mollie
    private function is_currency_supported_by_mollie($currency) {
        // List of currencies supported by Mollie
        $supported_currencies = array(
            'EUR', 'USD', 'GBP', 'CAD', 'AUD', 'CHF', 'SEK', 'NOK', 'DKK', 'NZD', 'PLN'
        );
        
        // Debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Checking if currency " . $currency . " is supported by Mollie: " . (in_array($currency, $supported_currencies) ? 'Yes' : 'No'));
        }
        
        return in_array($currency, $supported_currencies);
    }

    // Add this new method to handle the stop refresh AJAX request
    public function ajax_stop_refresh() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'tav_stop_refresh_nonce')) {
            wp_send_json_error('Invalid nonce');
            exit;
        }
        
        // Log the stop refresh request
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Stopping refresh cycle for currency: " . $this->current_currency);
        }
        
        // Send success response
        wp_send_json_success(array(
            'currency' => $this->current_currency,
            'message' => 'Refresh cycle stopped'
        ));
        exit;
    }

    // Add this new method to clear all currency-related caches
    private function clear_currency_caches($new_currency) {
        // Clear WooCommerce session cache
        if (function_exists('WC') && WC()->session) {
            // Remove any cached cart totals
            WC()->session->set('cart_totals', null);
            WC()->session->set('refresh_totals', true);
            
            // Force WooCommerce to forget previous currency
            WC()->session->set('previous_currency', WC()->session->get('chosen_currency'));
            WC()->session->set('chosen_currency', $new_currency);
            
            // Clear any cached fragments
            WC()->session->set('wc_fragments', null);
        }
        
        // Clear transients that might store currency information
        delete_transient('wc_products_onsale');
        
        // Delete any currency-specific transients
        global $wpdb;
        $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '%_transient_wc_product_%'");
        $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '%_transient_wc_report_%'");
        
        // Debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Cleared all currency caches for switch to: " . $new_currency);
        }
    }

    // Add this new method to force the currency to be applied early
    public function force_apply_currency() {
        // Skip in admin
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }
        
        // Base currency is always EUR
        $base_currency = 'EUR';
        
        // Get the currency from session or WC session
        $session_currency = isset($_SESSION['tav_currency']) ? $_SESSION['tav_currency'] : null;
        $wc_session_currency = (function_exists('WC') && WC()->session) ? WC()->session->get('chosen_currency') : null;
        
        // Use the most reliable source
        if ($wc_session_currency) {
            $this->current_currency = $wc_session_currency;
            $_SESSION['tav_currency'] = $wc_session_currency;
        } elseif ($session_currency) {
            $this->current_currency = $session_currency;
            if (function_exists('WC') && WC()->session) {
                WC()->session->set('chosen_currency', $session_currency);
            }
        } else {
            // Default to base currency (EUR)
            $this->current_currency = $base_currency;
            $_SESSION['tav_currency'] = $base_currency;
            if (function_exists('WC') && WC()->session) {
                WC()->session->set('chosen_currency', $base_currency);
            }
        }
        
        // Debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Force applied currency: " . $this->current_currency . " (Base currency: " . $base_currency . ")");
        }
        
        // Apply filters to ensure currency is used everywhere
        add_filter('woocommerce_currency', array($this, 'change_woocommerce_currency'), 999);
        add_filter('option_woocommerce_currency', array($this, 'force_woocommerce_currency_option'), 999);
        
        // Force recalculation of cart totals if needed
        if (function_exists('WC') && WC()->cart) {
            WC()->cart->calculate_totals();
        }
    }

    // Add this new method to validate cart currency
    public function validate_cart_currency($valid, $product_id, $quantity) {
        // If cart is empty, always allow adding the product
        if (WC()->cart->is_empty()) {
            return $valid;
        }
        
        // Get the product's currency
        $product_currency = get_post_meta($product_id, '_product_currency', true);
        if (!$product_currency) {
            $product_currency = get_woocommerce_currency(); // Default currency
        }
        
        // Check if there are items in the cart with different currencies
        $cart_items = WC()->cart->get_cart();
        foreach ($cart_items as $cart_item) {
            $cart_item_currency = isset($cart_item['product_currency']) ? $cart_item['product_currency'] : get_woocommerce_currency();
            
            if ($cart_item_currency !== $product_currency) {
                // Show an error message
                wc_add_notice(
                    sprintf(
                        __('Sorry, you cannot add products with different currencies to your cart. Your cart contains products in %s. Please complete your current order or empty your cart before adding this product in %s.', 'tavtheme-simple-multi-currency-for-woocommerce'),
                        $this->get_currency_name($cart_item_currency),
                        $this->get_currency_name($product_currency)
                    ),
                    'error'
                );
                
                return false;
            }
        }
        
        return $valid;
    }
    
    // Helper function to get currency name
    private function get_currency_name($currency_code) {
        $currencies = array(
            'USD' => __('US Dollars', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'EUR' => __('Euros', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'GBP' => __('British Pounds', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'AUD' => __('Australian Dollars', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'BRL' => __('Brazilian Real', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'CAD' => __('Canadian Dollars', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'CNY' => __('Chinese Yuan', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'CZK' => __('Czech Koruna', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'DKK' => __('Danish Krone', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'HKD' => __('Hong Kong Dollar', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'HUF' => __('Hungarian Forint', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'INR' => __('Indian Rupee', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'IDR' => __('Indonesia Rupiah', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'ILS' => __('Israeli Shekel', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'JPY' => __('Japanese Yen', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'MYR' => __('Malaysian Ringgits', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'MXN' => __('Mexican Peso', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'NZD' => __('New Zealand Dollar', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'NOK' => __('Norwegian Krone', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'PHP' => __('Philippine Pesos', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'PLN' => __('Polish Zloty', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'RON' => __('Romanian Leu', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'SGD' => __('Singapore Dollar', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'ZAR' => __('South African rand', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'SEK' => __('Swedish Krona', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'CHF' => __('Swiss Franc', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'TWD' => __('Taiwan New Dollars', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'THB' => __('Thai Baht', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'TRY' => __('Turkish Lira', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'RUB' => __('Russian Ruble', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'IRR' => __('Iranian Rial', 'tavtheme-simple-multi-currency-for-woocommerce'),
        );
        
        return isset($currencies[$currency_code]) ? $currencies[$currency_code] : $currency_code;
    }
    
    // Add this new method to display product currency notice
    public function display_product_currency_notice() {
        global $product;
        
        if (!$product) {
            return;
        }
        
        $product_id = $product->get_id();
        $product_currency = get_post_meta($product_id, '_product_currency', true);
        
        if (!$product_currency) {
            $product_currency = 'EUR'; // Default to EUR
        }
        
        $currency_formats = $this->get_currency_formats();
        $currency_symbol = isset($currency_formats[$product_currency]['symbol']) ? $currency_formats[$product_currency]['symbol'] : $product_currency;
        
        // Get user's currency and country from session
        $user_currency = isset($_SESSION['tav_user_currency']) ? $_SESSION['tav_user_currency'] : null;
        $user_country = isset($_SESSION['tav_user_country']) ? $_SESSION['tav_user_country'] : null;
        
        // If no user currency detected, try to detect it now
        if (!$user_currency) {
            $user_currency = $this->detect_user_currency();
            
            // If we have a user currency now, get the country from session
            if ($user_currency) {
                $user_country = isset($_SESSION['tav_user_country']) ? $_SESSION['tav_user_country'] : '';
            }
        }
        
        // If user currency matches product currency, no need to show any notice
        if ($user_currency === $product_currency) {
            return;
        }
        
        echo '<div class="product-currency-notice" style="margin-bottom: 15px; padding: 10px; background-color: #f8f8f8; border-left: 3px solid #2271b1; font-size: 0.9em;">';
        
        // Basic notice about product currency
        echo sprintf(
            __('This product is priced in %s (%s).', 'tavtheme-simple-multi-currency-for-woocommerce'),
            '<strong>' . $this->get_currency_name($product_currency) . '</strong>',
            $currency_symbol
        );
        
        // Only show additional notice if user currency is different from product currency
        if ($user_currency && $user_country && $user_currency !== $product_currency) {
            $user_currency_symbol = isset($currency_formats[$user_currency]['symbol']) ? $currency_formats[$user_currency]['symbol'] : $user_currency;
            
            echo '<br><br>' . sprintf(
                __('Based on your location (%s), we also show prices in %s (%s) for your convenience.', 'tavtheme-simple-multi-currency-for-woocommerce'),
                '<strong>' . $user_country . '</strong>',
                '<strong>' . $this->get_currency_name($user_currency) . '</strong>',
                $user_currency_symbol
            );
        }
        
        echo '</div>';
    }

    // Add this method to get currency options for the admin dropdown
    private function get_currency_options() {
        return array(
            // European Currencies
            'EUR' => __('Euro (€)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'GBP' => __('British Pound (£)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'CHF' => __('Swiss Franc (CHF)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'SEK' => __('Swedish Krona (kr)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'NOK' => __('Norwegian Krone (kr)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'DKK' => __('Danish Krone (kr)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'PLN' => __('Polish Złoty (zł)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'CZK' => __('Czech Koruna (Kč)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'HUF' => __('Hungarian Forint (Ft)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'RON' => __('Romanian Leu (lei)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'BGN' => __('Bulgarian Lev (лв)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'HRK' => __('Croatian Kuna (kn)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'ISK' => __('Icelandic Króna (kr)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            
            // American Currencies
            'USD' => __('US Dollar ($)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'CAD' => __('Canadian Dollar (C$)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'MXN' => __('Mexican Peso (MX$)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'BRL' => __('Brazilian Real (R$)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'ARS' => __('Argentine Peso (AR$)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'CLP' => __('Chilean Peso (CLP$)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'COP' => __('Colombian Peso (COL$)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'PEN' => __('Peruvian Sol (S/.)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'UYU' => __('Uruguayan Peso ($U)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            
            // Asian Currencies
            'JPY' => __('Japanese Yen (¥)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'CNY' => __('Chinese Yuan (¥)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'INR' => __('Indian Rupee (₹)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'KRW' => __('South Korean Won (₩)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'SGD' => __('Singapore Dollar (S$)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'HKD' => __('Hong Kong Dollar (HK$)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'TWD' => __('Taiwan Dollar (NT$)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'THB' => __('Thai Baht (฿)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'MYR' => __('Malaysian Ringgit (RM)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'IDR' => __('Indonesian Rupiah (Rp)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'PHP' => __('Philippine Peso (₱)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'VND' => __('Vietnamese Dong (₫)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            
            // Middle Eastern Currencies
            'ILS' => __('Israeli New Shekel (₪)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'AED' => __('UAE Dirham (د.إ)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'SAR' => __('Saudi Riyal (﷼)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'QAR' => __('Qatari Rial (﷼)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'IRR' => __('Iranian Rial (﷼)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'TRY' => __('Turkish Lira (₺)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            
            // Oceania Currencies
            'AUD' => __('Australian Dollar (A$)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'NZD' => __('New Zealand Dollar (NZ$)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            
            // African Currencies
            'ZAR' => __('South African Rand (R)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'EGP' => __('Egyptian Pound (E£)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'NGN' => __('Nigerian Naira (₦)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            'KES' => __('Kenyan Shilling (KSh)', 'tavtheme-simple-multi-currency-for-woocommerce'),
            
            // Other Currencies
            'RUB' => __('Russian Ruble (₽)', 'tavtheme-simple-multi-currency-for-woocommerce'),
        );
    }

    public function enqueue_geolocation_scripts() {
        wp_enqueue_script('jquery');
        
        // Register and enqueue our geolocation JS
        wp_register_script('tav-geolocation', TAV_SIMPLE_CURRENCY_URL . 'js/geolocation.js', array('jquery'), TAV_SIMPLE_CURRENCY_VERSION, true);
        
        // Create the js file if it doesn't exist
        $js_dir = TAV_SIMPLE_CURRENCY_PATH . 'js';
        if (!file_exists($js_dir)) {
            mkdir($js_dir, 0755, true);
        }
        
        // Enqueue the script
        wp_enqueue_script('tav-geolocation');
        
        // Add variables for the AJAX request
        wp_localize_script('tav-geolocation', 'tav_geolocation_vars', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tav_geolocation_nonce')
        ));
    }
    
    public function ajax_get_user_currency() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'tav_geolocation_nonce')) {
            wp_send_json_error('Invalid nonce');
            exit;
        }
        
        // Get user currency and country
        if (isset($_POST['currency']) && isset($_POST['country'])) {
            $user_currency = sanitize_text_field($_POST['currency']);
            $user_country = sanitize_text_field($_POST['country']);
            
            // Store in session
            $_SESSION['tav_user_currency'] = $user_currency;
            $_SESSION['tav_user_country'] = $user_country;
            
            // Debug log
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("User currency detected: " . $user_currency . " from " . $user_country);
            }
            
            wp_send_json_success();
            exit;
        }
        
        wp_send_json_error('Invalid data');
        exit;
    }

    // Add this function to reset any existing currency settings when the plugin is activated
    public static function activate() {
        // Set EUR as the base currency in WooCommerce
        update_option('woocommerce_currency', 'EUR');
        
        // Clear any transients that might store currency information
        delete_transient('tav_exchange_rates');
        
        // Clear any WooCommerce transients
        global $wpdb;
        $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '%_transient_wc_%'");
    }

    // Add this function to check and fix currency settings on every page load
    public function check_currency_settings() {
        // Check if WooCommerce base currency is set to EUR
        $wc_currency = get_option('woocommerce_currency');
        if ($wc_currency !== 'EUR') {
            // Fix it
            update_option('woocommerce_currency', 'EUR');
            
            // Debug log
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("Fixed WooCommerce base currency: changed from " . $wc_currency . " to EUR");
            }
        }
        
        // Set the base currency in our plugin
        $base_currency = 'EUR';
        
        // If we're in admin, make sure we're showing EUR
        if (is_admin() && !wp_doing_ajax()) {
            $this->current_currency = $base_currency;
        }
        
        // Apply our filters to ensure EUR is used as base
        add_filter('option_woocommerce_currency', array($this, 'force_woocommerce_currency_option'), 999);
        
        // Clear any cached currency settings that might be causing issues
        $this->clear_currency_cache_settings();
    }
    
    // Add this function to clear any cached currency settings in the database
    private function clear_currency_cache_settings() {
        global $wpdb;
        
        // Clear specific options that might be storing DKK as currency
        $options_to_check = array(
            'woocommerce_currency',
            'woocommerce_default_currency',
            'woocommerce_currency_pos',
            'woocommerce_price_thousand_sep',
            'woocommerce_price_decimal_sep',
            'woocommerce_price_num_decimals'
        );
        
        foreach ($options_to_check as $option_name) {
            $option_value = get_option($option_name);
            if ($option_name === 'woocommerce_currency' && $option_value !== 'EUR') {
                update_option($option_name, 'EUR');
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("Fixed option {$option_name}: changed from {$option_value} to EUR");
                }
            }
        }
        
        // Clear any transients related to currency
        $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '%_transient%currency%'");
        $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '%_transient%exchange%'");
        $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '%_transient%rate%'");
        
        // Clear WooCommerce sessions that might have currency info
        $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '%_wc_session_%'");
        
        // Clear our own transients
        delete_transient('tav_exchange_rates');
        
        // Debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Cleared all currency cache settings");
        }
    }
}

// Initialize plugin
function TAV_Simple_Currency() {
    return TAV_Simple_Currency::get_instance();
}

// Register activation hook
register_activation_hook(__FILE__, array('TAV_Simple_Currency', 'activate'));

// Start plugin
TAV_Simple_Currency(); 