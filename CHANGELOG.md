# Changelog

All notable changes to SubjectsPlus are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).
History up to the original 4.6.0 code base (July 2021) is in [docs/history/CHANGELOG-upstream.md](docs/history/CHANGELOG-upstream.md).

## [Unreleased]

## [4.7.1] - 2026-11-12

Installer, records, administration and security improvements. Upgrading from 4.7.0 (or any 4.x): copy the new
files over the old ones, sign in as an administrator and follow **Admin → Upgrade** (backup, then 6 database
updates). Add `php tools/workflow-daily.php` to cron if it is not there yet: it now also runs the link checker.

### Security
- **High — reflected XSS on the public search page** (`subjects/search.php?searchterm=…`, also the staff search):
  the search box echoed the term into its `value` attribute after `scrubData()`, which turns `&quot;` back into
  `"`, so `x" autofocus onfocus="…` ran script. All search-box attributes are now escaped.
- **High — reflected XSS in the TalkBack form** (all themes): the comment and email were echoed raw into the form
  after a failed submission; a cross-site POST could run script. Values are escaped (and the comment is now put
  inside the textarea).
- **High — SQL injection in the REST API** `database/letter/…` parameter (needed a valid key): pasted between
  quotes. Now quoted; the `ONLY_FULL_GROUP_BY` error in the same query (subjects list) is fixed too.
- **Per-guide permissions enforced in every guide-editor helper** (`save_guide`, `save_tab`, `save_tab_order`,
  `save_section`, `update_section_layout`, `section_data`, `guide_data`, `ajax_mod`, `metadata`): staff without
  *admin* or *allguides* can only change guides they are an editor of (4.7.0 only checked the guide page itself).
- Box type names posted to the editor are reduced to letters and digits before a class is loaded (could name a
  path for the autoloader); guide and box ids are always integers; tab order ids are cast.
