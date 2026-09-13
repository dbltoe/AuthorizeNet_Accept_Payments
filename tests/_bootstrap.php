<?php
/**
 * Shared scaffolding for the Authorize.Net Accept.js Payments harnesses.
 *
 * One definition of check() with the argument order enforced at runtime, a
 * fake database that records every statement and answers the few queries the
 * module makes, and the handful of Zen Cart functions the module calls,
 * stubbed to behave the way core does (escaping in particular: a stub that
 * escapes more than Zen Cart would make every escaping test pass vacuously).
 *
 * Usage:
 *   require __DIR__ . '/_bootstrap.php';
 *   $PLUGIN = ana_plugin_dir();
 */

function ana_plugin_dir()
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $found = [];
    foreach (glob(dirname(__DIR__) . '/zc_plugins/AuthorizeNetAccept/v*', GLOB_ONLYDIR) as $candidate) {
        if (is_file($candidate . '/manifest.php')) {
            $found[] = str_replace('\\', '/', $candidate);
        }
    }
    if ($found === []) {
        fwrite(STDERR, "cannot locate the plugin directory below " . dirname(__DIR__) . "\n");
        exit(2);
    }
    if (count($found) > 1) {
        usort($found, static function ($a, $b) {
            return version_compare(basename($a), basename($b));
        });
        fwrite(STDERR, "  note: " . count($found) . " version directories present; using " . basename(end($found)) . "\n");
    }
    return $dir = end($found);
}

function ana_repo_root()
{
    return dirname(ana_plugin_dir(), 3);
}

$GLOBALS['ana_failures'] = 0;

/** One assertion. Argument order is (label, condition), checked at runtime. */
function check($label, $cond)
{
    if (!is_string($label) || is_string($cond)) {
        fwrite(STDERR, "\n  ABORT check() takes (label, condition) -- arguments look reversed\n");
        exit(2);
    }
    if ($cond) {
        echo "  ok    $label\n";
    } else {
        $GLOBALS['ana_failures']++;
        echo "  FAIL  $label\n";
    }
}

function section($title)
{
    echo "\n[$title]\n";
}

/** Prints the PASS: line the runner looks for, or exits non-zero. */
function ana_done($summary)
{
    echo "\n";
    if ($GLOBALS['ana_failures'] > 0) {
        echo "FAIL: {$GLOBALS['ana_failures']} check(s) failed -- $summary\n";
        exit(1);
    }
    echo "PASS: $summary\n";
    exit(0);
}

/* ------------------------------------------------------------------ */
/* Zen Cart look-alikes                                                */
/* ------------------------------------------------------------------ */

/** Thrown by the zen_redirect() stub so a harness can assert on a redirect. */
class AnaRedirect extends Exception
{
}

/** The notifier base class: records events and lets a harness attach a callback per event. */
if (!class_exists('base')) {
    class base
    {
        public static $events = [];
        public static $listeners = [];

        public function notify($eventID, $param1 = [], &$param2 = null, &$param3 = null, &$param4 = null, &$param5 = null, &$param6 = null, &$param7 = null, &$param8 = null, &$param9 = null)
        {
            self::$events[] = $eventID;
            if (isset(self::$listeners[$eventID])) {
                $fn = self::$listeners[$eventID];
                $fn($this, $param1, $param2, $param3);
            }
        }

        public function attach(&$observer, $eventIDArray)
        {
        }
    }
}

class AnaResult
{
    public $fields = [];
    public $EOF = true;
    private $rows;
    private $index = 0;

    public function __construct(array $rows = [])
    {
        $this->rows = $rows;
        $this->fields = $rows[0] ?? [];
        $this->EOF = ($rows === []);
    }

    public function MoveNext()
    {
        $this->index++;
        $this->EOF = !isset($this->rows[$this->index]);
        $this->fields = $this->rows[$this->index] ?? [];
    }

    public function RecordCount()
    {
        return count($this->rows);
    }
}

/**
 * A queryFactory stand-in: every statement is kept in $log, and the queries
 * the module makes get canned answers a harness can override in $answers
 * (substring of the SQL => rows).
 */
class AnaDb
{
    public $log = [];
    public $answers = [];
    public $insertId = 77;

