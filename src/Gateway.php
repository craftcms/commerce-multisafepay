<?php

declare(strict_types=1);

namespace CraftCms\CommerceMultiSafepay;

use CraftCms\Cms\Cp\SelectOptions;
use CraftCms\Cms\Support\Env;
use CraftCms\Cms\Support\Json;
use CraftCms\Cms\Support\Url;
use CraftCms\Cms\Ui\Controls\BooleanMenu;
use CraftCms\Cms\Ui\Controls\Text;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Commerce\Exceptions\NotImplementedException;
use CraftCms\Commerce\Helpers\Currency;
use CraftCms\Commerce\Order\Adjuster\Discount;
use CraftCms\Commerce\Order\Elements\Order;
use CraftCms\Commerce\Payment\Currencies;
use CraftCms\Commerce\Payment\Data\PaymentSource;
use CraftCms\Commerce\Payment\Data\Transaction;
use CraftCms\Commerce\Payment\Forms\BasePaymentForm;
use CraftCms\Commerce\Payment\Forms\OffsitePaymentForm;
use CraftCms\Commerce\Payment\Gateway\Contracts\RequestResponseInterface;
use CraftCms\Commerce\Payment\Gateway\Gateway as BaseGateway;
use CraftCms\Commerce\Payment\Models\Transaction as TransactionModel;
use CraftCms\Commerce\Payment\Transactions;
use CraftCms\CommerceMultiSafepay\Events\PaymentRequestBuilding;
use CraftCms\CommerceMultiSafepay\Responses\OrderResponse;
use CraftCms\CommerceMultiSafepay\Responses\RefundResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Override;
use RuntimeException;

use function CraftCms\Cms\t;

/**
 * MultiSafepay payment gateway, using the MultiSafepay JSON API.
 *
 * Customers are redirected to MultiSafepay’s payment pages, and MultiSafepay reports status changes to the gateway’s
 * webhook.
 */
class Gateway extends BaseGateway
{
    /**
     * How long a signed webhook notification stays valid, in seconds. Matches MultiSafepay’s PHP SDK.
     */
    public const int NOTIFICATION_TTL = 600;

    /**
     * @var string|null The MultiSafepay website API key. Can be set to an environment variable.
     */
    public ?string $apiKey = null;

    /**
     * @var bool|string Whether to use MultiSafepay’s test environment. Can be set to an environment variable.
     */
    public bool|string $testMode = false;

    /**
     * @var string|null The locale of MultiSafepay’s payment pages and emails, such as `nl_NL`. Can be set to an
     * environment variable.
     */
    public ?string $locale = null;

    /**
     * @var bool|string Whether to send the order’s line items and adjustments to MultiSafepay as its shopping cart.
     * Can be set to an environment variable.
     */
    public bool|string $sendCartInfo = false;

    #[Override]
    public static function displayName(): string
    {
        return t('MultiSafepay REST', category: 'commerce-multisafepay');
    }

    public function getApiKey(bool $parse = true): ?string
    {
        return $parse ? Env::parse($this->apiKey) : $this->apiKey;
    }

