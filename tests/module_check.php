<?php
/**
 * The payment module on the storefront side: what it renders, what it
 * accepts from the browser, what it sends to the gateway, and how it maps
 * each gateway outcome. The gateway is a fake transport; the database records
 * every statement.
 */

require __DIR__ . '/_bootstrap.php';

define('IS_ADMIN_FLAG', false);
ana_define_environment();
$PLUGIN = ana_plugin_dir();
require $PLUGIN . '/catalog/includes/modules/payment/authorizenet_accept.php';

$db = new AnaDb();
$db->answers["SHOW TABLE STATUS LIKE 'zen_orders'"] = [['Auto_increment' => 42]];
$db->answers['SELECT orders_id FROM zen_orders'] = [['orders_id' => 20]]; // stale on purpose: the counter must win
$messageStack = new AnaMessageStack();
$zcDate = new AnaDate();
$currencies = new class {
    public function get_value($code)
    {
        return ($code === 'USD') ? 1.0 : 0.5;
    }
};
$order = ana_sample_order();
$_SESSION = ['customer_id' => 9];

/** A module whose gateway answers are scripted. */
class ScriptedModule extends authorizenet_accept
{
    public static $reply;
    public $lastApi;

    public function api(): AuthorizeNetAcceptApi
    {
        $api = parent::api();
        $api->setTransport(static function ($url, $json, $client) {
            $reply = ScriptedModule::$reply;
            return is_callable($reply) ? $reply($url, $json, $client) : $reply;
        });
        $this->lastApi = $api;
        return $api;
    }
}

section('construction');
$module = new ScriptedModule();
check('enabled from the STATUS setting', $module->enabled === true);
check('storefront title is the catalog title', $module->title === 'Credit Card');
check('completed status comes from ORDER_STATUS_ID', $module->order_status === 2);
check('gateway currency comes from CURRENCY', $module->gateway_currency === 'USD');
check('the module tells Zen Cart it collects card data on site', $module->collectsCardDataOnsite === true);
check('form action is checkout_process', strpos($module->form_action_url, 'checkout_process') !== false);
check('sandbox mode is recognized', $module->isSandbox() === true && $module->authorizeOnly() === false);

section('selection(): the payment block');
$selection = $module->selection();
check('id and label', $selection['id'] === 'authorizenet_accept' && $selection['module'] === 'Credit Card');
check('four fields with CVV on', count($selection['fields']) === 4);
$all = implode("\n", array_map(static function ($f) { return $f['field']; }, $selection['fields']));
check('name-on-card is a named field', strpos($all, 'name="authorizenet_accept_owner"') !== false);
check('name-on-card defaults to the billing name', strpos($all, 'value="Pat Buyer"') !== false);
check('the card number input has an id and NO name', preg_match('~<input type="text" id="authorizenet_accept-cc-number"[^>]*>~', $all, $m) === 1 && strpos($m[0], 'name=') === false);
check('the CVV input has an id and NO name', preg_match('~<input type="text" id="authorizenet_accept-cc-cvv"[^>]*>~', $all, $m) === 1 && strpos($m[0], 'name=') === false);
check('the expiry selects have ids and NO names', preg_match_all('~<select id="authorizenet_accept-cc-expires-(month|year)"[^>]*>~', $all, $m) === 2 && strpos(implode('', $m[0]), 'name=') === false);
check('the expiry month defaults to this month', strpos($all, '<option value="' . date('m') . '" selected>') !== false);
foreach (['descriptor', 'value', 'brand', 'last4', 'expires'] as $carrier) {
    check("hidden carrier $carrier is present and empty", strpos($all, '<input type="hidden" name="authorizenet_accept_' . $carrier . '" value="">') !== false);
}
check('an error box precedes the script', strpos($all, 'id="authorizenet_accept-error"') !== false);
check('the extra markup rides on the last field, not on a field of its own', strpos($selection['fields'][3]['field'], 'authorizenet_accept_value') !== false);
check('the script is emitted once', substr_count($all, '<script>') === 1);
check('the script config points at the SANDBOX SDK', strpos($all, '"scriptUrl":"https://jstest.authorize.net/v1/Accept.js"') !== false);
check('the script config carries the public key and login, not the transaction key', strpos($all, '"clientKey":"publicClientKey"') !== false && strpos($all, '"apiLoginID":"login123"') !== false && strpos($all, 'txnkeySECRET') === false);
check('the script config knows both checkout flows', strpos($all, '#paymentSubmit') !== false && strpos($all, '#opc-order-confirm') !== false);
check('no SDK <script src> in the markup; the loader builds it', preg_match('~<script[^>]+src=~', $all) === 0);
check('the selection notifier fired', in_array('NOTIFY_AUTHNET_ACCEPT_SELECTION_FIELDS', base::$events, true));
check('the script-config notifier fired', in_array('NOTIFY_AUTHNET_ACCEPT_SCRIPT_CONFIG', base::$events, true));

