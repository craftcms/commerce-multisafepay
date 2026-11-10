<p align="center"><img src="./resources/icon.svg" width="100" height="100" alt="MultiSafepay for Craft Commerce icon"></p>

<h1 align="center">MultiSafepay for Craft Commerce</h1>

This plugin provides a [MultiSafepay](https://www.multisafepay.com/) integration for [Craft Commerce](https://craftcms.com/commerce).

## Requirements

MultiSafepay 5.x requires Craft CMS 6.0, Craft Commerce 6.0 and PHP 8.5 or later.

MultiSafepay 4.1.x supports Craft 4 and Craft Commerce 4, or Craft 5 and Craft Commerce 5. If you’re upgrading from 4.x, see [UPGRADE.md](UPGRADE.md).

## Installation

You can install this plugin from the Plugin Store or with Composer.

#### From the Plugin Store

Go to the **Plugin Store** in your project’s control panel and search for “MultiSafepay for Craft Commerce”. Then click on the **Install** button in the sidebar.

#### With Composer

Open your terminal and run the following commands:

```bash
# Navigate to your project directory:
cd /path/to/my-project.test

# Require the plugin package with Composer:
composer require craftcms/commerce-multisafepay

# Run the installer:
php artisan craft:plugin:install commerce-multisafepay
```

## Setup

To add a MultiSafepay payment gateway, go to Commerce → Settings → Gateways, create a new gateway, and set the gateway type to “MultiSafepay REST”.

- **API Key**: the API key of your website in the MultiSafepay dashboard.
- **Locale**: the language of MultiSafepay’s payment pages and emails, such as `nl_NL`. Leave it blank to let MultiSafepay decide.
- **Test mode?**: use MultiSafepay’s test environment (`testapi.multisafepay.com`). Test mode needs an API key from a MultiSafepay test account.
- **Send cart information?**: send the order’s line items and adjustments to MultiSafepay, which shows them on its payment pages. The cart is only sent when the customer pays in the order’s currency.

> [!NOTE]
> All settings can be set to environment variables. See [Environmental Configuration](https://craftcms.com/docs/6.x/configure.html#control-panel-settings) in the Craft docs to learn more about using secrets in configuration.

Customers choose a payment method on MultiSafepay’s payment pages. MultiSafepay reports payment status changes to the gateway’s webhook, which the plugin sets on each order, so you don’t need to configure a webhook URL in the MultiSafepay dashboard. Notifications are sent with `POST` and signed with your API key, and the plugin always fetches the order status from MultiSafepay rather than trusting the notification.

## Customizing payment requests

To change the request sent to MultiSafepay when an order is created, listen for the `PaymentRequestBuilding` event and modify its `request` array. Its keys are MultiSafepay’s [Create order](https://docs.multisafepay.com/reference/create-order) parameters.

```php
use CraftCms\CommerceMultiSafepay\Events\PaymentRequestBuilding;
use Illuminate\Support\Facades\Event;

Event::listen(function(PaymentRequestBuilding $event): void {
    // Send the customer straight to iDEAL
    $event->request['gateway'] = 'IDEAL';
});
```
