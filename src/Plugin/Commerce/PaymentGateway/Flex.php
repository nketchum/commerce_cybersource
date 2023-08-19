<?php

namespace Drupal\commerce_cybersource\Plugin\Commerce\PaymentGateway;

use CyberSource\Api\CaptureApi;
use CyberSource\Api\KeyGenerationApi;
use CyberSource\Api\PaymentsApi;
use CyberSource\Api\RefundApi;
use CyberSource\Api\VoidApi;
use CyberSource\ApiClient;
use CyberSource\ApiException;
use CyberSource\Authentication\Core\MerchantConfiguration;
use CyberSource\Configuration;
use CyberSource\Model\CapturePaymentRequest;
use CyberSource\Model\CreatePaymentRequest;
use CyberSource\Model\GeneratePublicKeyRequest;
use CyberSource\Model\Ptsv2paymentsClientReferenceInformation;
use CyberSource\Model\Ptsv2paymentsidcapturesOrderInformationAmountDetails;
use CyberSource\Model\Ptsv2paymentsidrefundsOrderInformation;
use CyberSource\Model\Ptsv2paymentsidreversalsClientReferenceInformation;
use CyberSource\Model\Ptsv2paymentsOrderInformation;
use CyberSource\Model\Ptsv2paymentsOrderInformationAmountDetails;
use CyberSource\Model\Ptsv2paymentsOrderInformationBillTo;
use CyberSource\Model\Ptsv2paymentsPaymentInformation;
use CyberSource\Model\Ptsv2paymentsPaymentInformationPaymentInstrument;
use CyberSource\Model\Ptsv2paymentsProcessingInformation;
use CyberSource\Model\Ptsv2paymentsTokenInformation;
use CyberSource\Model\RefundCaptureRequest;
use CyberSource\Model\VoidPaymentRequest;
use Drupal\commerce_payment\CreditCard;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\DeclineException;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides the CyberSource Flex payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "cybersource_flex",
 *   label = @Translation("CyberSource Flex"),
 *   display_label = @Translation("CyberSource Flex"),
 *   forms = {
 *     "add-payment-method" = "Drupal\commerce_cybersource\PluginForm\FlexForm",
 *   },
 *   js_library = "commerce_cybersource/flex-microform",
 *   payment_method_types = {"flex_credit_card"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "maestro", "mastercard", "visa",
 *   },
 * )
 */
class Flex extends OnsitePaymentGatewayBase implements FlexInterface {

  const COMMERCE_CYBERSOURCE_FLEX_TRANSACTION_AUTH_ONLY = 'authorization_only';
  const COMMERCE_CYBERSOURCE_FLEX_TRANSACTION_AUTH_AND_CAPTURE = 'authorization_and_capture';
  const COMMERCE_CYBERSOURCE_FLEX_LIVE_API_SERVER = 'api.cybersource.com';
  const COMMERCE_CYBERSOURCE_FLEX_TEST_API_SERVER = 'apitest.cybersource.com';

  /**
   * Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The CyberSource API.
   *
   * @var \CyberSource\ApiClient
   */
  protected $api;

  /**
   * Payment method transaction type.
   *
   * @var bool
   */
  protected $capture;

