# Authorize.Net Accept.js Payments for Zen Cart

Card payments through Authorize.Net's current JSON API with Accept.js
tokenization, as a drop-in successor to the Authorize.net AIM module that
ships with Zen Cart. The customer sees the same card fields on the same
payment page; underneath, the card number and security code are tokenized in
the browser and never reach your server.

Zen Cart 1.5.8 through 3.0.0, PHP 7.4 through 8.5, from one codebase. Free,
GPL-2.0. On 1.5.8 through 2.0.x two small bridge files go into the core
folders as well (docs/INSTALL.md).

## Why

Authorize.Net has retired the AIM and SIM methods that Zen Cart's built-in
modules use. They still answer today, with no shutdown date announced, but
they get no new features, and the current API is the only place Authorize.Net
accepts a tokenized card. This module moves a store onto that API without
changing what the customer sees.

## What you get

- Accept.js card entry: the card number and CVV inputs have no `name`, so a
  form post can never carry them. If the script can't run, the server refuses
  the order rather than accepting a bare card number.
- Authorize-only or authorize-and-capture, with separate order statuses for
  paid, authorized-but-uncaptured, refunded and held-for-review orders.
- On the order page: the transaction history for the order, and refund,
  capture and void forms that use Zen Cart's own order-page actions.
- Line items, tax and shipping detail sent with the transaction; receipt
  emails from the gateway if you want them; a duplicate-transaction window.
- Sandbox, test-request and production modes.
- Works with the standard checkout and with One Page Checkout.
- Notifier seams for add-ons (see docs/CUSTOMIZING.md). The Pro edition,
  which adds Apple Pay, Google Pay and card on file, is built on them.

## Install

1. Upload the `zc_plugins/AuthorizeNetAccept` folder to your store's
   `zc_plugins` directory. On Zen Cart 1.5.8 through 2.0.x, also upload the
   `includes` folder from the package's `for_zen_cart_1.5.8_to_2.0.x` folder.
2. Admin > Modules > Plugin Manager: install **Authorize.Net Accept.js
   Payments**. This creates the transaction table.
3. Admin > Modules > Payment: install **Authorize.net (Accept.js)** and enter
   the three credentials.

Details in docs/INSTALL.md and the readme.html inside the plugin folder,
which the Plugin Manager links to.

## Credentials

From the Merchant Interface (or the sandbox's):
Account > Settings > Security Settings > General Security Settings

- **API Credentials & Keys**: the API Login ID, and a Transaction Key.
- **Manage Public Client Key**: the Public Client Key that Accept.js uses in
  the browser. It's public by design and can't run transactions.

A sandbox account is free at
https://developer.authorize.net/hello_world/sandbox.html and is the right
place to try the module first: set Transaction Mode to Sandbox and use the
sandbox account's credentials.

## Testing

Everything in `tests/` runs without a web server or a database:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tests\run_all.ps1
```

That lints every shipped file and runs every harness on each PHP build it
can find (7.4 through 8.5). The gateway is a fake transport in the harness;
the real sandbox is exercised from a Zen Cart install.

## Support

Open an issue on GitHub, or post in the plugin's support thread on the Zen
Cart forum once it exists (the link is in the Plugin Manager panel).

## License

GPL-2.0. Copyright (c) 2026 My Zen Cart Host (dbltoe).
