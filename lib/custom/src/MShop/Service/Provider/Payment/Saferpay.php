<?php

declare(strict_types=1);

/*
 * This file is part of the gsaimeos/gs-saferpay.
 *
 * Copyright (C) 2020 by Gilbertsoft LLC (gilbertsoft.org)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Aimeos\MShop\Service\Provider\Payment;

use Aimeos\MShop\Service\Provider\Payment\Base;
use Ticketpark\SaferpayJson\Request;
use Ticketpark\SaferpayJson\Request\PaymentPage;
use Ticketpark\SaferpayJson\Request\RequestConfig;
use Ticketpark\SaferpayJson\Request\Transaction;
use Ticketpark\SaferpayJson\Request\Exception\SaferpayErrorException;
use Ticketpark\SaferpayJson\Response;
use Ticketpark\SaferpayJson\Response\ErrorResponse;

/**
 * Payment provider for saferpay.
 *
 */
class Saferpay extends Base implements
    \Aimeos\MShop\Service\Provider\Payment\Iface
{
    private $beConfig = [
        'saferpay.ApiUsername' => [
            'code' => 'saferpay.ApiUsername',
            'internalcode' => 'saferpay.ApiUsername',
            'label' => 'Username',
            'type' => 'string',
            'internaltype' => 'string',
            'default' => '',
            'required' => true,
        ],
        'saferpay.ApiPassword' => [
            'code' => 'saferpay.ApiPassword',
            'internalcode' => 'saferpay.ApiPassword',
            'label' => 'Password',
            'type' => 'string',
            'internaltype' => 'string',
            'default' => '',
            'required' => true,
        ],
        'saferpay.ApiTestMode' => [
            'code' => 'saferpay.ApiTestMode',
            'internalcode' => 'saferpay.ApiTestMode',
            'label' => 'Connect to the Saferpay Test Environment',
            'type' => 'boolean',
            'internaltype' => 'integer',
            'default' => 1,
            'required' => false,
        ],
        'saferpay.CustomerId' => [
            'code' => 'saferpay.CustomerId',
            'internalcode' => 'saferpay.CustomerId',
            'label' => 'Saferpay customer id',
            'type' => 'string',
            'internaltype' => 'string',
            'default' => '',
            'required' => true,
        ],
        'saferpay.TerminalId' => [
            'code' => 'saferpay.TerminalId',
            'internalcode' => 'saferpay.TerminalId',
            'label' => 'Saferpay terminal id',
            'type' => 'string',
            'internaltype' => 'string',
            'default' => '',
            'required' => true,
        ],
        'saferpay.ConfigSet' => [
            'code' => 'saferpay.ConfigSet',
            'internalcode' => 'saferpay.ConfigSet',
            'label' => 'Saferpay config set',
            'type' => 'string',
            'internaltype' => 'string',
            'default' => '',
            'required' => false,
        ],
        'saferpay.PaymentMethods' => [
            'code' => 'saferpay.PaymentMethods',
            'internalcode' => 'saferpay.PaymentMethods',
            'label' => 'Saferpay allowed payment methods',
            'type' => 'string',
            'internaltype' => 'string',
            'default' => '',
            'required' => false,
        ],
        'saferpay.PaymentMethodsOptions' => [
            'code' => 'saferpay.PaymentMethodsOptions',
            'internalcode' => 'saferpay.PaymentMethodsOptions',
            'label' => 'Saferpay payment methods options',
            'type' => 'string',
            'internaltype' => 'string',
            'default' => '',
            'required' => false,
        ],
        'saferpay.MerchantEmails' => [
            'code' => 'saferpay.MerchantEmails',
            'internalcode' => 'saferpay.MerchantEmails',
            'label' => 'Saferpay merchant emails comma separated',
            'type' => 'string',
            'internaltype' => 'string',
            'default' => '',
            'required' => false,
        ],
    ];

    
    /**
     * @var \Aimeos\MShop\Order\Item\Base\Iface
     */
    private $orderBase = null;
    
    /**
     * @var \Aimeos\MShop\Order\Item\Base\Service\Iface
     */
    private $orderBaseService = null;

    /**
     * @var RequestConfig
     */
    private $requestConfig = null;

    /**
     * @var Request\Container\Adress
     */
    private $billingAddress = null;

    /**
     * @var Request\Container\Adress
     */
    private $deliveryAddress = null;

    /**
     * Returns a non empty string or null
     *
     * @param string $string
     * @return string|null Prefix without dot
     */
    protected function getNonEmptyStringOrNull(string $string): ?string
    {
        return empty($string) ? null : $string;
    }

    /**
     * Returns the prefix for the configuration definitions
     *
     * @return string Prefix without dot
     */
    protected function getConfigPrefix(): string
    {
        return 'saferpay';
    }

    /**
     * Returns the value for the given configuration key
     *
     * @param array|string $keys Key name or list of key names that should be tested for in the order to test
     * @param mixed $default Default value if no configuration is found
     * @return mixed Configuration value
     */
    protected function getConfigValue($keys, $default = null)
    {
        if (is_string($keys)) {
            $keys = [$keys];
        }

        foreach ($keys as &$key) {
            $key = $this->getConfigPrefix() . '.' . $key;
        }

        return parent::getConfigValue($keys, $default);
    }

    /**
     * Returns the calculated amount of the price item
     *
     * @param \Aimeos\MShop\Price\Item\Iface $price Price item
     * @param boolean $costs Include costs per item
     * @param boolean $tax Include tax
     * @return int Money amount in cents
     */
    protected function getAmount(\Aimeos\MShop\Price\Item\Iface $price, $costs = true, $tax = true): int
    {
        return (int)(((float)parent::getAmount($price, $costs, $tax)) * 100);
    }

    protected function getOrderItemBase(\Aimeos\MShop\Order\Item\Iface $order): \Aimeos\MShop\Order\Item\Base\Iface
    {
        if (is_null($this->orderBase)) {
            $this->orderBase = $this->getOrderBase($order->getBaseId(), \Aimeos\MShop\Order\Item\Base\Base::PARTS_ALL);
        }

        return $this->orderBase;
    }

    /**
     * Returns order service item for specified base ID.
     *
     * @param \Aimeos\MShop\Order\Item\Iface $order Base ID of the order
     * @return \Aimeos\MShop\Order\Item\Base\Service\Iface Order service item
     */
    protected function getOrderItemBaseService(
        \Aimeos\MShop\Order\Item\Iface $order
    ): \Aimeos\MShop\Order\Item\Base\Service\Iface {
        if (is_null($this->orderBaseService)) {
            $this->orderBaseService = $this->getOrderItemBase($order)->getService(
                \Aimeos\MShop\Order\Item\Base\Service\Base::TYPE_PAYMENT,
                $this->getServiceItem()->getCode()
            );
            /*
            $this->orderBaseService = $this->getBasketService(
                $this->getOrderBase($order->getBaseId(), \Aimeos\MShop\Order\Item\Base\Base::PARTS_SERVICE),
                \Aimeos\MShop\Order\Item\Base\Service\Base::TYPE_PAYMENT,
                $this->getServiceItem()->getCode()
            );
            */
        }

        return $this->orderBaseService;
    }

    protected function getOrderId(\Aimeos\MShop\Order\Item\Iface $order): string
    {
        return $order->getId();
    }

    protected function getPrice(\Aimeos\MShop\Order\Item\Iface $order): \Aimeos\MShop\Price\Item\Iface
    {
        return $this->getOrderItemBase($order)->getPrice();
    }

    protected function getRequestId(\Aimeos\MShop\Order\Item\Iface $order): ?string
    {
        return $this->getNonEmptyStringOrNull(
            $this->getOrderItemBaseService($order)->getAttribute('RequestId', 'payment/saferpay')
        );
    }

    protected function getRetryIndicator(\Aimeos\MShop\Order\Item\Iface $order): int
    {
        return $this->getOrderItemBaseService($order)->getAttribute('RetryIndicator', 'payment/saferpay') ?? 0;
    }

    protected function getToken(\Aimeos\MShop\Order\Item\Iface $order): ?string
    {
        return $this->getNonEmptyStringOrNull(
            $this->getOrderItemBaseService($order)->getAttribute('Token', 'payment/saferpay')
        );
    }

    protected function getTransactionId(\Aimeos\MShop\Order\Item\Iface $order): ?string
    {
        return $this->getNonEmptyStringOrNull(
            $this->getOrderItemBaseService($order)->getAttribute('TransactionId', 'payment/saferpay')
        );
    }


    protected function getSuccessUrl(): string
    {
        return parent::getConfigValue(['payment.url-success']);
    }

    protected function getFailUrl(): string
    {
        return parent::getConfigValue(['payment.url-failure', 'payment.url-success']);
    }

    protected function getAbortUrl(): string
    {
        return parent::getConfigValue(['payment.url-cancel', 'payment.url-success']);
    }

    protected function getUpdateUrl(): string
    {
        return parent::getConfigValue(['payment.url-update']);
    }

    protected function getApiKey(): string
    {
        return $this->getConfigValue(['ApiUsername']);
    }

    protected function getApiSecret(): string
    {
        return $this->getConfigValue(['ApiPassword']);
    }

    protected function getTestMode(): bool
    {
        return (bool)$this->getConfigValue(['ApiTestMode']);
    }

    protected function getCustomerId(): string
    {
        return (string)$this->getConfigValue(['CustomerId']);
    }

    protected function getTerminalId(): string
    {
        return (string)$this->getConfigValue(['TerminalId']);
    }

    protected function getConfigSet(): ?string
    {
        return $this->getNonEmptyStringOrNull($this->getConfigValue(['ConfigSet']));
    }

    protected function getPaymentMethods(): ?array
    {
        $result = $this->getConfigValue(['PaymentMethods']);
        return !empty($result) ? explode(',', $result) : null;
    }

    protected function getPaymentMethodsOptions(): ?Request\Container\PaymentMethodsOptions
    {
        /*
        new Request\Container\PaymentMethodsOptions();
        return $this->getConfigValue(['PaymentMethodsOptions']);
        */
        return null;
    }

    protected function getMerchantEmails(): ?array
    {
        $result = $this->getConfigValue(['MerchantEmails']);
        return !empty($result) ? explode(',', $result) : null;
    }


    protected function getRequestConfig(): RequestConfig
    {
        if (is_null($this->requestConfig)) {
            $this->requestConfig = new RequestConfig(
                $this->getApiKey(),
                $this->getApiSecret(),
                $this->getCustomerId(),
                $this->getTestMode()
            );
        }

        return $this->requestConfig;
    }

    protected function getClientInfoContainer(): Request\Container\ClientInfo
    {
        return (new Request\Container\ClientInfo())
            ->setShopInfo('Aimeos (gsaimeos/gs-saferpay)')
            ->setOsInfo(PHP_OS_FAMILY . ' (' . PHP_OS . ')')
        ;
    }

    protected function getRequestHeaderContainer(
        string $requestId,
        int $retryIndicator = 0
    ): Request\Container\RequestHeader {
        return (new Request\Container\RequestHeader(
            $this->getCustomerId(),
            $requestId,
            $retryIndicator
        ))
            ->setClientInfo($this->getClientInfoContainer())
        ;
    }

    protected function getAddressContainer(
        \Aimeos\MShop\Order\Item\Base\Address\Iface $address
    ): Request\Container\Address {
        return (new Request\Container\Address())
            ->setFirstName($this->getNonEmptyStringOrNull($address->getFirstName()))
            ->setLastName($this->getNonEmptyStringOrNull($address->getLastName()))
            ->setCompany($this->getNonEmptyStringOrNull($address->getCompany()))
            //->setGender($this->getNonEmptyStringOrNull())
            ->setStreet($this->getNonEmptyStringOrNull($address->getAddress1()))
            ->setZip($this->getNonEmptyStringOrNull($address->getPostal()))
            ->setCity($this->getNonEmptyStringOrNull($address->getCity()))
            ->setCountryCode($this->getNonEmptyStringOrNull($address->getCountryId()))
            ->setEmail($this->getNonEmptyStringOrNull($address->getEmail()))
            //->setDateOfBirth($this->getNonEmptyStringOrNull())
            //->setLegalForm($this->getNonEmptyStringOrNull())
            ->setStreet2($this->getNonEmptyStringOrNull(trim($address->getAddress2() . ' ' . $address->getAddress3())))
            ->setCountrySubdivisionCode($this->getNonEmptyStringOrNull($address->getState()))
            ->setPhone($this->getNonEmptyStringOrNull($address->getTelephone()))
        ;
    }

    protected function getBillingAddressContainer(
        \Aimeos\MShop\Order\Item\Base\Iface $orderBase
    ): Request\Container\Address {
        if (is_null($this->billingAddress)) {
            $this->billingAddress = $this->getAddressContainer(
                $orderBase->getAddress(\Aimeos\MShop\Order\Item\Base\Address\Base::TYPE_PAYMENT)
            );
        }

        return $this->billingAddress;
    }

    protected function getDeliveryAddressContainer(
        \Aimeos\MShop\Order\Item\Base\Iface $orderBase
    ): Request\Container\Address {
        if (is_null($this->deliveryAddress)) {
            try {
                $this->deliveryAddress = $this->getAddressContainer(
                    $orderBase->getAddress(\Aimeos\MShop\Order\Item\Base\Address\Base::TYPE_DELIVERY)
                );
            } catch (\Aimeos\MShop\Order\Exception $e) {
                // ignore address not found errors and return billing address instead
                return $this->getBillingAddressContainer($orderBase);
            }
        }

        return $this->deliveryAddress;
    }

    protected function getPayerContainer(\Aimeos\MShop\Order\Item\Base\Iface $orderBase): Request\Container\Payer
    {
        return (new Request\Container\Payer())
            //->setLanguageCode($address->getLanguageId())
            ->setBillingAddress($this->getBillingAddressContainer($orderBase))
            ->setDeliveryAddress($this->getDeliveryAddressContainer($orderBase))
        ;
    }

    protected function getAmountContainer(\Aimeos\MShop\Price\Item\Iface $price): Request\Container\Amount
    {
        return new Request\Container\Amount(
            $this->getAmount($price),
            $price->getCurrencyId()
        );
    }

    protected function getPaymentContainer(\Aimeos\MShop\Order\Item\Iface $order): Request\Container\Payment
    {
        $orderId = $this->getOrderId($order);
        return (new Request\Container\Payment($this->getAmountContainer($this->getPrice($order))))
            ->setOrderId($orderId)
            ->setDescription(sprintf($this->getContext()->getI18n()->dt('mshop', 'Order %1$s'), $orderId))
        ;
    }

    protected function getReturnUrlsContainer(): Request\Container\ReturnUrls
    {
        return new Request\Container\ReturnUrls(
            $this->getSuccessUrl(),
            $this->getFailUrl(),
            $this->getAbortUrl()
        );
    }

    protected function getNotificationContainer(\Aimeos\MShop\Order\Item\Iface $order): Request\Container\Notification
    {
        return (new Request\Container\Notification())
            ->setMerchantEmails($this->getMerchantEmails())
            ->setPayerEmail($this->getBillingAddressContainer($this->getOrderItemBase($order))->getEmail())
            ->setNotifyUrl($this->getUpdateUrl())
        ;
    }

    protected function getTransactionReferenceContainer(
        \Aimeos\MShop\Order\Item\Iface $order
    ): Request\Container\TransactionReference {
        return (new Request\Container\TransactionReference())
            ->setTransactionId($this->getTransactionId($order))
            //->setOrderId($this->getOrderId($order))
        ;
    }

    protected function getInitializeRequest(
        \Aimeos\MShop\Order\Item\Iface $order,
        string $requestId,
        int $retryIndicator = 0
    ): PaymentPage\InitializeRequest {
        return (new PaymentPage\InitializeRequest(
            $this->getRequestConfig(),
            $this->getTerminalId(),
            $this->getPaymentContainer($order),
            $this->getReturnUrlsContainer()/*,
            $this->getRequestHeaderContainer($requestId, $retryIndicator)*/
        ))
            ->setConfigSet($this->getConfigSet())
            ->setPaymentMethods($this->getPaymentMethods())
            ->setPaymentMethodsOptions($this->getPaymentMethodsOptions())
            ->setPayer($this->getPayerContainer($this->getOrderItemBase($order)))
            ->setNotification($this->getNotificationContainer($order))
        ;
    }

    protected function getAssertRequest(
        \Aimeos\MShop\Order\Item\Iface $order
    ): PaymentPage\AssertRequest {
        return (new PaymentPage\AssertRequest(
            $this->getRequestConfig(),
            $this->getToken($order)
        ));
    }

    protected function getCaptureRequest(
        \Aimeos\MShop\Order\Item\Iface $order
    ): Transaction\CaptureRequest {
        return (new Transaction\CaptureRequest(
            $this->getRequestConfig(),
            $this->getTransactionReferenceContainer($order)
        ))
            ->setAmount($this->getAmountContainer($this->getPrice($order)))
        ;
    }

    protected function getCancelRequest(
        \Aimeos\MShop\Order\Item\Iface $order
    ): Transaction\CancelRequest {
        return (new Transaction\CancelRequest(
            $this->getRequestConfig(),
            $this->getTransactionReferenceContainer($order)
        ));
    }

    /**
     * Queries for status updates for the given order if supported.
     *
     * @param \Aimeos\MShop\Order\Item\Iface $order Order invoice object
     * @throws \Aimeos\MShop\Service\Exception
     */
    protected function updateStatus(\Aimeos\MShop\Order\Item\Iface $order)
    {
        if (($token = $this->getToken($order)) === null) {
            return;
        }

        $assertRequest = $this->getAssertRequest($order);

        try {
            $response = $assertRequest->execute();
        } catch (SaferpayErrorException $e) {
            throw new \Aimeos\MShop\Service\Exception(sprintf(
                $this->getContext()->getI18n()->dt(
                    'mshop',
                    'Saferpay: Error on assert payment for order ID "%1$s" with error "%2$s"'
                ),
                $this->getOrderId($order),
                $e->getErrorResponse()->getErrorMessage()
            ));
        }
        
        // Save the transaction id, will be needed later to verify the payment
        $orderBaseItem = $this->getOrderItemBase($order);
        $serviceItem = $this->getOrderItemBaseService($order);
        /*
        $serviceItem = $this->getBasketService(
            $orderBaseItem,
            \Aimeos\MShop\Order\Item\Base\Service\Base::TYPE_PAYMENT,
            $this->getServiceItem()->getCode()
        );
        */

        $attributes = [
            'TransactionId' => $response->getTransaction()->getId(),
            'SixTransactionReference' => $response->getTransaction()->getSixTransactionReference(),
        ];

        if (
            $response->getTransaction()->getType() === Response\Container\Transaction::TYPE_PAYMENT
            && $response->getTransaction()->getStatus() === Response\Container\Transaction::STATUS_CAPTURED
        ) {
            $attributes['CaptureId'] = $response->getTransaction()->getCaptureId();
        }

        $this->setAttributes(
            $serviceItem,
            $attributes,
            'payment/saferpay'
        );

        $this->saveOrderBase($orderBaseItem);

        // Update the payment status
        $this->setPaymentStatus($order, $response->getTransaction());
        $this->saveOrder($order);
    }

    /**
     * Checks for existing token or throws exception.
     *
     * @param \Aimeos\MShop\Order\Item\Iface $order Order invoice object
     * @throws \Aimeos\MShop\Service\Exception
     */
    public function checkToken(\Aimeos\MShop\Order\Item\Iface $order)
    {
        if ($this->getToken($order) === null) {
            throw new \Aimeos\MShop\Service\Exception(sprintf(
                $this->getContext()->getI18n()->dt('mshop', 'Saferpay: Token for order ID "%1$s" not available'),
                $this->getOrderId($order)
            ));
        }
    }

    /**
     * Checks for existing transaction id or throws exception.
     *
     * @param \Aimeos\MShop\Order\Item\Iface $order Order invoice object
     * @throws \Aimeos\MShop\Service\Exception
     */
    public function checkTransactionId(\Aimeos\MShop\Order\Item\Iface $order)
    {
        if ($this->getTransactionId($order) === null) {
            throw new \Aimeos\MShop\Service\Exception(sprintf(
                $this->getContext()->getI18n()->dt('mshop', 'Saferpay: TransactionId for order ID "%1$s" not available'),
                $this->getOrderId($order)
            ));
        }
    }

    /**
     * Initializes the provider object.
     *
     * @param \Aimeos\MShop\Context\Item\Iface $context Context object
     * @param \Aimeos\MShop\Service\Item\Iface $serviceItem Service item with configuration
     * @throws \Aimeos\MShop\Service\Exception If one of the required configuration values isn't available
     */
    public function __construct(\Aimeos\MShop\Context\Item\Iface $context, \Aimeos\MShop\Service\Item\Iface $serviceItem)
    {
        parent::__construct($context, $serviceItem);
    }

    /**
     * Returns the configuration attribute definitions of the provider to generate a list of available fields and
     * rules for the value of each field in the administration interface.
     *
     * @return array List of attribute definitions implementing \Aimeos\MW\Common\Critera\Attribute\Iface
     */
    public function getConfigBE()
    {
        return $this->getConfigItems($this->beConfig);
    }

    /**
     * Checks the backend configuration attributes for validity.
     *
     * @param array $attributes Attributes added by the shop owner in the administraton interface
     * @return array An array with the attribute keys as key and an error message as values for all attributes that are
     *     known by the provider but aren't valid
     */
    public function checkConfigBE(array $attributes)
    {
        $errors = parent::checkConfigBE($attributes);

        return array_merge($errors, $this->checkConfig($this->beConfig, $attributes));
    }

    /**
     * Tries to get an authorization or captures the money immediately for the given order if capturing the money
     * separately isn't supported or not configured by the shop owner.
     *
     * @param \Aimeos\MShop\Order\Item\Iface $order Order invoice object
     * @param array $params Request parameter if available
     * @return \Aimeos\MShop\Common\Helper\Form\Standard Form object with URL, action and parameters to redirect to
     *     (e.g. to an external server of the payment provider or to a local success page)
     */
    public function process(\Aimeos\MShop\Order\Item\Iface $order, array $params = [])
    {
        // Get RequestId and RetryIndicator and set new values for later usage
        $orderBaseItem = $this->getOrderItemBase($order);
        $serviceItem = $this->getOrderItemBaseService($order);
        /*
        $serviceItem = $this->getBasketService(
            $orderBaseItem,
            \Aimeos\MShop\Order\Item\Base\Service\Base::TYPE_PAYMENT,
            $this->getServiceItem()->getCode()
        );
        */
        $requestId = $serviceItem->getAttribute('RequestId', 'payment/saferpay') ?? uniqid();
        $retryIndicator = ($serviceItem->getAttribute('RetryIndicator', 'payment/saferpay') ?? -1) + 1;

        $this->setAttributes(
            $serviceItem,
            [
                'RequestId' => $requestId,
                'RetryIndicator' => $retryIndicator,
            ],
            'payment/saferpay'
        );

        try {
            // Create the InitializeRequest
            $initializeRequest = $this->getInitializeRequest(
                $order,
                $requestId,
                $retryIndicator
            );
            
            // Send the request
            try {
                $response = $initializeRequest->execute();
            } catch (SaferpayErrorException $e) {
                throw new \Aimeos\MShop\Service\Exception(sprintf(
                    $this->getContext()->getI18n()->dt(
                        'mshop',
                        'Saferpay: Error on initialize payment for order ID "%1$s" with error "%2$s"'
                    ),
                    $this->getOrderId($order),
                    $e->getErrorResponse()->getErrorMessage()
                ));
            }

            // Save the response token, will be needed later to verify the payment
            $this->setAttributes(
                $serviceItem,
                [
                    'Token' => $response->getToken(),
                    //'Expiration' => $response->getExpiration(),
                ],
                'payment/saferpay'
            );
        } finally {
            // Make sure changed attributes are always saved
            $this->saveOrderBase($orderBaseItem);
        }
        
        // Redirect to the payment page
        //return new \Aimeos\MShop\Common\Helper\Form\Standard($response->getRedirectUrl(), 'GET', []);
        return new \Aimeos\MShop\Common\Item\Helper\Form\Standard($response->getRedirectUrl(), 'GET', []);
    }

    /**
     * Queries for status updates for the given order if supported.
     *
     * @param \Aimeos\MShop\Order\Item\Iface $order Order invoice object
     * @throws \Aimeos\MShop\Service\Exception
     */
    public function query(\Aimeos\MShop\Order\Item\Iface $order)
    {
        $this->checkToken($order);
        $this->updateStatus($order);
    }

    /**
     * Captures the money later on request for the given order if supported.
     *
     * @param \Aimeos\MShop\Order\Item\Iface $order Order invoice object
     */
    public function capture(\Aimeos\MShop\Order\Item\Iface $order)
    {
        $this->checkTransactionId($order);

        // Create the CaptureRequest
        $captureRequest = $this->getCaptureRequest($order);
        
        // Send the request
        try {
            $response = $captureRequest->execute();
        } catch (SaferpayErrorException $e) {
            throw new \Aimeos\MShop\Service\Exception(sprintf(
                $this->getContext()->getI18n()->dt(
                    'mshop',
                    'Saferpay: Error on capture payment for order ID "%1$s" with error "%2$s"'
                ),
                $this->getOrderId($order),
                $e->getErrorResponse()->getErrorMessage()
            ));
        }

        $orderBaseItem = $this->getOrderItemBase($order);
        $serviceItem = $this->getOrderItemBaseService($order);

        // Save the capture id, will be needed for refunds
        $this->setAttributes(
            $serviceItem,
            [
                'CaptureId' => $response->getCaptureId(),
            ],
            'payment/saferpay'
        );

        // Make sure changed attributes are always saved
        $this->saveOrderBase($orderBaseItem);

        // Update the payment status
        $order->setPaymentStatus(\Aimeos\MShop\Order\Item\Base::PAY_RECEIVED);
        $this->saveOrder($order);
    }

    /**
     * Refunds the money for the given order if supported.
     *
     * @param \Aimeos\MShop\Order\Item\Iface $order Order invoice object
     */
    public function refund(\Aimeos\MShop\Order\Item\Iface $order)
    {
        /*
        $baseItem = $this->getOrderBase($order->getBaseId());
        $type = \Aimeos\MShop\Order\Item\Base\Service\Base::TYPE_PAYMENT;
        $serviceItem = $this->getBasketService($baseItem, $type, $this->getServiceItem()->getCode());

        if (($tid = $serviceItem->getAttribute('TRANSACTIONID', 'payment/paypal')) === null) {
            $msg = $this->getContext()->getI18n()->dt('mshop', 'PayPal Express: Payment transaction ID for order ID "%1$s" not available');
            throw new \Aimeos\MShop\Service\Exception(sprintf($msg, $order->getId()));
        }

        $values = $this->getAuthParameter();
        $values['METHOD'] = 'RefundTransaction';
        $values['REFUNDSOURCE'] = 'instant';
        $values['REFUNDTYPE'] = 'Full';
        $values['TRANSACTIONID'] = $tid;
        $values['INVOICEID'] = $order->getId();

        $urlQuery = http_build_query($values, '', '&');
        $response = $this->send($this->apiendpoint, 'POST', $urlQuery);
        $rvals = $this->checkResponse($order->getId(), $response, __METHOD__);

        $attributes = [ 'REFUNDTRANSACTIONID' => $rvals['REFUNDTRANSACTIONID'] ];
        $this->setAttributes($serviceItem, $attributes, 'payment/paypal');
        $this->saveOrderBase($baseItem);

        $order->setPaymentStatus(\Aimeos\MShop\Order\Item\Base::PAY_REFUND);
        $this->saveOrder($order);
        */
    }

    /**
     * Cancels the authorization for the given order if supported.
     *
     * @param \Aimeos\MShop\Order\Item\Iface $order Order invoice object
     */
    public function cancel(\Aimeos\MShop\Order\Item\Iface $order)
    {
        $this->checkTransactionId($order);

        // Create the ancelRequest
        $cancelRequest = $this->getCancelRequest($order);
        
        // Send the request
        try {
            $response = $cancelRequest->execute();
        } catch (SaferpayErrorException $e) {
            throw new \Aimeos\MShop\Service\Exception(sprintf(
                $this->getContext()->getI18n()->dt(
                    'mshop',
                    'Saferpay: Error on cancel payment for order ID "%1$s" with error "%2$s"'
                ),
                $this->getOrderId($order),
                $e->getErrorResponse()->getErrorMessage()
            ));
        }

        // Update the payment status
        $order->setPaymentStatus(\Aimeos\MShop\Order\Item\Base::PAY_CANCELED);
        $this->saveOrder($order);
    }

    /**
     * Updates the order status sent by payment gateway notifications
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Request object
     * @param \Psr\Http\Message\ResponseInterface $response Response object
     * @return \Psr\Http\Message\ResponseInterface Response object
     */
    public function updatePush(
        \Psr\Http\Message\ServerRequestInterface $request,
        \Psr\Http\Message\ResponseInterface $response
    ) {
        $params = $request->getQueryParams();

        if (!isset($params['orderid'])) {
            return $response->withStatus(400, 'Saferpay: Parameter "orderid" is missing');
        }

        $this->updateStatus($this->getOrder($params['orderid']));

        return $response->withStatus(200);
    }

    /**
     * Updates the orders for whose status updates have been received by the confirmation page
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Request object with parameters and request body
     * @param \Aimeos\MShop\Order\Item\Iface $orderItem Order item that should be updated
     * @throws \Aimeos\MShop\Service\Exception If updating the orders failed
     * @return \Aimeos\MShop\Order\Item\Iface Updated order item
     */
    public function updateSync(
        \Psr\Http\Message\ServerRequestInterface $request,
        \Aimeos\MShop\Order\Item\Iface $orderItem
    ) {
        $this->updateStatus($orderItem);

        if ($this->getTransactionId($orderItem) !== null) {
            $paymentStatus = $orderItem->getPaymentStatus();

            if ($paymentStatus === \Aimeos\MShop\Order\Item\Base::PAY_AUTHORIZED) {
                $this->capture($orderItem);
            } elseif ($paymentStatus === \Aimeos\MShop\Order\Item\Base::PAY_CANCELED) {
                $this->cancel($orderItem);
            }
        }

        return $orderItem;
    }

    /**
     * Checks what features the payment provider implements.
     *
     * @param int $what Constant from abstract class
     * @return bool True if feature is available in the payment provider, false if not
     */
    public function isImplemented($what)
    {
        switch ($what) {
            case Base::FEAT_QUERY:
            case Base::FEAT_CANCEL:
            case Base::FEAT_CAPTURE:
            //case Base::FEAT_REFUND:
            case Base::FEAT_REPAY:
                return true;
        }

        return false;
    }

    /**
     * Maps the Saferpay status to the appropriate payment status and sets it in the order object.
     *
     * @param \Aimeos\MShop\Order\Item\Iface $invoice
     * @param Response\Container\Transaction $transaction
     */
    protected function setPaymentStatus(
        \Aimeos\MShop\Order\Item\Iface $invoice,
        Response\Container\Transaction $transaction
    ) {
        if ($transaction->getType() !== Response\Container\Transaction::TYPE_PAYMENT) {
            return;
        }

        switch ($transaction->getStatus()) {
            case Response\Container\Transaction::STATUS_AUTHORIZED:
                $invoice->setPaymentStatus(\Aimeos\MShop\Order\Item\Base::PAY_AUTHORIZED);
                break;

            case Response\Container\Transaction::STATUS_CANCELED:
                $invoice->setPaymentStatus(\Aimeos\MShop\Order\Item\Base::PAY_CANCELED);
                break;

            case Response\Container\Transaction::STATUS_CAPTURED:
                $invoice->setPaymentStatus(\Aimeos\MShop\Order\Item\Base::PAY_RECEIVED);
                break;

            case Response\Container\Transaction::STATUS_PENDING:
                $invoice->setPaymentStatus(\Aimeos\MShop\Order\Item\Base::PAY_PENDING);
                break;

            default:
                $this->getContext()->getLogger()->log(
                    'Saferpay: order ID = ' . $this->getOrderId($invoice)
                    . ', status = ' . print_r($transaction->getStatus(), true),
                    \Aimeos\MW\Logger\Base::INFO,
                    'core/service/payment'
                );
        }
    }

    /**
     * Adds the costs to the price item with the corresponding tax rate
     *
     * @param \Aimeos\MShop\Price\Item\Iface[] $prices Associative list of tax rates as key and price items as value
     * @param \Aimeos\MShop\Price\Item\Iface $price Price item that should be added
     * @param int $quantity Product quantity
     * @return \Aimeos\MShop\Price\Item\Iface[] Updated list of price items
     */
    protected function addPrice(array $prices, $price, $quantity = 1)
    {
        $taxrate = $price->getTaxRate();

        if (!isset($prices[$taxrate])) {
            $prices[$taxrate] = \Aimeos\MShop::create($this->getContext(), 'price')->createItem();
            $prices[$taxrate]->setTaxRate($taxrate);
        }

        $prices[$taxrate]->addItem($price, $quantity);

        return $prices;
    }
}
