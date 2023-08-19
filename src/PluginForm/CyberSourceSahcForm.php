<?php

namespace Drupal\commerce_cybersource\PluginForm;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class CyberSourceSahcForm extends BasePaymentOffsiteForm implements ContainerInjectionInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Config Factory Service Object.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new CyberSourceSahcForm object.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   Current user.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   Request stack.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(AccountInterface $current_user, RequestStack $request_stack, ConfigFactoryInterface $config_factory, LoggerInterface $logger, EntityTypeManagerInterface $entity_type_manager) {
    $this->currentUser = $current_user;
    $this->requestStack = $request_stack;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('request_stack'),
      $container->get('config.factory'),
      $container->get('logger.channel.commerce_cybersource'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    /** @var \Drupal\commerce_order\Entity\Order $order */
    $order = $payment->getOrder();
    /** @var \Drupal\commerce_paypal\Plugin\Commerce\PaymentGateway\ExpressCheckoutInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();

    $payment_gateway_plugin_configuration = $payment_gateway_plugin->getConfiguration();

    $data = [];

    // Required. Access key generated in the Business Center SA profile.
    $data['access_key'] = $payment_gateway_plugin_configuration['access_key'];

    /*
     * Required. Profile ID from the "General Settings" page of your
     * CyberSource Secure Acceptance profile.
     */
    $data['profile_id'] = $payment_gateway_plugin_configuration['profile_id'];

    // Required. CyberSource recommends either an order or transaction ID here.
    $data['reference_number'] = $payment->getOrderId();

    // Required.
    $data['locale'] = $payment_gateway_plugin_configuration['locale'];

    // Required.
    $data['amount'] = number_format($payment->getAmount()
      ->getNumber(), 2, '.', '');

    // Required.
    $data['currency'] = $payment->getAmount()->getCurrencyCode();

    // Required.
    $data['transaction_type'] = $payment_gateway_plugin_configuration['transaction_type'];

    // Create commerce_payment entity and use it's UUID as transaction_uuid.
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $commerce_payment */
    $commerce_payment = $payment_storage->create([
      'state' => 'new',
      'amount' => $order->getBalance(),
      'payment_gateway' => $this->getEntity()->getPaymentGatewayId(),
      'order_id' => $order->id(),
      'remote_id' => '',
      'remote_state' => '',
    ]);
    $commerce_payment->save();
    // Required. Transaction UUID.
    $data['transaction_uuid'] = $commerce_payment->uuid();

    /*
     * Required.
     * Date and time signature was generated. Must be in UTC date & time format.
     * Used by CyberSource to detect duplicate transaction attempts.
     */
    $data['signed_date_time'] = gmdate("Y-m-d\TH:i:s\Z");

    // Required. The ideal case is to have no fields un-signed.
    // Fields submitted by Drupal, useless to CyberSource.
    if ($this->currentUser->isAnonymous()) {
      /*
      Drupal doesn't add a form_token for Anonymous on the redirect form.
      Telling CyberSource that we're sending it, then not sending it, causes
      CyberSource to throw an access denied mystery error.
      @link https://drupal.org/node/2121409 Checkout as anonymous doesn't work @endlink
      */
      $data['unsigned_field_names'] = 'form_build_id,form_id';
    }
    else {
      $data['unsigned_field_names'] = 'form_build_id,form_id,form_token';
    }

    /*
    Optional.
    The substring is required because the CyberSource API only accepts 15
    characters for IP address; IPv6 addresses are 45 characters in length.
    */
    $data['customer_ip_address'] = substr($this->requestStack->getCurrentRequest()
      ->getClientIp(), 0, 15);
    // Optional.
    $data['payment_method'] = 'card';
    // Optional. Overrides the custom receipt profile setting in the SA profile.
    $data['override_custom_receipt_page'] = $form['#return_url'];
    // Optional. Overrides the custom cancel page.
    $data['override_custom_cancel_page'] = $form['#cancel_url'];
    // Optional. Customer email.
    if (!empty($order->getEmail())) {
      $data['bill_to_email'] = $order->getEmail();
    }

    /** @var \Drupal\profile\Entity\ProfileInterface $billing_profile */
    $billing_profile = $order->getBillingProfile();
    // Prepare the billing address for use in the request.
    if ($billing_profile && !$billing_profile->get('address')->isEmpty()) {
      $billing_address = $billing_profile->get('address')->first()->getValue();
      if (is_array($billing_address)) {
        // Optional. First name.
        if (!empty($billing_address['given_name'])) {
          $data['bill_to_forename'] = $billing_address['given_name'];
        }
        // Optional. Last name.
        if (!empty($billing_address['family_name'])) {
          $data['bill_to_surname'] = $billing_address['family_name'];
        }
      }
      // Optional. Company name.
      if (!empty($billing_address['organisation'])) {
        $data['bill_to_company_name'] = $billing_address['organisation'];
      }
      // Optional. Address line 1.
      if (!empty($billing_address['address_line1'])) {
        $data['bill_to_address_line1'] = $billing_address['address_line1'];
      }
      // Optional. Address line 2.
      if (!empty($billing_address['address_line2'])) {
        $data['bill_to_address_line2'] = $billing_address['address_line2'];
      }
      // Optional.
      if (!empty($billing_address['locality'])) {
        $data['bill_to_address_city'] = $billing_address['locality'];
      }
      // Optional. @link https://drupal.org/node/2112947 UK optional state @endlink
      if (!empty($billing_address['administrative_area'])) {
        $data['bill_to_address_state'] = $billing_address['administrative_area'];
      }
      // Optional.
      if (!empty($billing_address['country_code'])) {
        $data['bill_to_address_country'] = $billing_address['country_code'];
      }
      // Optional.
      if (!empty($billing_address['postal_code'])) {
        $data['bill_to_address_postal_code'] = $billing_address['postal_code'];
      }
    }
    // Optional, helpful for sorting transactions in Business Center.
    $site_name = $this->configFactory->get('system.site')->get('name');
    $data['merchant_defined_data5'] = substr($site_name, 0, 100);

    // Send line items. CyberSource limits items listed on the invoice to 200.
    $send_line_items = 0;
    $line_items = $order->getItems();
    for ($i = 0, $j = count($line_items); $i < $j && $i < 200; $i++) {
      /** @var  $line_item \Drupal\commerce_order\Entity\OrderItemInterface */
      $line_item = $line_items[$i];
      $item_unit_price = $line_item->getUnitPrice()->getNumber();
      if (!is_null($item_unit_price)) {
        // Handle common line item data, as long as the amount is greater than zero. Cybersource does not currently allow
        // sending of negative amount line items.
        if ($item_unit_price >= 0) {
          $send_line_items++;
          $data['item_' . $i . '_unit_price'] = number_format($item_unit_price, 2, '.', '');
          $data['item_' . $i . '_quantity'] = (int) $line_item->getQuantity();
          $sku = $line_item->label();

          // Handle product data.
          if ($line_item->hasPurchasedEntity()) {
            /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $purchase_entity */
            $purchase_entity = $line_item->getPurchasedEntity();
            $sku = $purchase_entity->getSku();

            /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
            $product = $purchase_entity->getProduct();
            if ($product) {
              $data['item_' . $i . '_name'] = substr($product->label(), 0, 199);
            }
          }
          $data['item_' . $i . '_sku'] = $sku;
        }
      }
    }

    // Add "line_item_count" field if we are sending any line items.
    if ($send_line_items > 0) {
      $data['line_item_count'] = $send_line_items;
    }

    $form = $this->buildRedirectForm($form, $form_state, $payment_gateway_plugin->getRedirectUrl(), $data, 'post');

    /*
    All fields should be added to $form by now so they can be signed.
    The signed_field_names is a required field and should list itself.
    */
    $data['signed_field_names'] = '';
    $signed_field_names_list = array_keys($data);
    $form['signed_field_names'] = [
      '#type' => 'hidden',
      '#value' => implode(',', $signed_field_names_list),
      '#parents' => ['signed_field_names'],
    ];
    $data['signed_field_names'] = $form['signed_field_names']['#value'];

    /*
    Add the signature field of signed name value pairs.
    Generated as SHA256 base64 using the secret key.
    */
    $form['signature'] = [
      '#type' => 'hidden',
      '#value' => $payment_gateway_plugin->signData($data),
      '#parents' => ['signature'],
    ];

    // Log data which will be sent to Cybersource.
    if ($payment_gateway_plugin_configuration['log_api_calls']) {
      $data['signature'] = $form['signature']['#value'];
      $this->logger->notice('Data sent to Cybersource using Cybersource SAHC payment gateway: %data', ['%data' => print_r($data, 1)]);
    }

    return $form;
  }

}
