<?php

declare(strict_types=1);

use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Commerce\Order\Data\OrderAdjustment;
use CraftCms\Commerce\Order\Elements\Order;
use CraftCms\Commerce\Order\LineItem\Data\LineItem;
use CraftCms\Commerce\Payment\Data\Transaction;
use CraftCms\Commerce\Payment\Forms\OffsitePaymentForm;
use CraftCms\Commerce\Payment\Gateway\Gateways;
use CraftCms\Commerce\Payment\Gateway\GatewayTypes;
use CraftCms\Commerce\Payment\Models\Transaction as TransactionModel;
use CraftCms\Commerce\Payment\Transactions;
use CraftCms\CommerceMultiSafepay\Events\PaymentRequestBuilding;
use CraftCms\CommerceMultiSafepay\Gateway;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

const MSP_API_KEY = 'msp_test_key';

function mspGateway(array $settings = ['apiKey' => MSP_API_KEY]): Gateway
{
    /** @var Gateway $gateway */
    $gateway = app(Gateways::class)->createGateway([
        'type' => Gateway::class,
        'name' => 'MultiSafepay',
        'handle' => 'multisafepay',
        'paymentType' => 'purchase',
        'settings' => $settings,
    ]);

    return $gateway;
}

/**
 * Saves a gateway and an order, and returns a purchase transaction for it.
 */
function mspTransaction(float $amount = 12.3, array $settings = ['apiKey' => MSP_API_KEY], ?callable $prepareOrder = null): Transaction
{
    $gateway = mspGateway($settings);
    app(Gateways::class)->saveGateway($gateway);

    $order = new Order();
    $order->email = 'customer@example.com';
    $order->gatewayId = $gateway->id;
    $order->cancelUrl = 'shop/checkout';
    Elements::saveElement($order);

    if ($prepareOrder) {
        $prepareOrder($order);
    }

    $transaction = app(Transactions::class)->createTransaction($order, null, TransactionModel::TYPE_PURCHASE);
    $transaction->paymentAmount = $amount;
    $transaction->amount = $amount;

    return $transaction;
}

/**
 * Saves a purchase transaction that redirected to MultiSafepay.
 */
function mspRedirectedTransaction(): Transaction
{
    $transaction = mspTransaction();
    $transaction->status = TransactionModel::STATUS_REDIRECT;
    $transaction->response = mspCreatedOrder($transaction->hash);
    app(Transactions::class)->saveTransaction($transaction);

    return $transaction;
}

/**
 * The response to a “Create order” request.
 */
function mspCreatedOrder(string $orderId): array
{
    return ['success' => true, 'data' => [
        'order_id' => $orderId,
        'payment_url' => 'https://testpayv2.multisafepay.com/connect/abc123/?lang=nl_NL',
        'session_id' => 'abc123',
    ]];
}

/**
 * The response to a “Get order” request.
 */
function mspOrder(string $orderId, string $status, string $paymentType = 'IDEAL'): array
{
    return ['success' => true, 'data' => [
        'order_id' => $orderId,
        'transaction_id' => 17472052901237122,
        'status' => $status,
        'financial_status' => $status,
        'amount' => 1230,
        'currency' => 'USD',
        'payment_details' => ['type' => $paymentType],
    ]];
}

/**
 * Signs a payload the way MultiSafepay documents: base64("{timestamp}:" . hex(HMAC-SHA512("{timestamp}:{payload}", apiKey))).
 */
function mspSignature(string $payload, ?int $timestamp = null, string $apiKey = MSP_API_KEY): string
{
    $timestamp ??= time();

    return base64_encode($timestamp . ':' . hash_hmac('sha512', $timestamp . ':' . $payload, $apiKey));
}

/**
 * Makes the given notification the current request.
 */
function mspNotification(string $transactionId, string $method = 'POST', ?string $payload = null, ?string $auth = null): void
{
    $server = $auth !== null ? ['HTTP_AUTH' => $auth] : [];
    $uri = '/commerce/webhooks/process-webhook/gateway/1?transactionid=' . urlencode($transactionId) . '&timestamp=' . time();

    app()->instance('request', Request::create($uri, $method, [], [], [], $server, $payload));
}