    public function setApiKey(?string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    public function getTestMode(bool $parse = true): bool|string
    {
        return $parse ? (bool)Env::parseBoolean($this->testMode) : $this->testMode;
    }

    public function setTestMode(bool|string $testMode): void
    {
        $this->testMode = $testMode;
    }

    public function getLocale(bool $parse = true): ?string
    {
        return $parse ? Env::parse($this->locale) : $this->locale;
    }

    public function setLocale(?string $locale): void
    {
        $this->locale = $locale;
    }

    public function getSendCartInfo(bool $parse = true): bool|string
    {
        return $parse ? (bool)Env::parseBoolean($this->sendCartInfo) : $this->sendCartInfo;
    }

    public function setSendCartInfo(bool|string $sendCartInfo): void
    {
        $this->sendCartInfo = $sendCartInfo;
    }

    #[Override]
    public function getRules(): array
    {
        return array_merge(parent::getRules(), [
            'paymentType' => ['required', Rule::in(['purchase'])],
            'apiKey' => ['required'],
        ]);
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext()): ?Ui
    {
        $envTip = t('This can be set to an environment variable with a boolean value ({examples}).', [
            'examples' => '`yes`/`no`/`true`/`false`/`on`/`off`/`0`/`1`',
        ]);

        return Ui::make([
            Field::make(t('API Key', category: 'commerce-multisafepay'))
                ->required()
                ->control(Text::make('apiKey')
                    ->value($this->apiKey)
                    ->textExpanderTriggers(SelectOptions::getEnvTextExpanderTriggers())),
            Field::make(t('Locale', category: 'commerce-multisafepay'))
                ->instructions(t('The language of MultiSafepay’s payment pages and emails, such as `nl_NL`. Leave blank to let MultiSafepay decide.', category: 'commerce-multisafepay'))
                ->control(Text::make('locale')
                    ->value($this->locale)
                    ->textExpanderTriggers(SelectOptions::getEnvTextExpanderTriggers())),
            Field::make(t('Test mode?', category: 'commerce-multisafepay'))
                ->instructions(t('Whether to use MultiSafepay’s test environment. Test mode needs an API key from a MultiSafepay test account.', category: 'commerce-multisafepay'))
                ->tip($envTip)
                ->control(BooleanMenu::make('testMode')
                    ->value(BooleanMenu::optionValue($this->testMode))
                    ->includeEnvVars()),
            Field::make(t('Send cart information?', category: 'commerce-multisafepay'))
                ->instructions(t('Whether to send the order’s line items and adjustments to MultiSafepay, which shows them on its payment pages.', category: 'commerce-multisafepay'))
                ->tip($envTip)
                ->control(BooleanMenu::make('sendCartInfo')
                    ->value(BooleanMenu::optionValue($this->sendCartInfo))
                    ->includeEnvVars()),
        ]);
    }

    #[Override]
    public function getPaymentTypeOptions(): array
    {
        return [
            'purchase' => t('Purchase (Authorize and Capture Immediately)', category: 'commerce'),
        ];
    }

    #[Override]
    public function getPaymentFormModel(): BasePaymentForm
    {
        return new OffsitePaymentForm();
    }

    #[Override]
    public function purchase(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        $event = new PaymentRequestBuilding($transaction, $form, $this->buildPaymentRequest($transaction));
        event($event);

        return new OrderResponse($this->client()->createOrder($event->request));
    }

    #[Override]
    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        // The MultiSafepay order ID is the hash of the transaction that created it.
        return new OrderResponse($this->client()->getOrder((string)$transaction->hash));
    }

    #[Override]
    public function refund(Transaction $transaction): RequestResponseInterface
    {
        $orderId = $this->orderIdForRefund($transaction);
        $currency = (string)$transaction->paymentCurrency;
        $amount = $this->amount($transaction->paymentAmount, $currency);

        // MultiSafepay refunds the full amount when the amount is 0.
        if ($amount <= 0) {
            throw new InvalidArgumentException('The refund amount must be greater than zero.');
        }

        return new RefundResponse($this->client()->refundOrder($orderId, [
            'amount' => $amount,
            'currency' => $currency,
            'description' => t('Order', category: 'commerce') . ' #' . $transaction->orderId,
        ]));
    }

    /**
     * Handles a MultiSafepay webhook notification.
     *
     * MultiSafepay adds `transactionid` (the order ID, which is the transaction hash) and `timestamp` to the
     * notification URL. `POST` notifications are signed with the API key, and are rejected if the signature is
     * invalid. The notification’s contents are never trusted: the order status is always fetched from MultiSafepay.
     *
     * @see https://docs.multisafepay.com/docs/webhook
     */
    #[Override]
    public function processWebHook(): Response
    {
        $request = request();

        if ($request->isMethod('POST') && !$this->isValidSignature((string)$request->header('Auth'), $request->getContent())) {
            Log::warning('MultiSafepay webhook: invalid signature.');
            return response('Invalid signature', 403);
        }

        $transactionHash = $this->getTransactionHashFromWebhook();
        $transaction = $transactionHash ? app(Transactions::class)->getTransactionByHash($transactionHash) : null;

        if (!$transaction || (int)$transaction->gatewayId !== (int)$this->id || $transaction->type !== TransactionModel::TYPE_PURCHASE) {
            Log::warning('MultiSafepay webhook: no purchase transaction with the hash “' . $transactionHash . '” for this gateway.');
            return response('OK');
        }

        // A signed payload names the order it is about, which has to be the one in the URL.
        $payload = $request->isMethod('POST') ? Json::decodeIfJson($request->getContent()) : null;
        if (is_array($payload) && isset($payload['order_id']) && $payload['order_id'] !== $transaction->hash) {
            Log::warning('MultiSafepay webhook: order ID “' . $payload['order_id'] . '” did not match “' . $transaction->hash . '”.');
            return response('OK');
        }

        $children = app(Transactions::class)->getChildrenByTransactionId((int)$transaction->id);
        $hasChildWithStatus = fn(string $status) => collect($children)->contains(fn(Transaction $child) => $child->status === $status);

        if ($hasChildWithStatus(TransactionModel::STATUS_SUCCESS)) {
            return response('OK');
        }

        $order = $this->client()->getOrder((string)$transaction->hash);
        $response = new OrderResponse($order);

        if (($order['success'] ?? false) !== true) {
            // MultiSafepay resends notifications that aren’t acknowledged.
            Log::warning('MultiSafepay webhook: unable to fetch the order for “' . $transaction->hash . '”: ' . $response->getMessage());
            return response('Unable to fetch the order', 500);
        }

        $status = match ($response->getCode()) {
            'completed' => TransactionModel::STATUS_SUCCESS,
            'declined', 'cancelled', 'canceled', 'void', 'expired' => TransactionModel::STATUS_FAILED,
            default => null,
        };

        // Only final statuses are recorded, and each only once, because MultiSafepay can notify about the same status
        // more than once.
        if ($status === null || $hasChildWithStatus($status)) {
            return response('OK');
        }

        $child = app(Transactions::class)->createTransaction(null, $transaction);
        $child->type = $transaction->type;
        $child->status = $status;
        $child->response = $order;
        $child->code = $response->getCode();
        $child->reference = $response->getTransactionReference();
        $child->message = $response->getMessage();
        app(Transactions::class)->saveTransaction($child);

        return response('OK');
    }

