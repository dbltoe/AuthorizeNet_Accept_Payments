# Changelog

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
