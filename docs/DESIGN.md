# Authorize.Net Accept.js Payments: design

The source of truth for this plugin. Read it before touching anything.
The companion Pro plugin's design lives in the Pro repo's docs/DESIGN.md.

## 1. What it is

A payment module for Zen Cart 1.5.8 through 3.0.0 that takes card payments
through Authorize.Net's current JSON API with Accept.js tokenization, as a
drop-in successor to the core Authorize.net AIM module. Free, GPL-2.0, in the
Zen Cart Plugins Library.

Why it exists (researched 2026-09-13):

- Authorize.Net calls AIM, SIM, DPM, Relay Response and Silent Post obsolete
  and "in the process of being phased out". Every core Zen Cart release, 1.5.7
  through 3.0.0-dev, still ships only AIM and SIM.
- The legacy name-value protocol has no field for an Accept.js nonce or a
  wallet token. Anything modern has to start from the JSON API, and nothing in
  the Zen Cart ecosystem does that on a supported release (proseLA's CIM
  module stops at 2.0.0 and is a file-drop package).
- It is the base the Pro plugin (Apple Pay, Google Pay, card on file) stands
  on. The seams are designed for that, but the free plugin is complete on its
  own.

## 2. Decisions

| Decision | Choice | Why |
|---|---|---|
| Card entry | Accept.js with the store's own fields (not AcceptUI) | Same look as AIM; card number and CVV inputs carry no `name`, so they can never be posted. SAQ A-EP. AcceptUI (hosted lightbox, SAQ A) is a possible later option. |
| SDK loading | Built at click time from `js.authorize.net` / `jstest.authorize.net` | Authorize.Net requires the SDK from their host; there is no local copy by design. Loaded on demand so a page with the module unselected never fetches it. |
| Submit hook | Capture-phase `click` listener on `document` | Runs before the button's inline `onclick`, jQuery's delegated handlers (One Page Checkout) and the form's submit event, so it works on both checkout flows without touching core JS. After tokenizing it re-issues the click, so Zen Cart's own `check_form()` still runs. |
| Where the JS lives | `checkout_script.php`, emitted inside the payment block | No dependency on how each release finds `jscript_*.php` in plugins; survives One Page Checkout's AJAX re-render of the payment block. |
| API client | Own 300-line class, no SDK | The official PHP SDK is a Composer tree with its own opinions; the API is four request shapes. Testable with a fake transport. |
| Key order | Every element passes through `orderKeys()` with the schema order | The JSON API validates in XML schema order; out-of-order keys fail with E00003. |
| Transaction table | Own table `authorizenet_accept`, one row per gateway call | Richer than core's `authorizenet` table (network transaction id, AVS/CVV, masked request); does not collide with core's schema. Kept on uninstall. |
| Version floor | Zen Cart 1.5.8 | Payment modules load from `zc_plugins` only from 2.1.0 (verified absent in 1.5.8 and 2.0.0: the payment class, Modules > Payment and the order page all read the core folders only), so 1.5.8 through 2.0.x get two bridge files in the core folders (`for_zen_cart_1.5.8_to_2.0.x/`): a module file and a language file that hand off to the plugin's copies, preferring the version the Plugin Manager installed. The installer refuses those releases until the files are present and never writes into core folders itself; if the plugin is deleted with the bridge left behind, a placeholder class keeps Modules > Payment from fataling. From 2.1.0 the plugin's copy wins over a core file of the same name, so the bridge is inert after an upgrade. 1.5.8 is John's standard floor (set 2026-09-13). PHP 7.4 through 8.5. |
| Config | Created by the module's `install()` under Modules > Payment | Exactly like a core payment module; the Plugin Manager installer only creates the table. |
| No `zen_config()` | `cfg()` helper on `defined()`/`constant()` | `zen_config()` is 3.0.0 only. |
| Wallet hook (1.0.1) | The checkout script owns the hidden carriers for everyone: `window.authorizenet_accept.setWalletToken(descriptor, value, meta)` writes a wallet token into them, selects the module (a real radio click, so One Page Checkout records it), keeps the token in sessionStorage for 15 minutes keyed to the order total, restores it when OPC re-renders the block, and lets the submit click through without card validation or an Accept.js call. Card edits, choosing another method, or submitting the form drop it. Each render dispatches `authorizenet_accept:ready` on the document with the API. | Found through a forum member's Google Pay add-on (2026-09-15): the 1.0.0 interceptor only let its own Accept.js nonce through, so a wallet token in the carriers was blocked on the standard checkout with the card-number message. The Pro's wallets and any third-party add-on need one sanctioned way in. |
| Settings survive Remove | `remove()` stashes all but STATUS in one configuration row; `install()` restores and deletes it; Plugin Manager uninstall forgets it | Prompted by zencart/documentation#1456 (torvista, 2026-09-13): re-installing a payment module should not mean retyping every key. Done in the module rather than in a per-site observer so every store gets it. |

