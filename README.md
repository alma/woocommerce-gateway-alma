Alma Monthly Payments for WooCommerce
=====

Contributors: almapayments, olance  
Tags: payments, payment gateway, woocommerce, ecommerce, e-commerce, sell, woo commerce, alma, monthly payments, split payments  
Requires at least: 4.4  
Tested up to: 5.4  
Requires PHP: 5.6  
Stable tag: 1.0  
License: GPLv3  
License URI: https://www.gnu.org/licenses/gpl-3.0.html  

This plugin adds a new payment method to WooCommerce, which allows you to offer monthly payments to your customer using Alma.

## ⚠️ Restricted availability

🇫🇷Pour le moment, Alma n'est disponible qu'aux marchands **français** avec lesquels nous pouvons interagir. Vous pouvez [créer votre compte](https://dashboard.getalma.eu) librement, mais devez nous contacter pour l'activer et commencer à accepter des paiements.

🇬🇧For the moment, Alma is only available to **french** merchants with whom we can communicate. You can [create your account](https://dashboard.getalma.eu) freely, but you must contact us to have it activated and to start accepting payments.


## Description

[Alma](https://getalma.eu) is a service to provide merchants with an **easy** and **safe** monthly payments solution.  
Let your customers pay for their purchases at their own pace! You'll receive the funds instantly, and your customer will pay later over a few monthly instalments.

This plugin integrates Alma into WooCommerce by adding a new payment method that you can activate to offer monthly payments to your customers.

## Installation

### Prerequisites

You first need to create your merchant account on [dashboard.getalma.eu](https://dashboard.getalma.eu) and activate your account.

### Configuring the plugin

After installing the plugin, go to WooCommerce settings and activate the new Alma payment method.
You should be redirected to the payment method settings upon activation.

Fill in the API keys for your account, which you can find on your dashboard\'s [security page](https://dashboard.getalma.eu/security).

After you save your API keys, you\'ll have access to different settings to control what the plugin should display on the Cart and Checkout pages.
We advise you to stay in \"Test\" mode until you\'re happy with your configuration and are ready to accept payments from your customers.

Once everything is properly set up, go ahead and switch to \"Live\" mode!

## Screenshots

![Alma\'s payment method settings](.wordpress.org/screenshot-1.png)
![Cart eligibility for monthly payments](.wordpress.org/screenshot-2.png)
![Payment method at checkout](.wordpress.org/screenshot-3.png)
![Alma\'s payment page that users are sent to upon order confirmation](.wordpress.org/screenshot-4.png)

## Contributing

You need to have `docker` and `docker-compose` installed on your computer.

- Clone the repository
- Run `docker-compose up` to start WordPress
- Run `docker-compose exec wordpress bash` to open a shell in the docker container
- Run `cd wp-content/plugins/alma-woocommerce-gateway` to go into the plugin directory
- Run `composer install` to install dependencies
- Go to http://localhost:8000 and follow WordPress installation steps
- Go to http://localhost:8000/wp-admin/plugin-install.php and install & enable *WooCommerce*
- Create a product with a price
- Go to http://localhost:8000/wp-admin/plugins.php and enable alma
- Create an account on http://dashboard.sandbox.getalma.eu/ to get an API key
- Go to http://localhost:8000/wp-admin/admin.php?page=wc-settings&tab=checkout&section=alma and fill your API key
- Visit the shop and add a product to the cart to see Alma in action 🚀

### Xdebug

To configure or disable xdebug, edit the `docker/customphp-config.ini` file and restart the docker container.

### Translations

To edit the translations, use [Poedit](https://poedit.net/)

- Open the `.pot` file and click on `Update from code`, then save
- Open the `.po` file and click on `Update from code`, add/update the translations, then save

### Build

To build extension for production run `./build.sh`
