# Installing Authorize.Net Accept.js Payments

## Before you start

- Zen Cart 2.1.0 or later. Earlier releases can't load a payment module
  from a plugin; the Plugin Manager will refuse the install and say so.
- PHP 7.4 or later with the curl and json extensions (every host has them).
- An Authorize.Net merchant account, or a free sandbox account for testing:
  https://developer.authorize.net/hello_world/sandbox.html
- Your store served over https. In Production mode the module hides itself
  on a page that isn't.

## Step 1: upload

Upload the `zc_plugins/AuthorizeNetAccept` folder from the package into your
store's `zc_plugins` directory, so that you have
`zc_plugins/AuthorizeNetAccept/v1.0.0/manifest.php`.

Nothing else in the package needs uploading. There are no core files to
overwrite and no template files to merge.

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

Switch Transaction Mode to Production and enter the live account's three
credentials. Consider a small real purchase, then void it from the order page
before the daily settlement.

## Moving from the AIM module

Install this module alongside the AIM module, test it, then remove AIM under
Modules > Payment. Orders paid through AIM keep their history; the old
module's order-page tools go with it, so capture or refund any open AIM
transactions before you remove it, or do so in the Merchant Interface.

## Uninstall

Modules > Payment > Remove takes the module's settings away. Plugin Manager >
Uninstall does that too if you skipped it, and removes the plugin files'
registration. The transaction table is kept on purpose: it holds your payment
history. Drop `authorizenet_accept` yourself if you're sure you don't need it.
