phpqrcode.php in this directory is a vendored copy of t0k4rt/phpqrcode
(https://github.com/t0k4rt/phpqrcode), originally by Dominik Dzienia.

License: LGPL-3.0-or-later (compatible with this plugin's GPL-2.0-or-later).

This file is loaded only on demand (require_once) inside the QR meta box
and the PNG download endpoint. It is not autoloaded for normal page loads.

To update: replace phpqrcode.php with a newer release from the upstream
repository. No build step required — it's a single file.
