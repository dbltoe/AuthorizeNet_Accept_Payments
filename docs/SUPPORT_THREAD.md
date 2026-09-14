# Authorize.Net Accept.js Payments - support thread

Posted by John 2026-09-13 in Addon Payment Modules as "AuthorizeNet Accept Payments":
https://www.zen-cart.com/threads/207341?page=1#post-1347112

Opening post for the Zen Cart forum support thread (Addon Payment Modules).
Markdown; the forum also accepts it as plain text. Not part of the release
package. Once posted, the opening-post permalink (`/threads/N?page=1#post-M`)
goes into the manifest, the readme and the Library listing.

---

**Authorize.Net Accept.js Payments v1.0.0** - card payments through Authorize.Net's current JSON API with Accept.js tokenization, as a drop-in successor to the AIM module

**Plugins Library:** (link once listed)
**GitHub:** https://github.com/dbltoe/AuthorizeNet_Accept_Payments
**Zen Cart:** 1.5.8, 2.0, 2.1, 2.2, 2.3 and 3.0.0-dev, from one codebase
**PHP:** 7.4 through 8.5
**License:** GPL-2.0

**Why it exists**

Authorize.Net has retired the AIM and SIM methods that Zen Cart's built-in modules use. They still answer today, with no shutdown date announced, but they get no new features, and the current API is the only place Authorize.Net accepts a tokenized card. This module moves a store onto that API without changing what the customer sees.

**What it does**

The customer sees the same card fields on the same payment page. Underneath, the card number and security code inputs have no `name` attribute, so a form post can never carry them. When the customer clicks Continue, Authorize.Net's own Accept.js script turns the card into a one-time nonce in the browser, and that nonce is what your server sends to the gateway. If the script can't run at all, the order is refused rather than a bare card number being accepted.

For the store owner:

- Authorize-only or authorize-and-capture, with separate order statuses for paid, authorized-but-uncaptured, refunded and held-for-review orders.
- On the order page: the transaction history for the order, plus refund, capture and void through Zen Cart's own order-page actions.
- Line items, tax and shipping sent with each transaction; gateway receipt emails if you want them; a duplicate-transaction window.
- Sandbox, test-request and Production modes. It starts in Sandbox.
- Works with the standard checkout, the PA-DSS AJAX confirmation and One Page Checkout.
- Modules > Payment > Remove keeps a copy of the settings; the next Install puts them back.

**Encapsulated.** One folder under `zc_plugins/`, installed from Plugin Manager, then enabled under Modules > Payment like any core payment module. Its own transaction table, kept on uninstall because it holds your payment history.

**Installing**

1. Upload `zc_plugins/AuthorizeNetAccept/` so it lands at `<store root>/zc_plugins/AuthorizeNetAccept/v1.0.0/`.
2. Zen Cart 1.5.8 through 2.0.x only: also upload the `includes` folder from the package's `for_zen_cart_1.5.8_to_2.0.x` folder. Those releases look for a payment module only in the core folders; the two small files there hand off to the plugin. From 2.1.0 on, skip this.
3. Admin -> Modules -> Plugin Manager -> Authorize.Net Accept.js Payments -> Install.
4. Admin -> Modules -> Payment -> Authorize.net (Accept.js) -> Install, then Edit: enter the API Login ID, Transaction Key and Public Client Key, and choose the Transaction Mode.

The full documentation (readme.html) is inside the plugin and linked from the Plugin Manager panel. It covers the three credentials, sandbox testing with the test card numbers, going live, the order-page actions, statuses, currencies and troubleshooting.

**You need**

An Authorize.Net account (a paid service with a merchant account behind it; their sandbox is free for testing and is the right place to start), a store served over https (in Production mode the module hides itself on a page that isn't), and PHP with curl and json.

**Moving from AIM**

Install this module alongside the AIM module, test it, then remove AIM under Modules > Payment. Orders paid through AIM keep their history. Capture or refund any open AIM transactions before you remove it, or do so in the Merchant Interface.

**Not included**

Apple Pay, Google Pay and card on file. Those need customer profiles and wallet registration and are a separate Pro edition built on this module's notifier seams.

**Reporting a problem**

Please include: your Zen Cart and PHP versions, your template, whether you use One Page Checkout, the Transaction Mode, and the exact message shown. Turn Debug Mode to Log File (Sandbox mode always logs) and attach the log for the call in question; it shows the masked request and the full gateway response, with the transaction key and the card nonce masked. For E00007, the credentials don't match the mode (sandbox credentials only work in Sandbox mode). For "Your card details did not arrive securely", JavaScript didn't run on the payment page; check the browser console.