## 3. File map

```
zc_plugins/AuthorizeNetAccept/v1.0.2/
  manifest.php                               Plugin Manager panel (Read Me / GitHub / forum buttons)
  readme.html                                the store owner's manual, served from zc_plugins
  changelog.txt
  Installer/ScriptedInstaller.php            table create; ZC/PHP/curl checks; uninstall removes the module's settings
  admin/includes/extra_datafiles/            TABLE_AUTHORIZENET_ACCEPT
  catalog/includes/extra_datafiles/          TABLE_AUTHORIZENET_ACCEPT
  catalog/includes/languages/english/modules/payment/lang.authorizenet_accept.php
  catalog/includes/modules/payment/authorizenet_accept.php          the module
  catalog/includes/modules/payment/authorizenet_accept/
      AuthorizeNetAcceptApi.php              JSON client, schema ordering, masking
      AuthorizeNetAcceptLog.php              transaction table, log files
      admin_notification.php                 order-page block: history + refund / capture / void
      checkout_script.php                    the browser side
for_zen_cart_1.5.8_to_2.0.x/                 uploaded on 1.5.8 - 2.0.x only
  includes/modules/payment/authorizenet_accept.php                          bridge to the plugin's module
  includes/languages/english/modules/payment/lang.authorizenet_accept.php  bridge to the plugin's language file
```

## 4. Checkout flow

1. `selection()` renders name-on-card (named), card number and CVV (id only,
   no name), expiry selects (id only), five named hidden carriers
   (`authorizenet_accept_descriptor`, `_value`, `_brand`, `_last4`,
   `_expires`), an error box, and the script.
2. Customer clicks Continue (or OPC Review / Confirm). The capture-phase
   listener validates locally (Luhn, expiry, CVV length), loads the SDK if
   needed, calls `Accept.dispatchData`, writes the nonce and card summary into
   the carriers, then re-issues the click. Any edit to the card clears the
   nonce; a nonce older than 10 minutes is re-fetched.
3. `pre_confirmation_check()` reads and validates the carriers (descriptor
   whitelist, nonce shape, owner length) and keeps them in `$paymentData`.
4. `process_button()` re-emits them as hidden fields on the confirmation
   page. For the PA-DSS AJAX confirmation and One Page Checkout,
   `process_button_ajax()` returns them as `extraFields` with literal values
   (escaped for the attribute) and an empty `ccFields`: core's `ccFields`
   copy selects by name with the payment form still on the page, matches the
   old and the new field, reads the empty new one first and wipes the nonce.
   Found on the pilot store's first checkout; the AJAX request already
   carries the values, so no copy is needed.
5. `before_process()` builds `createTransactionRequest` (auth-only or
   auth-capture; amount, currency, opaqueData, order, line items, tax,
   shipping, customer, billTo, shipTo, customerIP, transactionSettings), sends
   it, records the row, logs if asked, and maps the outcome:
   - response code 1: approved; 4: approved but held (order status from
     REVIEW_ORDER_STATUS_ID); 2 or 3: back to the payment page with the
     gateway's reason (CVV/expiry reasons reworded); no transactionResponse:
     back with the gateway's message (bad credentials, schema error).
6. `after_process()` writes the order-status history line and ties the
   transaction row to the order id.

Currency: when the order currency differs from CURRENCY, the total is
converted with the store's rates and tax, shipping and line items are omitted,
as the core module does.