  /**
   * Constructs a new PaymentGatewayBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce_payment\PaymentTypeManager $payment_type_manager
   *   The payment type manager.
   * @param \Drupal\commerce_payment\PaymentMethodTypeManager $payment_method_type_manager
   *   The payment method type manager.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    PaymentTypeManager $payment_type_manager,
    PaymentMethodTypeManager $payment_method_type_manager,
    TimeInterface $time,
    LoggerInterface $logger,
    RequestStack $request_stack
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $entity_type_manager,
      $payment_type_manager,
      $payment_method_type_manager,
      $time
    );

    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
    $this->requestStack = $request_stack;

    // Call CyberSource API with provided plugin configuration.
    if ($configuration) {
      // Check if payment method is in capture mode.
      switch ($this->configuration['transaction_type']) {
        case self::COMMERCE_CYBERSOURCE_FLEX_TRANSACTION_AUTH_AND_CAPTURE:
          $this->capture = TRUE;
          break;

        default:
          $this->capture = FALSE;
          break;
      }

      if ($this->getMode() == 'test') {
        $host = self::COMMERCE_CYBERSOURCE_FLEX_TEST_API_SERVER;
      }
      else {
        $host = self::COMMERCE_CYBERSOURCE_FLEX_LIVE_API_SERVER;
      }

      $config = new Configuration();

      $merchantConfig = new MerchantConfiguration();
      $merchantConfig->setMerchantID($this->configuration['merchant_id']);
      $merchantConfig->setApiKeyID($this->configuration['key_serial_number']);
      $merchantConfig->setSecretKey($this->configuration['key_shared_secret']);
      $merchantConfig->setHost($host);
      $merchantConfig->setAuthenticationType('HTTP_SIGNATURE');
      $config->setHost($host);

      // CyberSource API request.
      $this->api = new ApiClient($config, $merchantConfig);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('datetime.time'),
      $container->get('logger.channel.commerce_cybersource'),
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'merchant_id' => '',
      'key_serial_number' => '',
      'key_shared_secret' => '',
      'transaction_type' => self::COMMERCE_CYBERSOURCE_FLEX_TRANSACTION_AUTH_ONLY,
      'log_api_calls' => 0,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['merchant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant ID'),
      '#default_value' => $this->configuration['merchant_id'],
      '#required' => TRUE,
    ];
    $form['key_serial_number'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Serial Number'),
      '#default_value' => $this->configuration['key_serial_number'],
      '#required' => TRUE,
      '#maxlength' => 256,
      '#description' => $this->t('Your keyId (Serial Number).'),
    ];
    $form['key_shared_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Shared secret'),
      '#default_value' => $this->configuration['key_shared_secret'],
      '#required' => TRUE,
      '#description' => $this->t('Your Shared secret (keyId data).'),
    ];
    $form['transaction_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Transaction type'),
      '#default_value' => $this->configuration['transaction_type'],
      '#required' => TRUE,
      '#options' => [
        self::COMMERCE_CYBERSOURCE_FLEX_TRANSACTION_AUTH_ONLY => $this->t('Authorize funds'),
        self::COMMERCE_CYBERSOURCE_FLEX_TRANSACTION_AUTH_AND_CAPTURE => $this->t('Authorize and capture funds'),
      ],
    ];
    $form['log_api_calls'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log API requests and responses.'),
      '#default_value' => $this->configuration['log_api_calls'],
      '#description' => $this->t('Logs will contain personally identifiable information (PII) in plain text and should usually not be enabled in production.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['merchant_id'] = $values['merchant_id'];
      $this->configuration['key_serial_number'] = $values['key_serial_number'];
      $this->configuration['key_shared_secret'] = $values['key_shared_secret'];
      $this->configuration['transaction_type'] = $values['transaction_type'];
      $this->configuration['log_api_calls'] = $values['log_api_calls'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    // New Flex payment methods are created using a transient token that
    // expires in 15 minutes. The token should be stored in a non-reusable
    // payment method along with the card expiration month / year so it can be
    // converted to a long term payment instrument once available.
    // Throw an exception if we did not get a token.
    // @todo Validate the token to ensure it wasn't tampered with on submit.
    if (empty($payment_details['token'])) {
      throw new \InvalidArgumentException('Missing transient token.');
    }

    // Decode the token to extract the card number.
    list($header, $payload, $signature) = explode('.', $payment_details['token']);
    $payload = Json::decode(base64_decode($payload));

    // Set the credit card type based on the card number prefix.
    $types = CreditCard::getTypes();
    foreach ($types as $type) {
      foreach ($type->getNumberPrefixes() as $prefix) {
        if (CreditCard::matchPrefix($payload['data']['number'], $prefix)) {
          $payment_method->card_type = $type->getId();
        }
      }
    }

    // Set the masked card number and expiration month and year.
    $payment_method->card_number = $payload['data']['number'];
    $payment_method->card_exp_month = $payment_details['expiration']['month'];
    $payment_method->card_exp_year = $payment_details['expiration']['year'];

    // Save the full token in the transient token field on the payment method.
    $payment_method->transient_token = $payment_details['token'];

    // Set the payment method to expire in 14 minutes to provide some buffer
    // for timing out the token and sending the customer back in checkout..
    $payment_method->setExpiresTime(\Drupal::time()->getRequestTime() + 840);

    // Make this non-reusable for now so it does not appear in the accounts
    // interface for stored payment methods.
    $payment_method->setReusable(FALSE);

    $payment_method->save();
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    // @todo Delete the remote token when the payment method is deleted here.
    $payment_method->delete();
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = FALSE) {
    $this->assertPaymentState($payment, ['new']);
    $payment_method = $payment->getPaymentMethod();
    $this->assertPaymentMethod($payment_method);

    if (empty($this->configuration['merchant_id'])) {
      throw new InvalidRequestException('No merchant ID was found');
    }
    $order = $payment->getOrder();

    // Prepare the reference and order information for the payment request.
    $client_reference_information_arr = [
      'code' => $order->id(),
    ];
    $client_reference_information = new Ptsv2paymentsClientReferenceInformation($client_reference_information_arr);

    $amount = $payment->getAmount()->getNumber();
    $currency_code = $payment->getAmount()->getCurrencyCode();
    $amount_details_arr = [
      'totalAmount' => $amount,
      'currency' => $currency_code,
    ];
    $amount_details = new Ptsv2paymentsOrderInformationAmountDetails($amount_details_arr);

    // Add fields for the full billing address to the request.
    if ($billing_profile = $payment_method->getBillingProfile()) {
      /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $address */
      $address = $billing_profile->get('address')->first();
      $billing_to_arr = [
        'firstName' => $address->getGivenName(),
        'lastName' => $address->getFamilyName(),
        'address1' => $address->getAddressLine1(),
        'address2' => $address->getAddressLine2(),
        'locality' => $address->getLocality(),
        'postalCode' => $address->getPostalCode(),
        'country' => $address->getCountryCode(),
        'administrativeArea' => $address->getAdministrativeArea(),
        'company' => [
          'name' => $address->getOrganization(),
        ],
      ];
    }

    $owner = $payment_method->getOwner();
    if (!empty($order->getEmail())) {
      $billing_to_arr['email'] = $order->getEmail();
    }

    $billing_to = new Ptsv2paymentsOrderInformationBillTo($billing_to_arr);
    $order_information_arr = [
      'amountDetails' => $amount_details,
      'billTo' => $billing_to,
    ];
    $order_information = new Ptsv2paymentsOrderInformation($order_information_arr);

    // Prepare the request object.
    $request_obj_arr = [
      'clientReferenceInformation' => $client_reference_information,
      'orderInformation' => $order_information,
    ];

    // If the payment method has a transient token, use it to process payment.
    $transient_token = $payment_method->transient_token->value;

    if (!empty($transient_token)) {
      // Create the tokens essential for subsequent transactions.
      $processing_information_arr = [
        'actionList' => [
          '0' => 'TOKEN_CREATE',
        ],
        'actionTokenTypes' => [
          '0' => 'customer',
          '1' => 'paymentInstrument',
          '2' => 'shippingAddress',
        ],
        'capture' => $this->capture,
      ];
      $processing_information = new Ptsv2paymentsProcessingInformation($processing_information_arr);
      $request_obj_arr['processingInformation'] = $processing_information;

      // Add the transient token as the payment token information.
      $token_information_arr = [
        'transientTokenJwt' => $transient_token,
      ];
      $token_information = new Ptsv2paymentsTokenInformation($token_information_arr);
      $request_obj_arr['tokenInformation'] = $token_information;
    }
    else {
      // Otherwise assume the remote ID is a payment instrument ID.
      $payment_instrument_arr = [
        'id' => $payment_method->getRemoteId(),
      ];
      $payment_instrument = new Ptsv2paymentsPaymentInformationPaymentInstrument($payment_instrument_arr);

      $payment_information_arr = [
        'paymentInstrument' => $payment_instrument,
      ];
      $payment_information = new Ptsv2paymentsPaymentInformation($payment_information_arr);
      $request_obj_arr['paymentInformation'] = $payment_information;

      // Indicate whether or not to capture.
      $processing_information_arr = [
        'capture' => $this->capture,
      ];
      $processing_information = new Ptsv2paymentsProcessingInformation($processing_information_arr);
      $request_obj_arr['processingInformation'] = $processing_information;
    }

    // Prepare the full CyberSource Payment API request.
    $request_obj = new CreatePaymentRequest($request_obj_arr);
    $payment_api = new PaymentsApi($this->api);

    try {
      // Log the API request if enabled.
      if ($this->configuration['log_api_calls']) {
        $this->logger->notice('Flex::createPayment() API request: <pre>@object</pre>', ['@object' => $request_obj->__toString()]);
      }

      $result = $payment_api->createPayment($request_obj);

      // Log the API response if enabled.
      if ($this->configuration['log_api_calls']) {
        $this->logger->notice('Flex::createPayment() API response - @status: <pre>@object</pre>', ['@status' => $result[0]->getStatus() ?? 'UNKNOWN', '@object' => $result[0]->__toString()]);
      }
    }
    catch (ApiException $e) {
      // Log the API error message if enabled.
      if ($this->configuration['log_api_calls']) {
        $this->logger->notice('Flex::createPayment() API error - @reason: <pre>@body</pre>', ['@reason' => $e->getResponseBody()->reason ?? 'UNKNOWN', '@body' => print_r($e->getResponseBody(), TRUE)]);
      }

      throw new InvalidRequestException($e->getMessage(), $e->getCode());
    }
    /** @var \CyberSource\Model\PtsV2PaymentsPost201Response $payment_response */
    $payment_response = $result[0];
    $payment_status = strtoupper($payment_response->getStatus());

    // Ensure the remote payment status is correct.
    if ($payment_status !== 'AUTHORIZED') {
      $error = sprintf('Error while creating the Cybersource payment: Unexpected status "%s".', $payment_status);
      /** @var \CyberSource\Model\PtsV2PaymentsPost201ResponseErrorInformation $error_information */
      $error_information = $payment_response->getErrorInformation();

      // Check if a reason or a message was provided.
      if ($error_information->getReason() || $error_information->getMessage()) {
        $error = sprintf('Error while creating the Cybersource payment: (Reason: %s, Message: %s).', (string) $error_information->getReason(), (string) $error_information->getMessage());
      }

      if ($payment_status === 'DECLINED') {
        throw new DeclineException($error);
      }

      if ($payment_status === 'INVALID_REQUEST') {
        throw new InvalidRequestException($error);
      }

      // Fallback to throwing a generic payment gateway exception.
      throw new PaymentGatewayException($error);
    }

    // If this was a transient token transaction, save the permanent tokens.
    if (!empty($transient_token)) {
      // Save the remote customer ID to the user account if it exists.
      if ($owner && $owner->isAuthenticated()) {
        $this->setRemoteCustomerId($owner, $payment_response->getTokenInformation()->getCustomer()->getId());
        $owner->save();
      }

      // Update the stored payment method with the long term payment instrument
      // ID, removing its transient token so subsequent transactions are
      // properly prepared.
      $payment_method->setRemoteId($payment_response->getTokenInformation()->getPaymentInstrument()->getId());
      $payment_method->transient_token = '';

      // Set the expires timestamp on the payment method to the actual credit
      // card month / year and ensure it is now reusable.
      $payment_method->setExpiresTime(CreditCard::calculateExpirationTimestamp($payment_method->card_exp_month->value, $payment_method->card_exp_year->value));
      $payment_method->setReusable(TRUE);

      $payment_method->save();
    }

    // Set the remote ID for the transaction itself.
    $payment->setRemoteId($result[0]->getId());
    // Set the payment state based on whether or not payment was captured.
    $payment->setState($this->capture ? 'completed' : 'authorization');
    $payment->save();
  }

  /**
   * {@inheritdoc}
   *
   * @throws \CyberSource\ApiException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    $this->assertPaymentState($payment, ['authorization']);
    // If not specified, capture the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $currency_code = $amount->getCurrencyCode();
    $remote_id = $payment->getRemoteId();

    $amount_details_arr = [
      'totalAmount' => $amount->getNumber(),
      'currency' => $currency_code,
    ];
    $amount_details = new Ptsv2paymentsidcapturesOrderInformationAmountDetails($amount_details_arr);

    $order_information_arr = [
      'amountDetails' => $amount_details,
    ];
    $order_information = new Ptsv2paymentsidrefundsOrderInformation($order_information_arr);

    $request_obj_arr = [
      'orderInformation' => $order_information,
    ];

    $request_obj = new CapturePaymentRequest($request_obj_arr);

    // CyberSource Capture API request.
    $capture_api = new CaptureApi($this->api);
    try {
      // Log the API request if enabled.
      if ($this->configuration['log_api_calls']) {
        $this->logger->notice('Flex::capturePayment() API request: <pre>@object</pre>', ['@object' => $request_obj->__toString()]);
      }

      $result = $capture_api->capturePayment($request_obj, $remote_id);

      // Log the API response if enabled.
      if ($this->configuration['log_api_calls']) {
        $this->logger->notice('Flex::capturePayment() API response - @status: <pre>@object</pre>', ['@status' => $result[0]->getStatus() ?? 'UNKNOWN', '@object' => $result[0]->__toString()]);
      }
    }
    catch (ApiException $e) {
      // Log the API error message if enabled.
      if ($this->configuration['log_api_calls']) {
        $this->logger->notice('Flex::capturePayment() API error - @reason: <pre>@body</pre>', ['@reason' => $e->getResponseBody()->reason ?? 'UNKNOWN', '@body' => print_r($e->getResponseBody(), TRUE)]);
      }

      throw new InvalidRequestException($e->getMessage(), $e->getCode());
    }

    $payment->setState('completed');
    $payment->setAmount($amount);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $currency_code = $amount->getCurrencyCode();
    $this->assertRefundAmount($payment, $amount);

    $remote_id = $payment->getRemoteId();

    $amount_details_arr = [
      'totalAmount' => $amount->getNumber(),
      'currency' => $currency_code,
    ];
    $amount_details = new Ptsv2paymentsidcapturesOrderInformationAmountDetails($amount_details_arr);

    $order_information_arr = [
      'amountDetails' => $amount_details,
    ];
    $order_information = new Ptsv2paymentsidrefundsOrderInformation($order_information_arr);

    $request_obj_arr = [
      'orderInformation' => $order_information,
    ];
    $request_obj = new RefundCaptureRequest($request_obj_arr);

    // CyberSource Refund API request.
    $refund_api = new RefundApi($this->api);
    try {
      // Log the API request if enabled.
      if ($this->configuration['log_api_calls']) {
        $this->logger->notice('Flex::refundPayment() API request: <pre>@object</pre>', ['@object' => $request_obj->__toString()]);
      }

      $result = $refund_api->refundPayment($request_obj, $remote_id);

      // Log the API response if enabled.
      if ($this->configuration['log_api_calls']) {
        $this->logger->notice('Flex::refundPayment() API response - @status: <pre>@object</pre>', ['@status' => $result[0]->getStatus() ?? 'UNKNOWN', '@object' => $result[0]->__toString()]);
      }
    }
    catch (ApiException $e) {
      // Log the API error message if enabled.
      if ($this->configuration['log_api_calls']) {
        $this->logger->notice('Flex::refundPayment() API error - @reason: <pre>@body</pre>', ['@reason' => $e->getResponseBody()->reason ?? 'UNKNOWN', '@body' => print_r($e->getResponseBody(), TRUE)]);
      }

      throw new InvalidRequestException($e->getMessage(), $e->getCode());
    }

    $old_refunded_amount = $payment->getRefundedAmount();
    $new_refunded_amount = $old_refunded_amount->add($amount);
    if ($new_refunded_amount->lessThan($payment->getAmount())) {
      $payment->setState('partially_refunded');
    }
    else {
      $payment->setState('refunded');
    }

    $payment->setRefundedAmount($new_refunded_amount);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    $this->assertPaymentState($payment, ['authorization']);

    $clientReference_information_arr = [
      'code' => 'void_payment',
    ];
    $clientReference_information = new Ptsv2paymentsidreversalsClientReferenceInformation($clientReference_information_arr);

    $request_obj_arr = [
      'clientReferenceInformation' => $clientReference_information,
    ];
    $request_obj = new VoidPaymentRequest($request_obj_arr);

    $remote_id = $payment->getRemoteId();
    // CyberSource Refund API request.
    $void_api = new VoidApi($this->api);
    try {
      // Log the API request if enabled.
      if ($this->configuration['log_api_calls']) {
        $this->logger->notice('Flex::voidPayment() API request: <pre>@object</pre>', ['@object' => $request_obj->__toString()]);
      }

      $result = $void_api->voidPayment($request_obj, $remote_id);

      // Log the API response if enabled.
      if ($this->configuration['log_api_calls']) {
        $this->logger->notice('Flex::voidPayment() API response - @status: <pre>@object</pre>', ['@status' => $result[0]->getStatus() ?? 'UNKNOWN', '@object' => $result[0]->__toString()]);
      }
    }
    catch (ApiException $e) {
      // Log the API error message if enabled.
      if ($this->configuration['log_api_calls']) {
        $this->logger->notice('Flex::voidPayment() API error - @reason: <pre>@body</pre>', ['@reason' => $e->getResponseBody()->reason ?? 'UNKNOWN', '@body' => print_r($e->getResponseBody(), TRUE)]);
      }

      throw new InvalidRequestException($e->getMessage(), $e->getCode());
    }

    $payment->setState('authorization_voided');
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function generateKey() {
    // CyberSource Key Generation API request.
    $key_api = new KeyGenerationApi($this->api);
    $request = $this->requestStack->getCurrentRequest();
    $flex_request_arr = [
      'encryptionType' => 'RsaOaep256',
      // The request will fail if the target origin is not https.
      'targetOrigin' => 'https://' . $request->getHttpHost(),
    ];

    $flex_request = new GeneratePublicKeyRequest($flex_request_arr);

    // Get response in JWT format, according to API of CyberSource.
    try {
      // Log the API request if enabled.
      if ($this->configuration['log_api_calls']) {
        $this->logger->notice('Flex::generateKey() API request: <pre>@object</pre>', ['@object' => $flex_request->__toString()]);
      }

      $response = $key_api->generatePublicKey('JWT', $flex_request);

      // Log the API response if enabled.
      if ($this->configuration['log_api_calls']) {
        $this->logger->notice('Flex::generateKey() API response: <pre>@object</pre>', ['@object' => $response[0]->__toString()]);
      }
    }
    catch (ApiException $e) {
      // Log the API error message if enabled.
      if ($this->configuration['log_api_calls']) {
        $this->logger->notice('Flex::generateKey() API error - @reason: <pre>@body</pre>', ['@reason' => $e->getResponseBody()->reason ?? 'UNKNOWN', '@body' => print_r($e->getResponseBody(), TRUE)]);
      }

      throw new InvalidRequestException($e->getMessage(), $e->getCode());
    }

    return $response[0]->getKeyId();
  }

}
