/**
 * @file
 * Defines behaviors for the CyberSource payment method form.
 */

(function ($, Drupal, drupalSettings) {

  'use strict';

  /**
   * Attaches the commerceCyberSource behavior.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches the commerceCyberSource behavior.
   *
   * @see Drupal.commerceCyberSource
   */
  Drupal.behaviors.commerceCyberSourceForm = {
    attach: function () {
      $('#commerce-checkout-flow-multistep-default').once('cybersource').each(function () {
        var $form = $(this);
        var interval = setInterval(function () {
          if ($('#cybersource-card-number').length && $('#cybersource-card-cvv').length) {
            clearInterval(interval);
            var captureContext = drupalSettings.commerceCyberSource.clientToken,
              flex = new Flex(captureContext),
              microform = flex.microform({
                styles: {
                  input: {
                    'font-size': '14px',
                    'font-family': 'Lucida Sans Unicode, Verdana, sans-serif'
                  },
                  ':disabled': {
                    cursor: 'not-allowed'
                  },
                  valid: {
                    color: '#3c763d'
                  },
                  invalid: {
                    color: '#a94442'
                  }
                }
              });

            microform
              .createField('number', { placeholder: 'Enter card number' })
              .load('#cybersource-card-number');

            microform
              .createField('securityCode', { placeholder: '•••' })
              .load('#cybersource-card-cvv');

            $('.form-submit', $form).on('click', function () {
              var $submit = $(this);

              microform.createToken({
                expirationMonth: $('.cybersource-month', $form).val(),
                expirationYear: $('.cybersource-year', $form).val(),
              }, function (err, token) {
                if (err) {
                  switch (err.reason) {
                    case 'CREATE_TOKEN_TIMEOUT':
                    case 'CREATE_TOKEN_NO_FIELDS_LOADED':
                    case 'CREATE_TOKEN_NO_FIELDS':
                    case 'CREATE_TOKEN_VALIDATION_PARAMS':
                    case 'CREATE_TOKEN_VALIDATION_FIELDS':
                    case 'CREATE_TOKEN_VALIDATION_SERVERSIDE':
                    case 'CREATE_TOKEN_UNABLE_TO_START':
                      console.error(err.message);
                      return;
                    default:
                      console.error('Unknown error');
                      return;
                  }
                }
                $('.cybersource-token', $form).val(token);
                $form.submit();
                $submit.prop('disabled', true);
              });
            }).attr('type', 'button');
          }
        }, 10);
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