    public function Execute($sql, $limit = null)
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        $this->log[] = $sql;
        foreach ($this->answers as $needle => $rows) {
            if (stripos($sql, $needle) !== false) {
                return new AnaResult($rows);
            }
        }
        return new AnaResult([]);
    }

    /** Mirrors queryFactory::bindVars() for the types the module uses. */
    public function bindVars($sql, $key, $value, $type)
    {
        switch ($type) {
            case 'integer':
                $value = (int)$value;
                break;
            case 'float':
                $value = (float)$value;
                break;
            case 'passthru':
                break;
            case 'string':
            case 'stringIgnoreNull':
            default:
                $value = "'" . addslashes((string)$value) . "'";
                break;
        }
        return str_replace($key, (string)$value, $sql);
    }

    public function insert_ID()
    {
        return $this->insertId;
    }

    /** Statements matching a substring, for assertions. */
    public function matching($needle)
    {
        return array_values(array_filter($this->log, static function ($s) use ($needle) {
            return stripos($s, $needle) !== false;
        }));
    }
}

/**
 * Core has two messageStack classes with different signatures: the storefront
 * one takes (class, message, type), the admin one takes (message, type). The
 * stub follows IS_ADMIN_FLAG so a harness catches a module calling the wrong
 * one for its side.
 */
class AnaMessageStack
{
    public $messages = [];

    public function add_session($a, $b = 'error', $c = 'error')
    {
        if (defined('IS_ADMIN_FLAG') && IS_ADMIN_FLAG === true) {
            $this->messages[] = ['class' => 'admin', 'message' => $a, 'type' => $b];
        } else {
            $this->messages[] = ['class' => $a, 'message' => $b, 'type' => $c];
        }
    }

    public function add($a, $b = 'error', $c = 'error')
    {
        $this->add_session($a, $b, $c);
    }
}

/** $zcDate look-alike for the formats the module uses. */
class AnaDate
{
    public function output($format, $timestamp = null)
    {
        $timestamp = $timestamp ?? time();
        return strtr($format, [
            '%B' => date('F', $timestamp),
            '%m' => date('m', $timestamp),
            '%y' => date('y', $timestamp),
            '%Y' => date('Y', $timestamp),
        ]);
    }
}

$GLOBALS['ana_history'] = [];
$GLOBALS['ana_mail'] = [];

/* The portable CLI builds may not load the curl extension. The harness never
 * makes a real HTTP call (the API client gets a fake transport), but the
 * installer's requirement check must see the function a real host has. */
if (!function_exists('curl_init')) {
    function curl_init($url = null)
    {
        throw new RuntimeException('the harness never opens a real connection');
    }
}

if (!function_exists('zen_output_string_protected')) {
    /* Core: htmlspecialchars($string, ENT_COMPAT, CHARSET) -- no more, no less. */
    function zen_output_string_protected($string)
    {
        return htmlspecialchars((string)$string, ENT_COMPAT, 'utf-8');
    }
}
if (!function_exists('zen_db_input')) {
    function zen_db_input($string)
    {
        return addslashes((string)$string);
    }
}
if (!function_exists('zen_href_link')) {
    function zen_href_link($page = '', $parameters = '', $connection = 'NONSSL', $add_session_id = true, $search_engine_safe = true, $static = false, $use_dir_ws_catalog = true)
    {
        return 'https://store.example/index.php?main_page=' . $page . ($parameters !== '' ? '&' . $parameters : '');
    }
}
if (!function_exists('zen_redirect')) {
    function zen_redirect($url)
    {
        throw new AnaRedirect($url);
    }
}
if (!function_exists('zen_draw_input_field')) {
    function zen_draw_input_field($name, $value = '', $parameters = '', $type = 'text', $reinsert_value = true)
    {
        return '<input type="' . $type . '" name="' . $name . '" value="' . zen_output_string_protected($value) . '"' . ($parameters !== '' ? ' ' . $parameters : '') . '>';
    }
}
if (!function_exists('zen_draw_hidden_field')) {
    function zen_draw_hidden_field($name, $value = '', $parameters = '')
    {
        return '<input type="hidden" name="' . $name . '" value="' . zen_output_string_protected($value) . '"' . ($parameters !== '' ? ' ' . $parameters : '') . '>';
    }
}
if (!function_exists('zen_draw_checkbox_field')) {
    function zen_draw_checkbox_field($name, $value = '', $checked = false, $parameters = '')
    {
        return '<input type="checkbox" name="' . $name . '" value="' . zen_output_string_protected($value) . '"' . ($checked ? ' checked' : '') . '>';
    }
}
if (!function_exists('zen_draw_textarea_field')) {
    function zen_draw_textarea_field($name, $wrap, $width, $height, $text = '', $parameters = '')
    {
        return '<textarea name="' . $name . '">' . zen_output_string_protected($text) . '</textarea>';
    }
}
if (!function_exists('zen_draw_form')) {
    function zen_draw_form($name, $action, $parameters = '', $method = 'post', $params = '', $usessl = false)
    {
        return '<form name="' . $name . '" action="' . $action . '?' . $parameters . '" method="' . $method . '"' . ($usessl ? ' data-token="1"' : '') . '>';
    }
}
if (!function_exists('zen_hide_session_id')) {
    function zen_hide_session_id()
    {
        return '';
    }
}
if (!function_exists('zen_get_all_get_params')) {
    function zen_get_all_get_params($exclude = [])
    {
        return 'oID=42&';
    }
}
if (!function_exists('zen_get_ip_address')) {
    function zen_get_ip_address()
    {
        return '203.0.113.7';
    }
}
if (!function_exists('zen_session_name')) {
    function zen_session_name()
    {
        return 'zenid';
    }
}
if (!function_exists('zen_session_id')) {
    function zen_session_id()
    {
        return 'sess1234567890';
    }
}
if (!function_exists('zen_update_orders_history')) {
    function zen_update_orders_history($orders_id, $message = '', $updated_by = null, $orders_new_status = -1, $notify_customer = -1, $also_update_orders_table = true)
    {
        $GLOBALS['ana_history'][] = ['orders_id' => $orders_id, 'message' => $message, 'status' => $orders_new_status, 'notify' => $notify_customer];
        return 1;
    }
}
if (!function_exists('zen_mail')) {
    function zen_mail($to_name, $to_address, $email_subject, $email_text, $from_email_name, $from_email_address, $block = [], $module = 'default', $attachments = [])
    {
        $GLOBALS['ana_mail'][] = ['to' => $to_address, 'subject' => $email_subject, 'text' => $email_text];
    }
}