- **Third-party scripts removed** from the SP-Lux theme (modernizr and js-offcanvas were loaded from another
  library's web site) and Google's reCAPTCHA script is only loaded on TalkBack when reCAPTCHA is switched on.
- `changeMe()` / `lastModded()` (change history) use prepared statements.
- LibGuides exports are no longer served from `control/guides/lgimporter/` (`.htaccess` and Nginx rule).

### Added
- **Installer starts from the site root**: opening `https://your-site/` on a new installation goes straight to the
  wizard. If no install code exists yet, the wizard **creates one** in `control/includes/install-code.php` and
  says where to read it (no command line needed).
- **Installer switches itself off for good** after a successful installation: it answers 404 and runs no code,
  whether or not `control/installer/` is deleted; the "remove the installer" warning is gone.
- **Vendor / Publisher** on every record (record form, with suggestions), in the public A–Z list (shown under each
  database, *By vendor* list and dropdown, found by the A–Z search), the REST API (`vendor` field and
  `…/database/vendor/NAME`), Excel / CSV import-export (`Vendor / Publisher`, KBART `publisher_name`) and the staff
  search.
- **Records → Find & Bulk Edit**: search title, alternate title, vendor, URL or ID; filter by vendor, status, format,
  access, A–Z display and subject; change the ticked records at once (status through the workflow rules, vendor,
  format, access restriction, show / hide in the A–Z list, add to a subject, delete for e-resource managers).
- **Admin → Record Settings → User types**: add, rename, describe, order and delete user types (Staff, Machine,
  Student …); choose which types appear in the public staff directory, the staff API and OAI (was type 1 only).
- **Admin → Audit Log**: sign-ins (and failures), sign-outs, permission changes, user / record / guide / FAQ /
  TalkBack / video changes, settings saved on any Admin page (field names only, never values), bulk edits, API
  keys, backups and upgrades. Filters, CSV download, retention `$sp_audit_retention_days` (default 730 days).
- **Admin → API Clients**: one key per application (only a hash is stored; the key is shown once), its own
  rate limit and allowed services, enable / disable / delete, usage for the last 30 days.
- **REST API**: keys accepted in the `X-API-Key` or `Authorization: Bearer` header (URLs end up in logs); rate
  limits (`$api_rate_limit` per key, default 120/min; `$api_rate_limit_failed` per IP for wrong keys, default
  20/min) with `X-RateLimit-*` headers and 429 + `Retry-After`; JSON errors with proper status codes (401, 403, 404,
  405, 429, 503); CORS for the sites in `$api_cors_origins`; usage counts.
- **Admin → Usage Statistics** (replaces the old page, which failed): any date range or 7 / 30 / 90 / 365 days;
  guide views, tab, link and database clicks; daily chart; top guides, databases (with vendor), vendors, other
  links and tabs; REST API requests; CSV downloads.
- **Admin → System Health**: versions (with PHP security-support dates), database updates, size and time zone,
  PHP settings and folders, web-server protection, installer state, leftover files, default salt, daily task and
  link-checker heartbeats, email (with a test message), API and Content-Security-Policy settings, number of admins.
- **Admin → Upgrade** (web upgrade wizard): what changed (from CHANGELOG.md), server check, **database backup
  download** (gzip SQL, pure PHP, optional without statistics rows), apply the database updates and record the
  version; the admin banner now links here.
- **Scheduled link checker** (`tools/link-check.php`, also run by the daily task): checks the links of public
  guides and A–Z records in batches about once a week, politely (one request per second per site, private
  addresses never requested); **Guides → Guide Health → Broken links** lists links that failed twice in a row, per
  guide or record, with *Check again* and *Ignore*.
- **Stricter Content-Security-Policy** (`$sp_csp = "strict"`, or `"report-only"` to try it first): scripts, styles,
  fonts, connections and plug-ins only from the site and `$sp_csp_allow` (Google Analytics / reCAPTCHA added
  automatically when configured). Inline scripts are still allowed (SubjectsPlus 4 needs them). Tested with no
  violations on the public pages and the main staff pages.
- **Signed releases**: the release workflow adds a GitHub build-provenance attestation (Sigstore) for the zip,
  tar.gz and SHA256SUMS.txt (`gh attestation verify …`) and a CycloneDX software bill of materials
  (`build/sbom.php`).
- **Static analysis**: PHPStan level 1 (`phpstan.neon.dist`) with a baseline of the problems already present, run
  in CI so new code is checked; Dependabot for Composer, npm and GitHub Actions; PHP 8.5 in the lint matrices; a
  second browser-test job (Nginx + PHP 8.4-FPM + MySQL 8.4, database created by the wizard).

### Changed
- **Accessibility of the public pages (WCAG 2.2 AA)**: skip link, visible keyboard focus, zoom no longer blocked
  (SP-Lux), accessible names for every dropdown and the search box, labelled TalkBack fields, links that open a new
  window say so, icon-only "more information" toggles are keyboard buttons, decorative icons hidden from screen
  readers, footer / letter / button contrast fixed (SP-Lux turquoise darkened), reduced motion respected. The
  default theme has **no axe-core violations** on the home, A–Z, guide, staff, FAQ, TalkBack and search pages.
- The A–Z dropdowns use a script file instead of inline `onChange` handlers.
- Usage statistics: known crawlers are not counted and visitor IP addresses are shortened before they are stored
  (`$stats_skip_bots`, `$stats_anonymise_ip`).
- Installer: when the wizard creates the database user, it also allows the address the database server actually
  sees (Docker, port forwarding), so the new user can connect.
- `$sp_version` is now set for every page (the installer lock and some pages had no version).

### Fixed
- Staff email that failed with a fatal error: "recommend delete" for records and FAQs, the guide link report, the
  TalkBack FAQ suggestion (new `sp_send_mail()` helper, uses the SMTP user and password too).
- TalkBack ignored the email address people typed (field name mismatch), so every comment was "Anonymous".
- Record form: with the plain-text editor, *Internal notes* overwrote the description; title fields are escaped.
- Deleting a box in the guide editor: PHP 8 `count()` TypeError and a wrong table alias.
- Old usage-statistics class: a no-op `(int)` cast and an `ONLY_FULL_GROUP_BY` error.
- LibGuides importer: a clear message instead of HTTP 500 when no export has been uploaded; `$libguides_xml_path`
  and `$libguides_url` settings (links pointed at one university's LibGuides site).
- Upstream e-mail templates and an "Ask a Librarian" link pointed at another university's servers.
- Staff records list joined the `source` table without a condition (slow, and empty when `source` was empty).

### Removed
- Unused, broken classes `Databases\AzList*` and `AllHandler`.

## [4.7.0] - 2026-11-12

A guided, safer installation. Existing sites upgrade as usual (INSTALL.md §7); no database update. Includes the 4.6.1 `.htaccess` fix.

### Added
- **Installer wizard** (`control/installer/`) replacing `control/setup-config.php` and `control/install.php`:
  install code → requirements → database → site & administrator → options → install.
- **One-time install code** (`php tools/install-code.php`, a file you create yourself, or `SP_INSTALL_CODE`):
  nobody can install the site without access to the server. The code is deleted after installation.
- **Requirements check**: PHP version and extensions, writable folders, upload limit, `display_errors`, HTTPS,
  and a live test that configuration and code folders are not downloadable (shipped `.htaccess` / Nginx rules
  active). Each problem shows its fix; red items block the installation.
- **Create the database and user from the wizard** with a database administrator account that is used once and
  never stored: `utf8mb4` database, a user with rights on it only, strong generated password, connection test.
- **Administrator chooses the password** (12+ characters, strength meter, common-password check) instead of a
  generated password shown once.
- **config.php without a writable folder**: when `control/includes/` is read-only the wizard offers
  `config.php` for download and checks the uploaded copy. Values are written with `var_export()`, so passwords
  containing `$ " \ '` work (the 4.x writer broke on them).
- **Secrets outside config.php**: `SP_DB_*`, `SP_SALT`, `SP_ENCRYPTION_KEY`, `SP_API_KEY`, `SP_SMTP_PASSWORD`
  from environment variables or a `.env` file outside the web root.
- **Site address detection behind proxies** (`X-Forwarded-Proto`, `X-Forwarded-Host`, trusted only from
  private/loopback addresses), editable, and **Always use HTTPS** (`$sp_force_https`).
- **Email test** (SMTP) and **off-campus access provider** set-up in the wizard.
- **Sample content** (optional): public guide, hidden course-guide template, three open-access databases, an FAQ.
- **Installer locks itself** after a successful run (`control/includes/.installed`, HTTP 404) and admins see a
  reminder until the installer files are removed.
- Browser tests for the wizard (code, requirements, create / existing database, read-only config download,
  password rules, lock-out, sample content).

### Changed
- `/control/` on a site without `config.php` opens the wizard (was an error page / the old installer).
- The guide editor's side panel now sits above the fixed guide header, so the top of the Boxes / Layouts /
  Analytics / Check panels is no longer hidden when an admin notice pushes the header down.

### Removed
- `control/setup-config.php` and `control/install.php` (superseded by the wizard).

### Fixed
- `build/package-release.sh` is now stored as executable in Git (mode `100755`); in 4.6.1 it was still
  `100644`, so `release.yml` could not run it directly.

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

[Unreleased]: https://github.com/abidfakhrealam/SubjectsPlus/compare/v4.7.1...HEAD
[4.7.1]: https://github.com/abidfakhrealam/SubjectsPlus/releases/tag/v4.7.1
[4.7.0]: https://github.com/abidfakhrealam/SubjectsPlus/releases/tag/v4.7.0
[4.6.1]: https://github.com/abidfakhrealam/SubjectsPlus/releases/tag/v4.6.1
[4.6.0]: https://github.com/abidfakhrealam/SubjectsPlus/releases/tag/v4.6.0
