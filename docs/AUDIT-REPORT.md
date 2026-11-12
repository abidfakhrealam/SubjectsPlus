# SubjectsPlus 4.6.0 – 4.7.1 — Security, Bug & Stack Compatibility Audit

**Scope:** the complete 4.6.0 code base (`control/`, `lib/`, `api/`, `subjects/`, `addons/`, `ckeditor/`, `tools/`),
starting from the 4.6 maintenance build (`4.6.0-jgu.8`, October 2026).
**Method:** manual source review of every request entry point (≈190 scripts that read `$_GET` / `$_POST` /
`$_REQUEST` / `$_FILES`), a scripted scan of every SQL string built by concatenation (124 sites triaged),
`php -l` on PHP 8.1 / 8.2 / 8.3 / 8.4, live runs on five server stacks with the database server in strict
SQL mode, a Playwright browser suite (133 checks per stack), and reflected-XSS / SQL-error sweeps of every
public and staff `GET` parameter (81 public + 119 authenticated requests per stack).
**Before/after evidence:** the same suite run against the unpatched build failed **23** checks (staff → admin
escalation, PHP upload accepted, config files served, admin pages executing for non-admins, …); the
4.6.0 release passes **133 / 133** on every stack (§4).

Line numbers refer to the 4.6.0 release files. In 4.7.0 `control/setup-config.php` and `control/install.php` were replaced by the installer wizard (`control/installer/`).

---

## 1. Summary

| Severity | Found & fixed | Main themes |
|---|---|---|
| Critical | 4 | privilege escalation, role checks that did not stop execution, unauthenticated upload + SQL injection, public SQL injection |
| High | 9 | SSRF / local file access, SQL injection (public guide rendering, API, theme search), data exposure, unsafe uploads, reflected XSS on the public search page |
| Medium | 13 | CSRF, open redirect, session fixation, LTI signature, path traversal, staff-side SQL injection & reflected XSS, API key handling, disabled form still processing |
| Low | 10 | leaked keys in comments, wrong reCAPTCHA key, unescaped CSS name, record file visibility, SAML variable name, error disclosure |
| Bugs / compatibility | 24 | `ONLY_FULL_GROUP_BY` on MySQL 8.4 / MariaDB, PHP 8 `TypeError`s and fatals, Querier binding bug, editor JS error, missing assets, broken public caches |

Root causes that recur: (1) **`scrubData()` is not an SQL escape** — its HTMLPurifier step turns `&#039;`
back into `'`, so every query that put a "scrubbed" string between quotes was injectable; (2) `header('Location: …')`
without `exit`; (3) helper scripts that trusted ids / roles sent by the browser; (4) outbound requests made
for whatever URL a visitor supplied.

New shared defences (`control/includes/security.php`): `sp_require_same_origin()` (Fetch-Metadata / Origin /
Referer CSRF check, wired into `header.php` for every staff POST and `staff_only.php` for every helper request),
`sp_require_role()`, `sp_http_get()` (SSRF-safe fetch: http/https only, every resolved address must be public,
DNS pinned with `CURLOPT_RESOLVE`, redirects re-validated, 10 s / 2 MB limits), `sp_int()`, `sp_int_list()`,
`sp_safe_filename()`, `sp_isbn()`, `sp_h()`, `sp_js()`.

---

## 2. Findings

### Critical

| ID | Finding | Location (4.6.0) | Root cause | Patch |
|---|---|---|---|---|
| C-1 | **Any staff member could become administrator** via `action=update_permissions` (sets `ptags` of any account) | `control/admin/admin_bits.php:20` | `$subcat` never set, so `header.php` applied no role check; every caller is an admin page | `sp_require_role()` (admins only); ids cast, strings quoted (`:40–155`) |
| C-2 | **Profile page rewrote any account**: posting `staff_id`, `ptags`, `user_type_id`, `active` to *Edit your profile* changed another user or granted admin; empty `staff_id` created users | `control/admin/profile.php:29` | posted record fields trusted | record always the session's own; privileged fields re-read from the database; inserts removed |
| C-3 | **Role checks did not stop the page**: non-admins / non-records staff were redirected but the page kept running (and processing POSTs) — 11.7 KB of admin HTML was still returned | `control/includes/header.php:143,148,153,158` | `header("location:…")` without `exit` | `exit` after each redirect (verified: HTTP 302, empty body) |
| C-4 | **Unauthenticated upload + SQL injection**: anyone could POST a file into any staff folder; `staff_id` concatenated into SQL | `control/includes/asset_manager_upload.php:13` | no session check, raw `$_POST['staff_id']` | staff session required, caller's own folder, prepared query, images only (`getimagesize`, `image/*`) |

