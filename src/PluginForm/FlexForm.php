<?php

namespace Drupal\commerce_cybersource\PluginForm;

use Drupal\commerce_payment\PluginForm\PaymentMethodAddForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides FlexForm class.
 */
class FlexForm extends PaymentMethodAddForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $payment_method = $this->entity;
    if ($payment_method->bundle() === 'flex_credit_card') {
      $form['payment_details'] = $this->buildCreditCardForm($form['payment_details'], $form_state);
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildCreditCardForm(array $element, FormStateInterface $form_state) {
    static $client_token = NULL;

    /** @var \Drupal\commerce_cybersource\Plugin\Commerce\PaymentGateway\FlexInterface $plugin */
    $plugin = $this->plugin;

    // Generate a client token if one hasn't been generated yet.
    $client_token = $client_token ?? $plugin->generateKey();

    $element['#attached']['library'][] = 'commerce_cybersource/checkout';
    $element['#attached']['drupalSettings']['commerceCyberSource'] = [
      'clientToken' => $client_token,
      'integration' => 'custom',
    ];
    $element['#attributes']['class'][] = 'credit-card-form';
    $months = [];
    for ($i = 1; $i < 13; $i++) {
      $month = str_pad($i, 2, '0', STR_PAD_LEFT);
      $months[$month] = $month;
    }
    // Build a year select list that uses a 4 digit key with a 2 digit value.
    $current_year_4 = date('Y');
    $current_year_2 = date('y');
    $years = [];
    for ($i = 0; $i < 10; $i++) {
      $years[$current_year_4 + $i] = $current_year_2 + $i;
    }

    $element['number'] = [
      '#type' => 'item',
      '#title' => t('Card number'),
      '#markup' => '<div id="cybersource-card-number" class="cybersource-field"></div>',
    ];
    $element['#attributes']['class'][] = 'credit-card-form';
    $element['expiration'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['credit-card-form__expiration'],
      ],
    ];
    $element['expiration']['month'] = [
      '#type' => 'select',
      '#title' => t('Month'),
      '#options' => $months,
      '#default_value' => date('m'),
      '#required' => TRUE,
      '#attributes' => [
        'class' => ['cybersource-month'],
      ],
    ];
    $element['expiration']['divider'] = [
      '#type' => 'item',
      '#title' => '',
      '#markup' => '<span class="credit-card-form__divider">/</span>',
    ];
    $element['expiration']['year'] = [
      '#type' => 'select',
      '#title' => t('Year'),
      '#options' => $years,
      '#default_value' => $current_year_4,
      '#required' => TRUE,
      '#attributes' => [
        'class' => ['cybersource-year'],
      ],
    ];
    $element['cvv'] = [
      '#type' => 'item',
      '#title' => t('CVV code'),
      '#markup' => '<div id="cybersource-card-cvv" class="cybersource-field"></div>',
    ];
    $element['token'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'class' => ['cybersource-token'],
      ],
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  protected function validateCreditCardForm(array &$element, FormStateInterface $form_state) {
    $values = $form_state->getValue($element['#parents']);
    if (empty($values['token'])) {
      $form_state->setError($element['token'], $this->t('Error while token generation. Please contact site administrator.'));
    }
  }

  /**
   * Handles the submission of the credit card form.
   *
   * @param array $element
   *   The credit card form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the complete form.
   */
  protected function submitCreditCardForm(array $element, FormStateInterface $form_state) {
    $values = $form_state->getValue($element['#parents']);
    $this->entity->card_exp_month = $values['expiration']['month'];
    $this->entity->card_exp_year = $values['expiration']['year'];
  }

}
