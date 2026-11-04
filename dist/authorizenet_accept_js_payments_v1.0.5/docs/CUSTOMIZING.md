# Customizing

Everything below is done from an observer of your own, never by editing the
module. Observers in a plugin's `catalog/includes/classes/observers/` named
`auto.*.php` are loaded by Zen Cart itself.

## The notifier seams

Every event passes the module's code as the first (by value) parameter and
the rest by reference, in this order.

| Event | Parameters | Use it to |
|---|---|---|
| `NOTIFY_AUTHNET_ACCEPT_SELECTION_FIELDS` | `$fields`, `$extra` | add, remove or reorder fields in the payment block; append markup after them (wallet buttons, notes) |
| `NOTIFY_AUTHNET_ACCEPT_SCRIPT_CONFIG` | `$anaScriptConfig` | change what the checkout script is told: messages, the submit selector, the nonce lifetime |
| `NOTIFY_AUTHNET_ACCEPT_ALLOWED_DESCRIPTORS` | `$descriptors` | allow more opaqueData descriptors (`COMMON.APPLE.INAPP.PAYMENT`, `COMMON.GOOGLE.INAPP.PAYMENT`) |
| `NOTIFY_AUTHNET_ACCEPT_PRE_CONFIRMATION` | `$data`, `$problem` | inspect the submission; set `$problem` to a message to send the customer back |
| `NOTIFY_AUTHNET_ACCEPT_CONFIRMATION_FIELDS` | `$fields`, `$data` | change what the confirmation page shows |
| `NOTIFY_AUTHNET_ACCEPT_PROCESS_BUTTON` | `$html`, `$data` | add hidden fields to the confirmation form |
| `NOTIFY_AUTHNET_ACCEPT_BEFORE_TRANSACTION` | `$request`, `$data` | edit the `transactionRequest` before it is sent: swap the payment element for a profile, add settings, user fields |
| `NOTIFY_AUTHNET_ACCEPT_AFTER_TRANSACTION` | `$result`, `$request` | react to the outcome (approved, declined, held) |
| `NOTIFY_AUTHNET_ACCEPT_ADMIN_TRANSACTION` | `$result`, `$request` | react to a refund, capture or void from the order page |

Key order in `$request` is fixed up after `NOTIFY_AUTHNET_ACCEPT_BEFORE_TRANSACTION`
fires, so an observer can add elements in any order.

## Public helpers on the module object

The observer receives the module as `$class`.

- `$class->cfg('LOGIN')`: any setting by its short key.
- `$class->api()`: an API client with the module's credentials and mode, for
  requests of your own (customer profiles, transaction details).