### High

| ID | Finding | Location | Root cause | Patch |
|---|---|---|---|---|
| H-1 | **SSRF / local XML disclosure** in the public RSS proxy — `file://`, `php://`, loopback and cloud-metadata addresses fetched; feed HTML printed unescaped (XSS) | `subjects/includes/feedme.php:35`, `lib/SubjectsPlus/Control/PGFeed.php:152` | `XMLReader::open($source)` / `file_get_contents($source)` on visitor input | `sp_http_get()` only; scheme check; titles escaped, links http(s) only, descriptions through HTMLPurifier; cache file names hashed |
| H-2 | **Blind SSRF + cache poisoning** in the public Book List helper: `url=` fetched with TLS checks off; `isbn=` used in file paths; visitors could overwrite cached titles/links shown on guides | `subjects/bookListPlusletActionRouter.php` → `control/includes/booklist_router.php:197` | cURL on visitor URL, `CURLOPT_SSL_VERIFYPEER=false`, no validation | host allow-list (Google Books, Open Library, Syndetics, Ex Libris) over verified TLS, ISBN validation, covers must be images, public writes only fill an empty entry for an ISBN that is in a Book List box. Also fixed: the cache path pointed outside the web root, so covers were never cached |
| H-3 | **Public SQL injection** through `?subject=` while rendering database tokens in guides | `lib/SubjectsPlus/Control/Pluslet/Pluslet.php:567,625`, `lib/SubjectsPlus/Control/TextTokenizer.php:267,322`, `control/includes/functions.php:2002`, `findDescOverride()` `:900` | string concatenation | prepared statements / integer casts |
| H-4 | **Public SQL injection** on collection pages `subjects/collection.php?d=` (confirmed: `Unknown column 'gt'`) | `control/includes/functions.php:2400` | `scrubData()` output placed between quotes (quotes are not escaped) | `$db->quote()` |
| H-5 | **Public SQL injection** in the SP-Lux theme search `?searchterm=` | `subjects/themes/splux/index.php:126` | same as H-4 | `$db->quote()` |
| H-6 | **REST API SQL injection** (`email`, `type`, `shortform`, `search`, `tag`, dates, `max`) — backslash trick defeats the HTML-escaping "sanitiser" | `lib/SubjectsPlus/API/*WebService.php` (e.g. `StaffWebService.php:124`), helper `WebService.php:33` | values pasted between quotes | every value quoted with PDO or cast to int |
| H-7 | **Data exposure**: staff autocomplete answered anyone (hidden guides, box contents, staff details); the public autocomplete accepted any collection (unpublished TalkBack questions, hidden guides) | `control/includes/autocomplete_data.php:13`, `subjects/includes/autocomplete_data.php:17` | no session check; collection passed through | staff endpoint requires sign-in; public endpoint allows `guide` (public guides only), `guides`, `azrecords` |
| H-8 | **CKEditor uploader** accepted `x.php.jpg`, HTML/SVG, fake images; any session holding an email passed | `ckeditor/php/uploader.php:68,196` | extension-only check on the last segment | full staff session, `getimagesize()` for images, active-content / double extensions refused, same-origin check |
| H-9 | **Reflected XSS on the public search page** `?category=` / `?sortby=` (inline `<script>` and links) — and the staff search | `subjects/search.php:38`, `control/search.php:23` | values echoed when no search term was given | allow-lists, `sp_js()` / `rawurlencode()` + `sp_h()` |

### Medium

