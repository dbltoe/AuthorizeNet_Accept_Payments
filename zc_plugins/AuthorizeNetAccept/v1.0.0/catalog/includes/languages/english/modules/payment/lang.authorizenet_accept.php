<?php
/**
 * Authorize.Net Accept.js Payments -- language definitions.
 *
 * Loaded for both the storefront and the admin (Zen Cart's language loader
 * reads a plugin's catalog module definitions on both sides).
 *
 * @package  AuthorizeNetAccept
 * @license  https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU Public License V2.0
 */

$define = [
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_ADMIN_TITLE' => 'Authorize.net (Accept.js)',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CATALOG_TITLE' => 'Credit Card',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_ERROR_CURL_NOT_FOUND' => 'CURL functions not found - required for the Authorize.net Accept.js payment module',

    // Checkout fields
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CREDIT_CARD_TYPE' => 'Card Type:',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CREDIT_CARD_OWNER' => 'Name on Card:',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CREDIT_CARD_NUMBER' => 'Card Number:',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CREDIT_CARD_EXPIRES' => 'Expiry Date:',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CVV' => 'CVV Number:',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_POPUP_CVV_LINK' => 'What\'s this?',

    // Messages the checkout script shows beside the card fields
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_JS_CC_OWNER' => 'Please enter the name as it appears on the card.',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_JS_CC_NUMBER' => 'Please check the card number.',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_JS_CC_EXPIRES' => 'Please check the expiry date.',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_JS_CC_CVV' => 'Please enter the 3 or 4 digit security code from the card.',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_JS_WORKING' => 'Securing your card details...',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_JS_FAILED' => 'Your card details could not be secured for transmission. Please check them and try again.',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_JS_LOAD_FAILED' => 'The secure card service could not be loaded. Please check your connection and try again, or choose another payment method.',
    // Used by Zen Cart's own form check when the script could not run at all
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_JS_NONCE_MISSING' => '* Your card details could not be secured for transmission. Please try again or choose another payment method.\n',

    // Server-side checks
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_NONCE_MISSING' => 'Your card details did not arrive securely. Please enter them again.',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_DESCRIPTOR_NOT_ALLOWED' => 'That payment method isn\'t enabled on this store.',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_OWNER_TOO_SHORT' => 'Please enter the name as it appears on the card.',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_DECLINED_MESSAGE' => 'Your card could not be authorized for this reason. Please correct the information and try again, or contact us for further assistance.',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_GATEWAY_ERROR' => 'The payment gateway reported a problem: %s',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_ERROR' => 'Credit Card Error!',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_COMM_ERROR' => 'Unable to process the payment because of a communications error. You may try again or contact us for assistance.',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CVV_PROBLEM' => 'The card security code was not accepted.',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_EXPIRY_PROBLEM' => 'The expiry date was not accepted.',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_HELD_NOTE' => '***NOTE: Held for review by merchant.',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_PAYMENT_LABEL' => 'Credit Card payment.',

    // Order page: transaction history
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_TRANSACTIONS_TITLE' => 'Authorize.net transactions for this order',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_NO_TRANSACTIONS' => 'No gateway transactions are recorded for this order.',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_COL_DATE' => 'Date',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_COL_TYPE' => 'Type',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_COL_TRANS_ID' => 'Transaction ID',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_COL_RESULT' => 'Result',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_COL_AUTH' => 'Auth',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_COL_AVS_CVV' => 'AVS / CVV',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_COL_CARD' => 'Card',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_COL_AMOUNT' => 'Amount',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_RESULT_APPROVED' => 'Approved',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_RESULT_DECLINED' => 'Declined',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_RESULT_ERROR' => 'Error',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_RESULT_HELD' => 'Held for review',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_MERCHANT_LINK' => 'Go to Authorize.net',

    // Order page: refund
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_REFUND_TITLE' => '<strong>Refund Transactions</strong>',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_REFUND' => 'You may refund money to the customer\'s card here:',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_REFUND_AMOUNT_TEXT' => 'Amount to refund:',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_REFUND_CC_NUM_TEXT' => 'Last 4 digits of the card:',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_REFUND_TRANS_ID' => 'Original Transaction ID:',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_REFUND_CONFIRM_CHECK' => 'Check this box to confirm your intent: ',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_REFUND_TEXT_COMMENTS' => 'Notes (will show on Order History):',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_REFUND_DEFAULT_MESSAGE' => 'Refund Issued',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_REFUND_SUFFIX' => 'You may refund up to the amount already settled, within 120 days of the original transaction. A transaction that has not settled yet cannot be refunded; void it instead.',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_REFUND_BUTTON_TEXT' => 'Do Refund',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_REFUND_CONFIRM_ERROR' => 'Error: You requested a refund but did not check the confirmation box.',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_INVALID_REFUND_AMOUNT' => 'Error: You requested a refund but entered an invalid amount.',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CC_NUM_REQUIRED_ERROR' => 'Error: You requested a refund but did not enter the last 4 digits of the card number.',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_TRANS_ID_REQUIRED_ERROR' => 'Error: You need to specify a Transaction ID.',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_REFUND_INITIATED' => 'Refund initiated. Amount: %1$s. Transaction ID: %2$s',

    // Order page: capture
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_CAPTURE_TITLE' => '<strong>Capture Transactions</strong>',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_CAPTURE' => 'You may capture previously-authorized funds here:',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_CAPTURE_AMOUNT_TEXT' => 'Amount to capture (blank = full authorized amount):',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_CAPTURE_TRANS_ID' => 'Original Transaction ID:',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CAPTURE_CONFIRM_CHECK' => 'Check this box to confirm your intent: ',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_CAPTURE_TEXT_COMMENTS' => 'Notes (will show on Order History):',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_CAPTURE_DEFAULT_MESSAGE' => 'Settled previously-authorized funds.',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_CAPTURE_SUFFIX' => 'Captures must be performed within 30 days of the original authorization, and an authorization can only be captured once.',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_CAPTURE_BUTTON_TEXT' => 'Do Capture',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CAPTURE_CONFIRM_ERROR' => 'Error: You requested a capture but did not check the confirmation box.',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CAPT_INITIATED' => 'Funds capture initiated. Amount: %1$s. Transaction ID: %2$s - Auth Code: %3$s',

    // Order page: void
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_VOID_TITLE' => '<strong>Voiding Transactions</strong>',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_VOID' => 'You may void a transaction that has not settled yet, or an authorization that has not been captured.<br>Transaction ID to void:',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_VOID_CONFIRM_CHECK' => 'Check this box to confirm your intent: ',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_VOID_TEXT_COMMENTS' => 'Notes (will show on Order History):',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_VOID_DEFAULT_MESSAGE' => 'Transaction Cancelled',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_VOID_SUFFIX' => 'Voids must be completed before the original transaction settles in the daily batch.',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_VOID_BUTTON_TEXT' => 'Do Void',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_VOID_CONFIRM_ERROR' => 'Error: You requested a void but did not check the confirmation box.',
    'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_VOID_INITIATED' => 'Void initiated. Transaction ID: %1$s',
];