function mspChildren(Transaction $transaction): array
{
    return app(Transactions::class)->getChildrenByTransactionId($transaction->id);
}

it('registers the gateway type', function() {
    expect(app(GatewayTypes::class)->types()->all())->toContain(Gateway::class);
});

it('loads gateways saved with the Craft Commerce 5 class name', function() {
    $settings = ['apiKey' => '$MSP_API_KEY', 'testMode' => '$MSP_TEST_MODE', 'locale' => 'nl_NL', 'sendCartInfo' => true];
    $gateway = app(Gateways::class)->createGateway([
        'type' => 'craft\\commerce\\multisafepay\\gateways\\Gateway',
        'settings' => $settings,
    ]);

    expect($gateway)->toBeInstanceOf(Gateway::class)
        ->and($gateway->getSettings())->toBe($settings)
        ->and($gateway->getConfig()['type'])->toBe(Gateway::class);
});

it('requires an API key and the purchase payment type', function() {
    $gateway = mspGateway(['apiKey' => '']);
    $gateway->paymentType = 'authorize';

    expect($gateway->validate())->toBeFalse()
        ->and($gateway->errors()->keys())->toContain('apiKey', 'paymentType');
});

it('renders the settings', function() {
    $html = mspGateway(['apiKey' => '$MSP_API_KEY', 'locale' => 'nl_NL', 'testMode' => true, 'sendCartInfo' => false])->getSettingsHtml();

    expect($html)->toContain('apiKey')->toContain('$MSP_API_KEY')
        ->toContain('locale')->toContain('nl_NL')
        ->toContain('testMode')
        ->toContain('sendCartInfo');
});

it('parses environment variables in the settings', function() {
    putenv('MSP_TEST_MODE=true');
    putenv('MSP_LOCALE=de_DE');

    $gateway = mspGateway(['apiKey' => 'key', 'testMode' => '$MSP_TEST_MODE', 'locale' => '$MSP_LOCALE', 'sendCartInfo' => 'false']);

    expect($gateway->getTestMode())->toBeTrue()
        ->and($gateway->getTestMode(false))->toBe('$MSP_TEST_MODE')
        ->and($gateway->getLocale())->toBe('de_DE')
        ->and($gateway->getSendCartInfo())->toBeFalse();

    putenv('MSP_TEST_MODE');
    putenv('MSP_LOCALE');
});

it('creates a redirect order on the live API', function() {
    Http::fake(['api.multisafepay.com/v1/json/orders' => fn(ClientRequest $request) => Http::response(mspCreatedOrder($request['order_id']))]);
    $transaction = mspTransaction(12.3, ['apiKey' => MSP_API_KEY, 'locale' => 'nl_NL'], function(Order $order) {
        $order->setBillingAddress([
            'firstName' => 'Jenny',
            'lastName' => 'Andrews',
            'addressLine1' => 'Kraanspoor 39',
            'locality' => 'Amsterdam',
            'postalCode' => '1033 SC',
            'countryCode' => 'NL',
        ]);
    });

    $response = $transaction->getGateway()->purchase($transaction, new OffsitePaymentForm());

    expect($response->isRedirect())->toBeTrue()
        ->and($response->isSuccessful())->toBeFalse()
        ->and($response->isProcessing())->toBeFalse()
        ->and($response->getRedirectMethod())->toBe('GET')
        ->and($response->getRedirectUrl())->toBe('https://testpayv2.multisafepay.com/connect/abc123/?lang=nl_NL')
        ->and($response->getMessage())->toBe('');

    Http::assertSentCount(1);
    Http::assertSent(function(ClientRequest $request) use ($transaction) {
        $options = $request['payment_options'];

        return $request->method() === 'POST'
            && $request->url() === 'https://api.multisafepay.com/v1/json/orders'
            && $request->hasHeader('api_key', MSP_API_KEY)
            && $request['type'] === 'redirect'
            && $request['order_id'] === $transaction->hash
            && $request['currency'] === 'USD'
            && $request['amount'] === 1230
            && $request['description'] === 'Order #' . $transaction->orderId
            && $options['notification_method'] === 'POST'
            && str_ends_with($options['notification_url'], 'commerce/webhooks/process-webhook/gateway/' . $transaction->gatewayId)
            && !str_contains($options['notification_url'], '?')
            && str_contains($options['redirect_url'], 'commerce/payments/complete-payment')
            && str_contains($options['redirect_url'], 'commerceTransactionHash=' . $transaction->hash)
            && str_ends_with($options['cancel_url'], 'shop/checkout')
            && $request['customer'] === [
                'locale' => 'nl_NL',
                'email' => 'customer@example.com',
                'first_name' => 'Jenny',
                'last_name' => 'Andrews',
                'address1' => 'Kraanspoor 39',
                'zip_code' => '1033 SC',
                'city' => 'Amsterdam',
                'country' => 'NL',
            ]
            && !isset($request['shopping_cart']);
    });
});

