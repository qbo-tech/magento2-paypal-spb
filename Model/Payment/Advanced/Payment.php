<?php
namespace PayPal\CommercePlatform\Model\Payment\Advanced;

use Magento\Payment\Model\InfoInterface;

class Payment extends \Magento\Payment\Model\Method\AbstractMethod
{
    const CODE                         = 'paypalcp';

    const PAYMENT_REVIEW_STATE         = 'PENDING';
    const PENDING_PAYMENT_NOTIFICATION = 'This order is on hold due to a pending payment. The order will be processed after the payment is approved at the payment gateway.';
    const DECLINE_ERROR_MESSAGE        = 'Declining Pending Payment Transaction';
    const GATEWAY_ERROR_MESSAGE        = 'Payment has been declined by Payment Gateway';
    const DENIED_ERROR_MESSAGE         = 'Gateway response error';
    const COMPLETED_SALE_CODE          = 'COMPLETED';
    const DENIED_SALE_CODE             = 'DENIED';
    const REFUNDED_SALE_CODE           = 'REFUNDED';
    const FAILED_STATE_CODE            = 'FAILED';
    const SUCCESS_STATE_CODES          = array("PENDING", "COMPLETED");

    const PAYPAL_CLIENT_METADATA_ID_HEADER = 'PayPal-Client-Metadata-Id';
    const FRAUDNET_CMI_PARAM = 'fraudNetCMI';

    protected $_code = self::CODE;

    protected $_infoBlockType = 'PayPal\CommercePlatform\Block\Info';

    protected $_isGateway    = true;

    protected $_canCapture   = true;
    protected $_canAuthorize = true;

    /** @var \Magento\Sales\Model\Order */
    protected $_order        = false;

    protected $_response;

    protected $_successCodes = ['200', '201'];

    protected $_canHandlePendingStatus      = true;

    /** @var \PayPal\CommercePlatform\Logger\Handler */
    protected $_logger;

    /** @var \PayPalCheckoutSdk\Orders\OrdersCaptureRequest */
    protected $_paypalOrderCaptureRequest;

    /** @var \PayPalCheckoutSdk\Core\PayPalHttpClient */
    protected $_paypalClient;

    /** @var \PayPal\CommercePlatform\Model\Paypal\Api */
    protected $_paypalApi;

    protected $_scopeConfig;

    private $paymentSource;

    /**
     * Constructor method
     *
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
     * @param \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param Logger $logger
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb $resourceCollection
     * @param array $data
     * @param \Magento\Framework\Mail\Template\TransportBuilder $transportBuilder
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param Api $api
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Payment\Model\Method\Logger $paymentLogger,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \PayPal\CommercePlatform\Model\Paypal\Api $paypalApi,
        \PayPal\CommercePlatform\Logger\Handler $logger,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $paymentLogger,
            $resource,
            $resourceCollection,
            $data
        );

        $this->_logger      = $logger;
        $this->_paypalApi   = $paypalApi;
        $this->_scopeConfig = $scopeConfig;

        $this->paymentSource = null;
    }

    public function isAvailable(
        \Magento\Quote\Api\Data\CartInterface $quote = null
    ) {
        $isAvailable = parent::isAvailable($quote);

        return $isAvailable;
    }

    /**
     * Assign corresponding data
     *
     * @param \Magento\Framework\DataObject|mixed $data
     * @return $this
     * @throws LocalizedException
     */
    public function assignData(\Magento\Framework\DataObject $data)
    {
        parent::assignData($data);

        $infoInstance   = $this->getInfoInstance();
        $infoInstance->setAdditionalInformation('payment_source');

        $additionalData = $data->getData('additional_data') ?: $data->getData();

        foreach ($additionalData as $key => $value) {
            $infoInstance->setAdditionalInformation($key, $value);
        }

      // Set any additional info here if required

        return $this;
    }
    /**
     * Payment capturing
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Validator\Exception
     */
    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $paypalOrderId = $payment->getAdditionalInformation('order_id');

        $this->_order = $payment->getOrder();

