# Upgrading to MultiSafepay for Craft Commerce 5.0 (Commerce 6)

MultiSafepay for Craft Commerce 5.0 supports Craft Commerce 6. It talks to the [MultiSafepay JSON API](https://docs.multisafepay.com/reference/introduction) directly, rather than through Omnipay.

## Requirements

- Craft CMS 6.0 and Craft Commerce 6.0 or later
- PHP 8.5 or later
- `craftcms/yii2-adapter` is not needed.

## Upgrade steps

1. Update to MultiSafepay for Craft Commerce 4.1.0 and Craft Commerce 5 first.
2. Upgrade your project to Craft 6 and Craft Commerce 6.
3. Update the plugin: `composer require craftcms/commerce-multisafepay:^5.0`.
4. Review the breaking changes below.

Existing MultiSafepay gateways keep working: gateways saved with the old `craft\commerce\multisafepay\gateways\Gateway` type load as `CraftCms\CommerceMultiSafepay\Gateway`, and save with the new class name the next time they’re saved. Their settings (`apiKey`, `testMode`, `locale` and `sendCartInfo`) are unchanged.

## Breaking changes

### Omnipay

The plugin no longer depends on `craftcms/commerce-omnipay`, `omnipay/multisafepay` or `omnipay/common`. If your project uses Omnipay classes directly, require those packages yourself.

### Events

The Omnipay gateway events are gone. To change the request sent to MultiSafepay when an order is created, listen for `CraftCms\CommerceMultiSafepay\Events\PaymentRequestBuilding` and modify its `$request` array, which is the body of the [Create order](https://docs.multisafepay.com/reference/create-order) request.

| Before | After |
| --- | --- |
| `craft\commerce\omnipay\base\Gateway::EVENT_BUILD_GATEWAY_REQUEST` | `CraftCms\CommerceMultiSafepay\Events\PaymentRequestBuilding` |
| `craft\commerce\omnipay\base\Gateway::EVENT_BEFORE_SEND_PAYMENT_REQUEST` | `CraftCms\CommerceMultiSafepay\Events\PaymentRequestBuilding` |
| `craft\commerce\omnipay\base\Gateway::EVENT_BEFORE_GATEWAY_REQUEST_SEND` | No replacement |
| `craft\commerce\omnipay\base\Gateway::EVENT_AFTER_CREATE_ITEM_BAG` | `CraftCms\CommerceMultiSafepay\Events\PaymentRequestBuilding` (modify `$request['shopping_cart']['items']`) |

Before:

```php
use craft\commerce\multisafepay\gateways\Gateway;
use craft\commerce\omnipay\events\SendPaymentRequestEvent;
use yii\base\Event;

Event::on(Gateway::class, Gateway::EVENT_BEFORE_SEND_PAYMENT_REQUEST, function(SendPaymentRequestEvent $event) {
    $data = $event->requestData;
    $data['gateway'] = 'IDEAL';
    $event->modifiedRequestData = $data;
});
```

After:

```php
use CraftCms\CommerceMultiSafepay\Events\PaymentRequestBuilding;
use Illuminate\Support\Facades\Event;

Event::listen(function(PaymentRequestBuilding $event): void {
    $event->request['gateway'] = 'IDEAL';
});
```

`EVENT_BEFORE_SEND_PAYMENT_REQUEST` already used MultiSafepay’s parameter names, so those listeners only need the new event. Code that listened to `EVENT_BUILD_GATEWAY_REQUEST` and set Omnipay parameters (such as `returnUrl` or `transactionId`) needs to use MultiSafepay’s names (`payment_options.redirect_url`, `order_id`). The event is only dispatched for new orders, not for refunds or status lookups.

### Classes and namespaces

| Before | After |
| --- | --- |
| `craft\commerce\multisafepay\Plugin` | `CraftCms\CommerceMultiSafepay\Plugin` |
| `craft\commerce\multisafepay\gateways\Gateway` | `CraftCms\CommerceMultiSafepay\Gateway` (the old name is kept as an alias) |
| `craft\commerce\multisafepay\migrations\Install` | Removed |
| `craft\commerce\omnipay\base\RequestResponse` | `CraftCms\CommerceMultiSafepay\Responses\OrderResponse` and `CraftCms\CommerceMultiSafepay\Responses\RefundResponse` |

The gateway no longer extends `craft\commerce\omnipay\base\OffsiteGateway`, so Omnipay methods such as `createCard()`, `populateRequest()` and `getItemListForOrder()` are gone.

The `Install` migration only converted Craft Commerce 1 `craft\commerce\gateways\MultiSafepay_Rest` gateways, and was removed.

### Payments and webhooks

- The gateway now supports webhooks. Each order sets its notification URL to the gateway’s webhook (`commerce/webhooks/process-webhook/gateway/<id>`) with `notification_method: POST`. `POST` notifications must have a valid `Auth` signature made with the gateway’s API key and less than 10 minutes old, or they’re rejected with a 403. Notifications with `GET` (from a webhook URL set in the MultiSafepay dashboard) are accepted too. In both cases, the plugin ignores the notification’s contents and fetches the order status from MultiSafepay.
- Bank transfers that are awaiting payment (`initialized`), and card payments held for review (`uncleared`), now complete the order as processing payments when the customer returns, rather than failing. The webhook records the payment once MultiSafepay reports it `completed`.
- Failed payments now say whether they were declined, canceled or expired.
- Refunds with an amount of zero are refused, because MultiSafepay refunds the whole order when the amount is zero.

### Templates and Twig

The gateway has no payment form, as before: customers choose a payment method on MultiSafepay’s payment pages. `gateway.getPaymentFormHtml()` now returns `null` instead of an empty string.

The settings are rendered by `Gateway::settingsUi()`, and the `commerce-multisafepay/gatewaySettings` template was removed.
