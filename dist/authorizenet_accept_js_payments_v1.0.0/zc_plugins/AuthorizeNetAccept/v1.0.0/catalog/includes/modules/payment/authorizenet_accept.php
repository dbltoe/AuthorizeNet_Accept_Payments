<?php
/**
 * Authorize.Net Accept.js Payments -- the payment module.
 *
 * A drop-in successor to the core Authorize.net AIM module on Authorize.Net's
 * current JSON API. The visible difference for the customer is none: the same
 * card fields on the same payment page. The difference underneath is that the
 * card number and security code are tokenized in the browser by Accept.js and
 * never reach this server; the module posts a one-time nonce to the gateway in
 * their place. See docs/DESIGN.md for the reasoning and docs/CUSTOMIZING.md
 * for the notifier seams an add-on can use.
 *
 * Method names and the admin action hooks (_doRefund, _doCapt, _doVoid) match
 * the core module so Zen Cart's order page drives them unchanged.
 *
 * @package  AuthorizeNetAccept
 * @license  https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU Public License V2.0
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

require_once __DIR__ . '/authorizenet_accept/AuthorizeNetAcceptApi.php';
require_once __DIR__ . '/authorizenet_accept/AuthorizeNetAcceptLog.php';

class authorizenet_accept extends base
{
    const VERSION = '1.0.0';
    const CONFIG_PREFIX = 'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_';
    const CONFIG_GROUP_ID = 6;
    const SCRIPT_SANDBOX = 'https://jstest.authorize.net/v1/Accept.js';
    const SCRIPT_PRODUCTION = 'https://js.authorize.net/v1/Accept.js';
    /** Re-tokenize after this long; Accept.js nonces live 15 minutes. */
    const TOKEN_TTL_MS = 600000;
    /** Buttons the checkout script intercepts: Zen Cart's Continue, and One Page Checkout's Review / Confirm. */
    const SUBMIT_SELECTOR = '#paymentSubmit input[type="submit"], #paymentSubmit input[type="image"], #paymentSubmit button, #opc-order-confirm, #opc-order-review, #checkoutOneSubmit';
    /** Where the settings wait between a Remove and a re-Install under Modules > Payment. */
    const SETTINGS_STASH_KEY = 'AUTHORIZENET_ACCEPT_SETTINGS_STASH';

    /** @var string */
    public $code = 'authorizenet_accept';
    /** @var string */
    public $title = '';
    /** @var string */
    public $description = '';
    /** @var bool */
    public $enabled = false;
    /** @var int|null */
    public $sort_order = null;
    /** @var string */
    public $form_action_url = '';
    /** @var int */
    public $order_status = 0;
    /** @var bool  Card details are entered on the payment page (tokenized there, never posted). */
    public $collectsCardDataOnsite = true;
    /** @var string */
    public $gateway_currency = 'USD';

    /** @var array  What the customer submitted, validated by pre_confirmation_check(). */
    public $paymentData = [];
    /** @var string  Prefix for the order-history comment; an add-on can change it (e.g. "Apple Pay payment."). */
    public $paymentLabel = '';
    /** @var string */
    public $auth_code = '';
    /** @var string */
    public $transaction_id = '';
    /** @var string */
    public $avs_response = '';
    /** @var string */
    public $cvv_response = '';
    /** @var bool */
    public $heldForReview = false;
    /** @var bool */
    protected $amountConverted = false;
    /** @var float */
    protected $convertedAmount = 0.0;
    /** @var int */
    protected $transactionRowId = 0;
    /** @var int|null */
    protected $_check = null;

    /**
     * @param bool $uninstalling  true when the Plugin Manager is uninstalling the
     *                            plugin: the language file is not loaded and there
     *                            is no order, so only enough is set up to call remove().
     */
    public function __construct($uninstalling = false)
    {
        global $order, $messageStack;

        $this->enabled = ($this->cfg('STATUS') === 'True');
        $this->sort_order = defined(self::CONFIG_PREFIX . 'SORT_ORDER') ? (int)constant(self::CONFIG_PREFIX . 'SORT_ORDER') : null;

        if ($uninstalling === true) {
            return;
        }

        if (IS_ADMIN_FLAG === true) {
            $this->title = MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_ADMIN_TITLE;
            if ($this->enabled) {
                if (trim($this->cfg('LOGIN')) === '' || trim($this->cfg('TXNKEY')) === '' || trim($this->cfg('CLIENT_KEY')) === '') {
                    $this->title .= '<span class="alert"> (Not Configured)</span>';
                } elseif ($this->cfg('TESTMODE') === 'Sandbox') {
                    $this->title .= '<span class="alert"> (in Sandbox mode)</span>';
                } elseif ($this->cfg('TESTMODE') === 'Test') {
                    $this->title .= '<span class="alert"> (test requests only)</span>';
                }
                if (!function_exists('curl_init') && is_object($messageStack)) {
                    $messageStack->add_session(MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_ERROR_CURL_NOT_FOUND, 'error');
                }
            }
        } else {
            $this->title = MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CATALOG_TITLE;
        }
        $this->description = MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_DESCRIPTION;
        $this->paymentLabel = MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_PAYMENT_LABEL;

        if ($this->sort_order === null) {
            return; // not installed under Modules > Payment yet
        }

        $this->form_action_url = zen_href_link(FILENAME_CHECKOUT_PROCESS, '', 'SSL', false);

        $this->order_status = (int)DEFAULT_ORDERS_STATUS_ID;
        if ((int)$this->cfg('ORDER_STATUS_ID', 0) > 0) {
            $this->order_status = (int)$this->cfg('ORDER_STATUS_ID');
        }
        if ($this->authorizeOnly() && (int)$this->cfg('AUTH_ORDER_STATUS_ID', 0) > 0) {
            $this->order_status = (int)$this->cfg('AUTH_ORDER_STATUS_ID');
        }
        $this->gateway_currency = $this->cfg('CURRENCY', 'USD');

        if (is_object($order)) {
            $this->update_status();
        }
    }

    // -----------------------------------------------------------------
    // Configuration helpers
    // -----------------------------------------------------------------

    /**
     * A module setting, or $default when the key is not defined.
     */
    public function cfg(string $key, $default = '')
    {
        $name = self::CONFIG_PREFIX . $key;
        return defined($name) ? constant($name) : $default;
    }

    public function isSandbox(): bool
    {
        return $this->cfg('TESTMODE') === 'Sandbox';
    }

    public function authorizeOnly(): bool
    {
        return $this->cfg('AUTHORIZATION_TYPE') === 'Authorize';
    }

    /**
     * The API client for the configured mode. An add-on that needs the same
     * credentials (customer profiles, say) asks the module for this rather
     * than reading the settings itself.
     */
    public function api(): AuthorizeNetAcceptApi
    {
        return new AuthorizeNetAcceptApi(trim($this->cfg('LOGIN')), trim($this->cfg('TXNKEY')), $this->isSandbox());
    }

    /**
     * The opaqueData descriptors this store accepts. Plain Accept.js by
     * default; an add-on extends the list through the notifier.
     */
    public function allowedDescriptors(): array
    {
        $descriptors = [AuthorizeNetAcceptApi::DESCRIPTOR_ACCEPT];
        $this->notify('NOTIFY_AUTHNET_ACCEPT_ALLOWED_DESCRIPTORS', $this->code, $descriptors);
        return array_values(array_unique($descriptors));
    }

    // -----------------------------------------------------------------
    // Availability
    // -----------------------------------------------------------------

    /**
     * Zone restriction, and no live card entry on a page that is not https.
     */
    public function update_status()
    {
        global $order, $db;

        if (IS_ADMIN_FLAG === false && $this->cfg('TESTMODE') === 'Production') {
            $isHttps = (strpos((string)HTTP_SERVER, 'https:') === 0)
                || (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
                || (defined('ENABLE_SSL') && ENABLE_SSL === 'true');
            if (!$isHttps) {
                $this->enabled = false;
            }
        }

        if ($this->enabled && (int)$this->cfg('ZONE', 0) > 0 && isset($order->billing['country']['id'])) {
            $check_flag = false;
            $check = $db->Execute(
                "SELECT zone_id
                   FROM " . TABLE_ZONES_TO_GEO_ZONES . "
                  WHERE geo_zone_id = " . (int)$this->cfg('ZONE') . "
                    AND zone_country_id = " . (int)$order->billing['country']['id'] . "
                  ORDER BY zone_id"
            );
            while (!$check->EOF) {
                if ((int)$check->fields['zone_id'] < 1 || (int)$check->fields['zone_id'] === (int)$order->billing['zone_id']) {
                    $check_flag = true;
                    break;
                }
                $check->MoveNext();
            }
            if ($check_flag === false) {
                $this->enabled = false;
            }
        }
    }

    // -----------------------------------------------------------------
    // Checkout: payment page
    // -----------------------------------------------------------------

    /**
     * Zen Cart's own form check. The card fields are validated by the checkout
     * script before it fetches the nonce, so the only thing left to assert
     * here is that the nonce arrived, which fails only when the script could
     * not run at all.
     */
    public function javascript_validation()
    {
        return '  if (payment_value == "' . $this->code . '") {' . "\n"
            . '    if (document.checkout_payment.' . $this->code . '_value.value == "") {' . "\n"
            . '      error_message = error_message + "' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_JS_NONCE_MISSING . '";' . "\n"
            . '      error = 1;' . "\n"
            . '    }' . "\n"
            . '  }' . "\n";
    }

    /**
     * The card fields. Card number and CVV carry no name attribute, so they
     * can never be posted; the named hidden fields carry the nonce and the
     * non-sensitive card summary instead.
     */
    public function selection()
    {
        global $order, $zcDate;

        $expires_month = [];
        for ($i = 1; $i < 13; $i++) {
            $expires_month[] = ['id' => sprintf('%02d', $i), 'text' => $zcDate->output('%B - (%m)', mktime(0, 0, 0, $i, 1, 2000))];
        }
        $expires_year = [];
        $today = getdate();
        for ($i = $today['year']; $i < $today['year'] + 15; $i++) {
            $expires_year[] = ['id' => $zcDate->output('%y', mktime(0, 0, 0, 1, 1, $i)), 'text' => $zcDate->output('%Y', mktime(0, 0, 0, 1, 1, $i))];
        }
        $onFocus = ' onfocus="methodSelect(\'pmt-' . $this->code . '\')"';
        $ownerDefault = trim(($order->billing['firstname'] ?? '') . ' ' . ($order->billing['lastname'] ?? ''));

        $fields = [
            [
                'title' => MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CREDIT_CARD_OWNER,
                'field' => zen_draw_input_field($this->code . '_owner', $ownerDefault, 'id="' . $this->code . '-cc-owner" autocomplete="cc-name" maxlength="64"' . $onFocus),
                'tag' => $this->code . '-cc-owner',
            ],
            [
                'title' => MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CREDIT_CARD_NUMBER,
                'field' => '<input type="text" id="' . $this->code . '-cc-number" inputmode="numeric" autocomplete="cc-number" maxlength="23"' . $onFocus . '>',
                'tag' => $this->code . '-cc-number',
            ],
            [
                'title' => MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CREDIT_CARD_EXPIRES,
                'field' => $this->unnamedSelect($this->code . '-cc-expires-month', $expires_month, $zcDate->output('%m'), 'autocomplete="cc-exp-month"' . $onFocus)
                    . '&nbsp;'
                    . $this->unnamedSelect($this->code . '-cc-expires-year', $expires_year, '', 'autocomplete="cc-exp-year"' . $onFocus),
                'tag' => $this->code . '-cc-expires-month',
            ],
        ];
        if ($this->cfg('USE_CVV') === 'True') {
            $fields[] = [
                'title' => MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CVV,
                'field' => '<input type="text" id="' . $this->code . '-cc-cvv" inputmode="numeric" autocomplete="cc-csc" size="4" maxlength="4"' . $onFocus . '> '
                    . '<a href="javascript:popupWindow(\'' . zen_href_link(FILENAME_POPUP_CVV_HELP) . '\')">' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_POPUP_CVV_LINK . '</a>',
                'tag' => $this->code . '-cc-cvv',
            ];
        }

        $extra = '<div id="' . $this->code . '-error" class="messageStackError" style="display:none" role="alert" aria-live="polite"></div>'
            . '<input type="hidden" name="' . $this->code . '_descriptor" value="">'
            . '<input type="hidden" name="' . $this->code . '_value" value="">'
            . '<input type="hidden" name="' . $this->code . '_brand" value="">'
            . '<input type="hidden" name="' . $this->code . '_last4" value="">'
            . '<input type="hidden" name="' . $this->code . '_expires" value="">';

        $this->notify('NOTIFY_AUTHNET_ACCEPT_SELECTION_FIELDS', $this->code, $fields, $extra);

        $extra .= $this->checkoutScript();
        $lastField = count($fields) - 1;
        $fields[$lastField]['field'] .= $extra;

        return [
            'id' => $this->code,
            'module' => MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CATALOG_TITLE,
            'fields' => $fields,
        ];
    }

    /**
     * A <select> with an id but no name, so it is never posted.
     */
    protected function unnamedSelect(string $id, array $options, string $selected, string $parameters = ''): string
    {
        $html = '<select id="' . $id . '"' . ($parameters !== '' ? ' ' . $parameters : '') . '>';
        foreach ($options as $option) {
            $html .= '<option value="' . zen_output_string_protected((string)$option['id']) . '"'
                . ((string)$option['id'] === $selected ? ' selected' : '') . '>'
                . zen_output_string_protected((string)$option['text']) . '</option>';
        }
        return $html . '</select>';
    }

    /**
     * The checkout script, with its configuration, as markup.
     */
    protected function checkoutScript(): string
    {
        global $order;

        $anaScriptConfig = [
            'code' => $this->code,
            'apiLoginID' => trim($this->cfg('LOGIN')),
            'clientKey' => trim($this->cfg('CLIENT_KEY')),
            'scriptUrl' => $this->isSandbox() ? self::SCRIPT_SANDBOX : self::SCRIPT_PRODUCTION,
            'requireCvv' => ($this->cfg('USE_CVV') === 'True'),
            'minOwner' => defined('CC_OWNER_MIN_LENGTH') ? (int)CC_OWNER_MIN_LENGTH : 3,
            'zip' => (string)($order->billing['postcode'] ?? ''),
            'tokenTtlMs' => self::TOKEN_TTL_MS,
            'submitSelector' => self::SUBMIT_SELECTOR,
            'text' => [
                'owner' => MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_JS_CC_OWNER,
                'number' => MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_JS_CC_NUMBER,
                'expires' => MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_JS_CC_EXPIRES,
                'cvv' => MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_JS_CC_CVV,
                'working' => MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_JS_WORKING,
                'failed' => MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_JS_FAILED,
                'loadFailed' => MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_JS_LOAD_FAILED,
            ],
        ];
        $this->notify('NOTIFY_AUTHNET_ACCEPT_SCRIPT_CONFIG', $this->code, $anaScriptConfig);

        ob_start();
        require __DIR__ . '/authorizenet_accept/checkout_script.php';
        return (string)ob_get_clean();
    }

    // -----------------------------------------------------------------
    // Checkout: confirmation
    // -----------------------------------------------------------------

    /**
     * The posted payment summary, cleaned. Nothing here is a card number.
     */
    public function readSubmittedPayment(array $source): array
    {
        return [
            'descriptor' => trim((string)($source[$this->code . '_descriptor'] ?? '')),
            'value' => trim((string)($source[$this->code . '_value'] ?? '')),
            'brand' => substr(preg_replace('/[^A-Za-z ]/', '', strip_tags((string)($source[$this->code . '_brand'] ?? ''))), 0, 32),
            'last4' => substr(preg_replace('/\D/', '', (string)($source[$this->code . '_last4'] ?? '')), -4),
            'expires' => substr(preg_replace('/\D/', '', (string)($source[$this->code . '_expires'] ?? '')), 0, 4),
            'owner' => substr(trim(strip_tags((string)($source[$this->code . '_owner'] ?? ''))), 0, 64),
        ];
    }

    /**
     * '' when the submission is usable, otherwise the message for the customer.
     */
    public function validateSubmittedPayment(array $data): string
    {
        if ($data['descriptor'] === '' || $data['value'] === '') {
            return MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_NONCE_MISSING;
        }
        if (!in_array($data['descriptor'], $this->allowedDescriptors(), true)) {
            return MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_DESCRIPTOR_NOT_ALLOWED;
        }
        if (!preg_match('~^[A-Za-z0-9+/=._-]{16,16384}$~', $data['value'])) {
            return MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_NONCE_MISSING;
        }
        if ($data['descriptor'] === AuthorizeNetAcceptApi::DESCRIPTOR_ACCEPT) {
            $minOwner = defined('CC_OWNER_MIN_LENGTH') ? (int)CC_OWNER_MIN_LENGTH : 3;
            if (strlen(str_replace(' ', '', $data['owner'])) < $minOwner) {
                return MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_OWNER_TOO_SHORT;
            }
            if (strlen($data['last4']) !== 4 || strlen($data['expires']) !== 4) {
                return MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_NONCE_MISSING;
            }
        }
        return '';
    }

    public function pre_confirmation_check()
    {
        global $messageStack;

        $data = $this->readSubmittedPayment($_POST);
        $problem = $this->validateSubmittedPayment($data);
        $this->notify('NOTIFY_AUTHNET_ACCEPT_PRE_CONFIRMATION', $this->code, $data, $problem);
        if ($problem !== '') {
            $messageStack->add_session('checkout_payment', $problem . '<!-- [' . $this->code . '] -->', 'error');
            zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
        }
        $this->paymentData = $data;
    }

    public function confirmation()
    {
        $data = $this->paymentData;
        $fields = [];
        if (($data['descriptor'] ?? '') === AuthorizeNetAcceptApi::DESCRIPTOR_ACCEPT) {
            $fields = [
                ['title' => MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CREDIT_CARD_TYPE, 'field' => zen_output_string_protected($data['brand'])],
                ['title' => MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CREDIT_CARD_OWNER, 'field' => zen_output_string_protected($data['owner'])],
                ['title' => MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CREDIT_CARD_NUMBER, 'field' => 'XXXX-XXXX-XXXX-' . zen_output_string_protected($data['last4'])],
                ['title' => MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CREDIT_CARD_EXPIRES, 'field' => zen_output_string_protected(substr($data['expires'], 0, 2) . '/' . substr($data['expires'], 2, 2))],
            ];
        }
        $this->notify('NOTIFY_AUTHNET_ACCEPT_CONFIRMATION_FIELDS', $this->code, $fields, $data);
        return ['fields' => $fields];
    }

    /**
     * Hidden fields carried through the confirmation page to checkout_process.
     */
    public function process_button()
    {
        $data = $this->paymentData;
        $html = '';
        foreach (['descriptor', 'value', 'brand', 'last4', 'expires', 'owner'] as $key) {
            $html .= zen_draw_hidden_field($this->code . '_' . $key, (string)($data[$key] ?? ''));
        }
        $html .= zen_draw_hidden_field(zen_session_name(), zen_session_id());
        $this->notify('NOTIFY_AUTHNET_ACCEPT_PROCESS_BUTTON', $this->code, $html, $data);
        return $html;
    }

    /**
     * The same hand-off for the AJAX confirmation flow (PA-DSS AJAX checkout
     * and One Page Checkout). The values are written into the confirmation
     * form as literals, not copied by script: the copy core does for
     * 'ccFields' selects by name, and with the payment form still on the page
     * the selector matches both the old field and the new one and reads the
     * empty new one first, wiping the nonce (found on the pilot store). The
     * AJAX request that builds the confirmation already carries the values in
     * its POST, and pre_confirmation_check() has validated them by the time
     * this runs.
     */
    public function process_button_ajax()
    {
        $data = ($this->paymentData !== []) ? $this->paymentData : $this->readSubmittedPayment($_POST);
        $extraFields = [];
        foreach (['descriptor', 'value', 'brand', 'last4', 'expires', 'owner'] as $key) {
            // The template prints these into value="..." unescaped.
            $extraFields[$this->code . '_' . $key] = zen_output_string_protected((string)($data[$key] ?? ''));
        }
        $extraFields[zen_session_name()] = zen_session_id();
        return ['ccFields' => [], 'extraFields' => $extraFields];
    }

    // -----------------------------------------------------------------
    // Checkout: the charge
    // -----------------------------------------------------------------

    public function before_process()
    {
        global $db, $order, $messageStack, $currencies;

        $data = $this->readSubmittedPayment($_POST);
        $problem = $this->validateSubmittedPayment($data);
        if ($problem !== '') {
            $this->failCheckout($problem);
        }
        $this->paymentData = $data;

        // Convert to the gateway's currency when the order is in another one,
        // the way the core module does; tax, shipping and line items are then
        // left out because they would not add up in the converted amount.
        $amount = (float)$order->info['total'];
        $currency = (string)$order->info['currency'];
        $this->amountConverted = false;
        if ($currency !== $this->gateway_currency) {
            $amount = round((float)$order->info['total'] * (float)$currencies->get_value($this->gateway_currency), 2);
            $currency = $this->gateway_currency;
            $this->amountConverted = true;
            $this->convertedAmount = $amount;
        }

        $invoice = $this->nextInvoiceNumber();
        $request = [
            'transactionType' => $this->authorizeOnly() ? 'authOnlyTransaction' : 'authCaptureTransaction',
            'amount' => AuthorizeNetAcceptApi::amount($amount),
            'currencyCode' => $currency,
            'payment' => [
                'opaqueData' => [
                    'dataDescriptor' => $data['descriptor'],
                    'dataValue' => $data['value'],
                ],
            ],
            'order' => [
                'invoiceNumber' => AuthorizeNetAcceptApi::truncate($invoice, AuthorizeNetAcceptApi::LIMITS['invoiceNumber']),
                'description' => AuthorizeNetAcceptApi::truncate($this->orderDescription($order), AuthorizeNetAcceptApi::LIMITS['description']),
            ],
        ];
        if (!$this->amountConverted) {
            if ($this->cfg('SEND_LINE_ITEMS', 'True') === 'True') {
                $items = $this->lineItems($order);
                if ($items !== []) {
                    $request['lineItems'] = ['lineItem' => $items];
                }
            }
            $request['tax'] = ['amount' => AuthorizeNetAcceptApi::amount($order->info['tax'] ?? 0)];
            $request['shipping'] = ['amount' => AuthorizeNetAcceptApi::amount($order->info['shipping_cost'] ?? 0)];
        }
        $customer = [
            'type' => 'individual',
            'id' => AuthorizeNetAcceptApi::truncate((string)($_SESSION['customer_id'] ?? ''), AuthorizeNetAcceptApi::LIMITS['customerId']),
            'email' => AuthorizeNetAcceptApi::truncate((string)($order->customer['email_address'] ?? ''), AuthorizeNetAcceptApi::LIMITS['email']),
        ];
        $request['customer'] = array_filter($customer, 'strlen');
        $request['billTo'] = AuthorizeNetAcceptApi::address([
            'firstName' => $order->billing['firstname'] ?? '',
            'lastName' => $order->billing['lastname'] ?? '',
            'company' => $order->billing['company'] ?? '',
            'address' => $order->billing['street_address'] ?? '',
            'city' => $order->billing['city'] ?? '',
            'state' => $order->billing['state'] ?? '',
            'zip' => $order->billing['postcode'] ?? '',
            'country' => $order->billing['country']['title'] ?? '',
            'phoneNumber' => $order->customer['telephone'] ?? '',
        ]);
        if (!empty($order->delivery['street_address'])) {
            $request['shipTo'] = AuthorizeNetAcceptApi::address([
                'firstName' => $order->delivery['firstname'] ?? '',
                'lastName' => $order->delivery['lastname'] ?? '',
                'company' => $order->delivery['company'] ?? '',
                'address' => $order->delivery['street_address'] ?? '',
                'city' => $order->delivery['city'] ?? '',
                'state' => $order->delivery['state'] ?? '',
                'zip' => $order->delivery['postcode'] ?? '',
                'country' => $order->delivery['country']['title'] ?? '',
            ]);
        }
        $request['customerIP'] = zen_get_ip_address();
        $settings = [
            ['settingName' => 'duplicateWindow', 'settingValue' => (string)max(0, (int)$this->cfg('DUPLICATE_WINDOW', 120))],
            ['settingName' => 'emailCustomer', 'settingValue' => ($this->cfg('EMAIL_CUSTOMER') === 'True') ? 'true' : 'false'],
        ];
        if ($this->cfg('TESTMODE') === 'Test') {
            $settings[] = ['settingName' => 'testRequest', 'settingValue' => 'true'];
        }
        $request['transactionSettings'] = ['setting' => $settings];

        $this->notify('NOTIFY_AUTHNET_ACCEPT_BEFORE_TRANSACTION', $this->code, $request, $data);
        $request = AuthorizeNetAcceptApi::orderKeys($request, AuthorizeNetAcceptApi::TRANSACTION_REQUEST_ORDER);

        $api = $this->api();
        $result = $api->send('createTransactionRequest', [
            'refId' => AuthorizeNetAcceptApi::truncate($invoice, AuthorizeNetAcceptApi::LIMITS['refId']),
            'transactionRequest' => $request,
        ]);
        $this->notify('NOTIFY_AUTHNET_ACCEPT_AFTER_TRANSACTION', $this->code, $result, $request);

        $txn = $result['transaction'];
        $this->auth_code = $txn['authCode'];
        $this->transaction_id = $txn['transId'];
        $this->avs_response = $txn['avsResultCode'];
        $this->cvv_response = $txn['cvvResultCode'];
        $this->heldForReview = ($txn['responseCode'] === AuthorizeNetAcceptApi::RESPONSE_HELD);

        $this->transactionRowId = AuthorizeNetAcceptLog::record([
            'customers_id' => (int)($_SESSION['customer_id'] ?? 0),
            'session_id' => zen_session_id(),
            'transaction_type' => $request['transactionType'],
            'trans_id' => $txn['transId'],
            'network_trans_id' => $txn['networkTransId'],
            'response_code' => $txn['responseCode'],
            'reason_code' => $txn['reasonCode'] !== '' ? $txn['reasonCode'] : $result['messageCode'],
            'response_text' => $txn['reasonText'] !== '' ? $txn['reasonText'] : $result['messageText'],
            'auth_code' => $txn['authCode'],
            'avs_code' => $txn['avsResultCode'],
            'cvv_code' => $txn['cvvResultCode'],
            'account_type' => $txn['accountType'],
            'account_number' => $txn['accountNumber'],
            'amount' => $amount,
            'currency' => $currency,
            'payment_source' => $data['descriptor'],
            'request_json' => json_encode($api->maskedRequest(), JSON_UNESCAPED_SLASHES),
            'response_json' => json_encode($result['response'], JSON_UNESCAPED_SLASHES),
        ]);
        $this->debugLog('transaction', $api, $result);

        if ($result['messageCode'] === AuthorizeNetAcceptApi::CODE_COMM || $result['messageCode'] === AuthorizeNetAcceptApi::CODE_JSON) {
            $messageStack->add_session('checkout_payment', MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_COMM_ERROR . ' (' . zen_output_string_protected($result['messageCode'] . ' ' . $api->commErrNo) . ')', 'caution');
            zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
        }
        if ($txn['responseCode'] === 0) {
            // No transactionResponse at all: bad credentials, a schema error, or an unknown nonce.
            $this->failCheckout(sprintf(MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_GATEWAY_ERROR, zen_output_string_protected($result['messageText'] !== '' ? $result['messageText'] : $result['messageCode'])));
        }
        if ($txn['responseCode'] === AuthorizeNetAcceptApi::RESPONSE_DECLINED || $txn['responseCode'] === AuthorizeNetAcceptApi::RESPONSE_ERROR) {
            $this->failCheckout($this->customerFacingReason($txn) . ' - ' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_DECLINED_MESSAGE);
        }
        if ($this->heldForReview) {
            $review = (int)$this->cfg('REVIEW_ORDER_STATUS_ID', 0);
            $this->order_status = ($review > 0) ? $review : (int)DEFAULT_ORDERS_STATUS_ID;
        }

        $order->info['cc_type'] = ($txn['accountType'] !== '') ? $txn['accountType'] : $data['brand'];
        $order->info['cc_number'] = ($txn['accountNumber'] !== '') ? $txn['accountNumber'] : 'XXXX' . $data['last4'];
        $order->info['cc_owner'] = $data['owner'];
        $order->info['cc_expires'] = $data['expires'];
        $order->info['cc_cvv'] = '***';
    }

    /**
     * Order-status history, and the transaction row gets its order id.
     */
    public function after_process()
    {
        global $insert_id, $order;

        $comments = $this->paymentLabel . ' AUTH: ' . $this->auth_code . ' TransID: ' . $this->transaction_id;
        if ($this->amountConverted) {
            $comments .= ' (' . number_format($this->convertedAmount, 2) . ' ' . $this->gateway_currency . ')';
        }
        if ($this->heldForReview) {
            $comments .= ' ' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_HELD_NOTE;
        }
        zen_update_orders_history((int)$insert_id, $comments, null, $this->order_status, -1);

        if ($this->transactionRowId > 0) {
            AuthorizeNetAcceptLog::attachOrder((int)$insert_id, $this->transactionRowId);
        }
        return false;
    }

    /**
     * Back to the payment page with a message. Never returns.
     */
    protected function failCheckout(string $message): void
    {
        global $messageStack;
        $messageStack->add_session('checkout_payment', $message, 'error');
        zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
    }

    /**
     * What the customer is told about a decline or error. The gateway's own
     * text, except for the security-code and expiry cases where its wording
     * is aimed at developers.
     */
    protected function customerFacingReason(array $txn): string
    {
        $code = $txn['reasonCode'];
        if ($txn['responseCode'] === AuthorizeNetAcceptApi::RESPONSE_DECLINED && in_array($code, ['44', '45', '65'], true)) {
            return MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CVV_PROBLEM;
        }
        if ($txn['responseCode'] === AuthorizeNetAcceptApi::RESPONSE_ERROR && $code === '78') {
            return MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CVV_PROBLEM;
        }
        if ($txn['responseCode'] === AuthorizeNetAcceptApi::RESPONSE_ERROR && in_array($code, ['7', '8'], true)) {
            return MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_EXPIRY_PROBLEM;
        }
        return zen_output_string_protected($txn['reasonText'] !== '' ? $txn['reasonText'] : 'Declined');
    }

    /**
     * The next order number with a random suffix, because the gateway's
     * duplicate check keys on the invoice number and a retried checkout must
     * not look like a duplicate. Prefixed by mode so sandbox and test
     * transactions are obvious in the Merchant Interface.
     */
    protected function nextInvoiceNumber(): string
    {
        global $db;
        // The table's own counter is right even when old orders have been
        // deleted (an empty table with the counter at 27 gave "1" on the pilot
        // store); highest id plus one is the fallback.
        $next = 0;
        $status = $db->Execute("SHOW TABLE STATUS LIKE '" . zen_db_input(TABLE_ORDERS) . "'");
        if (!$status->EOF && !empty($status->fields['Auto_increment'])) {
            $next = (int)$status->fields['Auto_increment'];
        }
        if ($next <= 0) {
            $last = $db->Execute("SELECT orders_id FROM " . TABLE_ORDERS . " ORDER BY orders_id DESC LIMIT 1");
            $next = (int)($last->fields['orders_id'] ?? 0) + 1;
        }
        $prefix = '';
        if ($this->cfg('TESTMODE') === 'Test') {
            $prefix = 'TEST-';
        } elseif ($this->isSandbox()) {
            $prefix = 'SANDBOX-';
        }
        return $prefix . $next . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    }

    protected function orderDescription($order): string
    {
        $parts = [];
        foreach ((array)$order->products as $product) {
            $parts[] = $product['name'] . ' (qty: ' . $product['qty'] . ')';
        }
        $description = implode(' + ', $parts);
        if ($this->amountConverted) {
            $description .= ' (Converted from: ' . number_format((float)$order->info['total'] * (float)$order->info['currency_value'], 2) . ' ' . $order->info['currency'] . ')';
        }
        return $description;
    }

    /**
     * Up to 30 line items, the gateway's maximum, in schema order.
     */
    protected function lineItems($order): array
    {
        $items = [];
        foreach ((array)$order->products as $product) {
            if (count($items) >= 30) {
                break;
            }
            $itemId = trim((string)($product['model'] ?? ''));
            if ($itemId === '') {
                $itemId = (string)($product['id'] ?? '');
            }
            $description = [];
            foreach ((array)($product['attributes'] ?? []) as $attribute) {
                $description[] = trim((string)($attribute['option'] ?? '')) . ': ' . trim((string)($attribute['value'] ?? ''));
            }
            $item = [
                'itemId' => AuthorizeNetAcceptApi::truncate($itemId, AuthorizeNetAcceptApi::LIMITS['lineItemId']),
                'name' => AuthorizeNetAcceptApi::truncate($product['name'] ?? '', AuthorizeNetAcceptApi::LIMITS['lineItemName']),
                'quantity' => (string)(float)($product['qty'] ?? 1),
                'unitPrice' => AuthorizeNetAcceptApi::amount($product['final_price'] ?? 0),
                'taxable' => ((float)($product['tax'] ?? 0) > 0) ? 'true' : 'false',
            ];
            if ($description !== []) {
                $item['description'] = AuthorizeNetAcceptApi::truncate(implode('; ', $description), AuthorizeNetAcceptApi::LIMITS['lineItemDescription']);
            }
            if ($item['itemId'] === '' || $item['name'] === '') {
                continue;
            }
            $items[] = AuthorizeNetAcceptApi::orderKeys($item, AuthorizeNetAcceptApi::LINE_ITEM_ORDER);
        }
        return $items;
    }

    /**
     * Log files when Debug Mode asks for them or the module is in Sandbox
     * mode; an email to the store owner on a failed call when Debug Mode
     * includes email. Nothing secret goes into either.
     */
    protected function debugLog(string $kind, AuthorizeNetAcceptApi $api, array $result): void
    {
        $debugging = (string)$this->cfg('DEBUGGING', 'Off');
        $wantFile = (strpos($debugging, 'Log') !== false) || $this->isSandbox();
        $approved = ($result['transaction']['responseCode'] === AuthorizeNetAcceptApi::RESPONSE_APPROVED);
        $wantEmail = (strpos($debugging, 'Email') !== false) && !$approved;
        if (!$wantFile && !$wantEmail) {
            return;
        }
        $body = date('M-d-Y h:i:s') . "\n=================================\n\n"
            . 'Endpoint: ' . $api->endpoint() . "\n"
            . 'Result: ' . $result['resultCode'] . ' ' . $result['messageCode'] . ' ' . $result['messageText'] . "\n"
            . 'Response code: ' . $result['transaction']['responseCode'] . ' ' . $result['transaction']['reasonCode'] . ' ' . $result['transaction']['reasonText'] . "\n"
            . ($api->commError !== '' ? 'Comm error: ' . $api->commErrNo . ' ' . $api->commError . "\n" : '')
            . "\nSent (masked):\n" . json_encode($api->maskedRequest(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
            . "\nReceived:\n" . json_encode($result['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
            . "\nCURL info:\n" . print_r($api->commInfo, true) . "\n";
        if ($wantFile) {
            AuthorizeNetAcceptLog::writeFile($kind, $result['transaction']['transId'], $body);
        }
        if ($wantEmail && function_exists('zen_mail')) {
            zen_mail(STORE_NAME, STORE_OWNER_EMAIL_ADDRESS, 'Authorize.net Accept.js alert ' . date('M-d-Y h:i:s') . ' ' . $result['transaction']['transId'], $body, STORE_OWNER, STORE_OWNER_EMAIL_ADDRESS, ['EMAIL_MESSAGE_HTML' => nl2br(zen_output_string_protected($body))], 'debug');
        }
    }

    // -----------------------------------------------------------------
    // Admin: order page
    // -----------------------------------------------------------------

    public function admin_notification($order_id)
    {
        if (!defined('TABLE_AUTHORIZENET_ACCEPT')) {
            return '';
        }
        $transactions = AuthorizeNetAcceptLog::transactionsForOrder((int)$order_id);
        $last = null;
        $captureOpen = false;
        foreach ($transactions as $t) {
            $code = (int)$t['response_code'];
            if ($code !== AuthorizeNetAcceptApi::RESPONSE_APPROVED && $code !== AuthorizeNetAcceptApi::RESPONSE_HELD) {
                continue;
            }
            if ($t['transaction_type'] === 'authCaptureTransaction' || $t['transaction_type'] === 'authOnlyTransaction') {
                $last = $t;
                $captureOpen = ($t['transaction_type'] === 'authOnlyTransaction');
            } elseif ($t['transaction_type'] === 'priorAuthCaptureTransaction' || $t['transaction_type'] === 'voidTransaction') {
                $captureOpen = false;
            }
        }
        return require __DIR__ . '/authorizenet_accept/admin_notification.php';
    }

    /**
     * Refund a settled transaction. Field names match the core module's form.
     */
    public function _doRefund($oID, $amount = 0)
    {
        global $messageStack;

        $newStatus = (int)$this->cfg('REFUNDED_ORDER_STATUS_ID', 0);
        if ($newStatus === 0) {
            $newStatus = (int)DEFAULT_ORDERS_STATUS_ID;
        }
        $note = $this->postedNote('refnote');
        $refundAmount = (float)str_replace(',', '', (string)($_POST['refamt'] ?? '0'));
        $last4 = substr(preg_replace('/\D/', '', (string)($_POST['cc_number'] ?? '')), -4);
        $transId = preg_replace('/[^0-9A-Za-z]/', '', (string)($_POST['trans_id'] ?? ''));

        $proceed = true;
        if (($_POST['refconfirm'] ?? '') !== 'on') {
            $messageStack->add_session(MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_REFUND_CONFIRM_ERROR, 'error');
            $proceed = false;
        }
        if ($refundAmount <= 0) {
            $messageStack->add_session(MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_INVALID_REFUND_AMOUNT, 'error');
            $proceed = false;
        }
        if (strlen($last4) !== 4) {
            $messageStack->add_session(MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CC_NUM_REQUIRED_ERROR, 'error');
            $proceed = false;
        }
        if ($transId === '') {
            $messageStack->add_session(MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_TRANS_ID_REQUIRED_ERROR, 'error');
            $proceed = false;
        }
        if (!$proceed) {
            return false;
        }

        $request = AuthorizeNetAcceptApi::orderKeys([
            'transactionType' => 'refundTransaction',
            'amount' => AuthorizeNetAcceptApi::amount($refundAmount),
            'payment' => ['creditCard' => ['cardNumber' => $last4, 'expirationDate' => 'XXXX']],
            'refTransId' => $transId,
        ], AuthorizeNetAcceptApi::TRANSACTION_REQUEST_ORDER);
        $result = $this->adminTransaction((int)$oID, $request, $transId, $refundAmount, 'refund');
        $txn = $result['transaction'];
        if ($txn['responseCode'] !== AuthorizeNetAcceptApi::RESPONSE_APPROVED) {
            $messageStack->add_session($this->gatewayFailureText($result), 'error');
            return false;
        }
        $comments = 'REFUND INITIATED. Trans ID: ' . $txn['transId'] . ' ' . $txn['authCode'] . "\n"
            . ' Gross Refund Amt: ' . AuthorizeNetAcceptApi::amount($refundAmount) . "\n" . $note;
        zen_update_orders_history((int)$oID, $comments, null, $newStatus, 1);
        $messageStack->add_session(sprintf(MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_REFUND_INITIATED, AuthorizeNetAcceptApi::amount($refundAmount), $txn['transId']), 'success');
        return true;
    }

    /**
     * Capture an authorization. Zen Cart's order page calls this as
     * _doCapt($oID, 'Complete', $total, $currency); the amount actually used
     * is the one typed into the form, or the full authorization when blank.
     */
    public function _doCapt($oID, $status = 'Complete', $amount = 0, $currency = 'USD')
    {
        global $messageStack;

        $newStatus = (int)$this->cfg('ORDER_STATUS_ID', 0);
        if ($newStatus === 0) {
            $newStatus = (int)DEFAULT_ORDERS_STATUS_ID;
        }
        $note = $this->postedNote('captnote');
        $captureAmount = (float)str_replace(',', '', (string)($_POST['captamt'] ?? '0'));
        $transId = preg_replace('/[^0-9A-Za-z]/', '', (string)($_POST['captauthid'] ?? ''));

        $proceed = true;
        if (($_POST['captconfirm'] ?? '') !== 'on') {
            $messageStack->add_session(MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CAPTURE_CONFIRM_ERROR, 'error');
            $proceed = false;
        }
        if ($transId === '') {
            $messageStack->add_session(MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_TRANS_ID_REQUIRED_ERROR, 'error');
            $proceed = false;
        }
        if (!$proceed) {
            return false;
        }

        $request = ['transactionType' => 'priorAuthCaptureTransaction'];
        if ($captureAmount > 0) {
            $request['amount'] = AuthorizeNetAcceptApi::amount($captureAmount);
        }
        $request['refTransId'] = $transId;
        $request = AuthorizeNetAcceptApi::orderKeys($request, AuthorizeNetAcceptApi::TRANSACTION_REQUEST_ORDER);
        // A full capture sends no amount and the gateway echoes none back; record the authorization's.
        $recordAmount = ($captureAmount > 0) ? $captureAmount : AuthorizeNetAcceptLog::amountForTransaction($transId);
        $result = $this->adminTransaction((int)$oID, $request, $transId, $recordAmount, 'capture');
        $txn = $result['transaction'];
        if ($txn['responseCode'] !== AuthorizeNetAcceptApi::RESPONSE_APPROVED) {
            $messageStack->add_session($this->gatewayFailureText($result), 'error');
            return false;
        }
        $amountText = ($captureAmount > 0)
            ? AuthorizeNetAcceptApi::amount($captureAmount)
            : 'Full Amount' . ($recordAmount > 0 ? ' (' . AuthorizeNetAcceptApi::amount($recordAmount) . ')' : '');
        $comments = 'FUNDS COLLECTED. Auth Code: ' . $txn['authCode'] . "\n"
            . 'Trans ID: ' . $txn['transId'] . "\n"
            . ' Amount: ' . $amountText . "\n"
            . 'Time: ' . date('Y-m-d H:i:s') . "\n" . $note;
        zen_update_orders_history((int)$oID, $comments, null, $newStatus, 1);
        $messageStack->add_session(sprintf(MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CAPT_INITIATED, $amountText, $txn['transId'], $txn['authCode']), 'success');
        return true;
    }

    /**
     * Void an unsettled transaction or an uncaptured authorization.
     */
    public function _doVoid($oID, $note = '')
    {
        global $messageStack;

        $newStatus = (int)$this->cfg('REFUNDED_ORDER_STATUS_ID', 0);
        if ($newStatus === 0) {
            $newStatus = (int)DEFAULT_ORDERS_STATUS_ID;
        }
        $voidNote = trim($this->postedNote('voidnote') . ' ' . strip_tags((string)$note));
        $transId = preg_replace('/[^0-9A-Za-z]/', '', (string)($_POST['voidauthid'] ?? ''));

        $proceed = true;
        if (($_POST['voidconfirm'] ?? '') !== 'on') {
            $messageStack->add_session(MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_VOID_CONFIRM_ERROR, 'error');
            $proceed = false;
        }
        if ($transId === '') {
            $messageStack->add_session(MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_TRANS_ID_REQUIRED_ERROR, 'error');
            $proceed = false;
        }
        if (!$proceed) {
            return false;
        }

        $request = AuthorizeNetAcceptApi::orderKeys([
            'transactionType' => 'voidTransaction',
            'refTransId' => $transId,
        ], AuthorizeNetAcceptApi::TRANSACTION_REQUEST_ORDER);
        // A void carries no amount; record the amount of what was voided.
        $result = $this->adminTransaction((int)$oID, $request, $transId, AuthorizeNetAcceptLog::amountForTransaction($transId), 'void');
        $txn = $result['transaction'];
        if ($txn['responseCode'] !== AuthorizeNetAcceptApi::RESPONSE_APPROVED) {
            $messageStack->add_session($this->gatewayFailureText($result), 'error');
            return false;
        }
        $comments = 'VOIDED. Trans ID: ' . $txn['transId'] . ' ' . $txn['authCode'] . "\n" . $voidNote;
        zen_update_orders_history((int)$oID, $comments, null, $newStatus, 1);
        $messageStack->add_session(sprintf(MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_VOID_INITIATED, $txn['transId']), 'success');
        return true;
    }

    /**
     * Send an order-page transaction, record it, log it.
     */
    protected function adminTransaction(int $oID, array $request, string $refTransId, float $amount, string $kind): array
    {
        $api = $this->api();
        $result = $api->send('createTransactionRequest', [
            'refId' => AuthorizeNetAcceptApi::truncate('o' . $oID . '-' . substr($kind, 0, 6), AuthorizeNetAcceptApi::LIMITS['refId']),
            'transactionRequest' => $request,
        ]);
        $this->notify('NOTIFY_AUTHNET_ACCEPT_ADMIN_TRANSACTION', $this->code, $result, $request);
        $txn = $result['transaction'];
        AuthorizeNetAcceptLog::record([
            'orders_id' => $oID,
            'session_id' => '',
            'transaction_type' => $request['transactionType'],
            'trans_id' => $txn['transId'],
            'ref_trans_id' => $refTransId,
            'network_trans_id' => $txn['networkTransId'],
            'response_code' => $txn['responseCode'],
            'reason_code' => $txn['reasonCode'] !== '' ? $txn['reasonCode'] : $result['messageCode'],
            'response_text' => $txn['reasonText'] !== '' ? $txn['reasonText'] : $result['messageText'],
            'auth_code' => $txn['authCode'],
            'avs_code' => $txn['avsResultCode'],
            'cvv_code' => $txn['cvvResultCode'],
            'account_type' => $txn['accountType'],
            'account_number' => $txn['accountNumber'],
            'amount' => $amount,
            'currency' => $this->gateway_currency,
            'payment_source' => 'admin',
            'request_json' => json_encode($api->maskedRequest(), JSON_UNESCAPED_SLASHES),
            'response_json' => json_encode($result['response'], JSON_UNESCAPED_SLASHES),
        ]);
        $this->debugLog($kind, $api, $result);
        return $result;
    }

    protected function gatewayFailureText(array $result): string
    {
        $txn = $result['transaction'];
        $text = ($txn['reasonText'] !== '') ? $txn['reasonText'] : $result['messageText'];
        $code = ($txn['reasonCode'] !== '') ? $txn['reasonCode'] : $result['messageCode'];
        if ($text === '') {
            $text = 'The gateway did not accept the request.';
        }
        return zen_output_string_protected($text . ($code !== '' ? ' (' . $code . ')' : ''));
    }

    protected function postedNote(string $field): string
    {
        return trim(strip_tags((string)($_POST[$field] ?? '')));
    }

    // -----------------------------------------------------------------
    // Admin: Modules > Payment
    // -----------------------------------------------------------------

    public function get_error()
    {
        return [
            'title' => MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_ERROR,
            'error' => stripslashes(urldecode((string)($_GET['error'] ?? ''))),
        ];
    }

    public function check()
    {
        global $db;
        if ($this->_check === null) {
            $result = $db->Execute("SELECT configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_STATUS'");
            $this->_check = (int)$result->RecordCount();
        }
        return $this->_check;
    }

    public function install()
    {
        global $db, $messageStack;

        if (defined('MODULE_PAYMENT_AUTHORIZENET_ACCEPT_STATUS')) {
            $messageStack->add_session(sprintf(TEXT_ERROR_MODULE_ALREADY_INSTALLED, $this->title), 'error');
            zen_redirect(zen_href_link(FILENAME_MODULES, 'set=payment&module=' . $this->code, 'NONSSL'));
            return 'failed';
        }

        $this->installKey('Enable Authorize.net (Accept.js) Module', 'STATUS', 'True', 'Do you want to accept Authorize.net card payments through Accept.js?', 0, "zen_cfg_select_option(array('True', 'False'), ");
        $this->installKey('API Login ID', 'LOGIN', '', 'The API Login ID from the Merchant Interface (Account &gt; Settings &gt; API Credentials &amp; Keys). Use the sandbox account\'s when Transaction Mode is Sandbox.', 1);
        $this->installKey('Transaction Key', 'TXNKEY', '', 'The Transaction Key from the same page. Generating a new one there invalidates the old one within 24 hours.', 2, null, 'zen_cfg_password_display');
        $this->installKey('Public Client Key', 'CLIENT_KEY', '', 'The Public Client Key (Account &gt; Settings &gt; Manage Public Client Key). It is sent to the browser and is safe to expose; it cannot be used to run transactions.', 3);
        $this->installKey('Transaction Mode', 'TESTMODE', 'Sandbox', 'Where transactions go.<br><strong>Sandbox</strong> = the Authorize.net sandbox, with sandbox credentials; nothing is charged.<br><strong>Test</strong> = your live account with every request flagged as a test; nothing is charged.<br><strong>Production</strong> = live processing.', 4, "zen_cfg_select_option(array('Sandbox', 'Test', 'Production'), ");
        $this->installKey('Authorization Type', 'AUTHORIZATION_TYPE', 'Authorize+Capture', 'Authorize+Capture charges the card at checkout. Authorize only reserves the funds; capture them from the order page.', 5, "zen_cfg_select_option(array('Authorize+Capture', 'Authorize'), ");
        $this->installKey('Request CVV Number', 'USE_CVV', 'True', 'Ask the customer for the card\'s security code? Strongly recommended.', 6, "zen_cfg_select_option(array('True', 'False'), ");
        $this->installKey('Currency Supported', 'CURRENCY', 'USD', 'Which currency is your Authorize.net account configured to accept? Purchases in any other currency are converted to it, using your store\'s exchange rates, before submission.', 7, "zen_cfg_select_option(array('USD', 'CAD', 'GBP', 'EUR', 'AUD', 'NZD'), ");
        $this->installKey('Sort order of display', 'SORT_ORDER', '0', 'Sort order of display of payment modules to the customer. Lowest is displayed first.', 8);
        $this->installKey('Payment Zone', 'ZONE', '0', 'If a zone is selected, only enable this payment method for that zone.', 9, 'zen_cfg_pull_down_zone_classes(', 'zen_get_zone_class_title');
        $this->installKey('Set Completed Order Status', 'ORDER_STATUS_ID', '2', 'The status given to orders paid (authorized and captured) with this module.', 10, 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name');
        $this->installKey('Set Authorized (Uncaptured) Order Status', 'AUTH_ORDER_STATUS_ID', '1', 'The status given to orders when Authorization Type is Authorize only, until the funds are captured.', 11, 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name');
        $this->installKey('Set Refunded Order Status', 'REFUNDED_ORDER_STATUS_ID', '1', 'The status given to orders after a refund or void from the order page.', 12, 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name');
        $this->installKey('Set Held-For-Review Order Status', 'REVIEW_ORDER_STATUS_ID', '1', 'The status given to orders the gateway holds for review (its fraud filters). Approve or decline them in the Merchant Interface.', 13, 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name');
        $this->installKey('Gateway Receipt Email', 'EMAIL_CUSTOMER', 'False', 'Should Authorize.net email its own receipt to the customer, on top of the store\'s order email?', 14, "zen_cfg_select_option(array('True', 'False'), ");
        $this->installKey('Duplicate Window (seconds)', 'DUPLICATE_WINDOW', '120', 'The gateway rejects a second transaction that matches an earlier one within this many seconds. 0 turns the check off.', 15);
        $this->installKey('Send Line Items', 'SEND_LINE_ITEMS', 'True', 'Send the ordered products (up to 30) with the transaction, so they appear in the Merchant Interface. Not sent when the order is converted to another currency.', 16, "zen_cfg_select_option(array('True', 'False'), ");
        $this->installKey('Debug Mode', 'DEBUGGING', 'Off', 'Log File writes each gateway call (with the key and nonce masked) to the logs folder. Log and Email also emails the store owner about failed calls. Sandbox mode always writes the log.', 17, "zen_cfg_select_option(array('Off', 'Log File', 'Log and Email'), ");

        $this->restoreStashedSettings();
    }

    /**
     * Modules > Payment > Remove keeps a copy of the settings (see remove()),
     * so a re-install gets the store's own credentials and choices back
     * instead of the defaults. The copy is used once and deleted.
     */
    protected function restoreStashedSettings(): void
    {
        global $db, $messageStack;

        $row = $db->Execute("SELECT configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = '" . self::SETTINGS_STASH_KEY . "' LIMIT 1");
        if ($row->EOF) {
            return;
        }
        $saved = json_decode((string)$row->fields['configuration_value'], true);
        $restored = 0;
        if (is_array($saved)) {
            foreach ($this->keys() as $key) {
                if (!array_key_exists($key, $saved) || !is_scalar($saved[$key])) {
                    continue;
                }
                $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '" . zen_db_input((string)$saved[$key]) . "' WHERE configuration_key = '" . zen_db_input($key) . "' LIMIT 1");
                $restored++;
            }
        }
        $db->Execute("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = '" . self::SETTINGS_STASH_KEY . "'");
        if ($restored > 0 && is_object($messageStack)) {
            $messageStack->add_session(sprintf(MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_SETTINGS_RESTORED, $restored), 'success');
        }
    }

    /**
     * Keep the current settings (all but the on/off switch) in one row that
     * the Remove does not touch, so the next Install can put them back. The
     * row lives in the same table the settings already live in, so nothing
     * is stored anywhere new.
     */
    protected function stashSettings(): void
    {
        global $db;

        $values = [];
        foreach ($this->keys() as $key) {
            if ($key === self::CONFIG_PREFIX . 'STATUS' || !defined($key)) {
                continue;
            }
            $values[$key] = (string)constant($key);
        }
        if ($values === []) {
            return;
        }
        $db->Execute("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = '" . self::SETTINGS_STASH_KEY . "'");
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added)
             VALUES ('Authorize.net (Accept.js) saved settings', '" . self::SETTINGS_STASH_KEY . "', '" . zen_db_input(json_encode($values, JSON_UNESCAPED_SLASHES)) . "',
                     'The settings as they were when the module was removed under Modules > Payment; restored by the next Install and then deleted. Plugin Manager > Uninstall deletes it too.',
                     " . self::CONFIG_GROUP_ID . ", 99, now())"
        );
    }

    protected function installKey(string $title, string $key, string $value, string $description, int $sort, ?string $setFunction = null, ?string $useFunction = null): void
    {
        global $db;
        $columns = 'configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added';
        $values = "'" . zen_db_input($title) . "', '" . self::CONFIG_PREFIX . $key . "', '" . zen_db_input($value) . "', '" . zen_db_input($description) . "', " . self::CONFIG_GROUP_ID . ", " . $sort . ", now()";
        if ($setFunction !== null) {
            $columns .= ', set_function';
            $values .= ", '" . zen_db_input($setFunction) . "'";
        }
        if ($useFunction !== null) {
            $columns .= ', use_function';
            $values .= ", '" . zen_db_input($useFunction) . "'";
        }
        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (" . $columns . ") VALUES (" . $values . ")");
    }

    /**
     * Remove the settings, and take the module out of the installed list
     * when the plugin is being uninstalled around it (the Modules > Payment
     * page does that part itself when the Remove button is used).
     *
     * @param bool $keepSettings  true (the Remove button): stash the settings
     *                            for the next Install. false (Plugin Manager
     *                            uninstall): forget them.
     */
    public function remove($keepSettings = true)
    {
        global $db;
        if ($keepSettings === true) {
            $this->stashSettings();
        } else {
            $db->Execute("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = '" . self::SETTINGS_STASH_KEY . "'");
        }
        $db->Execute("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key LIKE 'MODULE\_PAYMENT\_AUTHORIZENET\_ACCEPT\_%'");

        $installed = $db->Execute("SELECT configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'MODULE_PAYMENT_INSTALLED' LIMIT 1");
        if (!$installed->EOF) {
            $list = array_filter(explode(';', (string)$installed->fields['configuration_value']), 'strlen');
            $without = array_values(array_diff($list, [$this->code . '.php']));
            if (count($without) !== count($list)) {
                $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '" . zen_db_input(implode(';', $without)) . "' WHERE configuration_key = 'MODULE_PAYMENT_INSTALLED' LIMIT 1");
            }
        }
    }

    public function keys()
    {
        $keys = [];
        foreach (['STATUS', 'LOGIN', 'TXNKEY', 'CLIENT_KEY', 'TESTMODE', 'AUTHORIZATION_TYPE', 'USE_CVV', 'CURRENCY',
                  'SORT_ORDER', 'ZONE', 'ORDER_STATUS_ID', 'AUTH_ORDER_STATUS_ID', 'REFUNDED_ORDER_STATUS_ID',
                  'REVIEW_ORDER_STATUS_ID', 'EMAIL_CUSTOMER', 'DUPLICATE_WINDOW', 'SEND_LINE_ITEMS', 'DEBUGGING'] as $key) {
            $keys[] = self::CONFIG_PREFIX . $key;
        }
        return $keys;
    }
}
