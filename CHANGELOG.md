# Changelog

## v1.0.3 (2026-10-06)

- Plugin ID is now 2459, the ID the Plugins Library assigned on 6 October
  2026 (the listing's earlier ID 2261 belonged to another plugin), so update
  notices in Plugin Manager now report this plugin.
- No payment, code or settings changes. Upgrading is optional and only
  matters for update notices.
- Upgrading: put the `v1.0.3` folder beside the old one and click Upgrade in
  Plugin Manager; Modules > Payment keeps its settings and credentials. On
  Zen Cart 2.2.0 and later the next Plugin Manager visit records the new ID.
  Zen Cart 1.5.8 through 2.1.x record the ID only when the plugin is first
  registered, so an Upgrade there doesn't change it, and upgrading isn't
  required: run this one line in Tools > Install SQL Patches (it adds your
  table prefix), or in phpMyAdmin with your prefix added:
  `UPDATE plugin_control SET zc_contrib_id = 2459 WHERE unique_key = 'AuthorizeNetAccept';`
  Don't remove the plugin folder to force the ID; on a live store that can
  take the payment module off the checkout.

## v1.0.2 (2026-09-16)

- The invoice number sent to the gateway now predicts the next order number
  the way core's AIM module does, the highest order id plus one, instead of
  reading the orders table's counter from SHOW TABLE STATUS. On MySQL 8.0 and
  later that value comes from the information_schema statistics cache, which
  refreshes once a day by default, so the same base number repeated across a
  day's orders (reported by chadlly2003 in the support thread). MariaDB reads
  it live, which is why our own test stores never showed it. The random suffix
  kept every invoice unique, so no transaction was affected; only the number
  was misleading.
- Nothing else changes: no settings change, no database change; card payments
  and the wallet hook are as in 1.0.1.

## v1.0.1 (2026-09-15)

- Wallet hook in the checkout script: `window.authorizenet_accept.setWalletToken()`
  for add-ons (Apple Pay, Google Pay, anything the descriptor whitelist
  allows). The script fills the hidden carriers, selects the module, keeps
  the token through a One Page Checkout re-render, and lets the order through
  without card validation or an Accept.js call. The 1.0.0 script only let its
  own nonce through, which stopped third-party wallet tokens on the standard
  checkout with the card-number message.
- The script config now carries the order total, currency, mode and the
  Accept descriptor for add-ons; a `authorizenet_accept:ready` event fires on
  each render.
- Nothing changes for card payments.

## v1.0.0 (2026-09-13)

First release.

- Card payments through Authorize.Net's JSON API (`createTransactionRequest`)
  with Accept.js tokenization. The card number and CVV are never posted to
  the store: the inputs carry no name, and a one-time nonce goes in their place.
- Authorize-only or authorize-and-capture; held-for-review handling with its
  own order status; gateway receipt emails; duplicate-transaction window;
  line items, tax and shipping detail; currency conversion to the gateway
  currency.
- Order page: a transaction history for the order, plus refund, capture and
  void that reuse Zen Cart's own order-page actions.
- Notifier seams for add-ons (docs/CUSTOMIZING.md).
- Works with the standard checkout and with One Page Checkout.
- Zen Cart 1.5.8 through 3.0.0, PHP 7.4 through 8.5, from one codebase;
  1.5.8 through 2.0.x add two bridge files in the core folders.
