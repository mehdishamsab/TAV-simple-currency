<?php
/**
 * Uninstall TavTheme Simple Multi Currency Switcher
 *
 * @package TavTheme Simple Multi Currency
 * @since 1.0.0
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete options
delete_option('tav_simple_currency_version');

// Delete transients
delete_transient('tav_exchange_rates');

// Delete product meta
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_product_currency'");
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_base_price'");

// Clear any cached data
wp_cache_flush(); 