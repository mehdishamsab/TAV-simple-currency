/**
 * TavTheme Simple Multi Currency for WooCommerce - Geolocation
 *
 * This file handles the geolocation functionality to detect user's currency based on IP.
 *
 * @package TavTheme Simple Multi Currency
 * @since 1.0.0
 */

jQuery(document).ready(function($) {
    /**
     * Detect user's currency based on IP address
     * Only run if we don't have the user currency stored in localStorage
     */
    if (!localStorage.getItem('tav_user_currency')) {
        // Use IP-based geolocation service
        $.ajax({
            url: 'https://ipapi.co/json/',
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response && response.currency) {
                    // Store the user's currency and country in localStorage
                    localStorage.setItem('tav_user_currency', response.currency);
                    localStorage.setItem('tav_user_country', response.country_name);
                    
                    // Send to server to store in session
                    $.ajax({
                        url: tav_geolocation_vars.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'tav_get_user_currency',
                            currency: response.currency,
                            country: response.country_name,
                            nonce: tav_geolocation_vars.nonce
                        }
                    });
                }
            },
            error: function() {
                console.log('Could not detect user location');
            }
        });
    }
}); 