it('uses the test API in test mode', function() {
    Http::fake(['testapi.multisafepay.com/v1/json/orders' => Http::response(mspCreatedOrder('x'))]);
    $transaction = mspTransaction(12.3, ['apiKey' => MSP_API_KEY, 'testMode' => true]);

    $transaction->getGateway()->purchase($transaction, new OffsitePaymentForm());

    Http::assertSent(fn(ClientRequest $request) => $request->url() === 'https://testapi.multisafepay.com/v1/json/orders');
});

it('sends amounts in the currency’s smallest unit', function(string $currency, float $amount, int $expected) {
    Http::fake(['api.multisafepay.com/*' => Http::response(mspCreatedOrder('x'))]);
    $transaction = mspTransaction($amount);
    $transaction->paymentCurrency = $currency;

    $transaction->getGateway()->purchase($transaction, new OffsitePaymentForm());

    Http::assertSent(fn(ClientRequest $request) => $request['amount'] === $expected && $request['currency'] === $currency);
})->with([
    'EUR with cents' => ['EUR', 19.99, 1999],
    'EUR, float rounding' => ['EUR', 0.29, 29],
    'JPY has no decimals' => ['JPY', 1500.0, 1500],
    'BHD has three decimals' => ['BHD', 1.234, 1234],
]);

it('sends the cart when “Send cart information” is on', function() {
    Http::fake(['api.multisafepay.com/*' => Http::response(mspCreatedOrder('x'))]);
    $transaction = mspTransaction(41.5, ['apiKey' => MSP_API_KEY, 'sendCartInfo' => true], function(Order $order) {
        $shirt = new LineItem(['id' => 7, 'qty' => 2]);
        $shirt->setPrice(20);
        $shirt->setDescription('T-shirt');
        $free = new LineItem(['id' => 8, 'qty' => 1]);
        $free->setPrice(0);
        $free->setDescription('Sticker');
        $order->setLineItems([$shirt, $free]);

        $order->setAdjustments([
            new OrderAdjustment(['name' => 'Express', 'description' => 'Next day', 'type' => 'shipping', 'amount' => 6.5]),
            new OrderAdjustment(['name' => '10% off', 'description' => 'Sale', 'type' => 'discount', 'amount' => -5]),
            new OrderAdjustment(['name' => 'VAT', 'type' => 'tax', 'amount' => 3.2, 'included' => true]),
        ]);
    });

    $transaction->getGateway()->purchase($transaction, new OffsitePaymentForm());

    Http::assertSent(fn(ClientRequest $request) => $request['shopping_cart'] === ['items' => [
        ['name' => 'T-shirt', 'description' => 'T-shirt', 'quantity' => 2, 'unit_price' => 20.0],
        ['name' => 'Express', 'description' => 'Next day', 'quantity' => 1, 'unit_price' => 6.5],
        ['name' => 'Discount', 'description' => 'Sale', 'quantity' => 1, 'unit_price' => -5.0],
    ]]);
});

