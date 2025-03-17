/**
 * TavTheme Simple Multi Currency for WooCommerce - Currency Switcher
 *
 * This file handles the currency switching functionality.
 *
 * @package TavTheme Simple Multi Currency
 * @since 1.0.0
 */

jQuery(document).ready(function($) {
    /**
     * Handle currency switching when the user selects a different currency
     */
    $('.tav-currency-switcher select').on('change', function() {
        var currency = $(this).val();
        
        // Show loading indicator
        $('body').append('<div class="tav-currency-loading" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(255,255,255,0.7);z-index:9999;display:flex;justify-content:center;align-items:center;"><div>Loading...</div></div>');
        
        // Send AJAX request to switch currency
        $.ajax({
            url: tav_currency_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'tav_switch_currency',
                currency: currency,
                nonce: tav_currency_vars.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Force clear any cached data
                    sessionStorage.clear();
                    localStorage.removeItem('wc_cart_hash');
                    
                    // Reload the page
                    window.location.reload(true);
                } else {
                    alert('Failed to switch currency. Please try again.');
                    $('.tav-currency-loading').remove();
                }
            },
            error: function() {
                alert('An error occurred. Please try again.');
                $('.tav-currency-loading').remove();
            }
        });
    });
}); 