| ID | Finding | Location | Patch |
|---|---|---|---|
| M-1 | CSRF on staff pages and helper scripts (several helpers change data on GET; the 4.6 forms have no tokens) | `control/includes/header.php:162`, `control/includes/staff_only.php:26`, `security.php:49` | `sp_require_same_origin()` — POSTs to staff pages, all methods on helpers |
| M-2 | Login CSRF (email/password form had no token); SAML return accepted without a pending sign-in | `control/login.php:207`, `control/auth-saml.php:37` | session token; `sp_auth_pending` must match |
| M-3 | Open redirect after login via `//evil.example/…` stored in `desired_page` | `control/includes/header.php:131`, `control/login.php:241` | `sp_safe_local_redirect()` |
| M-4 | Session fixation: `session_regenerate_id()` kept the pre-login session valid | `control/includes/functions.php:386` | `session_regenerate_id(true)` |
| M-5 | LTI launch accepted unsigned requests (`$lti->valid` never checked; empty key = empty key) | `addons/bb-lti/lti.php:21` | require configured key + secret, `hash_equals`, valid OAuth signature |
| M-6 | LTI course import runnable from a browser without sign-in | `control/lti_controller/coursesCodeImport.php:18` | CLI only; folder denied by web server |
| M-7 | Path traversal in guide thumbnails: `shortform` / `old-shortform` let staff delete or move any `.jpg` | `control/guides/metadata.php:84` | `sp_safe_filename()`, existence checks |
| M-8 | Staff-side SQL injection: `admin_bits.php`, `ajax_mod.php:23,63`, `guide_bits.php:129`, `talkback/index.php:127,133`, `talkback/tb_bits.php:37`, `export_contacts.php:39`, `guide.php:77`, `guide_data.php:182`, `manage_items.php`, `records/index.php:74`, `favorite_pluslets_data.php:16`, `SaveGuide.php:37`, `SectionData.php:60`, `video_bits.php:34`, `Pluslet/Clone.php` | concatenated ids / strings | casts, `quote()`, prepared statements |
| M-9 | Staff-side reflected XSS: `records/index.php` (`ctag`, `letter`), `wintype` on metadata / FAQ / TalkBack pages, `manage_items.php` (`subject_id` into `<script>`) | see paths; `header.php:42` | allow-lists, casts, `wintype` normalised for every staff page |
| M-10 | REST API key: loose `!=`, an empty configured key matched an empty parameter, and the shipped default key was public | `lib/SubjectsPlus/API/WebServiceHandler.php:91`, `config-default.php` | `hash_equals`, empty key ⇒ API refuses, default key empty, service name letters only |
| M-11 | Disabled problem-report form kept accepting and emailing submissions after redirecting | `subjects/usearch-problem-report.php:123` | `exit` |
| M-12 | Unauthenticated development script overwrote every guide's description | `api/adddisciplinedescription.php` | removed |
| M-13 | No web-server protection shipped: `config.php`, `functions.php`, `lib/`, `tools/`, `composer.*`, `README.md` were served (HTTP 200 on the baseline) | repository | `.htaccess` set + Nginx server block; verified 404 for 17 sensitive paths |

### Low

| ID | Finding | Location | Patch |
|---|---|---|---|
| L-1 | A third party's reCAPTCHA site **and secret** keys in source comments | `control/includes/functions.php:2497` | removed |
| L-2 | Problem-report form verified reCAPTCHA with the TalkBack secret | `subjects/usearch-problem-report.php:316` | correct key |
| L-3 | reCAPTCHA request body built without URL-encoding | `control/includes/functions.php:2552` | `http_build_query()` |
| L-4 | Theme CSS name from the database put unescaped into a `<link>`; JSON built by concatenation | `control/includes/header.php:196`, `config_bits.php:30` | name restricted, `json_encode` |
| L-5 | Files in public custom fields downloadable for records that are not public | `control/records/field_file.php:27` | record status must be public |
| L-6 | SAML email attribute could name a browser-supplied `HTTP_*` variable | `control/auth-saml.php` | refused |
| L-7 | LTI exceptions echoed to the browser | `addons/bb-lti/lti.php` | logged; generic message |
| L-8 | Admin folder name built from the administrator email without sanitising | `control/install.php:166`, `uploader.php` | `sp_safe_filename()` |
| L-9 | Page `<title>` printed unescaped | `control/includes/header.php` | `sp_h()` |
| L-10 | Hard-coded links to one institution's website and file host | `StaffDisplay.php:108`, `usearch-problem-report.php` | removed |

