<?php
/**
 * The payment module on the admin side: the Modules > Payment install and
 * remove, the order-page block, and refund / capture / void.
 */

require __DIR__ . '/_bootstrap.php';

define('IS_ADMIN_FLAG', true);
ana_define_environment(['AUTHORIZATION_TYPE' => 'Authorize']);
$PLUGIN = ana_plugin_dir();
require $PLUGIN . '/catalog/includes/modules/payment/authorizenet_accept.php';

$db = new AnaDb();
$db->answers["configuration_key = 'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_STATUS'"] = [['configuration_value' => 'True']];
$db->answers["configuration_key = 'MODULE_PAYMENT_INSTALLED'"] = [['configuration_value' => 'freecharger.php;authorizenet_accept.php;cod.php']];
$db->answers['FROM zen_authorizenet_accept WHERE orders_id = 42'] = [
    ['id' => 1, 'transaction_type' => 'authOnlyTransaction', 'trans_id' => '40000012345', 'ref_trans_id' => '', 'response_code' => 1, 'reason_code' => '1', 'response_text' => 'This transaction has been approved.', 'auth_code' => 'ABC123', 'avs_code' => 'Y', 'cvv_code' => 'M', 'account_type' => 'Visa', 'account_number' => 'XXXX0027', 'amount' => '123.4500', 'currency' => 'USD', 'payment_source' => 'COMMON.ACCEPT.INAPP.PAYMENT', 'date_added' => '2026-09-13 10:00:00'],
    ['id' => 2, 'transaction_type' => 'voidTransaction', 'trans_id' => '40000012399', 'ref_trans_id' => '40000012345', 'response_code' => 2, 'reason_code' => '16', 'response_text' => 'The transaction cannot be found. <b>x</b>', 'auth_code' => '', 'avs_code' => 'P', 'cvv_code' => '', 'account_type' => 'Visa', 'account_number' => 'XXXX0027', 'amount' => '0.0000', 'currency' => 'USD', 'payment_source' => 'admin', 'date_added' => '2026-09-13 11:00:00'],
];
$messageStack = new AnaMessageStack();
$_SESSION = [];

class ScriptedAdminModule extends authorizenet_accept
{
    public static $reply;
    public $lastApi;

    public function api(): AuthorizeNetAcceptApi
    {
        $api = parent::api();
        $api->setTransport(static function ($url, $json, $client) {
            return ScriptedAdminModule::$reply;
        });
        $this->lastApi = $api;
        return $api;
    }
}

section('construction in admin');
$module = new ScriptedAdminModule();
check('admin title carries the sandbox flag', strpos($module->title, 'Authorize.net (Accept.js)') === 0 && strpos($module->title, 'Sandbox') !== false);
check('authorize-only sets the uncaptured status', $module->authorizeOnly() === true && $module->order_status === 1);
check('check() finds the STATUS row', $module->check() === 1);
check('keys() lists the 18 settings', count($module->keys()) === 18 && $module->keys()[0] === 'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_STATUS');

section('the uninstall constructor path');
$bare = new authorizenet_accept(true);
check('constructing for uninstall needs no language constants and does no lookups', $bare->enabled === true && $bare->title === '' && $bare->form_action_url === '');

section('admin_notification(): the order block');
$html = $module->admin_notification(42);
check('the history table lists both rows', substr_count($html, '<tr>') === 3 && strpos($html, '40000012345') !== false && strpos($html, '40000012399') !== false);
check('a failed row shows its reason, escaped', strpos($html, 'Declined: The transaction cannot be found. &lt;b&gt;x&lt;/b&gt;') !== false);
check('type labels are friendly', strpos($html, 'Authorize only') !== false && strpos($html, '>Void<') !== false);
check('the refund form is prefilled with the last approved charge', preg_match('~name="trans_id" value="40000012345"~', $html) === 1 && preg_match('~name="cc_number" value="0027"~', $html) === 1 && preg_match('~name="refamt" value="123.45"~', $html) === 1);
check('the capture form is offered for the open authorization', strpos($html, 'action=doCapture') !== false && preg_match('~name="captauthid" value="40000012345"~', $html) === 1);
check('the void form is prefilled', strpos($html, 'action=doVoid') !== false && preg_match('~name="voidauthid" value="40000012345"~', $html) === 1);
check('every form is drawn with the security-token flag', substr_count($html, 'data-token="1"') === 3);
check('the block links to the Merchant Interface', strpos($html, 'https://account.authorize.net/') !== false);
check('no unescaped user data: the response text tag was escaped', strpos($html, '<b>x</b>') === false);

