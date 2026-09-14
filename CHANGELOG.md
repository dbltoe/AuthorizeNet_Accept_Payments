# Changelog

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
