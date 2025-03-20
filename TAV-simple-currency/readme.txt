=== TavTheme free Multi Currency for WooCommerce ===
Contributors: tavtheme
Donate in PayPal: mehdishamsab@gmail.com  
Tags: woocommerce, currency, multi-currency, exchange rate, ecommerce
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.2
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A simple multi-currency plugin for WooCommerce that allows each product to have its own currency and shows prices in the user's local currency.

== Description ==

TavTheme Simple Multi Currency for WooCommerce is a lightweight and easy-to-use plugin that allows you to set different currencies for each product in your WooCommerce store. It also automatically detects the user's location and shows prices in their local currency for better user experience.

= Key Features =

* **Product-specific currencies**: Set a different currency for each product
* **Automatic currency detection**: Detects user's currency based on their IP address
* **Dual price display**: Shows prices in both the product's currency and the user's local currency
* **Seamless checkout experience**: Maintains the product's currency throughout the checkout process
* **Payment gateway integration**: Works with popular payment gateways including Mollie
* **Real-time exchange rates**: Uses up-to-date exchange rates for accurate currency conversion

= How It Works =

1. Set the currency for each product in the product edit page
2. The plugin automatically detects the user's location and currency
3. Prices are displayed in both the product's currency and the user's local currency (if different)
4. The product's currency is maintained throughout the checkout process
5. Payment is processed in the product's currency

= Supported Payment Gateways =

* Mollie
* PayPal
* Stripe
* And many more!

= Supported Currencies =

The plugin supports all major currencies including:

* Euro (EUR)
* US Dollar (USD)
* British Pound (GBP)
* Danish Krone (DKK)
* Swedish Krona (SEK)
* Norwegian Krone (NOK)
* And many more!

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/tav-simple-currency` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Make sure WooCommerce is installed and activated
4. Go to any product edit page and set the currency for that product

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =

Yes, this plugin is an extension for WooCommerce and requires WooCommerce to be installed and activated.

= Can I have products with different currencies in the same store? =

Yes, that's the main feature of this plugin. You can set a different currency for each product.

= Can users add products with different currencies to the cart? =

No, to maintain a consistent checkout experience, users can only add products with the same currency to the cart at one time.

= How are exchange rates calculated? =

Exchange rates are fetched from a reliable API and cached for 12 hours. If the API is unavailable, the plugin falls back to default rates.

= Does this plugin work with all payment gateways? =

The plugin is designed to work with most popular payment gateways. It has been specifically tested with Mollie, PayPal, and Stripe.

== Screenshots ==

1. Product currency settings in the product edit page
2. Dual currency display on the product page
3. Cart with product-specific currency
4. Checkout process with the product's currency

== Changelog ==

= 1.5.0 =
* Initial release

== Upgrade Notice ==

= 1.5.0 =
Initial release of the plugin.

== Credits ==

* This plugin uses the [ipapi.co](https://ipapi.co/) service for IP-based geolocation.
* Exchange rates are provided by [Open Exchange Rates](https://open.er-api.com/). 