### Bugs and compatibility fixes

| Area | Fix | Location |
|---|---|---|
| MySQL 8.4 / MariaDB `ONLY_FULL_GROUP_BY` | record default source | `lib/SubjectsPlus/Control/Record.php:154` |
| 〃 | staff details (core + 2 themes), experts (core, box, 2 themes), FAQ collections (3), FAQ by subject, top links, LTI statistics | `subjects/staff_details.php:73`, `Pluslet/Experts.php:54`, `subjects/faq.php:295`, `control/faq/browse_faq.php:85`, `Stats.php:64`, `LtiWebService.php:111` |
| Database layer | `prepareStatement()` bound every placeholder to the **last** value (`bindParam` by reference); `fetch()` and `num_rows()` called non-existent methods | `lib/SubjectsPlus/Control/Querier.php:162,206` |
| PHP 8 | `scrubData(…,'integer')` `TypeError` on non-numeric input (public 500s) | `control/includes/functions.php:857` |
| PHP 8 | `Autocomplete` re-included `functions.php` (fatal "cannot redeclare"); unknown collection → `bindParam()` on null | `Autocomplete.php:15,293` |
| PHP 8 | `video.php?tag=` unknown tag → `count(null)` 500; public guide continued after redirect → `count(null)` 500 | `subjects/video.php:101`, `subjects/guide.php:84,154` |
| PHP 8 | `uploader2()` passed `explode()` by reference; staff form undefined keys; `department_id` default | `functions.php:568`, `Staff.php:118` |
| PHP 8.4 | implicit nullable parameters (new code) | `security.php`, `booklist_router.php` |
| Editor | CKEditor polled a removed iframe after each save (`getSelection` page error) | `assets/js/guides/saveSetup.js:432` |
| Public | "Suggest an e-resource" used jQuery without loading it | `subjects/request.php:21` |
| Setup | three scripts and the admin CSS loaded from wrong paths (404 / missing fonts) | `control/setup-config.php:35–40`, `configErrorPage.php` |
| Install | 4.6 database updates were left pending after a fresh install (login throttling inactive until an admin ran them) | `control/install.php:154` |
| Staff pages | `guide_bits.php` used `Querier` without importing it; `user_guides.php` required `staff_id`; dead `tablesorter` option loaded missing files | various |
| Public | staff profile for an unknown name printed warnings; public guide with a session-less `Guide` object | `staff_details.php`, `Guide.php:58` |

---

## 3. Stack compatibility

| Item | Result |
|---|---|
| `php -l` (E_ALL, deprecations fatal in CI) | 1 204 files clean on **PHP 8.1.34, 8.2.35, 8.3.6, 8.4.27** |
| Strict SQL | database server set to `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` and SubjectsPlus forced to use it (`$sp_db_sql_mode = 'server'`); installer, all 7 migrations, every tested page: **no failed statements** on MariaDB 10.11.14 and MySQL 8.4.6 |
| Apache 2.4.58 | shipped `.htaccess` set; PHP-FPM via `proxy_fcgi` |
| Nginx 1.24 | `docs/server/nginx-subjectsplus.conf` (validated with `nginx -t`, used for the test runs) |
| Not run here | MariaDB 11.x and MySQL 8.0 (not installable in the test environment; MySQL 8.0's defaults are a subset of 8.4's) |

---

## 4. Verification

Playwright (Chromium), desktop 1440×900 and mobile 375×812, fresh install into an empty database on every
stack. 133 checks: installer, browser-applied migrations, login / wrong password / login CSRF / throttling,
18 admin pages with zero console, network or PHP errors, guide creation, the editor (tab bar, Layouts /
Analytics / Check / Boxes flyouts unobstructed — `elementFromPoint` + `pointer-events`, drag-and-drop box,
layout switch, CKEditor edit + save + reload, accessibility check), guide copy, off-campus access provider +
link test, workflow presets + record status transition, user management, 11 public pages, A–Z filter, tabs,
TalkBack and request forms, no horizontal scroll on mobile, 17 blocked paths, SSRF, unauthenticated helper
access, open redirect, cross-site POST, API injection, non-admin escalation attempts.

