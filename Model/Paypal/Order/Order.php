<?php

namespace PayPal\CommercePlatform\Model\Paypal\Order;

class Order
{

    const DECIMAL_PRECISION = 2;

    /** @var \Magento\Checkout\Model\Session $checkoutSession */
    protected $_checkoutSession;

    /** @var \PayPal\CommercePlatform\Model\Paypal\Api */
    protected $_paypalApi;

    /** @var \PayPal\CommercePlatform\Model\Config */
    protected $_paypalConfig;

    /** @var \PayPalCheckoutSdk\Orders\OrdersCreateRequest */
    protected $_orderCreateRequest;

    /** @var \Magento\Framework\Controller\Result\JsonFactory */
    protected $_resultJsonFactory;

    /** @var \PayPal\CommercePlatform\Logger\Handler */
    protected $_loggerHandler;


    /**
     *
     * @var DataObject
     */
    protected $_data;
    /**
     *
     * @var \Magento\Quote\Model\Quote
     */
    protected $_quote;
    /**
     *
     * @var \Magento\Checkout\Model\Cart
     */
    protected $_cart;

    /**
     * Request's order model
     *
     * @var \Magento\Sales\Model\Order
     */
    protected $_order;
    /**
     * Customer address
     *
     * @var \Magento\Customer\Helper\Address
     */
    protected $_addressHelper   = null;
    /**
     *
     * @var \Magento\Quote\Model\Quote\Address
     */
    protected $_customerAddress = null;
    /**
     *
     * @var \Magento\Quote\Model\Quote\Address
     */
    protected $_customerBillingAddress = null;

    /**
     * Locale Resolver
     *
     * @var \Magento\Framework\Locale\Resolver
     */
    protected $localeResolver;
    /**
     *
     * @var type
     */
    protected $_totals;
    /**
     *
     * @var type
     */
    protected $_storeManager;
    /**
     *
     * @var Magento\Quote\Api\ShippingMethodManagementInterface
     */
    protected $_shippingMethodManager;
    /**
     *
     * @var type
     */
    protected $_logger;
    /**
     *
     * @var type
     */
    protected $_cartFactory;
    /**
     *
     * @var type
     */

    /** @var \Magento\Payment\Model\Cart\SalesModel\SalesModelInterface */
    protected $_cartPayment;

    protected $_customer;
    /**
     *
     * @var string
     */
    public static $_cancelUrl;
    public static $_returnUrl;
    public static $_notifyUrl;
    /**
     * @var string
     */
    const PAYMENT_METHOD = 'paypal';

    const ALLOWED_PAYMENT_METHOD = 'IMMEDIATE_PAY';

    const DISCOUNT_ITEM_NAME = 'Discount Item';

    //private $paymentSource;

