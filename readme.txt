=== Alma Monthly Payments for WooCommerce ===
Contributors: almapayments, olance
Tags: payments, payment gateway, woocommerce, ecommerce, e-commerce, sell, woo commerce, alma, monthly payments, split payments
Requires at least: 4.4
Tested up to: 5.4
Requires PHP: 5.6
Stable tag: 1.3.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

This plugin adds a new payment method to WooCommerce, which allows you to offer monthly payments to your customer using Alma.

== Description ==
[Alma](https://getalma.eu) is a service to provide merchants with an **easy** and **safe** monthly payments solution.

This plugin integrates Alma into WooCommerce by adding a new payment method that you can activate to offer monthly payments to your customers.

== Installation ==

= Prerequisites =

You first need to create your merchant account on [dashboard.getalma.eu](https://dashboard.getalma.eu) and activate your account.

= Configuring the plugin =

After installing the plugin, go to WooCommerce settings and activate the new Alma payment method.
You should be redirected to the payment method settings upon activation.

Fill in the API keys for your account, which you can find on your dashboard\'s [security page](https://dashboard.getalma.eu/security).

After you save your API keys, you\'ll have access to different settings to control what the plugin should display on the Cart and Checkout pages.
We advise you to stay in \"Test\" mode until you\'re happy with your configuration and are ready to accept payments from your customers.

Once everything is properly set up, go ahead and switch to \"Live\" mode!

== Screenshots ==
1. Alma\'s payment method settings
2. Cart eligibility for monthly payments
3. Payment method at checkout
4. Alma\'s payment page that users are sent to upon order confirmation

== Changelog ==

= 1.x.x =

* Add autoloader
* Minor refactorisations

= 1.3.0 =

* Remove PHP warnings & do not display widget on out-stock or un-priced product
* Minor refactoring
* Enhance dev docker env (display php warnings / errors + prioritize custom-php-ini file)
* Fix widget display price without tax depending on WooCommerce tax rule configuration
* Add fallback locale on checkout payment ALMA API call
* Add filter to override locale on checkout payment ALMA API call
* Add widget shortcodes

= 1.2.3 =

* Use unpkg.com instead of unpkg.io

= 1.2.2 =

* Do not display a radio button at checkout, when there's only one payment plan available

= 1.2.1 =

* Fixes default min/max amount values being converted to cents multiple times in a row
* Improves xdebug configuration

= 1.2.0 =

* Allows merchant to activate 2-, 3- a 4-installment plans, with min and max allowed amounts per plan
* Displays an Alma widget on product pages and cart page to inform customers of eligible payment plans
* Displays a full payment plan for each plan option on the checkout page
* Enables Alma by default for all `fr_*` locales
* Adds a `alma_wc_enable_for_locale` that can be used to enable Alma for additional locales
* Any of the module's settings can be overridden via a filter: `alma_wc_settings_[setting_name]`
* Fixes compatibility issues with WooCommerce 2.6.14
* Fixes many bugs
* Dependencies update

= 1.17 =
* Do not require Live key in Test mode and vice versa
* Include cancel url, order reference & order URLs in payment data
* Don't show the eligibility message in case of API error
* Stop checking in vendor dependencies – they'll be included in each release's ZIP file

= 1.1.6 =
* Dependencies update

= 1.1.5 =
* Fixes display on cart option that was always on
* Tested against latest versions of Wordpress/WooCommerce

= 1.1.4 =
* Fixes various warnings when WooCommerce isn't activated
* Updates dependencies
* Fixes errors when Alma's API is not available
* Attempt to fix problem triggered when calling the Wordpress API

= 1.1.3 =
* Fixes warning when `excluded_products_list` is not set

= 1.1.2 =
* Adds hidden option to be able to choose installments count to use for created payments

= 1.1.1 =
* Fixes bug occurring in `wp-admin/nav-menus.php`

= 1.1.0 =
* Fixes case of "alma" in includes path
* Adds possibility to exclude product categories from installment payments, to help enforce contractual restrictions
  (i.e. Alma cannot be used to sell/buy virtual/downloadable products such as gift cards, subscriptions, ...)

= 1.0.1 =
Let's start following semver.

* Switches logo file to SVG
* Uses latest Eligibility check behaviour to remove one extra call to the API
* Adds User-Agent string containing the module's version, WordPress version, WooCommerce, PHP client and PHP versions,
to all requests going to Alma's API.
* Adds support for Alma's IPN callback mechanism

= 1.0.0 =
This version evolved for a while without any version bump 🤷‍♂️
Features in the latest push to this release:

* Module can be configured in Test and Live mode; Test mode only shows Alma's payment method to visitors who are also
administrators of the shop
* A message displays below the cart to indicate whether the purchase is eligible to monthly installments
* The module adds a payment method to the checkout, which redirects the user to Alma's payment page.
If everything goes right (i.e. Customer doesn't cancel, pays the right amount, ... ), the order is validated upon customer return.