| Stack | Browser suite (desktop + mobile + HTTP) | Public sweep (81 req.) | Staff sweep (119 req.) | PHP error log |
|---|---|---|---|---|
| Nginx 1.24 + PHP 8.4.27 + MariaDB 10.11.14 | **133 / 133** | 0 XSS / SQL errors | 0 XSS / SQL errors ¹ | clean ² |
| Nginx 1.24 + PHP 8.1.34 + MariaDB 10.11.14 | **133 / 133** | 0 | 0 ¹ | clean ² |
| Nginx 1.24 + PHP 8.2.35 + MariaDB 10.11.14 | **133 / 133** | 0 | 0 ¹ | clean ² |
| Nginx 1.24 + PHP 8.4.27 + MySQL 8.4.6 | **133 / 133** | 0 | 0 ¹ | clean ² |
| Apache 2.4.58 + PHP 8.3.6 (FPM) + MariaDB 10.11.14 | **133 / 133** | 0 | 0 ¹ | clean ² |
| Unpatched baseline, Apache + PHP 8.3 + MariaDB | 110 / 133 — **23 failures** | — | — | — |

¹ The only non-200 answers are the staff-only LibGuides importer returning HTTP 500 when no LibGuides XML
export has been uploaded (residual item 6). ² Only two expected lines: the CSRF test's refused cross-site
request, and "workflow email not sent" because the test machine has no mail server.

All databases ran with the strict server SQL mode listed in §3 and SubjectsPlus set to use it.

---

## 5. Residual risks and recommendations

1. **Per-guide authorisation** — any staff member with the *records* permission can edit any guide (upstream
   design). Add ownership checks to `control/guides/helpers/*` if guides must be isolated between teams.
2. **CKEditor 4.22.1** is end-of-life for open-source users — plan a move to CKEditor 5 or TinyMCE.
3. **Staff-authored HTML** (boxes, descriptions, FAQ answers) is published as written; only trusted staff should
   have editing rights.
4. **Content-Security-Policy** is a baseline (`object-src`, `base-uri`, `frame-ancestors`, `form-action`);
   restricting scripts needs the inline scripts moved out first.
5. **`scrubData()`** remains an HTML-cleaning helper, not an SQL escape; new code must use prepared statements.
6. The LibGuides importer (`control/guides/lgimporter/`) throws when its XML file is missing (staff-only tool).

---

## 6. 4.7.0 installer re-test

The installer wizard was added in 4.7.0 and the whole suite re-run (now 143 checks per stack, including the wizard:
install-code gate, wrong code, requirements, connection tests, database creation, password rules, read-only
`config.php` download/upload, lock-out after installation, sample content):

| Stack | Install mode | Result | PHP log |
|---|---|---|---|
| Nginx 1.24 + PHP 8.4.27 + MariaDB 10.11.14 | wizard creates database + user (password with `$ " \ '`) | **143 / 143** | clean |
| Nginx 1.24 + PHP 8.1.34 + MariaDB 10.11.14 | existing database | **143 / 143** | clean |
| Nginx 1.24 + PHP 8.2.35 + MariaDB 10.11.14 | existing database, `control/includes/` read-only (config.php downloaded and uploaded) | **143 / 143** | clean |
| Nginx 1.24 + PHP 8.4.27 + MySQL 8.4.6 | wizard creates database + user | **143 / 143** | clean |
| Apache 2.4.58 + PHP 8.3.6 + MariaDB 10.11.14 | existing database | **143 / 143** | clean |

The XSS / SQL-error sweeps (81 public + 119 staff requests) were repeated on every stack with no findings.
Fixed while testing: the guide editor's side panel was partly hidden under the fixed guide header whenever an
admin notice was shown (`#main-options` z-index).

---

## 7. 4.7.1 audit and re-test

New in this round: attribute-injection payloads (`x" data-spx="1`, `x' data-spy='1`) in the XSS sweeps, POSTed
public forms, the new admin pages, PHPStan level 1 over the whole code base, and axe-core (WCAG 2.2 AA rules) on
the public pages.

