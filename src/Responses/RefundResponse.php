<?php

declare(strict_types=1);

namespace CraftCms\CommerceMultiSafepay\Responses;

use CraftCms\Commerce\Payment\Gateway\Contracts\RequestResponseInterface;

use function CraftCms\Cms\t;

/**
 * Wraps the response to a MultiSafepay refund request.
 *
 * @see https://docs.multisafepay.com/reference/refundorder
 */
class RefundResponse implements RequestResponseInterface
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
        return ($this->data['success'] ?? false) === true;
    }

    public function isProcessing(): bool
    {
        return false;
    }

    public function isRedirect(): bool
    {
        return false;
    }

    public function getRedirectMethod(): string
    {
        return '';
    }

    public function getRedirectData(): array
    {
        return [];
    }

    public function getRedirectUrl(): string
    {
        return '';
    }

    public function getTransactionReference(): string
    {
        return $this->isSuccessful() ? (string)($this->data['data']['transaction_id'] ?? '') : '';
    }

    public function getCode(): string
    {
        return $this->isSuccessful() ? '' : (string)($this->data['error_code'] ?? '');
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function getMessage(): string
    {
        if ($this->isSuccessful()) {
            return '';
        }

        return (string)($this->data['error_info'] ?? t('Unable to reach MultiSafepay.', category: 'commerce-multisafepay'));
    }
}
