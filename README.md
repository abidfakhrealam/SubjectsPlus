# SubjectsPlus 4.6.1

SubjectsPlus is an open-source web application for managing the research-help side of a library website:
subject and course research guides, an A–Z list of databases and e-resources, a staff directory, FAQs, a
suggestion box (TalkBack) and in-library videos. It was created at Ithaca College Library and developed for
many years at the University of Miami Libraries; it is released under the GNU GPL.

Version **4.6.1** is the maintained, security-hardened release of the 4.x line. It runs on current PHP
(8.1 – 8.4), MySQL 8.0 / 8.4 and MariaDB 10.11+ in strict SQL mode, behind Apache 2.4 or Nginx.

## Features

**Research guides**
- Drag-and-drop guide editor with tabs, sections and 11 column layouts (plus custom widths), top or side tabs
- Box types: rich text (CKEditor 4.22), link lists, database lists, book lists, RSS, video, FAQs, subject
  specialists, catalogue / discovery search boxes and more; boxes can be shared (linked) between guides
- Show/hide dates for any box, guide templates, copy a guide, per-guide analytics (views, tab / box / link /
  database clicks) with Excel export
- Review reminders (default every 6 months) and an accessibility check (alt text, link text, headings,
  tables, colour contrast) in the editor and across all guides

**E-resources (A–Z list)**
- Records with formats, access restrictions, statuses and custom fields managed by administrators
- Off-campus access through any number of providers — EZproxy, OpenAthens, RemoteXs/RemoteLog,
  app-based services or a custom link template — chosen per domain
- Optional workflow: approval, e-resource lifecycle (trial → active → renewal → cancelled) and patron
  requests, with date rules, reminders and history
- Import / export as Excel or CSV, KBART title-list import, monthly usage reports

**Staff and security**
- bcrypt passwords (legacy MD5 hashes upgraded at next sign-in), login throttling
- Optional single sign-on: OpenID Connect (Microsoft Entra ID, Google Workspace), SAML / Shibboleth, SIP2
  (Koha or any ILS); only existing, active staff accounts can sign in
- Role-based permissions (admin, records, e-resource manager, approver, FAQ, TalkBack, videos …)

**Public site**
- Responsive themes, configurable branding (logo, colours), optional public-interface translations
  (a Hindi translation is included as an example), REST API (JSON / XML)

## Requirements

| Component | Supported |
|---|---|
| PHP | **8.1, 8.2, 8.3, 8.4** with `pdo_mysql`, `mbstring`, `openssl`, `curl`, `gd`, `xml`/`simplexml`, `json` (`intl` and `gettext` optional) |
| Database | **MariaDB 10.11 / 11.x** or **MySQL 8.0 / 8.4**, strict SQL modes supported (`ONLY_FULL_GROUP_BY`, `STRICT_TRANS_TABLES`, `NO_ZERO_DATE`, `NO_ZERO_IN_DATE`) |
| Web server | **Apache 2.4** (`mod_rewrite`, `mod_headers`; `.htaccess` files included) or **Nginx** (hardened server block in [`docs/server/nginx-subjectsplus.conf`](docs/server/nginx-subjectsplus.conf)) with PHP-FPM |
| Browser (staff) | Current Chrome, Edge, Firefox or Safari |

Composer is not needed on the server: the PHP libraries are included in `lib/vendor`.

## Quick start

```sh
# 1. database
mysql -u root -p -e "CREATE DATABASE subjectsplus CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'sp'@'localhost' IDENTIFIED BY 'change-me'; GRANT ALL ON subjectsplus.* TO 'sp'@'localhost';"

# 2. code
unzip SubjectsPlus-4.6.1-stable.zip -d /var/www && mv /var/www/SubjectsPlus-4.6.1-stable /var/www/subjectsplus
chown -R www-data:www-data /var/www/subjectsplus      # or make control/includes/, assets/cache/,
                                                     # assets/users/, assets/records/ writable
# 3. web server: point it at /var/www/subjectsplus (see INSTALL.md for Apache and Nginx)

# 4. installer
#    open https://your-server/control/setup-config.php, enter the database details,
#    then the administrator email; note the generated admin password
```

Then sign in at `/control/`, change the admin password, and add the daily task
`php tools/workflow-daily.php`. Full instructions, upgrades and hardening: **[INSTALL.md](INSTALL.md)**.

## Documentation

- [INSTALL.md](INSTALL.md) — installation, Apache / Nginx, PHP settings, cron, upgrading, single sign-on,
  off-campus access, workflow, import / export, languages, branding
- [CHANGELOG.md](CHANGELOG.md) — what changed in 4.6.0
- [SECURITY.md](SECURITY.md) — reporting vulnerabilities and the hardening checklist
- [docs/AUDIT-REPORT.md](docs/AUDIT-REPORT.md) — security, bug and compatibility audit for 4.6.0
- [docs/history/CHANGELOG-upstream.md](docs/history/CHANGELOG-upstream.md) — history of SubjectsPlus up to 4.6

## Development

```sh
npm ci && npx playwright install chromium
npm run build                      # verify the CSS/JS bundles compile
SP_BASE=http://localhost:8080 SP_ADMIN_EMAIL=admin@library.edu SP_ADMIN_PASS=… npm run test:e2e
bash build/package-release.sh      # dist/SubjectsPlus-<version>-stable.{zip,tar.gz} + SHA256SUMS.txt
```

## License

GNU General Public License v2.0 or later — see [LICENSE.txt](LICENSE.txt). Bundled libraries (CKEditor,
jQuery, HTMLPurifier, PHPMailer, phpseclib, OpenID Connect client …) keep their own licences.