| Severity | Finding | Where | Fix |
|---|---|---|---|
| High | Reflected XSS, public search: `?searchterm=x" autofocus onfocus="…"` broke out of the search box `value` (`scrubData()` turns `&quot;` back into `"`) | `lib/SubjectsPlus/Control/CompleteMe.php` (public and staff search) | every attribute escaped |
| High | Reflected XSS, TalkBack form (all themes): comment and email echoed raw after a failed submit; reachable by cross-site POST | `subjects/views/talkback/comment_form*.php`, theme copies | escaped; comment put inside the textarea |
| High | SQL injection, REST API `database/letter/…` (key required) | `lib/SubjectsPlus/API/DatabaseWebService.php` | quoted |
| Medium | Guide-editor helpers did not check per-guide permissions (any *records* staff could change any guide through `save_tab`, `save_guide`, `guide_data`, `ajax_mod`, …) | `control/guides/helpers/*`, `metadata.php` | `GuideAccess` checks in every write path |
| Medium | Box type name used as a class name without filtering (autoloader path) | `control/guides/helpers/guide_data.php` | letters and digits only |
| Medium | Third-party scripts from another institution's web site in the SP-Lux theme; reCAPTCHA script loaded with reCAPTCHA off | `subjects/themes/splux/site-scripts.php`, TalkBack views | removed / conditional |
| Low | LibGuides XML export (staff names, emails) servable from the importer folder | `control/guides/lgimporter/` | `.htaccess` + Nginx rule, `$libguides_xml_path` |
| Low | Change-history SQL built with string concatenation | `changeMe()`, `lastModded()` | prepared statements |
| Bug | Four staff email actions fatal (`new Mailer()` without a message) | record / FAQ / TalkBack bits, guide link report | `sp_send_mail()` |
| Bug | TalkBack ignored the visitor's email (field name mismatch) | `subjects/talkback.php` | all field names accepted |
| Bug | API `ONLY_FULL_GROUP_BY` error in the subjects sub-query (some filters returned nothing) | `DatabaseWebService` | ordering inside `GROUP_CONCAT` |
| Bug | `count()` on an int (PHP 8 TypeError) when deleting a box; old stats page called a missing method | `guide_data.php`, `control/admin/stats.php` | fixed / page rewritten |
| Bug | `$sp_version` never set (installer lock and pages without version) | `functions.php` | `sp_version()` |

Test matrix (161 checks per stack: installer from the site root, editor, records, workflow, users, public pages,
HTTP security checks, and the 4.7.1 features — vendor field, bulk edit, user types, audit log, statistics, API
clients, backup / upgrade, link checker, per-guide permissions):

| Stack | Install mode | Result | XSS / SQL sweeps (169 public + 435 staff requests) | PHP log |
|---|---|---|---|---|
| Nginx + PHP 8.4 + MariaDB 10.11 | wizard creates database + user | **161 / 161** | clean | clean |
| Nginx + PHP 8.1 + MariaDB 10.11 | existing database | **161 / 161** | clean | clean |
| Nginx + PHP 8.2 + MariaDB 10.11 | read-only `control/includes/` | **161 / 161** | clean | clean |
| Nginx + PHP 8.4 + MySQL 8.4 | wizard creates database + user | **161 / 161** | clean | clean |
| Apache 2.4 + PHP 8.3 + MariaDB 10.11 | existing database | **161 / 161** | clean | clean |

"Clean" PHP log: only the expected lines (the CSRF test's refused request; no mail server on the test machine).
Also tested: upgrade 4.7.0 → 4.7.1 through *Admin → Upgrade* (6 updates applied, version recorded, all 4.7.1
checks pass afterwards); the downloaded backup restored into an empty database with identical row counts in
every table; REST API keys in header / URL, per-key and per-IP limits (429 + `Retry-After`), service restriction,
CORS; `$sp_csp = "strict"` with no CSP violations on 12 public and staff pages; axe-core: **0 violations** on 8
public pages with the default theme (SP-Lux: one remaining `aria-controls` warning when a guide type has no
guides). PHPStan level 1: 1,594 pre-existing findings in the baseline (mostly variables that come from included
files), none in code added in 4.7.1.

Still open (see §5): CKEditor 4 end-of-life; inline scripts prevent a CSP without `'unsafe-inline'`; records,
FAQ and TalkBack permissions are per module; the guide editor still uses jQuery UI.