it('doesn’t send the cart when paying in another currency', function() {
    Http::fake(['api.multisafepay.com/*' => Http::response(mspCreatedOrder('x'))]);
    $transaction = mspTransaction(12.3, ['apiKey' => MSP_API_KEY, 'sendCartInfo' => true], function(Order $order) {
        $item = new LineItem(['qty' => 1]);
        $item->setPrice(12.3);
        $order->setLineItems([$item]);
    });
    $transaction->paymentCurrency = 'EUR';

    $transaction->getGateway()->purchase($transaction, new OffsitePaymentForm());

    Http::assertSent(fn(ClientRequest $request) => !isset($request['shopping_cart']));
});

it('lets listeners modify the order request', function() {
    Http::fake(['api.multisafepay.com/*' => Http::response(mspCreatedOrder('x'))]);
    Event::listen(function(PaymentRequestBuilding $event) {
        $event->request['gateway'] = 'IDEAL';
        $event->request['customer']['locale'] = 'fr_BE';
    });
    $transaction = mspTransaction();

    $transaction->getGateway()->purchase($transaction, new OffsitePaymentForm());

    Http::assertSent(fn(ClientRequest $request) => $request['gateway'] === 'IDEAL' && $request['customer']['locale'] === 'fr_BE');
});

it('reports MultiSafepay errors as failed payments', function() {
    Http::fake(['api.multisafepay.com/*' => Http::response(['success' => false, 'data' => [], 'error_code' => 1032, 'error_info' => 'Invalid API key'], 401)]);
    $transaction = mspTransaction();

    $response = $transaction->getGateway()->purchase($transaction, new OffsitePaymentForm());

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->isRedirect())->toBeFalse()
        ->and($response->isProcessing())->toBeFalse()
        ->and($response->getCode())->toBe('1032')
        ->and($response->getMessage())->toBe('Invalid API key');
});

it('completes a purchase from the order status', function(string $status, string $type, bool $successful, bool $processing, string $message) {
    $transaction = mspRedirectedTransaction();
    Http::fake(['api.multisafepay.com/v1/json/orders/' . $transaction->hash => Http::response(mspOrder($transaction->hash, $status, $type))]);

    $response = $transaction->getGateway()->completePurchase($transaction);

    expect($response->isSuccessful())->toBe($successful)
        ->and($response->isProcessing())->toBe($processing)
        ->and($response->isRedirect())->toBeFalse()
        ->and($response->getCode())->toBe($status)
        ->and($response->getMessage())->toBe($message)
        ->and($response->getTransactionReference())->toBe('17472052901237122');

    Http::assertSent(fn(ClientRequest $request) => $request->method() === 'GET' && $request->hasHeader('api_key', MSP_API_KEY));
})->with([
    'completed' => ['completed', 'IDEAL', true, false, ''],
    'uncleared card payment' => ['uncleared', 'VISA', false, true, ''],
    'bank transfer awaiting payment' => ['initialized', 'BANKTRANS', false, true, ''],
    'iDEAL not paid' => ['initialized', 'IDEAL', false, false, 'The payment was not completed.'],
    'declined' => ['declined', 'VISA', false, false, 'The payment was declined.'],
    'cancelled' => ['cancelled', 'IDEAL', false, false, 'The payment was canceled.'],
    'void' => ['void', 'IDEAL', false, false, 'The payment was canceled.'],
    'expired' => ['expired', 'IDEAL', false, false, 'The payment expired.'],
]);

it('refunds the MultiSafepay order', function() {
    $purchase = mspRedirectedTransaction();
    Http::fake(['api.multisafepay.com/v1/json/orders/' . $purchase->hash . '/refunds' => Http::response(['success' => true, 'data' => ['transaction_id' => 6303074, 'refund_id' => 3326969]])]);

    // The completed child transaction is the one that gets refunded.
    $paid = app(Transactions::class)->createTransaction(null, $purchase);
    $paid->status = TransactionModel::STATUS_SUCCESS;
    $paid->response = mspOrder($purchase->hash, 'completed');
    $paid->paymentAmount = 20;
    app(Transactions::class)->saveTransaction($paid);

    $refund = app(Transactions::class)->createTransaction(null, $paid, TransactionModel::TYPE_REFUND);
    $refund->paymentAmount = 5.05;
    $response = $paid->getGateway()->refund($refund);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('6303074')
        ->and($response->getMessage())->toBe('');

    Http::assertSent(fn(ClientRequest $request) => $request->method() === 'POST'
        && $request['amount'] === 505
        && $request['currency'] === 'USD'
        && $request['description'] === 'Order #' . $purchase->orderId);
});

