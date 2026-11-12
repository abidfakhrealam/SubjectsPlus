# Installing, configuring and upgrading SubjectsPlus 4.7

Contents: [Requirements](#1-requirements) · [New installation](#2-new-installation) ·
[Apache 2.4](#3-apache-24) · [Nginx](#4-nginx) · [PHP settings](#5-php-settings) ·
[Daily task (cron)](#6-daily-task-cron) · [Upgrading](#7-upgrading-an-existing-4x-site) ·
[Checks after installing or upgrading](#8-checks-after-installing-or-upgrading) ·
[Staff sign-in](#9-staff-sign-in-microsoft-google-sip2-saml) · [Off-campus access](#10-off-campus-access) ·
[Record settings and workflow](#11-record-settings-and-workflow) · [Import & export](#12-import--export) ·
[Guides](#13-guide-tools) · [Languages](#14-public-interface-languages) · [Branding](#15-branding) ·
[REST API](#16-rest-api) · [Hardening checklist](#17-hardening-checklist) · [Troubleshooting](#18-troubleshooting)

---

## 1. Requirements

| | Supported and tested |
|---|---|
| PHP | 8.1, 8.2, 8.3, 8.4 — extensions `pdo_mysql`, `mbstring`, `openssl`, `curl`, `gd`, `xml`/`simplexml`, `json`; optional `intl`, `gettext` |
| Database | MariaDB 10.11 (and 11.x), MySQL 8.0 / 8.4 — strict SQL modes are fine |
| Web server | Apache 2.4 with `mod_rewrite` + `mod_headers` (PHP-FPM or mod_php), or Nginx + PHP-FPM |

SubjectsPlus sets the SQL mode of its own connection with `$sp_db_sql_mode` in `config.php`
(default `STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`). Release 4.6.0 is also
tested with `$sp_db_sql_mode = 'server';` under `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,
NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO` (the MySQL 8.4 default), so no server setting needs changing.

Composer is not needed on the server — PHP libraries are shipped in `lib/vendor`. To update them, run
`composer update` on a development machine, test, and commit the result.

## 2. New installation

Installation runs in the browser through the **installer wizard**: on a new site, opening the site address
(`https://library.edu/`, `/control/` or `/subjects/`) starts it. It checks the server, can create the database
for you, lets you choose the administrator password, and switches itself off for good when it has finished.

1. **Files** — unpack the release (verify it first with `sha256sum -c SHA256SUMS.txt`):

   ```sh
   unzip SubjectsPlus-4.7.1-stable.zip -d /var/www
   mv /var/www/SubjectsPlus-4.7.1-stable /var/www/subjectsplus
   cd /var/www/subjectsplus
   chown -R root:root .
   chown -R www-data:www-data assets/cache assets/users assets/records assets/images/guide_thumbs
   ```

   `control/includes/` may stay **read-only** (recommended): the wizard then lets you download `config.php`
   and upload it yourself. If you prefer the wizard to save it, make that folder writable for the installation
   only (`chown www-data control/includes`).

2. **Web server** — configure Apache ([§3](#3-apache-24)) or Nginx ([§4](#4-nginx)).

3. **Wizard** — open `https://library.edu/` (you are sent to `/control/installer/`).

4. **Install code** — the first page asks for a one-time code, so a stranger who finds the site before you
   cannot point it at their own database. Only someone who can read the server's files can get it:
   - if `control/includes/` is writable, the wizard **creates the code itself** in
     `control/includes/install-code.php` and shows that path: open the file on the server (file manager, FTP,
     editor, `cat`) and copy the code between the quotes (the file shows nothing in a browser);
   - or run, in the SubjectsPlus folder, `sudo -u www-data php tools/install-code.php` (prints e.g.
     `K7QM-2HXP-9WRT-4CNA`; Windows / WampServer: `C:\wamp64\bin\php\php8.x\php.exe tools\install-code.php`);
   - or create `control/includes/install-code.php` containing `<?php return 'a-phrase-of-at-least-12-characters';`;
   - containers: set the `SP_INSTALL_CODE` environment variable.

   The code is deleted when the installation finishes. Then follow the steps:

   | Step | What happens |
   |---|---|
   | Install code | Enter the code from step 3 |
   | Requirements | PHP version and extensions, writable folders, `upload_max_filesize`, `display_errors`, HTTPS, and a live test that `control/includes/` and `lib/` are **not** downloadable (i.e. `.htaccess` / the Nginx rules work). Red items block the installation; each shows how to fix it |
   | Database | Either use a database and user you created, or tick **Create the database and user for me** and give a database administrator account (used once, never stored): the wizard creates the `utf8mb4` database, a user with rights on that database only, and a strong generated password. *Test connection* checks the account first |
   | Site & administrator | Site address (detected, also behind a reverse proxy via `X-Forwarded-Proto`/`X-Forwarded-Host`; editable), **Always use HTTPS**, site and library name, and the administrator's name, email and **password of your choice** (12+ characters, strength meter) |
   | Options | SMTP server with a **Send a test message** button, off-campus access (EZproxy / OpenAthens / other link template), and **sample content** |
   | Install | Creates the tables, applies all database updates, writes `config.php` (or offers it for download), deletes the install code and switches the installer off (`control/includes/.installed`) |

   To create the database by hand instead:

   ```sql
   CREATE DATABASE subjectsplus CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'subjectsplus'@'localhost' IDENTIFIED BY 'a-long-random-password';
   GRANT ALL PRIVILEGES ON subjectsplus.* TO 'subjectsplus'@'localhost';
   ```

   MariaDB on Debian/Ubuntu lets `root` sign in only through the local socket, which a web application cannot
   use. For the *create for me* option, make a temporary administrator with a password and drop it afterwards:
   `CREATE USER 'spsetup'@'localhost' IDENTIFIED BY '…'; GRANT ALL ON *.* TO 'spsetup'@'localhost' WITH GRANT OPTION;`

5. **After installing**
   - nothing to remove: the installer answers 404 and runs no code from now on (deleting `control/installer/`
     is optional). To reinstall you would have to delete both `control/includes/config.php` and
     `control/includes/.installed` on the server;
   - keep `control/includes/` read-only: `chown -R root:root control/includes && chown root:www-data control/includes/config.php && chmod 640 control/includes/config.php`;
   - add the [daily task](#6-daily-task-cron);
   - add staff in *Admin → Users*, the domains for your off-campus access provider, and single sign-on if you use it;
   - work through the [hardening checklist](#17-hardening-checklist).

**Sample content** (optional in the wizard): a public guide *Getting Started with Research* (`getting-started`),
a hidden *Course Guide Template*, three open-access databases on the A–Z list (DOAJ, PubMed, Google Scholar)
and an FAQ. Edit or delete them like any other content.

**Secrets outside config.php** (optional): the database password and keys can come from environment variables
or a `.env` file outside the web root (default: the folder above SubjectsPlus; or set `SP_ENV_FILE`):
`SP_DB_HOST`, `SP_DB_PORT`, `SP_DB_NAME`, `SP_DB_USER`, `SP_DB_PASSWORD`, `SP_SALT`, `SP_ENCRYPTION_KEY`,
`SP_API_KEY`, `SP_SMTP_PASSWORD`. Values found there override `config.php`.

**Always use HTTPS** sets `$sp_force_https = TRUE;` (every http:// request is redirected; behind a TLS
proxy on a private address `X-Forwarded-Proto` is honoured) and `$enforce_https_upload_url = TRUE;`.

## 3. Apache 2.4

The release contains `.htaccess` files that block code and configuration (`lib/`, `tools/`,
`control/includes/*` except the few scripts browsers call, dotfiles, `*.md`, `composer.*`), stop PHP from
running in upload/cache folders, close `assets/records/` and send security headers. They need
`AllowOverride All` for the SubjectsPlus folder.

```sh
a2enmod rewrite headers proxy_fcgi setenvif && a2enconf php8.3-fpm
cp docs/server/apache-subjectsplus.conf /etc/apache2/sites-available/subjectsplus.conf   # edit names/paths
a2ensite subjectsplus && apachectl configtest && systemctl reload apache2
```

For pretty guide URLs (`/subjects/biology` instead of `/subjects/guide.php?subject=biology`) copy
`subjects/.htaccess-default` to `subjects/.htaccess`, uncomment the rules, set `RewriteBase`, and turn on
*mod_rewrite* in *Admin → Config Site*. `api/.htaccess` (REST API routing) is active by default.

Installed in a sub-folder (e.g. `https://library.edu/sp/`)? Nothing to change: the rules are relative to the
SubjectsPlus folder.

## 4. Nginx

Use [`docs/server/nginx-subjectsplus.conf`](docs/server/nginx-subjectsplus.conf): it reproduces every
`.htaccess` rule (Nginx ignores `.htaccess` files) — `try_files` routing for `/api/` and pretty guide URLs,
404 for dotfiles, `lib/`, `tools/`, `docs/`, `build/`, `tests/`, `assets/records/`,
`control/lti_controller/`, every `control/includes/` script except the browser-facing ones, no script
execution or SVG/HTML in `assets/users` and `assets/cache`, security headers, HSTS, a 24 MB upload limit
and FastCGI routing to PHP-FPM.

```sh
cp docs/server/nginx-subjectsplus.conf /etc/nginx/sites-available/subjectsplus   # edit server_name, root, certificates, PHP socket
ln -s /etc/nginx/sites-available/subjectsplus /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

Sub-folder install: prefix each `location` path with the folder (e.g. `/sp/control/includes/`).

## 5. PHP settings

`php.ini` (or the PHP-FPM pool):

```ini
display_errors = Off
log_errors = On
expose_php = Off
upload_max_filesize = 20M      ; CKEditor uploads, headshots, Excel/CSV imports
post_max_size = 24M
max_execution_time = 120       ; large imports, link checker
session.cookie_httponly = On
session.use_strict_mode = On
session.cookie_secure = On     ; when the site is HTTPS-only
```

SubjectsPlus itself hides PHP messages from visitors unless `$sp_debug = TRUE;` is set in `config.php`
(test servers only).

## 6. Daily task (cron)

`tools/workflow-daily.php` applies the workflow date rules (trial ends, renewals, reminders), sends the
weekly guide-review reminders, checks a batch of links in public guides and records (see §13), removes old
audit-log entries and records a heartbeat shown in *Admin → System Health*. Run it once a day as the
web-server user:

```cron
# /etc/cron.d/subjectsplus
15 6 * * *  www-data  php /var/www/subjectsplus/tools/workflow-daily.php >> /var/log/subjectsplus-daily.log 2>&1
```

Windows: Task Scheduler → daily → `C:\php\php.exe C:\inetpub\subjectsplus\tools\workflow-daily.php`.
Try a date: `php tools/workflow-daily.php --date=2027-01-01`. Emails use the SMTP settings in `config.php`
(`$email_host`, `$email_port`, `$email_smtp_auth`, `$email_username`, `$email_password`).

The link checker can also run on its own, e.g. at night: `php tools/link-check.php --batch=1000 -v`
(settings: `$link_check_batch` = links per run, default 300; `$link_check_interval_days`, default 7;
`$link_check_timeout`, default 10 seconds).

The scripts in `tools/` refuse to run from a browser.

## 7. Upgrading an existing 4.x site

**From 4.7.0 or later** (the short way):

1. Copy the files of the new release over the old ones (keep `control/includes/config.php`,
   `control/includes/.installed` and the `assets/users`, `assets/records`, `assets/images/guide_thumbs` folders).
2. Sign in as an administrator and open **Admin → Upgrade** (a notice on every admin page links to it while
   database updates are waiting). It shows what is new, checks the server, lets you **download a database
   backup** (compressed SQL; restore with `gunzip < file.sql.gz | mysql -u USER -p DATABASE`), applies the
   database updates and records the new version.
3. Check *Admin → System Health* and add the [daily task](#6-daily-task-cron) if it is not there yet.

**From any older 4.x** (or if you prefer a new folder):

1. **Back up** the database (`mysqldump --single-transaction subjectsplus > sp-backup.sql`) and the whole folder.
2. Unpack the new release into a **new folder** (do not unpack over the old one).
3. Copy from the old site:
   - `control/includes/config.php`
   - `assets/users/` (headshots, uploaded files), `assets/records/`, `assets/images/guide_thumbs/`
   - `subjects/.htaccess` if you use pretty URLs, and any custom theme folders under `subjects/themes/`
4. Add to the old `config.php`, above the closing `?>` (all optional — defaults apply when missing):

   ```php
   // Secret for encrypting optional staff personal data (40+ random characters; keep a copy):
   //   php -r 'echo bin2hex(random_bytes(24)), "\n";'
   $sp_encryption_key = "PUT-A-LONG-RANDOM-STRING-HERE";
   $sp_login_max_attempts   = 5;    // failed sign-ins per email before a lockout
   $sp_login_window_minutes = 15;   // lockout window
   $sp_academic_year_start_month = 7;   // usage-report "academic year" quick picks (7 = July)
   ```

   Keep an existing `$cryptKey`: it is needed to read staff data encrypted by older versions.
   If `$api_key` is still the old shared default (`a5h&vv5$#45`), replace it with a random value.
5. Empty `assets/cache/` (old JavaScript/CSS bundles), make the writable folders writable (§2) and delete
   `control/installer/` (an upgraded site never needs it).
6. Apply the database updates — **Admin → Upgrade** (or *Admin → Database Updates*) in the browser (a notice
   appears on every admin page while any are pending), or on the command line:

   ```sh
   php tools/migrate.php            # apply pending updates
   php tools/migrate.php --status   # list what has been applied
   ```

   Applied updates are recorded in the table `jgu_schema_migration` (name kept from the 4.6 maintenance
   releases so sites that already applied them are recognised).
7. Point the web server at the new folder ([§3](#3-apache-24) / [§4](#4-nginx)) and run the [checks](#8-checks-after-installing-or-upgrading).

Staff keep their passwords: old MD5 hashes still work and are converted to bcrypt at the next sign-in.

## 8. Checks after installing or upgrading

*Admin → System Health* runs most of these checks for you (versions, database updates, folders, web-server
protection, daily task, email test, security settings).

- [ ] Sign in as an existing staff member; sign out.
- [ ] Open a guide in the editor: drag a box in, type in CKEditor, *Save changes*, reload.
- [ ] Add a database record; check it on the public A–Z page and that its link uses your proxy.
- [ ] Upload a headshot and an image through the editor's image button.
- [ ] Open the public guide list, A–Z list, staff list, FAQ and TalkBack pages.
- [ ] `https://library.edu/control/includes/config.php`, `/lib/`, `/tools/migrate.php`,
      `/assets/records/` and `/composer.json` must all answer **404/403**.
- [ ] No new `Fatal`/`Warning` lines in the PHP error log.

## 9. Staff sign-in (Microsoft, Google, SIP2, SAML)

Configured in `control/includes/config.php` with `$sp_auth_providers` (commented examples are in
`config-default.php`). The login page shows one button per provider. **Only existing, active SubjectsPlus
staff accounts with the same email can sign in** — add people in *Admin → Users* first. Lockout and
attempt logging apply to every method. `$sp_auth_allowed_domains = array('library.edu');` limits sign-in
to your domains; `$sp_auth_local = FALSE;` hides the email + password form once single sign-on works.

Register `https://library.edu/control/auth.php` as the redirect (callback) address everywhere. HTTPS is required.

**Microsoft Entra ID** — Entra admin centre → App registrations → New registration (single tenant, Web
redirect URI as above) → Certificates & secrets → New client secret.

```php
array('id'=>'microsoft','type'=>'oidc','label'=>'Sign in with Microsoft',
      'issuer'=>'https://login.microsoftonline.com/<tenant id>/v2.0',
      'client_id'=>'<application id>','client_secret'=>'<secret>'),
```

**Google Workspace** — Cloud console → OAuth consent screen (*Internal*) → Credentials → OAuth client ID
(*Web application*) with the redirect URI above. `hosted_domain` refuses personal and other organisations'
accounts.

```php
array('id'=>'google','type'=>'oidc','label'=>'Sign in with Google','issuer'=>'https://accounts.google.com',
      'hosted_domain'=>'library.edu','client_id'=>'…','client_secret'=>'…'),
```

**SIP2 (Koha or any ILS)** — create a SIP login for SubjectsPlus and allow the server through the firewall to
the SIP port (TLS or a VPN if the ILS is elsewhere). Staff sign in with card number and PIN; the email comes
from the patron record (`BE` field). Without emails in the ILS use `'email_from'=>'barcode','email_suffix'=>'@library.edu'`.

```php
array('id'=>'koha','type'=>'sip2','label'=>'Library account','host'=>'ils.library.edu','port'=>6001,
      'tls'=>TRUE,'sip_login'=>'subjectsplus','sip_password'=>'…','institution'=>'MAIN'),
```

**SAML / Shibboleth** — install the Shibboleth SP (`mod_shib`) or `mod_auth_mellon` and protect only the
return page:

```apache
<Location /control/auth-saml.php>
    AuthType shibboleth
    ShibRequestSetting requireSession 1
    Require shib-session
</Location>
```

```php
array('id'=>'sso','type'=>'saml','label'=>'University single sign-on','login_url'=>'/Shibboleth.sso/Login',
      'email_attribute'=>'mail','trust_server_variable'=>TRUE),   // TRUE only once the Location block is live
```

`auth-saml.php` only accepts a sign-in that was started from the login page of the same browser, and never
reads `HTTP_*` (browser-supplied) variables.

Passwords: `control/forgotpassword.php` stays disabled (its upstream reset link was guessable);
administrators reset passwords in *Admin → Users*.

## 10. Off-campus access

*Admin → Off-campus Access* (admin permission):

1. **Add a provider** per service; pick the type for a starting template and replace the example address:

   | Service | Link template |
   |---|---|
   | EZproxy | `https://ezproxy.library.edu/login?url={url}` |
   | OpenAthens | `https://go.openathens.net/redirector/library.edu?url={url_enc}` |
   | RemoteXs / RemoteLog / other proxies | the vendor's login address with `{url}` or `{url_enc}` |
   | App / browser-extension services | leave the template empty; patrons see the note instead |

   `{url}` is the database address as-is, `{url_enc}` is percent-encoded; a template without either is used as a prefix.
2. List the **domains** each provider handles, one per line (`jstor.org` also covers `www.jstor.org`).
3. Tick **Default** on the provider for all other restricted databases.
4. **Test a link** shows the exact address patrons will get.

Only records whose access restriction is *Restricted* / *On campus only* (or any restriction whose behaviour
is set to use the access service) are rewritten; links already wrapped are never wrapped twice. Without
providers, the old `$proxyURL` setting still works.

## 11. Record settings and workflow

*Admin → Record Settings*: formats, access restrictions (with a link **behaviour**), statuses (which ones are
shown to the public, which one new records get) and custom fields (text, dropdown, checkboxes, date, number,
money, link, file — required / public). Values in use are retired rather than deleted. Files attached to
records are stored in `assets/records/` (closed to browsers) and downloaded through
`control/records/field_file.php` — staff only, unless the field is public **and** the record is in a public status.

*User types* (same page): Staff, Machine, Student and any you add. Each user account has one (*Admin → Users*);
types marked *Public directory* are listed on the public staff pages, in the staff API and OAI. "Staff" is built
in; a type cannot be deleted while users have it.

**Vendor / Publisher**: a field on every record, shown on the public A–Z list (with a *By vendor* list and
dropdown), in the API and in Excel / CSV import-export (column `Vendor / Publisher`; KBART `publisher_name`).

**Records → Find & Bulk Edit**: search by title, alternate title, vendor, URL or record ID, filter, tick records
and change them together — status (following the workflow permissions), vendor, format, access restriction,
A–Z display, add to a subject, delete (e-resource managers).

*Admin → Workflow*: presets (*Approval*, *E-resource lifecycle*, *Request & acquisition*), transitions (buttons
on the record page, per role: any records editor, e-resource manager, approver, admin), date rules (e.g.
"60 days before subscription end → Renewal due") run by the [daily task](#6-daily-task-cron). Give reviewers
the `approver` permission in *Admin → Users*. The public form `subjects/request.php` ("Suggest an e-resource")
creates a hidden record in the first request status.

## 12. Import & export

*Records → Import & Export*: e-resource records (Excel/CSV, preview before anything is written), KBART title
lists, usage reports (monthly clicks per database, guide views, tabs, links — set
`$sp_academic_year_start_month` for the "academic year" shortcuts), guides, staff and FAQs. Importing records
needs the e-resource manager or admin permission. Raise `upload_max_filesize` / `post_max_size` for large files.

## 13. Guide tools

- **Layouts**: 11 presets plus custom widths per section (select the section's arrows icon, then *Layouts*).
- **Tab style**: top or side tabs per guide (guide settings) or site-wide (`$sp_guide_tab_style = 'top'|'side'`).
- **Show/hide dates** per box (box ⚙ → *Show on the public page: From / Until*).
- **Templates**: guide settings → *Template*; *Start from a template, or copy an existing guide* lists them first.
- **Analytics** and **Check** (accessibility + *Mark as reviewed today*) in the editor; *Guides → Guide Health*
  lists review state, accessibility problems and **broken links** (from the scheduled link checker, §6) for all
  guides — editors see their own guides, admins everything; *Check again* / *Ignore* per link.
- **Who can edit a guide**: administrators, staff with the *allguides* permission, and the guide's editors
  (guide settings → staff). Everyone else is refused, in the editor and in every save request. Review interval: `$sp_guide_review_months`
  (default 6, 0 = off); `$sp_guide_show_reviewed` shows "Last reviewed" on public guides.

## 14. Public interface languages

Public pages can offer several languages; staff pages stay in English. Translations live in
`assets/locale/<code>/public.php` (English text ⇒ translation; missing entries fall back to English).
A Hindi translation is included. To offer it:

```php
$sp_public_language        = 'en';                                        // default language
$sp_public_languages       = array( 'en' => 'English', 'hi' => 'हिन्दी' ); // languages in the header switcher
$sp_public_language_switch = TRUE;
```

To add a language, copy `assets/locale/hi/public.php` to `assets/locale/<code>/public.php`, translate it and add
the code. The switcher is built into the default and SP-Lux themes; other themes can call `sp_lang_switcher()`.

## 15. Branding

`$sp_brand` in `config.php`: `name`, `logo` (URL or file in `assets/images/public/`), `home_url`, `primary` and
`accent` colours (`#rrggbb`), `favicon`. Readable text colours are chosen automatically.

## 16. REST API

Off by default. Enable with `$api_enabled = TRUE;` (Admin → Config Site). Create **one key per application** in
*Admin → API Clients* (each with its own rate limit and allowed services; the key is shown once, only a hash is
stored). The site key `$api_key` generated by the installer keeps working. Send the key in a header:

```sh
curl -H "X-API-Key: sp_…" https://library.edu/api/database/letter/A
curl -H "Authorization: Bearer sp_…" https://library.edu/api/database/vendor/EBSCO/output/xml
```

(or at the end of the URL, `…/key/<key>` — but URLs end up in web-server logs). Other examples:
`/api/staff/email/someone@library.edu`, `/api/guides/subject_id/22`.

Limits: `$api_rate_limit` requests per minute per key (default 120; 0 = none) and `$api_rate_limit_failed` per
IP address for missing or wrong keys (default 20). Answers carry `X-RateLimit-Limit / -Remaining / -Reset`; over
the limit the API answers **429** with `Retry-After`. Errors are JSON (`{"error":{"status":403,"message":…}}`).
Browser access from other sites (CORS): `$api_cors_origins = array("https://www.library.edu");`. Requests per
key and service: *Admin → API Clients* and *Admin → Usage Statistics*.

## 16a. Usage statistics and audit log

*Admin → Usage Statistics*: guide views, tab / link / database clicks for any period, with vendor totals, the
REST API and CSV downloads. Crawlers are not counted and IP addresses are shortened before they are stored
(`$stats_skip_bots`, `$stats_anonymise_ip`, both on).

*Admin → Audit Log*: who changed what and when — sign-ins, permissions, users, records, guides, settings (field
names only), bulk edits, API keys, backups, upgrades. Filter and download as CSV. Kept `$sp_audit_retention_days`
days (default 730; 0 = forever).

## 17. Hardening checklist

- [ ] HTTPS only; HSTS on (`Strict-Transport-Security`), `$enforce_https_upload_url = TRUE;`.
- [ ] `.htaccess` active (Apache: `AllowOverride All`) or the Nginx server block in use — verify with the URLs in §8.
- [ ] `control/includes/config.php` not writable by the web server after installation.
- [ ] *Admin → System Health* shows no red items.
- [ ] Content-Security-Policy: set `$sp_csp = "report-only";`, browse the public and staff pages with the browser
      console open, add any site you really need to `$sp_csp_allow`, then `$sp_csp = "strict";`.
- [ ] One API key per application (*Admin → API Clients*), with a rate limit.
- [ ] Optional: restrict `/control/` to campus / VPN addresses (example in `docs/server/apache-subjectsplus.conf`).
- [ ] Single sign-on with `$sp_auth_allowed_domains` set; `$sp_auth_local = FALSE;` once it works.
- [ ] Collect less staff personal data: hide home address / emergency contact columns with `$omit_user_columns`.
- [ ] `$api_enabled = FALSE;` unless you use the API; never reuse the sample API key.
- [ ] Daily database + `assets/` backups, with a restore test.
- [ ] `display_errors = Off`, `log_errors = On`; watch the PHP error log.

## 18. Troubleshooting

| Symptom | Fix |
|---|---|
| Admin pages show a yellow "database update(s) … not applied" notice | *Admin → Database Updates → Apply*, or `php tools/migrate.php` |
| Editor still behaves like the old version after an upgrade | Empty `assets/cache/`, then reload with Ctrl+F5 |
| "Cross-site request refused" (HTTP 403) | A form or script on another site posted to SubjectsPlus, or a proxy strips the `Origin`/`Referer` headers inconsistently; open SubjectsPlus from its own address |
| "Too many failed login attempts" | Wait `$sp_login_window_minutes`, or delete the rows for that email from `login_attempt` |
| Images or headshots do not upload | Make `assets/users/` writable; raise `upload_max_filesize` / `post_max_size` |
| A page is cut off / empty | Check the PHP error log: failed SQL statements are logged as `SubjectsPlus query failed: …` |
