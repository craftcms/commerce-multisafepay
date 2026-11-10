<?php

declare(strict_types=1);

namespace CraftCms\CommerceMultiSafepay\Events;

use CraftCms\Commerce\Payment\Data\Transaction;
use CraftCms\Commerce\Payment\Forms\BasePaymentForm;

/**
 * Dispatched before an order is created with MultiSafepay. Listeners may modify `$request`, which is sent as the body
 * of the [Create order](https://docs.multisafepay.com/reference/create-order) request.
 */
class PaymentRequestBuilding
{
    /**
     * @param array<string, mixed> $request
     */
    public function __construct(
        public readonly Transaction $transaction,
        public readonly ?BasePaymentForm $form,
        public array $request,
    ) {
    }
}
