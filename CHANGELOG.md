# Changelog

All notable changes to SubjectsPlus are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).
History up to the original 4.6.0 code base (July 2021) is in [docs/history/CHANGELOG-upstream.md](docs/history/CHANGELOG-upstream.md).

## [Unreleased]

## [4.6.1] - 2026-11-12

### Fixed
- **Apache 2.4 `.htaccess` compatibility (`assets/users/.htaccess`, `assets/cache/.htaccess`):** Replaced `php_admin_flag engine off` with `php_flag engine off` inside `<IfModule mod_php.c>`, resolving Apache HTTP 500 Internal Server Errors when serving uploaded headshots and cached assets under `mod_php`.
- **Release packaging permissions (`build/package-release.sh`):** Set executable (`100755`) file mode in Git so Linux CI runners execute the release packaging script directly without permission errors.

## [4.6.0] - 2026-11-12

Maintained, institution-neutral release of SubjectsPlus 4.6. Runs on PHP 8.1 – 8.4, MariaDB 10.11 and
MySQL 8.0 / 8.4 in strict SQL mode, behind Apache 2.4 or Nginx. Upgrading from any 4.x release: see
[INSTALL.md §7](INSTALL.md#7-upgrading-an-existing-4x-site). The full audit is in
[docs/AUDIT-REPORT.md](docs/AUDIT-REPORT.md).

### Security

Found and fixed in the 4.6.0 release audit:

- **Critical — privilege escalation:** any signed-in staff member could make themselves an administrator
  (`control/admin/admin_bits.php` `update_permissions` had no role check; `control/admin/profile.php`
  trusted the posted `staff_id`, `ptags`, `user_type_id` and `active`, so it could also rewrite other
  people's accounts). Both are now limited to administrators / the signed-in person's own record.
- **Critical — role checks did not stop the page:** `control/includes/header.php` redirected non-admin staff
  away from admin pages but kept executing the page (no `exit`), so admin actions in the page body still ran.
- **Critical — unauthenticated upload + SQL injection:** `control/includes/asset_manager_upload.php` accepted
  files from anyone and put the posted `staff_id` straight into SQL. Now staff-only, images only, the
  caller's own folder.
- **High — SSRF / local file access:** `subjects/includes/feedme.php` (public) fetched any URL or local path
  (`file://`, `php://`, internal addresses) and printed the result unescaped; the public Book List helper
  (`subjects/bookListPlusletActionRouter.php`) fetched any `url=` with TLS checks off and used `isbn=` in file
  paths. New `sp_http_get()` allows only public http(s) hosts (DNS pinned, redirects re-checked, size and time
  limits); ISBNs are validated; covers must be images; public visitors can only fill an empty cache entry for
  an ISBN used in a Book List box; feed output is escaped / purified.
- **High — SQL injection:** public guide rendering (`?subject=` in `Pluslet.php`, `TextTokenizer.php`,
  `functions.php`), `findDescOverride()`, the REST API (`email`, `type`, `shortform`, `search`, dates, `max`),
  and staff pages `admin_bits.php`, `ajax_mod.php`, `guide_bits.php`, `talkback/index.php`,
  `talkback/tb_bits.php`, `export_contacts.php`. All parameterised, quoted or cast.
- **High — data exposure:** `control/includes/autocomplete_data.php` returned hidden guides, box contents and
  staff details to anyone; now staff-only.
- **High — CKEditor uploader:** accepted `x.php.jpg`, HTML, SVG and fake images and trusted any session with an
  email in it. Now requires a full staff session, checks images with `getimagesize()`, refuses executable /
  active-content extensions and cross-site posts.
- **Medium — CSRF:** cross-site requests are refused on every staff helper script (all methods) and on every
  POST to a staff page (`sp_require_same_origin()`, Fetch-Metadata / Origin / Referer); the email + password
  login form and SAML return now require a token / pending sign-in.
- **Medium — open redirect after login** (`//evil.example/…` in `desired_page`); **session fixation**
  (`session_regenerate_id()` kept the old session alive).
- **Medium — LTI launch accepted unsigned requests** (`$lti->valid` was never checked); `coursesCodeImport.php`
  ran from a browser.
- **Medium — path traversal:** guide thumbnails (`metadata.php` `shortform` / `old-shortform` → delete or move
  any `.jpg`); folder names built from staff emails.
- **Medium — REST API key:** loose comparison, an empty key matched an empty key, and the shipped default key
  was public. Now `hash_equals()`, empty key = API refuses, service names restricted to letters.
- **Low:** leaked third-party reCAPTCHA keys in comments removed; problem-report form verified reCAPTCHA with
  the TalkBack key; reCAPTCHA request values were not URL-encoded; theme CSS name from the database used
  unescaped in a `<link>`; record files in public fields downloadable for non-public records; SAML email
  attribute could name a browser-supplied `HTTP_*` variable; LTI error messages echoed to the browser.
- **Hardening shipped with the release:** `.htaccess` set (root, `lib/`, `tools/`, `assets/users`,
  `assets/cache`, `assets/records`, `control/lti_controller`, `api/`) and an equivalent hardened Nginx server
  block; security headers (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
  `Permissions-Policy`, `Cross-Origin-Opener-Policy`, a baseline `Content-Security-Policy`).

Carried over from the 4.6 maintenance releases (already in production before this release): bcrypt
passwords with transparent MD5 upgrade, login throttling, AES-256-GCM for optional staff data, sign-in
required on 27 editor helper scripts, CKEditor 4.22.1, jQuery 3.7.1 / jQuery UI 1.13.3, PHPMailer 6.12,
HTMLPurifier 4.19, front-end libraries served locally, `random_int()` for generated secrets, XSS fixes in
the CKEditor callback and login form.

### Added
- Release tooling: `build/package-release.sh` and `build/package-release.ps1` (zip + tar.gz + `SHA256SUMS.txt`),
  `build/build-assets.php` (compiles and verifies the CSS/JS bundles), GitHub Actions `ci.yml` and `release.yml`.
- Real-browser test suite `tests/e2e/sp-e2e.cjs` (Playwright; desktop 1440×900 and mobile 375×812): installer,
  database updates, sign-in, throttling, admin pages, guide editor (drag & drop, layouts, flyouts, CKEditor,
  accessibility check), records, off-campus access, workflow, user management, public pages, and HTTP-level
  security regression checks.
- `control/includes/security.php`: `sp_h()`, `sp_require_same_origin()`, `sp_require_role()`, `sp_int()`,
  `sp_int_list()`, `sp_safe_filename()`, `sp_isbn()`, `sp_http_get()` (SSRF-safe fetch).
- Nginx and Apache virtual-host examples in `docs/server/`; `INSTALL.md`, `SECURITY.md`, `docs/AUDIT-REPORT.md`.
- `$sp_academic_year_start_month` (usage-report "academic year" shortcuts; default July).
- The installer now applies all database updates at the end of a fresh install.

### Changed
- Institution-neutral release: package `subjectsplus/subjectsplus` 4.6.0; neutral wording ("Guide templates",
  accreditation / statistics returns, `library.edu` placeholders); the public language switcher defaults to
  English only (the bundled Hindi translation is one setting away).
- `Querier::prepareStatement()` binds values with `bindValue()` (it bound every placeholder to the last value).
- Admin CSS on the setup / error pages is loaded through `css.php` (fonts and images resolve).

### Fixed
- **MySQL 8.4 / MariaDB strict mode (`ONLY_FULL_GROUP_BY`):** record default source, FAQ browse by subject, FAQ
  collections, staff details, "experts" on the home page and box, top-links statistics and the LTI statistics
  API — in the core and the bundled themes.
- PHP 8: `scrubData(…, 'integer')` threw a `TypeError` for non-numeric input; `Querier::fetch()` and
  `num_rows()` called methods that do not exist; `uploader2()` passed a non-variable by reference;
  `Autocomplete` included `functions.php` a second time (fatal "cannot redeclare"); undefined-index warnings
  in the staff form; implicit nullable parameters (PHP 8.4).
- Guide editor: CKEditor kept polling a removed iframe after every save (`Cannot read properties of undefined
  (reading 'getSelection')`); public "Suggest an e-resource" page used jQuery without loading it; setup page
  loaded three scripts from wrong paths; `guide_bits.php` used `Querier` without importing it; the public
  Book List cache wrote outside the web root (covers were never cached); department page links were
  hard-coded to one institution's website.

### Removed
- `api/adddisciplinedescription.php` (unauthenticated development script that overwrote every guide description).
- Unused copy `control/includes/PGFeed.php`, the dead `tablesorter` jQuery option (its files were never shipped),
  institution-specific documents, sample thumbnails and private Git submodules.

## Earlier 4.6 maintenance releases

The 4.6 maintenance line (October 2026) brought PHP 8.1 – 8.4 and MySQL 8 support and the features listed
under *Added* in the README: managed record settings and custom fields, record workflow, multiple
off-campus access providers, staff single sign-on (OIDC, SAML, SIP2), branding, 11 guide layouts and side
tabs, Excel / CSV / KBART import & export with usage reports, box show/hide dates, guide templates, per-guide
analytics, review reminders, the editor accessibility check and public-interface translations. Those
releases are folded into 4.6.0.

[Unreleased]: https://github.com/abidfakhrealam/SubjectsPlus/compare/v4.6.1...HEAD
[4.6.1]: https://github.com/abidfakhrealam/SubjectsPlus/releases/tag/v4.6.1
[4.6.0]: https://github.com/abidfakhrealam/SubjectsPlus/releases/tag/v4.6.0