    public function __construct(
        \Magento\Checkout\Model\Session $checkoutSession,
        \PayPal\CommercePlatform\Model\Paypal\Api $paypalApi,
        \PayPal\CommercePlatform\Model\Config $paypalConfig,
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory,
        \PayPal\CommercePlatform\Logger\Handler $logger,
        \Magento\Framework\DataObject $data,
        \Magento\Framework\Locale\Resolver $localeResolver,
        \Magento\Framework\DataObject $address,
        \Magento\Customer\Helper\Address $addressHelper,
        \Magento\Checkout\Model\Cart $cart,
        \Magento\Quote\Api\ShippingMethodManagementInterface $shippingMethodManager,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Payment\Model\Cart\SalesModel\Factory $cartFactory,
        \Magento\Framework\DataObject $dataObject,
        \Magento\Customer\Model\Session $customerSession
    ) {
        $this->_loggerHandler  = $logger;

        $this->_paypalApi    = $paypalApi;
        $this->_paypalConfig = $paypalConfig;

        $this->_orderCreateRequest = $this->_paypalApi->getOrderCreateRequest();
        $this->_resultJsonFactory  = $resultJsonFactory;
        $this->_checkoutSession    = $checkoutSession;

        // BEGIN IMPORT FROM PAYPALPLUSMX TODO check use
        $this->_data = $data;
        $this->_adressData = $address;
        $this->_cart = $cart;
        $this->_quote = $cart->getQuote();
        $this->_cartFactory = $cartFactory;
        $this->_cartPayment = $this->_cartFactory->create($this->_quote);
        $this->_customer = $customerSession->getCustomer();
        $this->_logger = $logger;

        $this->_customerBillingAddress = $cart->getQuote()->getBillingAddress();
        $this->_customerAddress = $cart->getQuote()->getShippingAddress();

        if (empty($this->_customerBillingAddress)) {
            $this->_customerBillingAddress = $dataObject;
        }
        if (empty($this->_customerAddress)) {
            $this->_customerAddress = $dataObject;
        }

        $this->_addressHelper = $addressHelper;
        $this->localeResolver = $localeResolver;
        $this->shippingMethodManager = $shippingMethodManager;
        $this->_storeManager = $storeManager;

        self::$_cancelUrl = $this->_storeManager->getStore()->getUrl('checkout/cart');
        self::$_returnUrl = $this->_storeManager->getStore()->getUrl('checkout/cart');
        self::$_notifyUrl = $this->_storeManager->getStore()->getUrl('paypal/ipn');
        //END IMPORT FROM PAYPALPLUSMX
    }

    /**
     * Create and execute request paypal API
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return void
     */
    public function createRequest()
    {
        $resultJson = $this->_resultJsonFactory->create();

        $this->_orderCreateRequest->prefer('return=representation');

        $requestBody = $this->buildRequestBody();

        $this->_loggerHandler->debug(__METHOD__ . ' ORDER REQUEST BODY ' . \print_r($requestBody, \true));

        $this->_orderCreateRequest->body = $requestBody;

        try {
            /** @var \PayPalHttp\HttpResponse $response */
            $response = $this->_paypalApi->execute($this->_orderCreateRequest);

            $this->_loggerHandler->debug(__METHOD__ . ' ORDER RESPONSE ' . print_r($response, true));
        } catch (\Exception $e) {
            $this->_loggerHandler->error($e->getMessage());

            throw $e;
        }

        return $response;
    }

