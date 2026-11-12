# Authorize.Net Accept.js Payments

Version 1.0.6 · Zen Cart 1.5.8 through 3.0.0 · PHP 7.4 through 8.5 · GPL v3 · by My Zen Cart Host (dbltoe)

Card payments through Authorize.Net's current JSON API with Accept.js tokenization: the card number and security code are tokenized in the customer's browser and never posted to your store. It's a drop-in successor to the Authorize.net AIM module that ships with Zen Cart, with the same card fields on the same payment page, plus a transaction history and refund, capture and void tools on the order page.

## What this is, and why

Authorize.Net has retired the AIM and SIM methods that Zen Cart's built-in modules use. They still answer, and no shutdown date has been announced, but they don't get new features and they can't carry a tokenized card. This module moves your store onto the current API without changing what the customer sees.

With Accept.js, the card number and CVV inputs on the payment page have no `name` attribute, so they can't be posted. When the customer clicks Continue, Authorize.Net's script turns the card into a one-time nonce, and that nonce is what your server sends to the gateway. If the script can't run at all, the order is refused rather than a bare card number being accepted.

## Requirements

- Zen Cart 1.5.8 or later. From 2.1.0 the plugin folder is all you upload. On 1.5.8 through 2.0.x two more small files are needed, because those releases look for a payment module only in the core folders; Installing covers them, and the Plugin Manager refuses the install and names them if they're missing.
- PHP 7.4 or later with the curl and json extensions.
- A live Authorize.Net account for real payments, or a free sandbox account for testing. The live account is a paid service with a merchant account behind it; Going live explains the two ways to get one.
- A store served over https. In Production mode the module hides itself on a page that isn't.
- The customer's browser must be able to load the Accept.js script from `js.authorize.net` (or `jstest.authorize.net` in Sandbox mode). Authorize.Net requires it to come from their host; there's no local copy.

## The three credentials

