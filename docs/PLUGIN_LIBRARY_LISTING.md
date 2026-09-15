# Plugins Library listing text

Not yet listed. Paste into the Zen Cart Plugins Library submission form,
category **Payment Modules**. The form takes Markdown. Not part of the
release package.

The Plugin ID arrives on acceptance: put it in the manifest, rebuild, run
the suite, commit, push, re-upload the zip. The listing's version string
must match the manifest exactly, `v` included, or nobody is notified of
updates.

---

## Title

Authorize.Net Accept.js Payments

## Short description

Card payments through Authorize.Net's current JSON API with Accept.js
tokenization, as a drop-in successor to the Authorize.net AIM module that
ships with Zen Cart. The card number never touches your server. Refund,
capture and void from the order page. Encapsulated; Zen Cart 1.5.8 through
3.0.0 and PHP 7.4 through 8.5 from one codebase.

## Description

**Why.** Authorize.Net has retired the AIM and SIM methods that Zen Cart's
built-in modules use. They still answer today, with no shutdown date
announced, but they get no new features, and the current API is the only
place Authorize.Net accepts a tokenized card. This module moves a store onto
that API without changing what the customer sees.

**What the customer sees.** The same card fields on the same payment page.
Underneath, the card number and security code inputs carry no `name`
attribute, so a form post can never contain them. When the customer clicks
Continue, Authorize.Net's own Accept.js script turns the card into a
one-time nonce in the browser, and that nonce is what your server sends to
the gateway. If the script can't run at all, the order is refused rather
than a bare card number being accepted.

**What the store owner gets.**

- Authorize-only or authorize-and-capture, with separate order statuses for
  paid, authorized-but-uncaptured, refunded and held-for-review orders.
- On the order page: the transaction history for the order, plus refund,
  capture and void forms that use Zen Cart's own order-page actions.
- Line items, tax and shipping sent with each transaction; gateway receipt
  emails if you want them; a duplicate-transaction window.
- Sandbox, test-request and Production modes. The module starts in Sandbox,
  so nothing is charged until you say so.
- Works with the standard checkout, the PA-DSS AJAX confirmation and One
  Page Checkout.
- Settings survive Modules > Payment > Remove: the next Install puts them
  back, credentials included.
- Notifier seams for add-ons at every step: the payment block, the request
  before it's sent, the result after, and the admin actions.

**Encapsulated.** One folder under `zc_plugins/`, installed from Plugin
Manager, then enabled under Modules > Payment exactly like a core payment
module. Eighteen settings. Its own transaction table, kept on uninstall
because it holds your payment history. On Zen Cart 1.5.8 through 2.0.x,
which look for a payment module only in the core folders, two small bridge
files from the package go into `includes/`; from 2.1.0 on nothing but the
plugin folder is uploaded.

**Runs on Zen Cart 1.5.8, 2.0, 2.1, 2.2, 2.3 and 3.0.0-dev, PHP 7.4 through
8.5, from a single codebase**, verified against all six release branches and
exercised against the Authorize.Net sandbox from a live store.

**You need** an Authorize.Net account (a paid service with a merchant
account behind it; their sandbox is free for testing), a store served over
https, and PHP with curl and json.

### Credentials

API Login ID, Transaction Key and Public Client Key, all from the Merchant
Interface under Account > Settings > Security Settings > General Security
Settings. The readme walks through it.

### Not included

Apple Pay, Google Pay and card on file. Those need customer profiles and
wallet registration and are the subject of a separate Pro edition built on
this module's notifier seams.

## Links

- GitHub: https://github.com/dbltoe/AuthorizeNet_Accept_Payments
- Support thread: https://www.zen-cart.com/threads/207341?page=1#post-1347112

## Version and compatibility fields

- Version: v1.0.1
- Zen Cart versions: v158, v200, v210, v220, v230, v300
- PHP: 7.4 through 8.5
- License: GPL-2.0
