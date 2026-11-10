<?php

declare(strict_types=1);

namespace CraftCms\CommerceMultiSafepay;

use Composer\InstalledVersions;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * A small client for the MultiSafepay JSON API.
 *
 * Every method returns the decoded response body, which has a `success` flag, and either `data` or `error_code` and
 * `error_info`.
 *
 * @see https://docs.multisafepay.com/reference/introduction
 */
class Client
{
    public const string LIVE_URL = 'https://api.multisafepay.com/v1/json';

    public const string TEST_URL = 'https://testapi.multisafepay.com/v1/json';

    public function __construct(
        private readonly string $apiKey,
        private readonly bool $testMode = false,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @see https://docs.multisafepay.com/reference/create-order
     */
    public function createOrder(array $data): array
    {
        return $this->request()->post('orders', $data)->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     * @see https://docs.multisafepay.com/reference/getorder
     */
    public function getOrder(string $orderId): array
    {
        return $this->request()->get('orders/' . rawurlencode($orderId))->json() ?? [];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @see https://docs.multisafepay.com/reference/refundorder
     */
    public function refundOrder(string $orderId, array $data): array
    {
        return $this->request()->post('orders/' . rawurlencode($orderId) . '/refunds', $data)->json() ?? [];
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->testMode ? self::TEST_URL : self::LIVE_URL)
            ->withHeaders(['api_key' => $this->apiKey])
            ->acceptJson()
            ->asJson()
            ->withUserAgent($this->userAgent());
    }

    private function userAgent(): string
    {
        return implode(' ', [
            'MultiSafepayCraftCommerce/' . (InstalledVersions::getPrettyVersion('craftcms/commerce-multisafepay') ?? 'dev'),
            'CraftCommerce/' . (InstalledVersions::getPrettyVersion('craftcms/commerce') ?? 'dev'),
        ]);
    }
}
