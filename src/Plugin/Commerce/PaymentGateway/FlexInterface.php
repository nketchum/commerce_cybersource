<?php

namespace Drupal\commerce_cybersource\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;

/**
 * Provides the interface for the Flex payment gateway.
 */
interface FlexInterface extends OnsitePaymentGatewayInterface, SupportsAuthorizationsInterface, SupportsRefundsInterface {

  /**
   * Generates the client key.
   *
   * Used by the add-payment-method plugin form.
   *
   * @return string
   *   The client key.
   */
  public function generateKey();

}