$anaTestMode = defined('MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TESTMODE') ? MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TESTMODE : 'Sandbox';
$anaLinks = '<a rel="noreferrer noopener" target="_blank" href="https://account.authorize.net/">Authorize.net Merchant Login</a>'
    . ' | <a rel="noreferrer noopener" target="_blank" href="https://sandbox.authorize.net/">Sandbox Login</a>'
    . ' | <a rel="noreferrer noopener" target="_blank" href="https://developer.authorize.net/hello_world/sandbox.html">Create a Sandbox Account</a>';
$anaCredentials = '<br><br><strong>Where the three credentials come from</strong> (Merchant Interface, or the sandbox\'s): '
    . 'Account &gt; Settings &gt; Security Settings &gt; General Security Settings &gt; <strong>API Credentials &amp; Keys</strong> for the API Login ID and a Transaction Key, '
    . 'and <strong>Manage Public Client Key</strong> on the same page for the Public Client Key that Accept.js uses in the browser.';
$anaTesting = '<br><br><strong>Sandbox testing:</strong> use sandbox credentials with Transaction Mode = Sandbox. '
    . 'Visa 4007000000027, Mastercard 5424000000000015, Discover 6011000000000012 and Amex 370000000000002 approve with any future expiry date; '
    . 'a billing ZIP of 46282 returns a decline. See <a rel="noreferrer noopener" target="_blank" href="https://developer.authorize.net/hello_world/testing_guide.html">the testing guide</a> for the full list.';

if (defined('MODULE_PAYMENT_AUTHORIZENET_ACCEPT_STATUS') && MODULE_PAYMENT_AUTHORIZENET_ACCEPT_STATUS === 'True') {
    $define['MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_DESCRIPTION'] = $anaLinks . $anaCredentials
        . ($anaTestMode !== 'Production' ? $anaTesting : '')
        . '<br><br>The card number and security code are tokenized in the customer\'s browser by Accept.js and never posted to this store.';
} else {
    $define['MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_DESCRIPTION'] = $anaLinks
        . '<br><br><strong>Requirements:</strong><hr>'
        . '* An <strong>Authorize.net merchant account</strong> (or a sandbox account for testing)<br>'
        . '* <strong>CURL</strong> compiled with SSL support into PHP<br>'
        . '* Your <strong>API Login ID, Transaction Key and Public Client Key</strong> from the Merchant Interface'
        . $anaCredentials
        . '<br><br>Replaces the retired AIM and SIM modules: the card number and security code are tokenized in the customer\'s browser by Accept.js and never posted to this store.';
}

return $define;
