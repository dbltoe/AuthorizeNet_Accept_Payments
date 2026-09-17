# Installing Authorize.Net Accept.js Payments

## Before you start

- Zen Cart 1.5.8 or later. On 1.5.8 through 2.0.x two extra files are
  uploaded in step 1; from 2.1.0 the plugin folder is all there is.
- PHP 7.4 or later with the curl and json extensions (every host has them).
- A live Authorize.Net account for real payments, or a free sandbox account
  for testing: https://developer.authorize.net/hello_world/sandbox.html
  The live account is a paid service (a monthly gateway fee plus a fee per
  transaction) with a merchant account behind it; see "Going live" below.
- Your store served over https. In Production mode the module hides itself
  on a page that isn't.

## Step 1: upload

Upload the `zc_plugins/AuthorizeNetAccept` folder from the package into your
store's `zc_plugins` directory, so that you have
`zc_plugins/AuthorizeNetAccept/v1.0.2/manifest.php`.

On Zen Cart 2.1.0 and later nothing else needs uploading. There are no core
files to overwrite and no template files to merge.

On Zen Cart 1.5.8 through 2.0.x, also upload the `includes` folder from the
package's `for_zen_cart_1.5.8_to_2.0.x` folder. Those releases look for a
payment module only in `includes/modules/payment`, so two small files there
hand off to the plugin:

- `includes/modules/payment/authorizenet_accept.php`
- `includes/languages/english/modules/payment/lang.authorizenet_accept.php`

Nothing in core is edited. Without them the Plugin Manager refuses the
install and names them. From 2.1.0 on they're ignored, so they can stay
through an upgrade.

## Step 2: Plugin Manager

Admin > Modules > Plugin Manager. Find **Authorize.Net Accept.js Payments**
and click Install. This creates the table that keeps the transaction history
and checks the release, PHP and curl.

## Step 3: the payment module

Admin > Modules > Payment. Find **Authorize.net (Accept.js)** and click
Install, then Edit, and fill in:

- **API Login ID** and **Transaction Key**: Merchant Interface > Account >
  Settings > Security Settings > General Security Settings > API Credentials
  & Keys. Generating a new Transaction Key there retires the old one within
  24 hours, so update the module at the same time.
- **Public Client Key**: Manage Public Client Key on the same page. If none
  exists yet, answer the security question to create one.
- **Transaction Mode**: Sandbox with sandbox credentials while you test.

The rest of the settings are explained in CONFIGURATION.md.

## Step 4: try it

Place an order with a sandbox test card (the numbers are shown on the
module's settings page while it's in Sandbox mode). Then open the order in
the admin: the transaction history and the refund, capture and void forms
are at the bottom of the order page.

## Going live

Real payments need a live Authorize.Net account, and the sandbox doesn't
stand in for one. It's a paid service: a monthly gateway fee plus a fee on
each transaction, with a merchant account behind it to settle the money into
your bank. Current rates and the sign-up form are at
https://www.authorize.net/sign-up/pricing.html. Choose Gateway Only if you
already have a merchant account with your bank or card processor, or
All-in-One if you want Authorize.Net to supply that too. Accounts resold by
banks and merchant services providers work the same way.

Collect the live account's three credentials from account.authorize.net (they
differ from the sandbox's), switch Transaction Mode to Production and enter
them. Consider a small real purchase, then void it from the order page before
the daily settlement.

## Moving from the AIM module

Install this module alongside the AIM module, test it, then remove AIM under
Modules > Payment. Orders paid through AIM keep their history; the old
module's order-page tools go with it, so capture or refund any open AIM
transactions before you remove it, or do so in the Merchant Interface.

## Remove and uninstall

Modules > Payment > Remove takes the module off the checkout page but keeps a
copy of its settings, credentials included, so the next Install restores them
and says so. The copy is used once and deleted.

Plugin Manager > Uninstall removes the module if you skipped that step,
forgets the saved settings, and unregisters the plugin. The transaction table
is kept on purpose: it holds your payment history. Drop `authorizenet_accept`
yourself if you're sure you don't need it.

On Zen Cart 1.5.8 through 2.0.x, delete the two bridge files from step 1 as
well if you're not reinstalling. Left behind without the plugin, they only
show a placeholder row under Modules > Payment that says what to delete.
