<?php

declare(strict_types=1);

namespace CraftCms\CommerceMultiSafepay\Responses;

use CraftCms\Commerce\Payment\Gateway\Contracts\RequestResponseInterface;

use function CraftCms\Cms\t;

/**
 * Wraps a MultiSafepay order, as returned when an order is created or fetched, or the error MultiSafepay returned
 * instead of one.
 *
 * @see https://docs.multisafepay.com/reference/getorder
 * @see https://docs.multisafepay.com/docs/payment-statuses
 */
class OrderResponse implements RequestResponseInterface
{
    /**
     * @param array<string, mixed> $data The decoded MultiSafepay response
     */
    public function __construct(
        protected readonly array $data,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->isOrder() && !$this->isRedirect() && $this->status() === 'completed';
    }

    public function isProcessing(): bool
    {
        if (!$this->isOrder()) {
            return false;
        }

        // Card payments flagged for review stay uncleared until they’re accepted or declined.
        if ($this->status() === 'uncleared') {
            return true;
        }

        // Bank transfers stay initialized until the customer has transferred the money.
        return $this->status() === 'initialized' && ($this->data['data']['payment_details']['type'] ?? null) === 'BANKTRANS';
    }

    public function isRedirect(): bool
    {
        // Only a newly created order has a payment URL and no status yet.
        return $this->isOrder() && $this->status() === '' && $this->getRedirectUrl() !== '';
    }

    public function getRedirectMethod(): string
    {
        return 'GET';
    }

    public function getRedirectData(): array
    {
        return [];
    }

    public function getRedirectUrl(): string
    {
        return (string)($this->data['data']['payment_url'] ?? '');
    }

    /**
     * Returns MultiSafepay’s transaction ID, which orders only have once the customer has started paying.
     */
    public function getTransactionReference(): string
    {
        return $this->isOrder() ? (string)($this->data['data']['transaction_id'] ?? '') : '';
    }

    public function getCode(): string
    {
        return $this->isOrder() ? $this->status() : (string)($this->data['error_code'] ?? '');
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function getMessage(): string
    {
        if (!$this->isOrder()) {
            return (string)($this->data['error_info'] ?? t('Unable to reach MultiSafepay.', category: 'commerce-multisafepay'));
        }

        if ($this->isSuccessful() || $this->isProcessing() || $this->isRedirect()) {
            return '';
        }

        return match ($this->status()) {
            'declined' => t('The payment was declined.', category: 'commerce-multisafepay'),
            'cancelled', 'canceled', 'void' => t('The payment was canceled.', category: 'commerce-multisafepay'),
            'expired' => t('The payment expired.', category: 'commerce-multisafepay'),
            default => t('The payment was not completed.', category: 'commerce-multisafepay'),
        };
    }

    protected function isOrder(): bool
    {
        return ($this->data['success'] ?? false) === true && is_array($this->data['data'] ?? null);
    }

    protected function status(): string
    {
        return (string)($this->data['data']['status'] ?? '');
    }
}