## 5. Admin

- Modules > Payment: 18 settings (see docs/CONFIGURATION.md). Title shows
  "(Not Configured)", "(in Sandbox mode)" or "(test requests only)".
- Order page: transaction history table for the order, plus refund (last 4 +
  transaction id, prefilled), capture (shown when an authorization is open or
  the module authorizes only) and void. The forms post to Zen Cart's own
  `doRefund` / `doCapture` / `doVoid` actions, which call `_doRefund()`,
  `_doCapt()` and `_doVoid()`; field names are the core module's.

## 6. Notifier seams (for add-ons)

All parameters after `$this->code` are by reference.

| Event | Parameters | Purpose |
|---|---|---|
| `NOTIFY_AUTHNET_ACCEPT_SELECTION_FIELDS` | `$fields`, `$extra` | add or reorder payment-block fields; append markup (wallet buttons) |
| `NOTIFY_AUTHNET_ACCEPT_SCRIPT_CONFIG` | `$anaScriptConfig` | change what the checkout script is told |
| `NOTIFY_AUTHNET_ACCEPT_ALLOWED_DESCRIPTORS` | `$descriptors` | permit more opaqueData descriptors (Apple Pay, Google Pay) |
| `NOTIFY_AUTHNET_ACCEPT_PRE_CONFIRMATION` | `$data`, `$problem` | inspect or reject the submission |
| `NOTIFY_AUTHNET_ACCEPT_CONFIRMATION_FIELDS` | `$fields`, `$data` | change the confirmation summary |
| `NOTIFY_AUTHNET_ACCEPT_PROCESS_BUTTON` | `$html`, `$data` | add hidden fields |
| `NOTIFY_AUTHNET_ACCEPT_BEFORE_TRANSACTION` | `$request`, `$data` | edit the transactionRequest (payment element, settings) |
| `NOTIFY_AUTHNET_ACCEPT_AFTER_TRANSACTION` | `$result`, `$request` | react to the outcome |
| `NOTIFY_AUTHNET_ACCEPT_ADMIN_TRANSACTION` | `$result`, `$request` | react to a refund / capture / void |

Public helpers an add-on may call on the module object: `cfg()`, `api()`,
`isSandbox()`, `authorizeOnly()`, `allowedDescriptors()`,
`readSubmittedPayment()`, `validateSubmittedPayment()`; and the
`$paymentLabel` property (the order-history prefix).

## 7. Security notes

- The card number and CVV never post: no `name` attribute. If the script
  fails entirely, the form posts without a nonce and the server rejects it.
- The nonce is single-use and expires in 15 minutes; it is not logged
  (masked as its length) and the transaction key is masked everywhere.
- The Public Client Key is public by design; the Transaction Key is stored
  with `zen_cfg_password_display`.
- Admin actions require the confirmation checkbox (the core module's void
  check was ineffective; this one is not) and go through Zen Cart's admin
  security token via `zen_draw_form(..., true)`.
- Output on the admin page is escaped with `zen_output_string_protected()`.

## 8. Testing

- `tests/run_all.ps1`: lint plus every harness on PHP 7.4 through 8.5
  (manifest, installer, module, api, security scan, readme, zc_compat, js).
- Rig: `F:\zclab\bin\link-plugin.ps1 -Copy` into zc158 / zc200 / zc210 /
  zc222 / zc230, junction into zc300; install through the Plugin Manager and
  then Modules > Payment; checkout in the Browser pane. On zc158 and zc200
  copy the bridge files into the worktree first, and `git clean` them out
  afterwards.
- Sandbox: needs a sandbox account's three credentials. Test cards in the
  module's description. Declines: billing ZIP 46282; held for review needs
  the sandbox's fraud filters. Refund needs a settled transaction (sandbox
  settles nightly), so voids are the same-day test.

## 9. Open items

- Sandbox end-to-end run once credentials exist (the module has only been
  exercised against a fake transport and on the rig up to the gateway call).
- An Authorize.Net partner "solution id" would identify the plugin in their
  reporting; not requested yet, and not required.
- AcceptUI as an alternative entry mode.
- eCheck (Accept.js can tokenize bank accounts too).