$js = $module->javascript_validation();
check('Zen Cart form check asserts only that the nonce arrived', strpos($js, 'authorizenet_accept_value.value == ""') !== false && strpos($js, 'cc_number') === false);

section('what the browser posts');
$good = [
    'authorizenet_accept_descriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT',
    'authorizenet_accept_value' => 'eyJjb2RlIjoiNTBfMl8wNjAwMDUzMzk0OTUxMTk3MzU4MjIzQjc2QjNGQzE3QkNENTQ0Q0Y0NTU0RTYzQzJCNTk4MjcxOEZDMjgyMEQ1OUI1RDMzMkU3N0RGOTg4MzdBOTBFNTNGNjMzQTcwRDM3RjIwQzYyRjU5RDkwODQ4NjMzM0Y2OUJBMDJEQjM4QjYxRUYxMEJEMjUwNDE2NzI5MzM1MTZGRDQxQzhCNjc5Rjg1MzIiLCJ0b2tlbiI6IjkzNzk0MjE1NDI0ODU2ODQ1MDQ1MDIiLCJ2IjoiMS4xIn0=',
    'authorizenet_accept_brand' => 'Visa',
    'authorizenet_accept_last4' => '0027',
    'authorizenet_accept_expires' => '1229',
    'authorizenet_accept_owner' => 'Pat Buyer',
];
check('a good submission validates', $module->validateSubmittedPayment($module->readSubmittedPayment($good)) === '');
check('a missing nonce is refused', $module->validateSubmittedPayment($module->readSubmittedPayment(['authorizenet_accept_owner' => 'Pat'])) === MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_NONCE_MISSING);
check('an unknown descriptor is refused', $module->validateSubmittedPayment($module->readSubmittedPayment(array_merge($good, ['authorizenet_accept_descriptor' => 'COMMON.APPLE.INAPP.PAYMENT']))) === MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_DESCRIPTOR_NOT_ALLOWED);
check('a nonce with markup in it is refused', $module->validateSubmittedPayment($module->readSubmittedPayment(array_merge($good, ['authorizenet_accept_value' => '<script>x</script>aaaaaaaaaaaaaaaa']))) === MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_NONCE_MISSING);
check('a short owner is refused', $module->validateSubmittedPayment($module->readSubmittedPayment(array_merge($good, ['authorizenet_accept_owner' => 'P']))) === MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_OWNER_TOO_SHORT);
$read = $module->readSubmittedPayment(array_merge($good, ['authorizenet_accept_brand' => 'Vi<b>sa</b>', 'authorizenet_accept_last4' => '12x0027', 'authorizenet_accept_owner' => ' <i>Pat</i> Buyer ']));
check('brand, last4 and owner are cleaned', $read['brand'] === 'Visa' && $read['last4'] === '0027' && $read['owner'] === 'Pat Buyer');

