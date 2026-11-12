# Local patches to Composer libraries

`lib/vendor` is committed, so these patches are already applied. If you run `composer update`
and the library has not fixed the issue itself, re-apply with:

    patch -p1 < tools/vendor-patches/<file>.patch

| Patch | Why |
|---|---|
| `openid-connect-php-1.0.2-php84-nullable.patch` | jumbojett/openid-connect-php 1.0.2 (latest release) uses implicitly nullable parameters, deprecated in PHP 8.4. Adds `?` to the types; no behaviour change. |