    /**
     * Setting up the JSON request body for creating the order with minimum request body. The intent in the
     * request body should be "AUTHORIZE" for authorize intent flow.
     * 
     * @param \Magento\Quote\Model\Quote $quote
     *
     */
    private function buildRequestBody()
    {
        $currencyCode   = $this->_quote->getBaseCurrencyCode();
        $amount         = $this->_formatPrice($this->_quote->getGrandTotal());
        $subtotal       = $this->_formatPrice($this->_cartPayment->getBaseSubtotal());
        $shippingAmount = $this->_formatPrice($this->_cartPayment->getBaseShippingAmount());
        $taxAmount      = $this->_formatPrice($this->_cartPayment->getBaseTaxAmount());

        $requestBody = [
            'intent' => 'CAPTURE',
            'application_context' => [
                'shipping_preference' => 'SET_PROVIDED_ADDRESS'
            ],
            'payer' => $this->_getPayer(),
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => $currencyCode,
                    'value' => $amount
                ]
            ]]
        ];

        if ($this->_paypalConfig->isSetFLag(\PayPal\CommercePlatform\Model\Config::CONFIG_XML_ENABLE_ITEMS)) {
            $requestBody['purchase_units'][0]['items'] = $this->getItemsFormatted();
            $requestBody['purchase_units'][0]['amount']['breakdown'] = [
                'item_total' => [
                    'value' => $subtotal,
                    'currency_code' => $currencyCode
                ],
                'shipping' => [
                    'value' => $shippingAmount,
                    'currency_code' => $currencyCode
                ],
                'discount' => $this->_getDiscountAmount(),
                'tax_total' => [
                    'value' => $taxAmount,
                    'currency_code' => $currencyCode
                ]
            ];
        }

        if (!$this->_quote->isVirtual()) {
            $requestBody['purchase_units'][0]['shipping'] = [
                'name' => ['full_name' => $this->_customerAddress->getFirstname() . ' ' . $this->_customerAddress->getLastname()],
                'address' => $this->_getShippingAddress(),
            ];
        }

        return $requestBody;
    }

    protected function _getPayer()
    {
        return [
            'email_address' => $this->_customerAddress->getEmail(),
            'name' => [
                'given_name' => $this->_customerAddress->getFirstname(),
                'surname'    => $this->_customerAddress->getLastname()
            ],
            'address' => $this->_getShippingAddress(),
        ];
    }

    /**
     * Get Items formatted paypal request
     *
     * @return array
     */
    public function getItemsFormatted()
    {
        $paypalItems = [];

        $currencyCode   = $this->_quote->getBaseCurrencyCode();

        /** @var \Magento\Quote\Model\Quote\Item $item */
        foreach ($this->_quote->getAllVisibleItems() as $item) {
            $paypalItems[] = [
                'name'        => $item->getName(),
                'sku'         => $item->getSku(),
                'description' => $item->getDescription(),
                'unit_amount' => [
                    'currency_code' => $currencyCode,
                    'value' => $this->_formatPrice($item->getPrice())
                ],
                'tax' => [
                    'currency_code' => $currencyCode,
                    'value' => $this->_formatPrice($item->getTaxAmount())
                ],
                'quantity'    => $item->getQty()
            ];
        }

        return $paypalItems;
    }

    /**
     * Get discount
     *
     * @param array $data
     */
    protected function _getDiscountAmount()
    {
        if ($this->_cartPayment->getBaseDiscountAmount() && $this->_cartPayment->getBaseDiscountAmount() != 0) {
            $discount = $this->_cartPayment->getBaseDiscountAmount();

            $discount = ($discount < 0) ? $discount * -1 : $discount;

            if ($this->_quote->getBaseGiftCardsAmount()) {
                $discount += $this->_quote->getBaseGiftCardsAmount();
            }
            if ($this->_quote->getBaseCustomerBalAmountUsed()) {
                $discount += $this->_quote->getBaseCustomerBalAmountUsed();
            }

            return [
                'value' => $this->_formatPrice($discount),
                'currency_code' => $this->_quote->getBaseCurrencyCode()
            ];
        }
    }

    /**
     * Get shipping address request data
     *
     * @return array
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function _getShippingAddress()
    {
        return $this->_prepareAddress($this->_customerAddress);
    }

    /**
     * Get billing address request data
     *
     * @return array
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function _getBillingAddress()
    {
        return $this->_prepareAddress($this->_customerBillingAddress);
    }

    /**
     * Prepare and get format address request data
     *
     * @param \Magento\Quote\Model\Quote\Address $address
     * 
     * @return array
     */
    protected function _prepareAddress($address)
    {
        $region = $address->getRegionCode() ?  $address->getRegionCode() :  $address->getRegion();
        $addressLines = $this->_prepareAddressLines($address);

        $requestAddress = array(
            'admin_area_2' => $address->getCity(),
            'admin_area_1' => $region ?: 'n/a',
            'postal_code' => $address->getPostcode(),
            'country_code' => $address->getCountryId(),
            'address_line_1' => $addressLines['line1'],
            'address_line_2' => $addressLines['line2'],
        );

        return $requestAddress;
    }

    /**
     * Convert streets to tow lines format
     *
     * @return array $address
     */
    protected function _prepareAddressLines($address)
    {
        $street = $this->_addressHelper->convertStreetLines($address->getStreet(), 2);
        $_address['line1'] = isset($street[0]) ? $street[0] : '';
        $_address['line2'] = isset($street[1]) ? $street[1] : '';

        return $_address;
    }
    /**
     * Format price string
     *
     * @param mixed $string
     * @return string
     */
    protected function _formatPrice($string)
    {
        return sprintf('%.2F', $string);
    }
}