it('reports refund errors', function() {
    $purchase = mspRedirectedTransaction();
    Http::fake(['api.multisafepay.com/*' => Http::response(['success' => false, 'data' => [], 'error_code' => 1025, 'error_info' => 'Multiple refunds with the same amount not allowed'], 422)]);

    $refund = app(Transactions::class)->createTransaction(null, $purchase, TransactionModel::TYPE_REFUND);
    $refund->paymentAmount = 5;
    $response = $purchase->getGateway()->refund($refund);

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getCode())->toBe('1025')
        ->and($response->getMessage())->toBe('Multiple refunds with the same amount not allowed');
});

it('won’t refund without a MultiSafepay order ID or with a zero amount', function(?array $parentResponse, float $amount) {
    Http::fake();
    $purchase = mspTransaction();
    $purchase->status = TransactionModel::STATUS_SUCCESS;
    $purchase->response = $parentResponse;
    app(Transactions::class)->saveTransaction($purchase);

    $refund = app(Transactions::class)->createTransaction(null, $purchase, TransactionModel::TYPE_REFUND);
    $refund->paymentAmount = $amount;

    expect(fn() => $purchase->getGateway()->refund($refund))->toThrow(Exception::class);
    Http::assertNothingSent();
})->with([
    'no order ID' => [['success' => true, 'data' => []], 5.0],
    // A zero amount would refund the whole order.
    'zero amount' => [['success' => true, 'data' => ['order_id' => 'abc']], 0.0],
]);

it('records the status of a signed POST notification', function() {
    $transaction = mspRedirectedTransaction();
    Http::fake(['api.multisafepay.com/v1/json/orders/' . $transaction->hash => Http::response(mspOrder($transaction->hash, 'completed'))]);
    $payload = json_encode(mspOrder($transaction->hash, 'completed')['data']);

    mspNotification($transaction->hash, 'POST', $payload, mspSignature($payload));
    $response = $transaction->getGateway()->processWebHook();

    $children = mspChildren($transaction);
    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('OK')
        ->and($children)->toHaveCount(1)
        ->and($children[0]->status)->toBe(TransactionModel::STATUS_SUCCESS)
        ->and($children[0]->code)->toBe('completed')
        ->and($children[0]->reference)->toBe('17472052901237122');
    Http::assertSentCount(1);
});

it('rejects POST notifications with a bad signature', function(callable $auth) {
    Http::fake();
    $transaction = mspRedirectedTransaction();
    $payload = json_encode(mspOrder($transaction->hash, 'completed')['data']);

    mspNotification($transaction->hash, 'POST', $payload, $auth($payload));
    $response = $transaction->getGateway()->processWebHook();

    expect($response->getStatusCode())->toBe(403)
        ->and(mspChildren($transaction))->toBeEmpty();
    Http::assertNothingSent();
})->with([
    'missing' => [fn(string $payload) => null],
    'not base64' => [fn(string $payload) => '%%%'],
    'wrong API key' => [fn(string $payload) => mspSignature($payload, apiKey: 'someone_else')],
    'different payload' => [fn(string $payload) => mspSignature(str_replace('completed', 'declined', $payload))],
    'too old' => [fn(string $payload) => mspSignature($payload, time() - 601)],
]);

