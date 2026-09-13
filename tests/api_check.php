<?php
/**
 * The JSON API client, driven through a fake transport.
 *
 * What matters here is the exact bytes that would go to the gateway: the
 * authentication block first, refId second, schema order everywhere, amounts
 * as two-decimal strings, and nothing secret in the masked copy.
 */

require __DIR__ . '/_bootstrap.php';

define('IS_ADMIN_FLAG', false);
$PLUGIN = ana_plugin_dir();
require $PLUGIN . '/catalog/includes/modules/payment/authorizenet_accept/AuthorizeNetAcceptApi.php';

section('helpers');
check('amount() gives two decimals with a dot and no separators', AuthorizeNetAcceptApi::amount(1234.5) === '1234.50');
check('amount() rounds half up at the cent', AuthorizeNetAcceptApi::amount(0.125) === '0.13');
check('amount() of a string', AuthorizeNetAcceptApi::amount('19.999') === '20.00');
check('truncate() trims and cuts', AuthorizeNetAcceptApi::truncate('  abcdefghij  ', 5) === 'abcde');
check('truncate() is multibyte safe', AuthorizeNetAcceptApi::truncate('ééééé', 3) === 'ééé');

$ordered = AuthorizeNetAcceptApi::orderKeys(['shipTo' => 1, 'amount' => 2, 'transactionType' => 3, 'custom' => 4, 'payment' => 5], AuthorizeNetAcceptApi::TRANSACTION_REQUEST_ORDER);
check('orderKeys() puts known keys in schema order and unknown ones last', array_keys($ordered) === ['transactionType', 'amount', 'payment', 'shipTo', 'custom']);

$address = AuthorizeNetAcceptApi::address(['zip' => '78701', 'company' => '', 'lastName' => 'Buyer', 'firstName' => 'Pat', 'address' => str_repeat('x', 80), 'country' => 'United States']);
check('address() drops empties, orders and truncates', array_keys($address) === ['firstName', 'lastName', 'address', 'zip', 'country'] && strlen($address['address']) === 60);

section('send(): payload shape');
$api = new AuthorizeNetAcceptApi(' login ', ' key ', true);
check('sandbox endpoint', $api->endpoint() === AuthorizeNetAcceptApi::ENDPOINT_SANDBOX);
check('production endpoint', (new AuthorizeNetAcceptApi('a', 'b', false))->endpoint() === AuthorizeNetAcceptApi::ENDPOINT_PRODUCTION);

$captured = [];
$api->setTransport(static function ($url, $json, $client) use (&$captured) {
    $captured = ['url' => $url, 'json' => $json];
    return ana_gateway_json(1);
});
$result = $api->send('createTransactionRequest', [
    'transactionRequest' => ['transactionType' => 'authCaptureTransaction', 'amount' => '1.00'],
    'refId' => 'ref-' . str_repeat('9', 30),
]);
$sent = json_decode($captured['json'], true);
check('root element is the request name', array_keys($sent) === ['createTransactionRequest']);
check('merchantAuthentication is first, refId second, then the body', array_keys($sent['createTransactionRequest']) === ['merchantAuthentication', 'refId', 'transactionRequest']);
check('credentials are trimmed', $sent['createTransactionRequest']['merchantAuthentication'] === ['name' => 'login', 'transactionKey' => 'key']);
check('refId is cut to 20 characters', strlen($sent['createTransactionRequest']['refId']) === 20);
check('JSON does not escape slashes', strpos($captured['json'], '\\/') === false);

section('send(): response handling');
check('BOM is stripped and the body decodes', $result['ok'] === true && $result['resultCode'] === 'Ok');
check('messageCode / messageText come from messages.message[0]', $result['messageCode'] === 'I00001' && $result['messageText'] === 'Successful.');
$t = $result['transaction'];
check('transaction is normalized', $t['responseCode'] === 1 && $t['transId'] === '40000012345' && $t['authCode'] === 'ABC123' && $t['accountNumber'] === 'XXXX0027' && $t['accountType'] === 'Visa' && $t['networkTransId'] === 'NET123');
check('reason comes from messages when there are no errors', $t['reasonCode'] === '1' && strpos($t['reasonText'], 'approved') !== false);

$api->setTransport(static function () { return ana_gateway_json(2); });
$declined = $api->send('createTransactionRequest', ['transactionRequest' => []])['transaction'];
check('a decline reports responseCode 2 and the error text', $declined['responseCode'] === 2 && $declined['reasonCode'] === '2' && strpos($declined['reasonText'], 'declined') !== false);

$api->setTransport(static function () { return ana_gateway_auth_failure_json(); });
$failed = $api->send('createTransactionRequest', ['transactionRequest' => []]);
check('an authentication failure has no transaction and carries E00007', $failed['ok'] === false && $failed['messageCode'] === 'E00007' && $failed['transaction']['responseCode'] === 0);

$api->setTransport(static function ($url, $json, $client) { $client->commErrNo = 28; $client->commError = 'Operation timed out'; return ''; });
$comm = $api->send('createTransactionRequest', ['transactionRequest' => []]);
check('a transport error becomes COMM with the curl message', $comm['messageCode'] === AuthorizeNetAcceptApi::CODE_COMM && $comm['messageText'] === 'Operation timed out' && $comm['response'] === null);

$api->setTransport(static function () { return '<html>502 Bad Gateway</html>'; });
$bad = $api->send('createTransactionRequest', ['transactionRequest' => []]);
check('an unreadable body becomes JSON', $bad['messageCode'] === AuthorizeNetAcceptApi::CODE_JSON && $bad['ok'] === false);

section('normalizeTransaction() edge cases');
$empty = AuthorizeNetAcceptApi::normalizeTransaction(null);
check('null gives every key with a neutral value', $empty['responseCode'] === 0 && $empty['transId'] === '' && $empty['errors'] === [] && $empty['messages'] === []);
$err = AuthorizeNetAcceptApi::normalizeTransaction(['responseCode' => '3', 'errors' => [['errorCode' => '11', 'errorText' => 'A duplicate transaction has been submitted.']], 'messages' => [['code' => '1', 'description' => 'ignored']]]);
check('errors take precedence over messages for the reason', $err['reasonCode'] === '11' && strpos($err['reasonText'], 'duplicate') !== false);

section('masking');
$api->setTransport(static function () { return ana_gateway_json(1); });
$api->send('createTransactionRequest', [
    'transactionRequest' => [
        'payment' => ['opaqueData' => ['dataDescriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT', 'dataValue' => 'eyJub25jZSI6InNlY3JldCJ9']],
        'refund' => ['creditCard' => ['cardNumber' => '4007000000027', 'cardCode' => '123']],
    ],
]);
$masked = json_encode($api->maskedRequest());
check('the transaction key is masked', strpos($masked, 'key') === false && strpos($masked, '****') !== false);
check('the nonce is masked', strpos($masked, 'eyJub25jZSI6') === false && strpos($masked, 'redacted') !== false);
check('a card number is masked to its last four', strpos($masked, '4007000000027') === false && strpos($masked, 'XXXXXXXXX0027') !== false);
check('a card code is masked', strpos($masked, '"cardCode":"***"') !== false);
check('lastRequest itself is left intact for the transport', $api->lastRequest['createTransactionRequest']['merchantAuthentication']['transactionKey'] === 'key');

ana_done('API client builds and reads requests correctly');
