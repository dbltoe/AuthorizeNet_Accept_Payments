Authorize.Net Accept.js Payments -- files for Zen Cart 1.5.8 through 2.0.x

Zen Cart 2.1.0 and later load a payment module straight from zc_plugins and
need nothing from this folder.

Zen Cart 1.5.8, 1.5.8a, 2.0.0 and 2.0.1 look for a payment module only in
includes/modules/payment, so on those releases upload the "includes" folder
from here to the store as well, alongside the zc_plugins folder. It adds two
small files that hand off to the plugin:

  includes/modules/payment/authorizenet_accept.php
  includes/languages/english/modules/payment/lang.authorizenet_accept.php

No core file is changed. The Plugin Manager refuses the install on those
releases until both files are present, and says so. After an upgrade to
2.1.0 or later the files are ignored and can be deleted or left alone.