All three come from the Merchant Interface (or the sandbox's, at https://sandbox.authorize.net/): **Account > Settings > Security Settings > General Security Settings**.

| Credential | Where | Notes |
| --- | --- | --- |
| API Login ID | API Credentials & Keys | Identifies the account. Not secret, but not published either. |
| Transaction Key | API Credentials & Keys > Obtain a new Transaction Key | Secret. Shown once; a new key disables the old one within 24 hours, so update the module at the same time. |
| Public Client Key | Manage Public Client Key | Used by Accept.js in the browser. Public by design: it can tokenize a card but can't run a transaction. Create one there if none exists. |

Don't have a sandbox account yet? Create one at https://developer.authorize.net/hello_world/sandbox.html. It's free and issues the same three credentials.

## Installing

1. Upload the `zc_plugins/AuthorizeNetAccept` folder into your store's `zc_plugins` directory, so that `zc_plugins/AuthorizeNetAccept/v1.0.6/manifest.php` exists. On Zen Cart 2.1.0 and later nothing else needs uploading; no core file is changed.
2. **Zen Cart 1.5.8 through 2.0.x only:** also upload the `includes` folder from the package's `for_zen_cart_1.5.8_to_2.0.x` folder. It adds two files, `includes/modules/payment/authorizenet_accept.php` and `includes/languages/english/modules/payment/lang.authorizenet_accept.php`, that hand off to the plugin; nothing in core is edited. On 2.1.0 and later they're ignored, so they can stay through an upgrade.
3. Admin > Modules > Plugin Manager: install **Authorize.Net Accept.js Payments**. This creates the transaction table.
4. Admin > Modules > Payment: install **Authorize.net (Accept.js)** (the **Settings** button in the plugin's Plugin Manager panel opens that page with the module selected), click Edit, enter the three credentials, and choose the Transaction Mode.

Once it's installed, a superuser can open this guide from the **ReadMe** button in Plugin Manager.

> The module starts in **Sandbox** mode on purpose. Nothing is charged until you switch it to Production with your live credentials.

## Settings

All under **Modules > Payment > Authorize.net (Accept.js)**.

| Setting | Default | What it does |
| --- | --- | --- |
| Enable Authorize.net (Accept.js) Module | True | Offer the module at checkout. |
| API Login ID | blank | See The three credentials. |
| Transaction Key | blank | See The three credentials. Stored with the password display and never sent to the browser. |
| Public Client Key | blank | See The three credentials. |
| Transaction Mode | Sandbox | **Sandbox**: the Authorize.Net sandbox with sandbox credentials; nothing is charged. **Test**: your live account with every request flagged as a test; nothing is charged. **Production**: live. |
| Authorization Type | Authorize+Capture | Charge at checkout, or only reserve the funds and capture them from the order page within 30 days. |
| Request CVV Number | True | Ask for the card's security code. Keep it on. |
| Currency Supported | USD | The currency your Authorize.Net account settles in. See Currencies. |
| Sort order of display | 0 | Position among the payment methods; lowest first. |
| Payment Zone | none | Offer the module only to billing addresses in this zone. |
| Set Completed Order Status | Processing | For orders authorized and captured. |
| Set Authorized (Uncaptured) Order Status | Pending | For authorize-only orders until the funds are captured. |
| Set Refunded Order Status | Pending | After a refund or void from the order page. |
| Set Held-For-Review Order Status | Pending | For orders the gateway's fraud filters hold. See Order statuses. |
| Gateway Receipt Email | False | Have Authorize.Net email its own receipt to the customer as well. |
| Duplicate Window (seconds) | 120 | The gateway rejects a matching second transaction within this window. 0 turns the check off. |
| Send Line Items | True | Send the ordered products (up to 30) so they show in the Merchant Interface. |
| Debug Mode | Off | Log File writes each gateway call to the logs folder with the key and nonce masked; Log and Email also emails the store owner about failed calls. Sandbox mode always writes the log. |

## Testing in the sandbox

With Transaction Mode = Sandbox and the sandbox account's credentials, place an order with one of these cards and any future expiry date and any 3-digit code (4 digits for Amex):

| Card | Number |
| --- | --- |
| Visa | 4007000000027 |
| Mastercard | 5424000000000015 |
| Discover | 6011000000000012 |
| American Express | 370000000000002 |

To see a decline, use a billing ZIP code of **46282**. Other ZIP codes trigger other responses; the full list is in Authorize.Net's testing guide (https://developer.authorize.net/hello_world/testing_guide.html). The sandbox settles transactions overnight, so a refund needs a transaction from an earlier day; voids work the same day.

In Sandbox mode every gateway call is written to the store's logs folder as `authnet_accept_<kind>_<transaction id>_<date>.log`, with the transaction key and the nonce masked, so you can see exactly what went over the wire.

## Going live

Real payments need a live Authorize.Net account, and the sandbox doesn't stand in for one. It's a paid service: a monthly gateway fee plus a fee on each transaction, with a merchant account behind it to settle the money into your bank. Current rates and the sign-up form are at https://www.authorize.net/sign-up/pricing.html. There are two ways in:

- **Gateway Only**: you already have a merchant account with your bank or card processor. Authorize.Net connects to it; you pay them the gateway fees and your processor its usual rates.
- **All-in-One**: Authorize.Net supplies the merchant account as well, at one blended rate per transaction.

Many banks and merchant services providers resell Authorize.Net accounts too; one from a reseller works the same way and issues the same three credentials. There's no setup fee, and the fraud filters (see Order statuses) are part of the gateway fee rather than an add-on.

1. Log in to the live Merchant Interface at https://account.authorize.net/ and collect the three credentials as in The three credentials. They're different from the sandbox's.
2. Switch Transaction Mode to Production.
3. Enter the live account's API Login ID, Transaction Key and Public Client Key.
4. Make sure the store is served over https; the module won't show itself otherwise.
5. Place a small real order, check it in the Merchant Interface, then void it from the order page before the day's settlement.

## The order page: history, refund, capture, void

At the bottom of Admin > Customers > Orders > (an order) you'll find:

- **The transaction history** for that order: every gateway call, with its type, transaction id, result, auth code, AVS and CVV results, card and amount.
- **Refund**: enter the amount, the last four digits of the card and the original transaction id (all prefilled from the last approved charge), tick the confirmation box and click Do Refund. Refunds need a settled transaction and must happen within 120 days. For an unsettled transaction, void instead.
- **Capture**: shown when the order has an uncaptured authorization, or when the module is set to Authorize only. Leave the amount blank to capture the full authorization. Captures must happen within 30 days and an authorization can be captured once.
- **Void**: cancels an unsettled transaction or an uncaptured authorization.

Each action writes a line into the order's status history, changes the order status as configured, and emails the customer that status update.

## Order statuses

- Approved and captured: the Completed Order Status.
- Approved, authorize-only: the Authorized (Uncaptured) status until you capture.
- Held for review: the gateway's fraud filters (its Advanced Fraud Detection Suite) held the transaction. The order is created with the Held-For-Review status and a note in its history. Approve or decline it in the Merchant Interface, then update the order yourself.
- Declined or error: the customer is sent back to the payment page with the gateway's reason and no order is created. The attempt is still recorded in the transaction table for troubleshooting.

## Currencies

An Authorize.Net account settles in one currency. Set Currency Supported to it. An order placed in another store currency is converted with your store's exchange rates before submission, the history line records the converted amount, and tax, shipping and line items are left out of that transaction because they wouldn't add up in the converted total.

## One Page Checkout and the AJAX confirmation

The module works with the standard checkout, with Zen Cart's PA-DSS AJAX confirmation (`PADSS_AJAX_CHECKOUT`), and with the One Page Checkout plugin. Its script intercepts the Continue button, and OPC's Review and Confirm buttons, tokenizes the card, and then lets the page carry on exactly as it would have.

## Security and PCI notes

- The card number and CVV are never posted to your server. The inputs have no `name` attribute; only a one-time nonce, the card brand, the last four digits, the expiry and the name on the card are posted.
- The nonce is single-use and expires after 15 minutes. It isn't logged (it appears in logs only as its length).
- The Transaction Key is stored with Zen Cart's password display and is never sent to the browser. The Public Client Key is public by design.
- The transaction table stores the request with the key and nonce masked, and the gateway's response, which carries at most the last four digits of the card.
- Card details entered on your own page put the store in PCI DSS SAQ A-EP scope, the same as any module with card fields on the page. Consult your acquirer's requirements.

## Troubleshooting

| What you see | What it means |
| --- | --- |
| "The payment gateway reported a problem: User authentication failed due to invalid authentication values." (E00007) | The API Login ID or Transaction Key is wrong for the mode you're in. Sandbox credentials only work in Sandbox mode, live ones only in Test or Production. |
| "Your card details could not be secured for transmission." | Accept.js couldn't tokenize the card: usually a wrong Public Client Key, an API Login ID that doesn't match it, or the browser blocking `js.authorize.net`. The script shows the gateway's own message under the card fields when it has one, scrolls it into view, and puts the cursor in the field it names. |
| "Your card details did not arrive securely." | The form posted without a nonce. JavaScript is off, or something on the page prevented the script from running. Check the browser console. |
| E00003 in a log | The gateway rejected the request's structure. Report it with the masked log; the module orders every element as the schema requires, so this points at something an add-on changed. |
| E00114 "Invalid OTS Token" | The nonce expired or was used twice. The customer waited more than 15 minutes on the confirmation page; they can enter the card again. |
| Error 54 on a refund | The transaction hasn't settled yet. Void it instead, or refund tomorrow. |
| The module doesn't show at checkout | Production mode on a page that isn't https, a Payment Zone that doesn't include the billing address, or the module disabled. |
| "(Not Configured)" next to the module name | One of the three credentials is blank. |
| Modules > Payment doesn't list the module at all (Zen Cart 1.5.8 through 2.0.x) | The two bridge files from Installing aren't uploaded, or landed in the wrong folder. |

Turn Debug Mode to Log File (or use Sandbox mode) and read the log for the call in question: it shows the masked request and the full response.

## Moving from the AIM module

Install this module alongside AIM, test it, then remove AIM under Modules > Payment. Orders paid through AIM keep their history, but AIM's order-page tools go with it, so capture or refund any open AIM authorizations first, or do so in the Merchant Interface.

## Upgrading

Upload the new version's folder beside the old one, so both the old folder and `zc_plugins/AuthorizeNetAccept/v1.0.6/` are present, then in Plugin Manager select the plugin and click **Upgrade**. Modules > Payment keeps its settings and credentials. Delete the old version folder afterwards.

**Version 1.0.6** moves the plugin to the GNU General Public License, version 3, and replaces readme.html with this guide: the ReadMe button in Plugin Manager now opens it inside the admin, for superusers, in its own window. No payment, checkout or settings changes.

## Removing and uninstalling

**Modules > Payment > Remove** takes the module off the checkout page but keeps a copy of its settings, credentials included, in one row of the configuration table. The next Install puts them back and tells you so, so you don't retype the three keys and fifteen choices after a test cycle or a mistaken remove. The copy is used once and deleted.

**Plugin Manager > Uninstall** removes the module (if you skipped the step above), forgets the saved settings, and unregisters the plugin. The transaction table (`authorizenet_accept`) is kept on purpose, because it holds your payment history; drop it yourself if you're sure you don't need it.

On Zen Cart 1.5.8 through 2.0.x, also delete the two bridge files from Installing if you're not going to reinstall. Left behind without the plugin, they only show a placeholder row under Modules > Payment that says what to delete.

## For developers

The module fires notifier events at every seam (the payment block, the descriptor whitelist, the request before it's sent, the result after) so an add-on can take a wallet token or a stored payment profile through the same path without editing the module. In the browser, an add-on hands a wallet token to `window.authorizenet_accept.setWalletToken()` and the checkout script does the rest: it fills the hidden fields, selects the module, keeps the token through a One Page Checkout re-render, and lets the order through without asking for a card. The list, with parameters and an example observer, is in `docs/CUSTOMIZING.md` in the package; the design notes are in `docs/DESIGN.md` in the GitHub repository.

## Support

Questions and problem reports go to the plugin's thread on the Zen Cart forum (https://www.zen-cart.com/threads/207341?page=1#post-1347112), which is also linked from the plugin's Plugin Manager panel. Source, issues and releases are on GitHub (https://github.com/dbltoe/AuthorizeNet_Accept_Payments).

## Changelog

See `CHANGELOG.md`. Version 1.0.6 moves the plugin to the GNU General Public License, version 3, and replaces readme.html with this guide, opened from the ReadMe button in Plugin Manager.

## License

Authorize.Net Accept.js Payments is free software under the GNU General Public License, version 3 (https://www.gnu.org/licenses/gpl-3.0.html); the full text is in `LICENSE`. Copyright © 2026 My Zen Cart Host (dbltoe).

Authorize.Net and Accept.js are trademarks of their owner; this plugin isn't affiliated with or endorsed by Authorize.Net.