base::$listeners['NOTIFY_AUTHNET_ACCEPT_ALLOWED_DESCRIPTORS'] = static function ($class, $code, &$descriptors) {
    $descriptors[] = 'COMMON.APPLE.INAPP.PAYMENT';
};
check('an observer can allow a wallet descriptor', $module->validateSubmittedPayment($module->readSubmittedPayment(array_merge($good, ['authorizenet_accept_descriptor' => 'COMMON.APPLE.INAPP.PAYMENT']))) === '');
check('the wallet descriptor is not asked for a card owner', $module->validateSubmittedPayment($module->readSubmittedPayment(['authorizenet_accept_descriptor' => 'COMMON.APPLE.INAPP.PAYMENT', 'authorizenet_accept_value' => $good['authorizenet_accept_value']])) === '');
unset(base::$listeners['NOTIFY_AUTHNET_ACCEPT_ALLOWED_DESCRIPTORS']);

section('pre_confirmation_check() and the confirmation page');
$_POST = ['authorizenet_accept_owner' => 'Pat Buyer'];
$redirected = '';
try {
    $module->pre_confirmation_check();
} catch (AnaRedirect $e) {
    $redirected = $e->getMessage();
}
check('a bad submission goes back to the payment page with a message', strpos($redirected, 'checkout_payment') !== false && end($messageStack->messages)['class'] === 'checkout_payment');
$_POST = $good;
$module->pre_confirmation_check();
check('a good submission is kept', $module->paymentData['last4'] === '0027');
$confirmation = $module->confirmation();
$confText = implode(' ', array_map(static function ($f) { return $f['title'] . ' ' . $f['field']; }, $confirmation['fields']));
check('confirmation shows brand, owner, masked number and expiry', strpos($confText, 'Visa') !== false && strpos($confText, 'XXXX-XXXX-XXXX-0027') !== false && strpos($confText, '12/29') !== false && strpos($confText, '<script') === false);
$button = $module->process_button();
check('process_button carries the six fields and the session', substr_count($button, 'type="hidden"') === 7 && strpos($button, 'name="authorizenet_accept_value"') !== false && strpos($button, 'name="zenid"') !== false);
$ajax = $module->process_button_ajax();
check('process_button_ajax hands the confirmation form literal values, never a by-name copy', $ajax['ccFields'] === [] && $ajax['extraFields']['authorizenet_accept_value'] === $good['authorizenet_accept_value'] && $ajax['extraFields']['authorizenet_accept_last4'] === '0027' && $ajax['extraFields']['zenid'] === 'sess1234567890' && count($ajax['extraFields']) === 7);
$module->paymentData = [];
$_POST = array_merge($good, ['authorizenet_accept_owner' => 'Pat "Quote" Buyer']);
$ajax = $module->process_button_ajax();
check('without a prior pre_confirmation_check it reads the POST, and escapes for the value attribute', $ajax['extraFields']['authorizenet_accept_owner'] === 'Pat &quot;Quote&quot; Buyer');
$_POST = $good;
$module->pre_confirmation_check();