section('_doRefund()');
$_POST = ['refamt' => '10.00', 'cc_number' => 'XXXX0027', 'trans_id' => '40000012345', 'refnote' => 'Refund Issued'];
check('refusing without the confirmation box', $module->_doRefund(42) === false && strpos(end($messageStack->messages)['message'], 'confirmation box') !== false);
$_POST['refconfirm'] = 'on';
$_POST['refamt'] = '0';
check('refusing a zero amount', $module->_doRefund(42) === false && strpos(end($messageStack->messages)['message'], 'invalid amount') !== false);
$_POST['refamt'] = '10.00';
$_POST['cc_number'] = '';
check('refusing without the last four', $module->_doRefund(42) === false && strpos(end($messageStack->messages)['message'], 'last 4 digits') !== false);
$_POST['cc_number'] = 'XXXX0027';
ScriptedAdminModule::$reply = ana_gateway_json(1, ['transId' => '40000012400']);
$GLOBALS['ana_history'] = [];
check('a refund goes through', $module->_doRefund(42) === true);
$req = $module->lastApi->lastRequest['createTransactionRequest']['transactionRequest'];
check('refund request: type, amount, last four with XXXX expiry, refTransId, in order', array_keys($req) === ['transactionType', 'amount', 'payment', 'refTransId'] && $req['transactionType'] === 'refundTransaction' && $req['amount'] === '10.00' && $req['payment'] === ['creditCard' => ['cardNumber' => '0027', 'expirationDate' => 'XXXX']] && $req['refTransId'] === '40000012345');
$h = end($GLOBALS['ana_history']);
check('history: refund line with the refunded status, customer notified', strpos($h['message'], 'REFUND INITIATED') === 0 && strpos($h['message'], 'Refund Issued') !== false && $h['status'] === 5 && $h['notify'] === 1);
check('success message names amount and transaction id', strpos(end($messageStack->messages)['message'], '10.00') !== false && strpos(end($messageStack->messages)['message'], '40000012400') !== false);
$row = end($db->log);
check('the refund was recorded against the order with its reference', strpos($row, 'INSERT INTO zen_authorizenet_accept') === 0 && strpos($row, "'refundTransaction'") !== false && strpos($row, "'40000012345'") !== false && strpos($row, "(42,") !== false);

ScriptedAdminModule::$reply = ana_gateway_json(3, ['errors' => [['errorCode' => '54', 'errorText' => 'The referenced transaction does not meet the criteria for issuing a credit.']]]);
check('a refused refund reports the gateway text with its code', $module->_doRefund(42) === false && strpos(end($messageStack->messages)['message'], 'criteria for issuing a credit. (54)') !== false);

section('_doCapt()');
$_POST = ['captamt' => '', 'captauthid' => '40000012345', 'captnote' => 'Settled previously-authorized funds.'];
check('refusing without the confirmation box', $module->_doCapt(42, 'Complete', 123.45, 'USD') === false);
$_POST['captconfirm'] = 'on';
ScriptedAdminModule::$reply = ana_gateway_json(1, ['transId' => '40000012345']);
check('a full capture goes through', $module->_doCapt(42, 'Complete', 123.45, 'USD') === true);
$req = $module->lastApi->lastRequest['createTransactionRequest']['transactionRequest'];
check('a blank amount captures the full authorization (no amount element)', array_keys($req) === ['transactionType', 'refTransId'] && $req['transactionType'] === 'priorAuthCaptureTransaction');
check('history: funds collected, completed status', strpos(end($GLOBALS['ana_history'])['message'], 'FUNDS COLLECTED') === 0 && end($GLOBALS['ana_history'])['status'] === 2);
$_POST['captamt'] = '100';
$module->_doCapt(42);
check('a typed amount is sent', $module->lastApi->lastRequest['createTransactionRequest']['transactionRequest']['amount'] === '100.00');

section('_doVoid()');
$_POST = ['voidauthid' => '40000012345', 'voidnote' => 'Transaction Cancelled'];
check('refusing without the confirmation box (the core module never actually checked this)', $module->_doVoid(42) === false);
$_POST['voidconfirm'] = 'on';
ScriptedAdminModule::$reply = ana_gateway_json(1, ['transId' => '40000012345']);
check('a void goes through', $module->_doVoid(42) === true);
$req = $module->lastApi->lastRequest['createTransactionRequest']['transactionRequest'];
check('void request', $req === ['transactionType' => 'voidTransaction', 'refTransId' => '40000012345']);
check('history: voided, refunded status', strpos(end($GLOBALS['ana_history'])['message'], 'VOIDED') === 0 && end($GLOBALS['ana_history'])['status'] === 5);
check('the admin-transaction notifier fired', in_array('NOTIFY_AUTHNET_ACCEPT_ADMIN_TRANSACTION', base::$events, true));

section('remove()');
$db->log = [];
$module->remove();
check('settings are deleted by prefix', strpos($db->log[0], "DELETE FROM zen_configuration WHERE configuration_key LIKE 'MODULE\\_PAYMENT\\_AUTHORIZENET\\_ACCEPT\\_%'") === 0);
$upd = $db->matching("SET configuration_value = 'freecharger.php;cod.php'");
check('the module is taken out of MODULE_PAYMENT_INSTALLED, leaving the others', count($upd) === 1);

section('install()');
$src = file_get_contents($PLUGIN . '/catalog/includes/modules/payment/authorizenet_accept.php');
preg_match_all("~installKey\('[^']*', '([A-Z_]+)', '([^']*)'~", $src, $m);
check('install() creates exactly the 18 keys that keys() lists', count($m[1]) === 18 && array_map(static function ($k) { return 'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_' . $k; }, $m[1]) === $module->keys());
$defaults = array_combine($m[1], $m[2]);
check('fresh installs default to Sandbox, capture, CVV on and no line-item surprise', $defaults['TESTMODE'] === 'Sandbox' && $defaults['AUTHORIZATION_TYPE'] === 'Authorize+Capture' && $defaults['USE_CVV'] === 'True' && $defaults['DEBUGGING'] === 'Off');
check('the transaction key uses the password display', strpos($src, "'TXNKEY', '', ") !== false && preg_match("~'TXNKEY'[^\n]*zen_cfg_password_display~", $src) === 1);

ana_done('admin side behaves');