        try {
            $this->_paypalOrderCaptureRequest = $this->_paypalApi->getOrdersCaptureRequest($paypalOrderId);

            //TODO move function.
            if($payment->getAdditionalInformation('payment_source')) {
                $this->paymentSource = json_decode($payment->getAdditionalInformation('payment_source'), true);
                $this->_paypalOrderCaptureRequest->body = ['payment_source' => $this->paymentSource];
            }

            $this->_paypalOrderCaptureRequest->headers[self::PAYPAL_CLIENT_METADATA_ID_HEADER] = $payment->getAdditionalInformation(self::FRAUDNET_CMI_PARAM);

            $this->_response = $this->_paypalApi->execute($this->_paypalOrderCaptureRequest);

            $this->_processTransaction($payment);
        } catch (\Exception $e) {
            $this->_logger->error(sprintf('[PAYPAL COMMERCE CAPTURING ERROR] - %s', $e->getMessage()));

            $this->_logger->error(__METHOD__ . ' | Exception : ' . $e->getMessage());
            $this->_logger->error(__METHOD__ . ' | Exception response : ' . print_r($this->_response, true));
            throw new \Magento\Framework\Exception\LocalizedException(__(self::GATEWAY_ERROR_MESSAGE));
        }
        return $this;
    }

    /**
     * Process Payment Transaction based on response data
     *
     * @param  \Magento\Payment\Model\InfoInterface $payment
     * @return \Magento\Payment\Model\InfoInterface $payment
     */
    protected function _processTransaction(&$payment)
    {
        if (!in_array($this->_response->statusCode, $this->_successCodes)) {
            throw new \Exception(__('Gateway error. Reason: %1', $this->_response->message));
        }
        $state = $this->_response->result->purchase_units[0]->payments->captures[0]->status;

        if (!$state || is_null($state) || !in_array($state, self::SUCCESS_STATE_CODES)) {
            throw new \Exception(__(self::GATEWAY_ERROR_MESSAGE));
        }

        $_txnId = $this->_response->result->purchase_units[0]->payments->captures[0]->id;

        $infoInstance = $this->getInfoInstance();
        $infoInstance->setAdditionalInformation('payment_id', $_txnId);

        $this->_canHandlePendingStatus = (bool)$this->getConfigValue('handle_pending_payments');

        switch ($state) {
            case self::PAYMENT_REVIEW_STATE:
                if (!$this->_canHandlePendingStatus) {
                    throw new \Exception(__(self::DECLINE_ERROR_MESSAGE));
                }
                $this->setComments($this->_order, __(self::PENDING_PAYMENT_NOTIFICATION), false);
                $payment->setTransactionId($_txnId)
                    ->setIsTransactionPending(true)
                    ->setIsTransactionClosed(false);

                //$this->_sendPendingPaymentEmail();
                break;
            case self::COMPLETED_SALE_CODE:
                $payment->setTransactionId($_txnId)
                    ->setIsTransactionClosed(true);
                break;
            default:
                $payment->setIsTransactionPending(true);
                break;
        }

        if (property_exists($this->_response->result, 'payment_source')) {
            $paymentSource = $this->_response->result->payment_source;

            if ($paymentSource) {
                $infoInstance->setAdditionalInformation('card_last_digits', $paymentSource->card->last_digits);
                $infoInstance->setAdditionalInformation('card_brand', $paymentSource->card->brand);
                $infoInstance->setAdditionalInformation('card_type', $paymentSource->card->type);
            }
        }

        return $payment;
    }

    /**
     * Set order comments
     * 
     * @param type $order
     * @param type $comment
     * @param type $isCustomerNotified
     * @return type
     */
    public function setComments(&$order, $comment, $isCustomerNotified)
    {
        $history = $order->addStatusHistoryComment($comment, false);
        $history->setIsCustomerNotified($isCustomerNotified);

        return $order;
    }

    /**
     * Get payment store config
     * 
     * @return string
     */
    public function getConfigValue($field)
    {
        $value =  $this->_scopeConfig->getValue(
            $this->_preparePathConfig($field),//$configPath,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        return $value;
    }

    protected function _preparePathConfig($field)
    {
        return sprintf('payment/%s/%s', self::CODE, $field);
    }
}