/**
 * Define the constants a storefront or admin request would have, then the
 * module's language file, then its settings. $settings overrides defaults.
 */
function ana_define_environment(array $settings = [], array $extra = [])
{
    $defaults = [
        'DB_PREFIX' => 'zen_',
        'TABLE_CONFIGURATION' => 'zen_configuration',
        'TABLE_ZONES_TO_GEO_ZONES' => 'zen_zones_to_geo_zones',
        'TABLE_ORDERS' => 'zen_orders',
        'TABLE_AUTHORIZENET_ACCEPT' => 'zen_authorizenet_accept',
        'DEFAULT_ORDERS_STATUS_ID' => '1',
        'FILENAME_CHECKOUT_PROCESS' => 'checkout_process',
        'FILENAME_CHECKOUT_PAYMENT' => 'checkout_payment',
        'FILENAME_POPUP_CVV_HELP' => 'popup_cvv_help',
        'FILENAME_MODULES' => 'modules',
        'FILENAME_ORDERS' => 'orders.php',
        'HTTP_SERVER' => 'https://store.example',
        'CC_OWNER_MIN_LENGTH' => '3',
        'STORE_NAME' => 'Test Store',
        'STORE_OWNER' => 'Owner',
        'STORE_OWNER_EMAIL_ADDRESS' => 'owner@example.com',
        'TEXT_ERROR_MODULE_ALREADY_INSTALLED' => 'The %s module is already installed.',
        'PROJECT_VERSION_MAJOR' => '2',
        'PROJECT_VERSION_MINOR' => '2.2',
    ];
    foreach (array_merge($defaults, $extra) as $name => $value) {
        if (!defined($name)) {
            define($name, $value);
        }
    }

    $moduleSettings = array_merge([
        'STATUS' => 'True',
        'LOGIN' => 'login123',
        'TXNKEY' => 'txnkeySECRET',
        'CLIENT_KEY' => 'publicClientKey',
        'TESTMODE' => 'Sandbox',
        'AUTHORIZATION_TYPE' => 'Authorize+Capture',
        'USE_CVV' => 'True',
        'CURRENCY' => 'USD',
        'SORT_ORDER' => '0',
        'ZONE' => '0',
        'ORDER_STATUS_ID' => '2',
        'AUTH_ORDER_STATUS_ID' => '1',
        'REFUNDED_ORDER_STATUS_ID' => '5',
        'REVIEW_ORDER_STATUS_ID' => '6',
        'EMAIL_CUSTOMER' => 'False',
        'DUPLICATE_WINDOW' => '120',
        'SEND_LINE_ITEMS' => 'True',
        'DEBUGGING' => 'Off',
    ], $settings);
    foreach ($moduleSettings as $key => $value) {
        if ($value === null) {
            continue; // "not installed" cases leave a key undefined
        }
        if (!defined('MODULE_PAYMENT_AUTHORIZENET_ACCEPT_' . $key)) {
            define('MODULE_PAYMENT_AUTHORIZENET_ACCEPT_' . $key, $value);
        }
    }

    $define = require ana_plugin_dir() . '/catalog/includes/languages/english/modules/payment/lang.authorizenet_accept.php';
    foreach ($define as $name => $value) {
        if (!defined($name)) {
            define($name, $value);
        }
    }
}