section('before_process(): the request');
ScriptedModule::$reply = ana_gateway_json(1);
$_POST = $good;
$module->before_process();
$sent = json_decode($module->lastApi->lastRequest ? json_encode($module->lastApi->lastRequest) : '{}', true);
$req = $sent['createTransactionRequest']['transactionRequest'];
check('auth+capture by default', $req['transactionType'] === 'authCaptureTransaction');
check('keys are in schema order', array_keys($req) === ['transactionType', 'amount', 'currencyCode', 'payment', 'order', 'lineItems', 'tax', 'shipping', 'customer', 'billTo', 'shipTo', 'customerIP', 'transactionSettings']);
check('amount is the order total as a string', $req['amount'] === '123.45' && $req['currencyCode'] === 'USD');
check('the nonce rides in opaqueData', $req['payment']['opaqueData'] === ['dataDescriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT', 'dataValue' => $good['authorizenet_accept_value']]);
check('invoice is SANDBOX-<the table counter>-<4 chars> and under 20 chars', preg_match('~^SANDBOX-42-[A-F0-9]{4}$~', $req['order']['invoiceNumber']) === 1 && strlen($sent['createTransactionRequest']['refId']) <= 20);
check('the counter query names the orders table, escaped', count($db->matching("SHOW TABLE STATUS LIKE 'zen_orders'")) >= 1);
check('description lists the products', strpos($req['order']['description'], 'A widget') !== false && strpos($req['order']['description'], '(qty: 2)') !== false);
check('line items: model or id, name cut to 31, attributes as description, taxable flag', $req['lineItems']['lineItem'][0]['itemId'] === 'WIDGET-1' && strlen($req['lineItems']['lineItem'][0]['name']) === 31 && $req['lineItems']['lineItem'][0]['description'] === 'Color: Blue' && $req['lineItems']['lineItem'][0]['taxable'] === 'true' && $req['lineItems']['lineItem'][1]['itemId'] === '13' && $req['lineItems']['lineItem'][1]['taxable'] === 'false');
check('line item keys are in schema order', array_keys($req['lineItems']['lineItem'][0]) === ['itemId', 'name', 'description', 'quantity', 'unitPrice', 'taxable']);
check('tax and shipping are sent', $req['tax'] === ['amount' => '8.45'] && $req['shipping'] === ['amount' => '15.00']);
check('customer id and email', $req['customer'] === ['type' => 'individual', 'id' => '9', 'email' => 'buyer@example.com']);
check('billTo in schema order with phone', array_keys($req['billTo']) === ['firstName', 'lastName', 'address', 'city', 'state', 'zip', 'country', 'phoneNumber']);
check('shipTo without phone', array_keys($req['shipTo']) === ['firstName', 'lastName', 'address', 'city', 'state', 'zip', 'country'] && $req['shipTo']['address'] === '2 Elm St');
check('customer IP', $req['customerIP'] === '203.0.113.7');
check('duplicate window and receipt email settings, no test flag in sandbox', $req['transactionSettings']['setting'] === [['settingName' => 'duplicateWindow', 'settingValue' => '120'], ['settingName' => 'emailCustomer', 'settingValue' => 'false']]);
check('the BEFORE and AFTER transaction notifiers fired', in_array('NOTIFY_AUTHNET_ACCEPT_BEFORE_TRANSACTION', base::$events, true) && in_array('NOTIFY_AUTHNET_ACCEPT_AFTER_TRANSACTION', base::$events, true));

section('before_process(): an approval');
check('auth code and transaction id are kept', $module->auth_code === 'ABC123' && $module->transaction_id === '40000012345' && $module->avs_response === 'Y' && $module->cvv_response === 'M');
check('the order gets the card summary from the gateway', $order->info['cc_type'] === 'Visa' && $order->info['cc_number'] === 'XXXX0027' && $order->info['cc_owner'] === 'Pat Buyer' && $order->info['cc_expires'] === '1229' && $order->info['cc_cvv'] === '***');
$inserts = $db->matching('INSERT INTO zen_authorizenet_accept');
check('one transaction row was recorded', count($inserts) === 1);
check('the recorded request is masked: no transaction key, no nonce', strpos($inserts[0], 'txnkeySECRET') === false && strpos($inserts[0], 'eyJjb2RlIjoi') === false && strpos($inserts[0], 'redacted') !== false);
check('the row carries trans id, codes, amount, currency and source', strpos($inserts[0], "'40000012345'") !== false && strpos($inserts[0], "'authCaptureTransaction'") !== false && strpos($inserts[0], "'COMMON.ACCEPT.INAPP.PAYMENT'") !== false && strpos($inserts[0], '123.45') !== false);
check('order status unchanged for a plain approval', $module->order_status === 2 && $module->heldForReview === false);

section('after_process()');
$insert_id = 42;
$module->after_process();
$history = end($GLOBALS['ana_history']);
check('history line has the label, auth code and transaction id, hidden from the customer', $history['orders_id'] === 42 && strpos($history['message'], 'Credit Card payment. AUTH: ABC123 TransID: 40000012345') === 0 && $history['status'] === 2 && $history['notify'] === -1);
$updates = $db->matching('UPDATE zen_authorizenet_accept SET orders_id = 42 WHERE id = 77');
check('the transaction row is tied to the order', count($updates) === 1);