it('re-fetches the order for GET notifications, which aren’t signed', function() {
    $transaction = mspRedirectedTransaction();
    Http::fake(['api.multisafepay.com/v1/json/orders/' . $transaction->hash => Http::response(mspOrder($transaction->hash, 'declined', 'VISA'))]);

    mspNotification($transaction->hash, 'GET');
    $response = $transaction->getGateway()->processWebHook();

    $children = mspChildren($transaction);
    expect($response->getContent())->toBe('OK')
        ->and($children)->toHaveCount(1)
        ->and($children[0]->status)->toBe(TransactionModel::STATUS_FAILED)
        ->and($children[0]->message)->toBe('The payment was declined.');
});

it('ignores signed notifications about a different order', function() {
    Http::fake();
    $transaction = mspRedirectedTransaction();
    $payload = json_encode(mspOrder('someone-elses-order', 'completed')['data']);

    mspNotification($transaction->hash, 'POST', $payload, mspSignature($payload));
    $response = $transaction->getGateway()->processWebHook();

    expect($response->getContent())->toBe('OK')
        ->and(mspChildren($transaction))->toBeEmpty();
    Http::assertNothingSent();
});

it('ignores notifications for unknown transactions', function() {
    Http::fake();
    mspRedirectedTransaction();

    mspNotification('not-a-transaction-hash', 'GET');
    $response = mspGateway()->processWebHook();

    expect($response->getContent())->toBe('OK');
    Http::assertNothingSent();
});

it('records each final status only once', function() {
    $transaction = mspRedirectedTransaction();
    Http::fake(['api.multisafepay.com/*' => Http::sequence()
        ->push(mspOrder($transaction->hash, 'expired'))
        ->push(mspOrder($transaction->hash, 'expired'))
        ->push(mspOrder($transaction->hash, 'completed')), ]);

    foreach (range(1, 4) as $i) {
        mspNotification($transaction->hash, 'GET');
        $transaction->getGateway()->processWebHook();
    }

    // The fourth notification arrives after the payment was recorded, so MultiSafepay isn't asked again.
    expect(array_map(fn(Transaction $child) => $child->status, mspChildren($transaction)))
        ->toBe([TransactionModel::STATUS_FAILED, TransactionModel::STATUS_SUCCESS]);
    Http::assertSentCount(3);
});

it('ignores statuses that aren’t final', function() {
    $transaction = mspRedirectedTransaction();
    Http::fake(['api.multisafepay.com/*' => Http::response(mspOrder($transaction->hash, 'initialized', 'BANKTRANS'))]);

    mspNotification($transaction->hash, 'GET');
    $response = $transaction->getGateway()->processWebHook();

    expect($response->getContent())->toBe('OK')
        ->and(mspChildren($transaction))->toBeEmpty();
});

it('asks MultiSafepay to retry when the order can’t be fetched', function() {
    $transaction = mspRedirectedTransaction();
    Http::fake(['api.multisafepay.com/*' => Http::response(['success' => false, 'error_code' => 500, 'error_info' => 'Server error'], 500)]);

    mspNotification($transaction->hash, 'GET');
    $response = $transaction->getGateway()->processWebHook();

    expect($response->getStatusCode())->toBe(500)
        ->and(mspChildren($transaction))->toBeEmpty();
});

it('handles notifications sent to the webhook URL', function() {
    $transaction = mspRedirectedTransaction();
    Http::fake(['api.multisafepay.com/*' => Http::response(mspOrder($transaction->hash, 'completed'))]);
    $payload = json_encode(mspOrder($transaction->hash, 'completed')['data']);

    $response = $this->call(
        'POST',
        '/commerce/webhooks/process-webhook/gateway/' . $transaction->gatewayId . '?transactionid=' . $transaction->hash . '&timestamp=' . time(),
        server: ['HTTP_AUTH' => mspSignature($payload), 'CONTENT_TYPE' => 'application/json'],
        content: $payload,
    );

    $response->assertOk();
    expect($response->getContent())->toBe('OK')
        ->and(mspChildren($transaction)[0]->status)->toBe(TransactionModel::STATUS_SUCCESS);
});

it('has no payment form', function() {
    expect(mspGateway()->getPaymentFormHtml([]))->toBeNull()
        ->and(mspGateway()->getPaymentFormModel())->toBeInstanceOf(OffsitePaymentForm::class);
});
