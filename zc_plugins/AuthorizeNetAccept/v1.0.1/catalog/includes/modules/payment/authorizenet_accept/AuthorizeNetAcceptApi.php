<?php
/**
 * Authorize.Net Accept.js Payments -- JSON API client.
 *
 * One small class, no SDK. It talks to the current Authorize.Net API
 * (https://developer.authorize.net/api/reference/) over JSON, which is the only
 * place the gateway accepts an Accept.js nonce or a digital-wallet token.
 *
 * Two things about that API are easy to get wrong, and both are handled here:
 *
 *   - It is an XML schema wearing JSON clothes, and it reads the keys IN
 *     ORDER. A request whose keys are out of schema order comes back as
 *     E00003 ("... has invalid child element ..."). Every element built here
 *     goes through orderKeys() with the schema order for that element.
 *   - The response body starts with a UTF-8 byte-order mark, which
 *     json_decode() refuses. It is stripped before decoding.
 *
 * Nothing in this file touches Zen Cart globals, so the harness can drive it
 * with a fake transport and assert on the exact JSON that would have gone out.
 *
 * @package  AuthorizeNetAccept
 * @license  https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU Public License V2.0
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

class AuthorizeNetAcceptApi
{
    const ENDPOINT_PRODUCTION = 'https://api.authorize.net/xml/v1/request.api';
    const ENDPOINT_SANDBOX = 'https://apitest.authorize.net/xml/v1/request.api';

    /** opaqueData descriptors the gateway understands. */
    const DESCRIPTOR_ACCEPT = 'COMMON.ACCEPT.INAPP.PAYMENT';
    const DESCRIPTOR_APPLE_PAY = 'COMMON.APPLE.INAPP.PAYMENT';
    const DESCRIPTOR_GOOGLE_PAY = 'COMMON.GOOGLE.INAPP.PAYMENT';

    /** transactionResponse.responseCode values. */
    const RESPONSE_APPROVED = 1;
    const RESPONSE_DECLINED = 2;
    const RESPONSE_ERROR = 3;
    const RESPONSE_HELD = 4;

    /** Result codes of our own for failures that never reached the gateway's parser. */
    const CODE_COMM = 'COMM';
    const CODE_JSON = 'JSON';

    /** Schema order of the children of transactionRequest. */
    const TRANSACTION_REQUEST_ORDER = [
        'transactionType', 'amount', 'currencyCode', 'payment', 'profile', 'solution', 'callId',
        'terminalNumber', 'authCode', 'refTransId', 'splitTenderId', 'order', 'lineItems', 'tax',
        'duty', 'shipping', 'taxExempt', 'poNumber', 'customer', 'billTo', 'shipTo', 'customerIP',
        'cardholderAuthentication', 'retail', 'employeeId', 'transactionSettings', 'userFields',
        'surcharge', 'merchantDescriptor', 'subMerchant', 'tip', 'processingOptions',
        'subsequentAuthInformation', 'otherTax', 'shipFrom', 'authorizationIndicatorType',
    ];

    /** Schema order of a billTo / shipTo element. */
    const ADDRESS_ORDER = [
        'firstName', 'lastName', 'company', 'address', 'city', 'state', 'zip', 'country',
        'phoneNumber', 'faxNumber', 'email',
    ];

    /** Schema order of a lineItem element. */
    const LINE_ITEM_ORDER = [
        'itemId', 'name', 'description', 'quantity', 'unitPrice', 'taxable',
    ];

    /** Field lengths from the API reference; longer values are cut, not rejected. */
    const LIMITS = [
        'refId' => 20,
        'invoiceNumber' => 20,
        'description' => 255,
        'customerId' => 20,
        'email' => 255,
        'firstName' => 50,
        'lastName' => 50,
        'company' => 50,
        'address' => 60,
        'city' => 40,
        'state' => 40,
        'zip' => 20,
        'country' => 60,
        'phoneNumber' => 25,
        'lineItemId' => 31,
        'lineItemName' => 31,
        'lineItemDescription' => 255,
    ];

    /** @var string */
    private $login;
    /** @var string */
    private $transactionKey;
    /** @var string */
    private $endpoint;
    /** @var int */
    private $timeout;
    /** @var callable|null  A test double for the HTTP call: fn(string $url, string $json, self $api): string */
    private $transport = null;

    /** @var array  The last payload sent, unmasked. Use maskedRequest() for anything that gets written down. */
    public $lastRequest = [];
    /** @var string */
    public $lastRaw = '';
    /** @var array|null */
    public $lastResponse = null;
    /** @var string */
    public $commError = '';
    /** @var int */
    public $commErrNo = 0;
    /** @var array */
    public $commInfo = [];
    /** @var int */
    public $httpCode = 0;

    public function __construct(string $login, string $transactionKey, bool $sandbox = false, int $timeout = 30)
    {
        $this->login = trim($login);
        $this->transactionKey = trim($transactionKey);
        $this->endpoint = $sandbox ? self::ENDPOINT_SANDBOX : self::ENDPOINT_PRODUCTION;
        $this->timeout = max(5, $timeout);
    }

    public function setTransport(callable $transport): void
    {
        $this->transport = $transport;
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * Money the way the gateway wants it: two decimals, dot, no separators.
     */
    public static function amount($value): string
    {
        return number_format(round((float)$value, 2), 2, '.', '');
    }

    /**
     * Cut to $max characters, not bytes, whether or not mbstring is loaded:
     * a UTF-8 sequence split in the middle is what the gateway rejects as
     * invalid JSON.
     */
    public static function truncate($value, int $max): string
    {
        $value = trim((string)$value);
        if ($max <= 0) {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max, 'UTF-8');
        }
        if (preg_match('/^.{0,' . $max . '}/us', $value, $m) === 1) {
            return $m[0];
        }
        return substr($value, 0, $max); // not valid UTF-8 to begin with
    }

    /**
     * Re-order an element's keys to match the schema. Keys the schema list
     * does not know are kept, after the known ones, in the order given.
     */
    public static function orderKeys(array $data, array $order): array
    {
        $ordered = [];
        foreach ($order as $key) {
            if (array_key_exists($key, $data)) {
                $ordered[$key] = $data[$key];
            }
        }
        foreach ($data as $key => $value) {
            if (!array_key_exists($key, $ordered)) {
                $ordered[$key] = $value;
            }
        }
        return $ordered;
    }

    /**
     * A billTo / shipTo element from loose values: empties dropped, lengths
     * enforced, keys in schema order.
     */
    public static function address(array $fields): array
    {
        $out = [];
        foreach (self::ADDRESS_ORDER as $key) {
            if (!isset($fields[$key])) {
                continue;
            }
            $limit = self::LIMITS[$key] ?? 255;
            $value = self::truncate($fields[$key], $limit);
            if ($value !== '') {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * Send one request. $requestName is the API's root element
     * (createTransactionRequest, getTransactionDetailsRequest, ...); $body is
     * everything that follows merchantAuthentication, in schema order. A refId
     * in $body is placed where the schema wants it, right after the
     * authentication block.
     *
     * Returns a normalized result:
     *   ok           messages.resultCode === 'Ok'
     *   resultCode   'Ok' | 'Error' | ''
     *   messageCode  first messages.message code (I00001, E00007, ...), or COMM / JSON
     *   messageText  its text
     *   transaction  normalizeTransaction() of transactionResponse (always present)
     *   response     the decoded body, or null
     */
    public function send(string $requestName, array $body): array
    {
        $payload = [
            'merchantAuthentication' => [
                'name' => $this->login,
                'transactionKey' => $this->transactionKey,
            ],
        ];
        if (isset($body['refId'])) {
            $payload['refId'] = self::truncate($body['refId'], self::LIMITS['refId']);
            unset($body['refId']);
        }
        foreach ($body as $key => $value) {
            $payload[$key] = $value;
        }
        $this->lastRequest = [$requestName => $payload];

        $json = json_encode($this->lastRequest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->commError = '';
        $this->commErrNo = 0;
        $this->commInfo = [];
        $this->httpCode = 0;
        $this->lastRaw = '';
        $this->lastResponse = null;

        if ($this->transport !== null) {
            $raw = call_user_func($this->transport, $this->endpoint, $json, $this);
        } else {
            $raw = $this->post($json);
        }
        $this->lastRaw = (string)$raw;

        $result = [
            'ok' => false,
            'resultCode' => '',
            'messageCode' => '',
            'messageText' => '',
            'transaction' => self::normalizeTransaction(null),
            'response' => null,
        ];

        if ($this->commErrNo !== 0 || $this->commError !== '') {
            $result['messageCode'] = self::CODE_COMM;
            $result['messageText'] = $this->commError;
            return $result;
        }

        $clean = preg_replace('/^\xEF\xBB\xBF/', '', trim($this->lastRaw));
        $decoded = json_decode($clean, true);
        if (!is_array($decoded)) {
            $result['messageCode'] = self::CODE_JSON;
            $result['messageText'] = 'The gateway returned a response that could not be read'
                . ($this->httpCode > 0 ? ' (HTTP ' . $this->httpCode . ')' : '') . '.';
            return $result;
        }

        $this->lastResponse = $decoded;
        $result['response'] = $decoded;
        $result['resultCode'] = (string)($decoded['messages']['resultCode'] ?? '');
        $result['messageCode'] = (string)($decoded['messages']['message'][0]['code'] ?? '');
        $result['messageText'] = (string)($decoded['messages']['message'][0]['text'] ?? '');
        $result['transaction'] = self::normalizeTransaction($decoded['transactionResponse'] ?? null);
        $result['ok'] = ($result['resultCode'] === 'Ok');
        return $result;
    }

    /**
     * The HTTP call itself. Honors Zen Cart's CURL proxy settings when the
     * store has them.
     */
    private function post(string $json): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json', 'Content-Length: ' . strlen($json)]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if (defined('CURL_PROXY_REQUIRED') && CURL_PROXY_REQUIRED === 'True' && defined('CURL_PROXY_SERVER_DETAILS')) {
            curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, defined('CURL_PROXY_TUNNEL_FLAG') && strtoupper(CURL_PROXY_TUNNEL_FLAG) === 'TRUE');
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            curl_setopt($ch, CURLOPT_PROXY, CURL_PROXY_SERVER_DETAILS);
        }

        $raw = curl_exec($ch);
        $this->commError = curl_error($ch);
        $this->commErrNo = curl_errno($ch);
        $this->commInfo = curl_getinfo($ch);
        $this->httpCode = (int)($this->commInfo['http_code'] ?? 0);
        curl_close($ch);

        return ($raw === false) ? '' : (string)$raw;
    }

    /**
     * transactionResponse, flattened to what the module needs, with every key
     * present so callers never test isset().
     */
    public static function normalizeTransaction($t): array
    {
        $out = [
            'responseCode' => 0,
            'authCode' => '',
            'avsResultCode' => '',
            'cvvResultCode' => '',
            'cavvResultCode' => '',
            'transId' => '',
            'refTransId' => '',
            'accountNumber' => '',
            'accountType' => '',
            'networkTransId' => '',
            'reasonCode' => '',
            'reasonText' => '',
            'messages' => [],
            'errors' => [],
        ];
        if (!is_array($t)) {
            return $out;
        }
        $out['responseCode'] = (int)($t['responseCode'] ?? 0);
        foreach (['authCode', 'avsResultCode', 'cvvResultCode', 'cavvResultCode', 'transId', 'accountNumber', 'accountType', 'networkTransId'] as $key) {
            $out[$key] = trim((string)($t[$key] ?? ''));
        }
        $out['refTransId'] = trim((string)($t['refTransID'] ?? ''));
        foreach ((array)($t['messages'] ?? []) as $m) {
            $out['messages'][] = ['code' => (string)($m['code'] ?? ''), 'text' => (string)($m['description'] ?? '')];
        }
        foreach ((array)($t['errors'] ?? []) as $e) {
            $out['errors'][] = ['code' => (string)($e['errorCode'] ?? ''), 'text' => (string)($e['errorText'] ?? '')];
        }
        if ($out['errors'] !== []) {
            $out['reasonCode'] = $out['errors'][0]['code'];
            $out['reasonText'] = $out['errors'][0]['text'];
        } elseif ($out['messages'] !== []) {
            $out['reasonCode'] = $out['messages'][0]['code'];
            $out['reasonText'] = $out['messages'][0]['text'];
        }
        return $out;
    }

    /**
     * The last request with everything secret or card-related blanked, for
     * logs and the transaction table.
     */
    public function maskedRequest(): array
    {
        return self::mask($this->lastRequest);
    }

    public static function mask($data)
    {
        if (!is_array($data)) {
            return $data;
        }
        $masked = [];
        foreach ($data as $key => $value) {
            if ($key === 'transactionKey') {
                $masked[$key] = '****';
            } elseif ($key === 'dataValue') {
                $masked[$key] = '[redacted, ' . strlen((string)$value) . ' chars]';
            } elseif ($key === 'cardNumber') {
                $s = (string)$value;
                $masked[$key] = strlen($s) > 4 ? str_repeat('X', strlen($s) - 4) . substr($s, -4) : $s;
            } elseif ($key === 'cardCode') {
                $masked[$key] = '***';
            } else {
                $masked[$key] = self::mask($value);
            }
        }
        return $masked;
    }
}