/** A minimal $order the way checkout has it. */
function ana_sample_order($currency = 'USD')
{
    $order = new stdClass();
    $order->info = [
        'total' => 123.45,
        'currency' => $currency,
        'currency_value' => ($currency === 'USD') ? 1.0 : 0.8,
        'tax' => 8.45,
        'shipping_cost' => 15.00,
    ];
    $order->customer = ['email_address' => 'buyer@example.com', 'telephone' => '555-0100'];
    $order->billing = [
        'firstname' => 'Pat', 'lastname' => 'Buyer', 'company' => '', 'street_address' => '1 Main St',
        'city' => 'Austin', 'state' => 'TX', 'postcode' => '78701', 'country' => ['id' => 223, 'title' => 'United States'], 'zone_id' => 57,
    ];
    $order->delivery = [
        'firstname' => 'Pat', 'lastname' => 'Buyer', 'company' => '', 'street_address' => '2 Elm St',
        'city' => 'Austin', 'state' => 'TX', 'postcode' => '78702', 'country' => ['id' => 223, 'title' => 'United States'],
    ];
    $order->products = [
        ['id' => 12, 'model' => 'WIDGET-1', 'name' => 'A widget with a rather long product name that runs on', 'qty' => 2, 'final_price' => 50.00, 'tax' => 8.25, 'attributes' => [['option' => 'Color', 'value' => 'Blue']]],
        ['id' => 13, 'model' => '', 'name' => 'Plain thing', 'qty' => 1, 'final_price' => 0.00, 'tax' => 0, 'attributes' => []],
    ];
    return $order;
}

/** Canned gateway bodies. */
function ana_gateway_json($responseCode = 1, array $overrides = [])
{
    $base = [
        'responseCode' => (string)$responseCode,
        'authCode' => 'ABC123',
        'avsResultCode' => 'Y',
        'cvvResultCode' => 'M',
        'cavvResultCode' => '2',
        'transId' => '40000012345',
        'refTransID' => '',
        'transHash' => '',
        'testRequest' => '0',
        'accountNumber' => 'XXXX0027',
        'accountType' => 'Visa',
        'messages' => [['code' => '1', 'description' => 'This transaction has been approved.']],
        'transHashSha2' => '',
        'SupplementalDataQualificationIndicator' => 0,
        'networkTransId' => 'NET123',
    ];
    if ($responseCode === 2) {
        $base['messages'] = [];
        $base['errors'] = [['errorCode' => '2', 'errorText' => 'This transaction has been declined.']];
        $base['authCode'] = '';
    }
    if ($responseCode === 3) {
        $base['messages'] = [];
        $base['errors'] = [['errorCode' => '78', 'errorText' => 'The Card Code (CVV2/CVC2/CID) is invalid.']];
        $base['authCode'] = '';
    }
    if ($responseCode === 4) {
        $base['messages'] = [['code' => '252', 'description' => 'Your order has been received. Thank you for your business!']];
    }
    // Overrides win over the per-code defaults, so a harness can script any error.
    $transaction = array_merge($base, $overrides);
    return "\xEF\xBB\xBF" . json_encode([
        'transactionResponse' => $transaction,
        'refId' => 'SANDBOX-42-ABCD',
        'messages' => ['resultCode' => 'Ok', 'message' => [['code' => 'I00001', 'text' => 'Successful.']]],
    ]);
}

function ana_gateway_auth_failure_json()
{
    return json_encode([
        'messages' => ['resultCode' => 'Error', 'message' => [['code' => 'E00007', 'text' => 'User authentication failed due to invalid authentication values.']]],
    ]);
}