    /**
     * Returns the `transactionid` MultiSafepay adds to the notification URL, which is the MultiSafepay order ID, and so
     * the hash of the transaction that created the order.
     */
    #[Override]
    public function getTransactionHashFromWebhook(): ?string
    {
        $hash = request()->query('transactionid');

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /**
     * Returns whether the `Auth` header of a `POST` notification is a valid signature of its payload.
     *
     * The header is the base64-encoded `{timestamp}:{signature}`, where the signature is the HMAC-SHA512 of
     * `{timestamp}:{payload}` with the API key.
     *
     * @see https://docs.multisafepay.com/docs/webhook#2-validate-the-request
     */
    protected function isValidSignature(string $auth, string $payload): bool
    {
        $decoded = base64_decode($auth, true);

        if ($decoded === false || !str_contains($decoded, ':')) {
            return false;
        }

        [$timestamp, $signature] = explode(':', $decoded, 2);

        if (!ctype_digit($timestamp) || (int)$timestamp + self::NOTIFICATION_TTL < time()) {
            return false;
        }

        $expected = hash_hmac('sha512', $timestamp . ':' . $payload, trim((string)$this->getApiKey()));

        return hash_equals($expected, $signature);
    }

    /**
     * Builds the body of the MultiSafepay “Create order” request.
     *
     * @return array<string, mixed>
     */
    protected function buildPaymentRequest(Transaction $transaction): array
    {
        $order = $transaction->getOrder() ?? throw new InvalidArgumentException('The transaction has no order.');
        $params = ['commerceTransactionId' => $transaction->id, 'commerceTransactionHash' => $transaction->hash];
        $currency = (string)$transaction->paymentCurrency;

        $request = [
            'type' => 'redirect',
            'order_id' => $transaction->hash,
            'currency' => $currency,
            'amount' => $this->amount($transaction->paymentAmount, $currency),
            'description' => t('Order', category: 'commerce') . ' #' . $transaction->orderId,
            'payment_options' => [
                // MultiSafepay only accepts URLs without a query string here, and adds the transaction hash (as
                // `transactionid`) to the URL itself.
                'notification_url' => Url::siteUrl('commerce/webhooks/process-webhook/gateway/' . $this->id),
                'notification_method' => 'POST',
                'redirect_url' => Url::actionUrl('commerce/payments/complete-payment', $params),
                'cancel_url' => Url::siteUrl((string)$order->cancelUrl),
            ],
            'customer' => $this->customer($order),
        ];

        if ($this->getSendCartInfo()) {
            $items = $this->cartItems($order, $transaction);

            if ($items !== []) {
                $request['shopping_cart'] = ['items' => $items];
            }
        }

        return $request;
    }

    /**
     * Returns the customer details sent to MultiSafepay: the locale, the email address and the billing address.
     *
     * @return array<string, string>
     */
    protected function customer(Order $order): array
    {
        $customer = [
            'locale' => $this->getLocale(),
            'email' => $order->getEmail(),
        ];

        if ($address = $order->getBillingAddress()) {
            $customer += [
                'first_name' => $address->getGivenName(),
                'last_name' => $address->getFamilyName(),
                'address1' => $address->getAddressLine1(),
                'address2' => $address->getAddressLine2(),
                'zip_code' => $address->getPostalCode(),
                'city' => $address->getLocality(),
                'state' => $address->getAdministrativeArea(),
                'country' => $address->getCountryCode(),
            ];
        }

        return array_filter($customer, fn($value) => $value !== null && $value !== '');
    }

    /**
     * Returns the order’s line items and adjustments as MultiSafepay shopping cart items, skipping zero amounts and
     * included adjustments.
     *
     * Prices are in the order’s currency, so no items are returned when paying in a different currency.
     *
     * @return list<array{name: string, description: string|null, quantity: int, unit_price: float}>
     */
    protected function cartItems(Order $order, Transaction $transaction): array
    {
        if ($transaction->currency !== $transaction->paymentCurrency) {
            Log::info('MultiSafepay: the cart wasn’t sent because the payment currency differs from the order currency.');
            return [];
        }

        $currency = (string)$transaction->currency;
        $items = [];

        foreach ($order->getLineItems() as $lineItem) {
            $price = Currency::round($lineItem->getSalePrice(), $currency);

            if ($price == 0) {
                continue;
            }

            $description = $lineItem->getDescription() ?: t('Item ID', category: 'commerce') . ' ' . $lineItem->id;
            $items[] = [
                'name' => $description,
                'description' => $description,
                'quantity' => $lineItem->qty,
                'unit_price' => $price,
            ];
        }

        foreach ($order->getAdjustments() ?? [] as $adjustment) {
            $price = Currency::round($adjustment->amount, $currency);

            if ($adjustment->included || $price == 0) {
                continue;
            }

            $items[] = [
                'name' => $adjustment->type === Discount::ADJUSTMENT_TYPE ? t('Discount', category: 'commerce') : $adjustment->name,
                'description' => $adjustment->description,
                'quantity' => 1,
                'unit_price' => $price,
            ];
        }

        return $items;
    }

    /**
     * Returns the MultiSafepay order ID to refund, from the response of the transaction being refunded.
     */
    protected function orderIdForRefund(Transaction $transaction): string
    {
        $response = $transaction->getParent()?->response;

        if (is_string($response)) {
            $response = Json::decodeIfJson($response);
        }

        $orderId = is_array($response) ? ($response['data']['order_id'] ?? null) : null;

        if (!is_string($orderId) || $orderId === '') {
            throw new RuntimeException('Cannot refund this transaction as the parent Order cannot be found!');
        }

        return $orderId;
    }

    /**
     * Returns an amount in the currency’s smallest unit, such as cents.
     */
    protected function amount(float $amount, string $currency): int
    {
        return (int)app(Currencies::class)->getTeller($currency)->convertToMoney($amount)->getAmount();
    }

    protected function client(): Client
    {
        return new Client((string)$this->getApiKey(), (bool)$this->getTestMode());
    }

    #[Override]
    public function authorize(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        throw new NotImplementedException(t('Authorizing is not supported by this gateway', category: 'commerce'));
    }

    #[Override]
    public function capture(Transaction $transaction, string $reference): RequestResponseInterface
    {
        throw new NotImplementedException(t('Capturing is not supported by this gateway', category: 'commerce'));
    }

    #[Override]
    public function completeAuthorize(Transaction $transaction): RequestResponseInterface
    {
        throw new NotImplementedException(t('Completing authorization is not supported by this gateway', category: 'commerce'));
    }

    #[Override]
    public function createPaymentSource(BasePaymentForm $sourceData, int $customerId): PaymentSource
    {
        throw new NotImplementedException(t('Payment sources are not supported by this gateway', category: 'commerce'));
    }

    #[Override]
    public function deletePaymentSource(string $token): bool
    {
        throw new NotImplementedException(t('Payment sources are not supported by this gateway', category: 'commerce'));
    }

    #[Override]
    public function supportsAuthorize(): bool
    {
        return false;
    }

    #[Override]
    public function supportsCapture(): bool
    {
        return false;
    }

    #[Override]
    public function supportsCompleteAuthorize(): bool
    {
        return false;
    }

    #[Override]
    public function supportsCompletePurchase(): bool
    {
        return true;
    }

    #[Override]
    public function supportsPaymentSources(): bool
    {
        return false;
    }

    #[Override]
    public function supportsPurchase(): bool
    {
        return true;
    }

    #[Override]
    public function supportsRefund(): bool
    {
        return true;
    }

    #[Override]
    public function supportsPartialRefund(): bool
    {
        return true;
    }

    #[Override]
    public function supportsWebhooks(): bool
    {
        return true;
    }
}
