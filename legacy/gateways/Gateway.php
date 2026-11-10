<?php

namespace craft\commerce\multisafepay\gateways;

// Gateways saved with MultiSafepay for Craft Commerce 4 store this class name as their type.
/** @deprecated in 5.0.0. Use {@see \CraftCms\CommerceMultiSafepay\Gateway} instead. */
class_alias(\CraftCms\CommerceMultiSafepay\Gateway::class, 'craft\commerce\multisafepay\gateways\Gateway');

/** @phpstan-ignore-next-line */
if (false) {
    class Gateway extends \CraftCms\CommerceMultiSafepay\Gateway
    {
    }
}