section('before_process(): a decline');
$messageStack->messages = [];
ScriptedModule::$reply = ana_gateway_json(2);
$redirected = '';
try {
    $module->before_process();
} catch (AnaRedirect $e) {
    $redirected = $e->getMessage();
}
$last = end($messageStack->messages);
check('a decline returns to the payment page with the gateway reason and the standard text', strpos($redirected, 'checkout_payment') !== false && strpos($last['message'], 'This transaction has been declined.') !== false && strpos($last['message'], MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_DECLINED_MESSAGE) !== false);
check('the declined attempt was recorded too', count($db->matching('INSERT INTO zen_authorizenet_accept')) === 2);

section('before_process(): a CVV error is reworded');
ScriptedModule::$reply = ana_gateway_json(3);
try {
    $module->before_process();
} catch (AnaRedirect $e) {
}
check('reason 78 becomes the CVV wording', strpos(end($messageStack->messages)['message'], MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CVV_PROBLEM) === 0);

section('before_process(): held for review');
ScriptedModule::$reply = ana_gateway_json(4);
$module->before_process();
check('a held transaction is accepted with the review status', $module->heldForReview === true && $module->order_status === 6);
$module->after_process();
check('the history line says it is held', strpos(end($GLOBALS['ana_history'])['message'], MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_HELD_NOTE) !== false);

section('before_process(): the gateway rejects the credentials');
ScriptedModule::$reply = ana_gateway_auth_failure_json();
try {
    $module->before_process();
} catch (AnaRedirect $e) {
}
check('E00007 is shown as a gateway problem, not a decline', strpos(end($messageStack->messages)['message'], 'User authentication failed') !== false);

section('before_process(): communications failure');
ScriptedModule::$reply = static function ($url, $json, $client) { $client->commErrNo = 7; $client->commError = 'Failed to connect'; return ''; };
try {
    $module->before_process();
} catch (AnaRedirect $e) {
}
$last = end($messageStack->messages);
check('a transport failure is a caution with the comm text', $last['type'] === 'caution' && strpos($last['message'], MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_COMM_ERROR) === 0);

section('before_process(): a converted currency');
$order = ana_sample_order('EUR');
ScriptedModule::$reply = ana_gateway_json(1);
$module->before_process();
$req = json_decode(json_encode($module->lastApi->lastRequest), true)['createTransactionRequest']['transactionRequest'];
/* Order totals are kept in the store's default currency; the gateway amount is
 * that total times the gateway currency's rate (1.0 for USD here), exactly as
 * the core module does it. What the customer SAW (the EUR figure) is only
 * mentioned in the description. */
check('amount is the default-currency total times the gateway rate, in the gateway currency', $req['amount'] === '123.45' && $req['currencyCode'] === 'USD');
check('tax, shipping and line items are left out when converting', !isset($req['tax']) && !isset($req['shipping']) && !isset($req['lineItems']));
check('the description says what the customer saw', strpos($req['order']['description'], 'Converted from: 98.76 EUR') !== false);
$module->after_process();
check('the history line shows the amount charged in the gateway currency', strpos(end($GLOBALS['ana_history'])['message'], '123.45 USD') !== false);

section('sandbox logging and the debug email');
$order = ana_sample_order();
$logDir = sys_get_temp_dir() . '/ana_logs_' . getmypid();
@mkdir($logDir);
define('DIR_FS_LOGS', $logDir);
ScriptedModule::$reply = ana_gateway_json(1);
$module->before_process();
$logs = glob($logDir . '/authnet_accept_transaction_40000012345_*.log');
check('sandbox mode writes a log named after the transaction', count($logs) === 1);
$logText = file_get_contents($logs[0]);
check('the log masks the key and the nonce and keeps the response', strpos($logText, 'txnkeySECRET') === false && strpos($logText, 'eyJjb2RlIjoi') === false && strpos($logText, '"transId": "40000012345"') !== false);
array_map('unlink', glob($logDir . '/*'));
@rmdir($logDir);

ana_done('storefront module behaves');
