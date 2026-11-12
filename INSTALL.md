# Installing, configuring and upgrading SubjectsPlus 4.6.0

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

1. **Database** — create an empty database and a user:

   ```sql
   CREATE DATABASE subjectsplus CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'sp'@'localhost' IDENTIFIED BY 'a-long-random-password';
   GRANT ALL PRIVILEGES ON subjectsplus.* TO 'sp'@'localhost';
   ```

2. **Files** — unpack the release and give the web server write access to these folders only:

   ```sh
   unzip SubjectsPlus-4.6.0-stable.zip -d /var/www
   mv /var/www/SubjectsPlus-4.6.0-stable /var/www/subjectsplus
   cd /var/www/subjectsplus
   chown -R root:root . && chown -R www-data:www-data control/includes assets/cache assets/users assets/records assets/images/guide_thumbs
   ```

   Verify the download first: `sha256sum -c SHA256SUMS.txt`.

3. **Web server** — configure Apache ([§3](#3-apache-24)) or Nginx ([§4](#4-nginx)).

4. **Installer** — open `https://library.edu/control/setup-config.php`:
   - enter the database host, port, user, password and database name → *Save Config*;
   - on the next page enter the **administrator email** (it becomes the admin login) and the other site
     settings → *Run the install*;
   - **write down the generated admin password** shown on the last page.

   The installer creates the tables, applies all 4.6 database updates (login throttling, record settings,
   workflow, access providers, guide features) and writes `control/includes/config.php` with random values
   for `$salt`, `$api_key` and `$sp_encryption_key`.

5. **After installing**
   - sign in at `/control/`, change the admin password (*Edit your profile*), add staff in *Admin → Users*;
   - make `control/includes/` read-only again: `chown -R root:root control/includes && chmod 644 control/includes/config.php`
     (or `chown root:www-data` + `chmod 640` so PHP can still read it);
   - add the [daily task](#6-daily-task-cron);
   - work through the [hardening checklist](#17-hardening-checklist).

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

`tools/workflow-daily.php` applies the workflow date rules (trial ends, renewals, reminders) and sends the
weekly guide-review reminders. Run it once a day as the web-server user:

```cron
# /etc/cron.d/subjectsplus
15 6 * * *  www-data  php /var/www/subjectsplus/tools/workflow-daily.php >> /var/log/subjectsplus-daily.log 2>&1
```

Windows: Task Scheduler → daily → `C:\php\php.exe C:\inetpub\subjectsplus\tools\workflow-daily.php`.
Try a date: `php tools/workflow-daily.php --date=2027-01-01`. Emails use the SMTP settings in `config.php`
(`$email_host`, `$email_port`, `$email_smtp_auth`, `$email_username`, `$email_password`).

The scripts in `tools/` refuse to run from a browser.

## 7. Upgrading an existing 4.x site

1. **Back up** the database (`mysqldump --single-transaction subjectsplus > sp-backup.sql`) and the whole folder.
2. Unpack 4.6.0 into a **new folder** (do not unpack over the old one).
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
5. Empty `assets/cache/` (old JavaScript/CSS bundles) and make the writable folders writable (§2).
6. Apply the database updates — **Admin → Database Updates** in the browser (a notice appears on every admin
   page while any are pending), or on the command line:

   ```sh
   php tools/migrate.php            # apply pending updates
   php tools/migrate.php --status   # list what has been applied
   ```

   Applied updates are recorded in the table `jgu_schema_migration` (name kept from the 4.6 maintenance
   releases so sites that already applied them are recognised).
7. Point the web server at the new folder ([§3](#3-apache-24) / [§4](#4-nginx)) and run the [checks](#8-checks-after-installing-or-upgrading).

Staff keep their passwords: old MD5 hashes still work and are converted to bcrypt at the next sign-in.

## 8. Checks after installing or upgrading

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
  lists review state and accessibility problems for all guides. Review interval: `$sp_guide_review_months`
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

Off by default. Enable with `$api_enabled = TRUE;` — the installer generates `$api_key`; the API refuses every
request while the key is empty. Examples: `/api/staff/email/someone@library.edu/key/<key>`,
`/api/database/letter/A/output/xml/key/<key>`, `/api/guides/subject_id/22/key/<key>`.

## 17. Hardening checklist

- [ ] HTTPS only; HSTS on (`Strict-Transport-Security`), `$enforce_https_upload_url = TRUE;`.
- [ ] `.htaccess` active (Apache: `AllowOverride All`) or the Nginx server block in use — verify with the URLs in §8.
- [ ] `control/includes/config.php` not writable by the web server after installation.
- [ ] Remove or block `control/setup-config.php`, `control/install.php` and `control/update.php` once installed
      (they refuse to run on an installed site, but less code is less risk).
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