- `$class->isSandbox()`, `$class->authorizeOnly()`, `$class->allowedDescriptors()`.
- `$class->readSubmittedPayment($_POST)` and `validateSubmittedPayment($data)`.
- `$class->paymentLabel`: the prefix of the order-history line ("Credit Card
  payment."); set it in `NOTIFY_AUTHNET_ACCEPT_BEFORE_TRANSACTION` to name
  another source.

## The browser side: handing over a wallet token (1.0.1)

The checkout script owns the hidden carriers. Don't write them yourself;
hand the token to the script and it does the rest:

```js
window.authorizenet_accept.setWalletToken(
    'COMMON.GOOGLE.INAPP.PAYMENT',   // a descriptor your observer allowed
    base64Token,                     // the token, exactly as the gateway wants it
    { brand: 'Google Pay', last4: '', expires: '' }   // optional summary; select: false skips the radio click
);
```

What that does: fills the descriptor, value and summary carriers; selects
the module with a real radio click, so One Page Checkout's own handler
records the choice; keeps the token in sessionStorage for 15 minutes, tied
to the order total, and puts it back when OPC re-renders the payment block;
and from then on lets Continue, Review and Confirm through untouched, with no
card validation and no Accept.js call. The token is dropped when the customer
edits the card fields, picks another payment method, or submits the form.

Also on the object: `clearWalletToken()`, `hasWalletToken()`, `select()`,
and `config()`, which returns `{code, total, currency, sandbox}` so a wallet
sheet can be built for the same amount the module will charge. Because OPC
re-renders the payment block, listen for the render rather than the page:

```js
document.addEventListener('authorizenet_accept:ready', function (event) {
    var api = event.detail;              // the same object as window.authorizenet_accept
    // draw your button next to the card fields; call api.setWalletToken() when the wallet answers
});
```

The event fires on every render, including the first, so the button comes
back after OPC redraws the block.

## Example: a wallet token instead of a card

```php
class zcObserverMyWallet extends base
{
    public function __construct()
    {
        $this->attach($this, [
            'NOTIFY_AUTHNET_ACCEPT_ALLOWED_DESCRIPTORS',
            'NOTIFY_AUTHNET_ACCEPT_SELECTION_FIELDS',
            'NOTIFY_AUTHNET_ACCEPT_BEFORE_TRANSACTION',
        ]);
    }

    public function update(&$class, $eventID, $code, &$p2, &$p3)
    {
        switch ($eventID) {
            case 'NOTIFY_AUTHNET_ACCEPT_ALLOWED_DESCRIPTORS':
                $p2[] = 'COMMON.GOOGLE.INAPP.PAYMENT';
                break;
            case 'NOTIFY_AUTHNET_ACCEPT_SELECTION_FIELDS':
                // $p3 is the markup appended after the card fields.
                $p3 .= '<div id="my-wallet-button"></div>';
                break;
            case 'NOTIFY_AUTHNET_ACCEPT_BEFORE_TRANSACTION':
                if ($p3['descriptor'] === 'COMMON.GOOGLE.INAPP.PAYMENT') {
                    $class->paymentLabel = 'Google Pay payment.';
                }
                break;
        }
    }
}
```

The wallet's script writes its token into the same hidden carriers the card
path uses (`authorizenet_accept_descriptor` and `authorizenet_accept_value`),
and the module charges it without any other change.

## The API client on its own

```php
$api = $class->api();
$result = $api->send('getTransactionDetailsRequest', ['transId' => '40000012345']);
if ($result['ok']) {
    $status = $result['response']['transaction']['transactionStatus'];
}
```

`send()` puts `merchantAuthentication` first and a `refId` second; everything
else in the body is sent in the order given, so build elements in schema
order (or pass them through `AuthorizeNetAcceptApi::orderKeys()`).

## Preset credentials on a test store

The module already restores its own settings across a Remove and re-Install
(see CONFIGURATION.md). For a development store that is rebuilt from scratch,
Zen Cart 2.2.0 and later fire `NOTIFY_ADMIN_MODULES_DO_INSTALL` right after any
module's `install()`, with `['module_name' => 'authorizenet_accept']` (2.1.0
has no such notifier), and an admin observer can write the sandbox credentials
at that moment:

```php
class zcObserverMyStoreModuleDefaults extends base
{
    public function __construct()
    {
        $this->attach($this, ['NOTIFY_ADMIN_MODULES_DO_INSTALL']);
    }

    public function update(&$class, $eventID, $info)
    {
        global $db;
        if (($info['module_name'] ?? '') !== 'authorizenet_accept') {
            return;
        }
        foreach ([
            'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_LOGIN' => 'your sandbox login id',
            'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TXNKEY' => 'your sandbox transaction key',
            'MODULE_PAYMENT_AUTHORIZENET_ACCEPT_CLIENT_KEY' => 'your sandbox public client key',
        ] as $key => $value) {
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '" . zen_db_input($value) . "' WHERE configuration_key = '" . $key . "' LIMIT 1");
        }
    }
}
```

Put it in the admin's `includes/classes/observers/auto.mystoremoduledefaults.php`
on the test store only; it is a place for sandbox keys, never live ones.

## Styling

The module adds one element of its own, `#authorizenet_accept-error`, with
the `messageStackError` class most templates already style. The card inputs
carry the ids `authorizenet_accept-cc-owner`, `-cc-number`,
`-cc-expires-month`, `-cc-expires-year` and `-cc-cvv`.

## Language

Copy `catalog/includes/languages/english/modules/payment/lang.authorizenet_accept.php`
to another language's folder inside the plugin and translate the values. The
loader reads the English file first and lets the session language override it.
