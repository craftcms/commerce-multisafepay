<?php

namespace craft\commerce\multisafepay\gateways;

use Craft;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\Transaction;
use craft\commerce\omnipay\base\OffsiteGateway;
use craft\helpers\Json;
use Omnipay\Common\AbstractGateway;
use Omnipay\MultiSafepay\Message\RestRefundRequest;
use Omnipay\Omnipay;
use Omnipay\MultiSafepay\RestGateway as OmnipayGateway;
use yii\base\NotSupportedException;

/**
 * Gateway represents MultiSafePay gateway
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since     1.0
 */
class Gateway extends OffsiteGateway
{
    /**
     * @var string
     */
    public $apiKey;

    /**
     * @var bool
     */
    public $testMode = false;

    /**
     * @var string
     */
    public $locale;

    /**
     * @var bool Whether cart information should be sent to the payment gateway
     */
    public $sendCartInfo;

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
    public function getPaymentTypeOptions(): array
    {
        return [
            'purchase' => Craft::t('commerce', 'Purchase (Authorize and Capture Immediately)')
        ];
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml()
    {
        return Craft::$app->getView()->renderTemplate('commerce-multisafepay/gatewaySettings', ['gateway' => $this]);
    }

    /**
     * @inheritdoc
     */
    public function populateRequest(array &$request, BasePaymentForm $paymentForm = null)
    {
        parent::populateRequest($request, $paymentForm);
        $request['type'] = 'redirect';
    }

    /**
     * @inheritdoc
     */
    public function rules()
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

        $gateway->setApiKey(Craft::parseEnv($this->apiKey));
        $gateway->setLocale(Craft::parseEnv($this->locale));        
        $gateway->setTestMode( Craft::parseBooleanEnv('$MULTISAFEPAY_TEST_MODE') || $this->testMode );

        return $gateway;

    }

    /**
     * @inheritdoc
     */
    protected function getGatewayClassName()
    {
        return '\\'.OmnipayGateway::class;
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
