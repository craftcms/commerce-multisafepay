# Release Notes for MultiSafepay for Craft Commerce

## Unreleased

> [!WARNING]
> This release requires Craft Commerce 6. See [UPGRADE.md](UPGRADE.md) for the breaking changes.

- MultiSafepay for Craft Commerce now requires Craft CMS 6, Craft Commerce 6 and PHP 8.5.
- The plugin now uses the MultiSafepay JSON API directly, and no longer requires `craftcms/commerce-omnipay` or `omnipay/multisafepay`.
- Moved all classes to the `CraftCms\CommerceMultiSafepay` namespace. `craft\commerce\multisafepay\gateways\Gateway` is kept as an alias of `CraftCms\CommerceMultiSafepay\Gateway`.
- Added `CraftCms\CommerceMultiSafepay\Events\PaymentRequestBuilding`, which replaces the Omnipay request events.
- The gateway now supports webhooks. MultiSafepay sends signed `POST` notifications, and the plugin records the order status it fetches from MultiSafepay.
- Bank transfers that are awaiting payment, and card payments held for review (“uncleared”), now complete the order as processing payments instead of failing.
- Failed payments now show a message saying whether the payment was declined, canceled or expired.

## 4.1.0 - 2024-03-22

- Added Craft CMS 5 and Craft Commerce 5 compatibility.

## 4.0.0 - 2022-05-04

### Added
- Added Craft CMS 4 and Craft Commerce 4 compatibility.
- All gateway settings now support environment variables.

## 3.0.0 - 2021-04-20

### Changed
- The plugin now requires Craft 3.6 and Commerce 3.3 or later.
- The plugin now requires Guzzle 7.

## 2.1.0.1 - 2019-07-24

### Changed
- Updated changelog with missing changes for 2.1.0

## 2.1.0 - 2019-07-24

### Changed
- Update Craft Commerce requirements to allow for Craft Commerce 3.

## 2.0.0 - 2019-03-04

### Added
- The API Key and Locale settings can now be set to environment variables.

### Changed
- MultiSafepay for Craft Commerce now uses Omnipay v3.
- MultiSafepay for Craft Commerce now requires Craft 3.1.5 or later.

## 1.1.0 - 2019-01-22

### Changed
- Switched to an MIT license.

## 1.0.0.1 - 2018-12-11

### Changed
- Added a `craftcms/cms` requirement to `composer.json`.

## 1.0.0 - 2018-04-02

- Initial release.
