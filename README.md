INTRODUCTION
------------

CyberSource module provides integration with cybersource.com.

CONFIGURATION
-------------

CyberSource module provides Drupal Commerce gateway plugin for CyberSource's [Secure Acceptance Hosted Checkout](https://developer.cybersource.com/library/documentation/dev_guides/Secure_Acceptance_Hosted_Checkout/Secure_Acceptance_Hosted_Checkout.pdf)
And CyberSource's [Flex Microform](https://developer.cybersource.com/api/developer-guides/dita-flex/SAFlexibleToken/FlexMicroform/GetStarted.html)
To be able to configure and use plugins, you need:

SAHC configuration:
1. Created profile in "Payment Configuration" -> "Secure Acceptance Settings".
  *  For this, please, use section 'Test and View Transactions' from mentioned earlier .pdf file.
  *  Please, use recommended settings added below.
2. Get Access Key and Secret Key from Secure Acceptance key used in settings of Secure Acceptance profile.
3. Get Profile ID from general settings page of you Secure Acceptance profile.
   It can be found under Profile Name in header region of the page.
4. Open "Payment Gateways" page on your Drupal site '/admin/commerce/config/payment-gateways'.

   *  Click on "Add payment gateway".
   *  Select Plugin "CyberSource SAHC" and use Merchant ID, Profile ID, Access Key, Secret Key from previous steps.
   *  Select Mode 'Test' or 'Live'.
      *  'Test' mode will use test server URL https://testsecureacceptance.cybersource.com/pay.
      *  'Live' mode will use live server URL https://secureacceptance.cybersource.com/pay
   *  Currently plugin provides only one transaction type 'authorization,create_payment_token'.
   *  Select value for 'Locale'.
   *  Please, use option 'Log API requests and responses.' only on test/develop sites as logs can contain sensitive information.

Flex configuration:
1. Create the profile https://developer.cybersource.com/hello-world/sandbox.html and log into it.
2. Go to Payment Configuration > Key Management.
3. Click generate key, choose API Cert/Secret > Shared Secret, and copy Key value.
4. Open "Payment Gateways" page on your Drupal site '/admin/commerce/config/payment-gateways'.
   *  Click on "Add payment gateway".
   *  Select Plugin "CyberSource Flex" and use Merchant ID from your profile, Serial Number - Key Detail of your created API key,
      Shared secret - Key value from step 3.
   *  Select Mode 'Test' or 'Live'.
      *  'Test' mode will use live server URL https://apitest.cybersource.com
      *  'Live' mode will use test server URL https://api.cybersource.com.
   *  Transaction types: 'Authorize funds' and 'Authorize and capture funds'.
   *  Please, use option 'Log API requests and responses.' only on test/develop sites as logs can contain sensitive information.

RECOMMENDED SETTINGS FOR CYBERSOURCE SECURE ACCEPTANCE PROFILE
-------------

1. GENERAL SETTINGS:

    *  'Integration methods' = 'Hosted checkout'
    *  'Added Value Services' = 'Payment Tokenization', 'Decision Manager', 'Verbose data'.

2. CUSTOMER RESPONSE:

    *  'Transaction Response Page' = 'Hosted By CyberSource'
    *  'Custom Cancel Response Page' = 'Hosted By CyberSource'

POSSIBLE ISSUES SAHC
-------------
If, during testing transactions, you will be faced with error message “Recurring Billing or Secure Storage service is not enabled for the merchant”,
please, contact your Cybersource Technical Account Manager in order to enable Token management for your account.
This [document](https://developer.cybersource.com/library/documentation/dev_guides/Token_Management/SO_API/TMS_SO_API.pdf) can be useful.

POSSIBLE ISSUES FLEX
-------------
Important!
1. This implementation requires HTTPS protocol.
2. While testing, you need to use ONLY real card data, which is required by CyberSource API
   if you will use the test card your token will always have an expired status, and you will get a 500 error.
