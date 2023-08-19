<?php

namespace Drupal\commerce_cybersource\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the CyberSource SAHC payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "cybersource_sahc",
 *   label = @Translation("CyberSource SAHC"),
 *   display_label = @Translation("CyberSource"),
 *    forms = {
 *     "offsite-payment" =
 *   "Drupal\commerce_cybersource\PluginForm\CyberSourceSahcForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "discover", "mastercard", "visa",
 *   },
 * )
 */
class CyberSourceSahc extends OffsitePaymentGatewayBase {

  const COMMERCE_CYBERSOURCE_SAHC_TRANSACTION_AUTH_CREATE_TOKEN = 'authorization,create_payment_token';

  const COMMERCE_CYBERSOURCE_SAHC_LIVE_TRANSACTION_SERVER = 'https://secureacceptance.cybersource.com/pay';

  const COMMERCE_CYBERSOURCE_SAHC_TEST_TRANSACTION_SERVER = 'https://testsecureacceptance.cybersource.com/pay';

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
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time, LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);

    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
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
      $container->get('logger.channel.commerce_cybersource')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'merchant_id' => '',
        'profile_id' => '',
        'access_key' => '',
        'secret_key' => '',
        'transaction_type' => self::COMMERCE_CYBERSOURCE_SAHC_TRANSACTION_AUTH_CREATE_TOKEN,
        'locale' => 'en-US',
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
    $form['profile_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Profile ID'),
      '#default_value' => $this->configuration['profile_id'],
      '#required' => TRUE,
      '#description' => $this->t('CyberSource secure acceptance profile ID from the "General Settings" page of your CyberSource Secure Acceptance profile.'),
    ];
    $form['access_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Access Key'),
      '#default_value' => $this->configuration['access_key'],
      '#required' => TRUE,
      '#description' => $this->t('First, shorter, component of the security key generated in the Secure Acceptance profile section titled "Security Keys".'),
    ];
    $form['secret_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Secret Key'),
      '#default_value' => $this->configuration['secret_key'],
      '#required' => TRUE,
      '#maxlength' => 256,
      '#description' => $this->t('Second, longer, 256-character component of the security key generated in the Secure Acceptance profile section titled "Security Keys".'),
    ];
    $form['transaction_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Transaction type'),
      '#default_value' => $this->configuration['transaction_type'],
      '#required' => TRUE,
      '#options' => [
        self::COMMERCE_CYBERSOURCE_SAHC_TRANSACTION_AUTH_CREATE_TOKEN => $this->t('Authorize funds and create payment token'),
      ],
    ];
    $form['locale'] = [
      '#type' => 'select',
      '#title' => $this->t('Locale'),
      '#default_value' => $this->configuration['locale'],
      '#required' => TRUE,
      '#options' => $this->localeOptions(),
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
      $this->configuration['profile_id'] = $values['profile_id'];
      $this->configuration['access_key'] = $values['access_key'];
      $this->configuration['secret_key'] = $values['secret_key'];
      $this->configuration['transaction_type'] = $values['transaction_type'];
      $this->configuration['locale'] = $values['locale'];
      $this->configuration['log_api_calls'] = $values['log_api_calls'];
    }
  }

  /**
   * Returns options for 'Locale' field on configuration form.
   *
   * @return array
   */
  public function localeOptions() {
    return [
      'ar-XN' => $this->t('Arabic'),
      'km-KH' => $this->t('Cambodia'),
      'zh-HK' => $this->t('Chinese - Hong Kong'),
      'zh-MO' => $this->t('Chinese - Maco'),
      'zh-CN' => $this->t('Chinese - Mainland'),
      'zh-SG' => $this->t('Chinese - Singapore'),
      'zh-TW' => $this->t('Chinese - Taiwan'),
      'cz-CZ' => $this->t('Czech'),
      'nl-nl' => $this->t('Dutch'),
      'en-US' => $this->t('English - American'),
      'en-AU' => $this->t('English - Australia'),
      'en-GB' => $this->t('English - Britain'),
      'en-CA' => $this->t('English - Canada'),
      'en-IE' => $this->t('English - Ireland'),
      'en-NZ' => $this->t('English - New Zealand'),
      'fr-FR' => $this->t('French'),
      'fr-CA' => $this->t('French - Canada'),
      'de-DE' => $this->t('German'),
      'de-AT' => $this->t('German - Austria'),
      'hu-HU' => $this->t('Hungary'),
      'id-ID' => $this->t('Indonesian'),
      'it-IT' => $this->t('Italian'),
      'ja-JP' => $this->t('Japanese'),
      'ko-KR' => $this->t('Korean'),
      'lo-LA' => $this->t("Lao People's Democratic Republic"),
      'ms-MY' => $this->t('Malaysian Bahasa'),
      'tl-PH' => $this->t('Philippines Tagalog'),
      'pl-PL' => $this->t('Polish'),
      'pt-BR' => $this->t('Portuguese - Brazil'),
      'ru-RU' => $this->t('Russian'),
      'sk-SK' => $this->t('Slovakian'),
      'es-ES' => $this->t('Spanish'),
      'es-AR' => $this->t('Spanish - Argentina'),
      'es-CL' => $this->t('Spanish - Chile'),
      'es-CO' => $this->t('Spanish - Colombia'),
      'es-MX' => $this->t('Spanish - Mexico'),
      'es-PE' => $this->t('Spanish - Peru'),
      'es-US' => $this->t('Spanish - American'),
      'tam' => $this->t('Tamil'),
      'th-TH' => $this->t('Thai'),
      'tr-TR' => $this->t('Turkish'),
      'vi-VN' => $this->t('Vietnamese'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getRedirectUrl() {
    if ($this->getMode() == 'test') {
      return self::COMMERCE_CYBERSOURCE_SAHC_TEST_TRANSACTION_SERVER;
    }
    else {
      return self::COMMERCE_CYBERSOURCE_SAHC_LIVE_TRANSACTION_SERVER;
    }
  }

  /**
   * Generate a base64-encoded SHA256 hash of an array using a secret key.
   *
   * @param array $data_to_sign
   *   An array of various strings to implode into a signature string.
   *
   * @return string
   */
  public function signData($data_to_sign) {
    $pairs = [];
    foreach ($data_to_sign as $key => $value) {
      $pairs[] = $key . "=" . $value;
    }
    $pairs = implode(',', $pairs);
    $secret_key = $this->configuration['secret_key'];
    return base64_encode(hash_hmac('sha256', $pairs, $secret_key, TRUE));
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    /** @var \Symfony\Component\HttpFoundation\ParameterBag $parameter_bag */
    $parameter_bag = $request->request;
    $all_received_parameters = $parameter_bag->all();

    // Log data received from Cybersource.
    if ($this->configuration['log_api_calls']) {
      $this->logger->notice('Data received from Cybersource: %data', ['%data' => print_r($all_received_parameters, 1)]);
    }

    if (!$parameter_bag->has('req_reference_number') || $parameter_bag->has('req_reference_number') && $parameter_bag->get('req_reference_number') != $order->id()) {
      if ($this->configuration['log_api_calls']) {
        $this->logger->notice('Invalid reference number.');
      }
      return;
    }

    if ($parameter_bag->has('req_transaction_uuid')) {
      /** @var \Drupal\commerce_payment\Entity\PaymentInterface $commerce_payment */
      $commerce_payment = $this->entityTypeManager->getStorage('commerce_payment')
        ->loadByProperties(['uuid' => $parameter_bag->get('req_transaction_uuid')]);
      $commerce_payment = array_shift($commerce_payment);
      if (empty($commerce_payment) || $commerce_payment->getOrderId() != $order->id()) {
        if ($this->configuration['log_api_calls']) {
          $this->logger->notice('Invalid transaction UUID.');
        }
        return;
      }
    }
    else {
      if ($this->configuration['log_api_calls']) {
        $this->logger->notice('Invalid transaction UUID.');
      }
      return;
    }

    if ($this->validateResponse($order, $all_received_parameters) !== FALSE) {
      // Perform any submit functions if necessary.
      $this->updatePayment($order, $commerce_payment, $all_received_parameters);
    }
    else {
      // Otherwise display the failure message and send the customer back to
      // the order payment page.
      throw new PaymentGatewayException('Payment failed at the payment server. Please review your information and try again. If issues persist please contact your issuing bank.');
    }

  }

  /**
   * Validate data returned from CyberSource is not forged.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   Order entity.
   * @param array $all_received_parameters
   *   All received parameters from CyberSource.
   *
   * @return bool
   */
  public function validateResponse(OrderInterface $order, array $all_received_parameters) {
    if (!empty($this->configuration['secret_key'])) {
      if (isset($all_received_parameters['signed_field_names'])) {
        if (isset($all_received_parameters['signature'])) {
          $data_to_sign = [];
          $fields = explode(',', $_POST['signed_field_names']);
          foreach ($fields as $field) {
            $data_to_sign[$field] = $all_received_parameters[$field];
          }
          $signed_string = $this->signData($data_to_sign);
          return ($signed_string == $all_received_parameters['signature']);
        }
        else {
          $fail_reason = 'Reply POST had no signature.';
        }
      }
      else {
        $fail_reason = 'Reply POST had no signed field names.';
      }
    }
    else {
      $fail_reason = 'The secret key is not setup.';
    }
    if ($this->configuration['log_api_calls']) {
      $this->logger->notice('Order @order_number with remote id: @remote_id has failed signature validation. Reason: @reason',
        [
          '@order_number' => $order->id(),
          '@remote_id' => $all_received_parameters['transaction_id'],
          '@reason' => $fail_reason,
        ]
      );
    }

    return FALSE;
  }

  /**
   * Save the transaction information returned from CyberSource.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   Order.
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $commerce_payment
   *   Payment.
   * @param array $all_received_parameters
   *   All received parameters from CyberSource.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function updatePayment(OrderInterface $order, PaymentInterface $commerce_payment, array $all_received_parameters) {
    $decision = Html::escape($all_received_parameters['decision']);
    $message = Html::escape($all_received_parameters['message']);
    $currency = Html::escape($all_received_parameters['req_currency']);
    $transaction_uuid = ($all_received_parameters['req_transaction_uuid']);

    $commerce_payment->setRemoteState($decision);

    switch (strtoupper($decision)) {
      case 'ACCEPT':
        // Set AVS code and label.
        $auth_avs_code = Html::escape($all_received_parameters['auth_avs_code']);
        $card_type = $this->mapCreditCardType(Html::escape($all_received_parameters['card_type_name']));
        $commerce_payment->setAvsResponseCode($auth_avs_code);
        $avs_response_code_label = $this->buildAvsResponseCodeLabel($auth_avs_code, $card_type);
        $commerce_payment->setAvsResponseCodeLabel($avs_response_code_label);
        // Use payment token as remote ID.
        $remote_id = Html::escape($all_received_parameters['payment_token']);
        $commerce_payment->setRemoteId($remote_id);
        $auth_amount = Html::escape($all_received_parameters['auth_amount']);
        $payment_amount = Price::fromArray([
          'number' => $auth_amount,
          'currency_code' => $currency,
        ]);
        $commerce_payment->setAmount($payment_amount);

        // Get transaction type from post.
        $transaction_type = Html::escape($all_received_parameters['req_transaction_type']);

        // Set transaction status to the appropriate status.
        switch ($transaction_type) {
          // Transaction type - Authorization and Create payment token
          case 'authorization,create_payment_token':
            $commerce_payment->setState('pending');
            if ($this->configuration['log_api_calls']) {
              $this->logger->notice('The payment @payment_id has been authorized and a payment token has been created.', ['@payment_id' => $commerce_payment->id()]);
            }
            break;
        }
        break;

      case 'CANCEL':
      case 'DECLINE':
      case 'REVIEW':
      case 'ERROR':
        // Delete unsuccessful payment entity.
        $commerce_payment->delete();
        // Create log message for the order.
        $code_message = $message . (empty($all_received_parameters['invalid_fields']) ? '' : ' - ' . Html::escape($all_received_parameters['invalid_fields']));
        /** @var \Drupal\commerce_log\LogStorageInterface $logStorage */
        $logStorage = $this->entityTypeManager->getStorage('commerce_log');
        $comment = t('Transaction @transaction_uuid has failed with the following code @code:@code_message', [
          '@transaction_uuid' => $transaction_uuid,
          '@code' => $decision,
          '@code_message' => $code_message,
        ]);
        $logStorage->generate($order, 'commerce_cybersource.order_comment', ['comment' => $comment])->save();

        if ($this->configuration['log_api_calls']) {
          $this->logger->notice('Order @order_number with transaction UUID @transaction_uuid has failed with the following code @code:@code_message', [
            '@order_number' => $order->id(),
            '@transaction_uuid' => $transaction_uuid,
            '@code' => $decision,
            '@code_message' => $code_message,
          ]);
        }
        throw new PaymentGatewayException('Payment failed at the payment server. Please review your information and try again. If issues persist please contact your issuing bank.');
        break;
    }

    $commerce_payment->save();
  }

  /**
   * {@inheritdoc}
   */
  function buildAvsResponseCodeLabel($avs_response_code, $card_type) {
    switch ($avs_response_code) {
      case 'A':
        return $this->t("Street address matches, but five-digit and nine-digit postal codes do not match.");
      case'B':
        return $this->t("Street address matches, but postal code is not verified.");
      case'C':
        return $this->t("Street address and postal code do not match.");
      case'D':
      case'M':
      case'N':
        return $this->t("Street address and postal code match.");
      case'E':
        return $this->t("AVS data is invalid or AVS is not allowed for this card type.");
      case'F':
        return $this->t("Card member's name does not match, but billing postal code matches.");
      case'H':
        return $this->t("Card member's name does not match, but street address and postal code match.");
      case'I':
        return $this->t("Address not verified.");
      case'J':
      case'Q':
        return $this->t("Card member's name, billing address, and postal code match.");
      case'K':
        return $this->t("Card member's name matches, but billing address and billing postal code do not match.");
      case'L':
        return $this->t("Card member's name and billing postal code match, but billing address does not match.");
      case'O':
        return $this->t("Card member's name and billing address match, but billing postal code does not match.");
      case'P':
        return $this->t("Postal code matches, but street address not verified.");
      case'R':
        return $this->t("System unavailable.");
      case'S':
        return $this->t("U.S.-issuing bank does not support AVS.");
      case'T':
        return $this->t("Card member's name does not match, but street address matches.");
      case'U':
        return $this->t("Your bank does not support non-U.S. AVS or is otherwise not functioning properly.");
      case'V':
        return $this->t("Card member's name, billing address, and billing postal code match.");
      case'W':
        return $this->t("Street address does not match, but nine-digit postal code matches.");
      case'X':
        return $this->t("Street address and nine-digit postal code match.");
      case'Y':
        return $this->t("Street address and five-digit postal code match.");
      case'Z':
        return $this->t("Street address does not match, but five-digit postal code matches.");
      case'1':
        return $this->t("AVS is not supported for this processor or card type.");
      case'2':
        return $this->t("The processor returned an unrecognized value for the AVS response.");
      case'3':
        return $this->t("Address is confirmed. Returned only for PayPal Express Checkout.");
      case'4':
        return $this->t("Address is not confirmed. Returned only for PayPal Express Checkout.");
      default:
        return parent::buildAvsResponseCodeLabel($avs_response_code, $card_type);
    }
  }

  /**
   * Maps the CyberSource credit card type to a Commerce credit card type.
   *
   * @param string $card_type
   *   The CyberSource credit card type.
   *
   * @return string
   *   The Commerce credit card type.
   */
  protected function mapCreditCardType($card_type) {
    $map = [
      'Visa' => 'visa',
      'Mastercard' => 'mastercard',
      'Amex' => 'amex',
      'Discover' => 'discover',
    ];
    if (!isset($map[$card_type])) {
      throw new HardDeclineException(sprintf('Unsupported credit card type "%s".', $card_type));
    }
    return $map[$card_type];
  }

}
