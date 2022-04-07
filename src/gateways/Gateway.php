<?php

namespace craft\commerce\multisafepay\gateways;

use Craft;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\Transaction;
use craft\commerce\omnipay\base\OffsiteGateway;
use craft\helpers\App;
use craft\helpers\Json;
use Omnipay\Common\AbstractGateway;
use Omnipay\MultiSafepay\Message\RestRefundRequest;
use Omnipay\MultiSafepay\RestGateway as OmnipayGateway;
use yii\base\NotSupportedException;

/**
 * Gateway represents MultiSafePay gateway
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since     1.0
 *
 * @property bool $testMode
 * @property string $apiKey
 * @property string $locale
 * @property-read null|string $settingsHtml
 */
class Gateway extends OffsiteGateway
{
    /**
     * @var string|null
     */
    private ?string $_apiKey = null;

    /**
     * @var bool|string
     */
    private bool|string $_testMode = false;

    /**
     * @var string|null
     */
    private ?string $_locale = null;

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('commerce', 'MultiSafepay REST');
    }

    /**
     * @inheritdoc
     */
    public function getSettings(): array
    {
        $settings = parent::getSettings();
        $settings['apiKey'] = $this->getApiKey(false);
        $settings['testMode'] = $this->getTestMode(false);
        $settings['locale'] = $this->getLocale(false);

        return $settings;
    }

    /**
     * @param bool $parse
     * @return bool|string
     * @since 4.0.0
     */
    public function getTestMode(bool $parse = true): bool|string
    {
        return $parse ? App::parseBooleanEnv($this->_testMode) : $this->_testMode;
    }

    /**
     * @param bool|string $testMode
     * @return void
     * @since 4.0.0
     */
    public function setTestMode(bool|string $testMode): void
    {
        $this->_testMode = $testMode;
    }

    /**
     * @param bool $parse
     * @return string|null
     * @since 4.0.0
     */
    public function getApiKey(bool $parse = true): ?string
    {
        return $parse ? App::parseEnv($this->_apiKey) : $this->_apiKey;
    }

    /**
     * @param string|null $apiKey
     * @return void
     * @since 4.0.0
     */
    public function setApiKey(?string $apiKey): void
    {
        $this->_apiKey = $apiKey;
    }

    /**
     * @param bool $parse
     * @return string|null
     * @since 4.0.0
     */
    public function getLocale(bool $parse = true): ?string
    {
        return $parse ? App::parseEnv($this->_locale) : $this->_locale;
    }

    /**
     * @param string|null $locale
     * @return void
     * @since 4.0.0
     */
    public function setLocale(?string $locale): void
    {
        $this->_locale = $locale;
    }

    /**
     * @inheritdoc
     */
    public function getPaymentTypeOptions(): array
    {
        return [
            'purchase' => Craft::t('commerce', 'Purchase (Authorize and Capture Immediately)'),
        ];
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('commerce-multisafepay/gatewaySettings', ['gateway' => $this]);
    }

    /**
     * @inheritdoc
     */
    public function populateRequest(array &$request, BasePaymentForm $form = null): void
    {
        parent::populateRequest($request, $form);
        $request['type'] = 'redirect';
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = ['paymentType', 'compare', 'compareValue' => 'purchase'];

        return $rules;
    }

    /**
     * @inheritdoc
     */
    protected function createGateway(): AbstractGateway
    {
        /** @var OmnipayGateway $gateway */
        $gateway = static::createOmnipayGateway($this->getGatewayClassName());

        $gateway->setApiKey($this->getApiKey());
        $gateway->setLocale($this->getLocale());
        $gateway->setTestMode($this->getTestMode());

        return $gateway;
    }

    /**
     * @inheritdoc
     */
    protected function getGatewayClassName(): ?string
    {
        return '\\' . OmnipayGateway::class;
    }

    /**
     * @inheritdoc
     */
    public function refund(Transaction $transaction): RequestResponseInterface
    {
        $request = $this->createRequest($transaction);
        $refundRequest = $this->prepareRefundRequest($request, $transaction->reference);

        // Get the order ID for the successful transaction and use that.
        $responseData = Json::decodeIfJson($transaction->getParent()->response);

        if ($responseData && isset($responseData['data']['order_id'])) {
            $reference = $responseData['data']['order_id'];
        } else {
            throw new NotSupportedException('Cannot refund this transaction as the parent Order cannot be found!');
        }

        /** @var RestRefundRequest $refundRequest */
        $refundRequest->setTransactionId($reference);

        return $this->performRequest($refundRequest, $transaction);
    }
}
