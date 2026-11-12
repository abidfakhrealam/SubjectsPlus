# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 4.7.x | Yes |
| 4.6.x | Critical fixes only — please move to 4.7 |
| < 4.6 | No — upgrade (see [INSTALL.md §7](INSTALL.md#7-upgrading-an-existing-4x-site)) |

## Reporting a vulnerability

Please **do not open a public issue**. Use GitHub's private vulnerability reporting
(*Security → Report a vulnerability* on the repository) and include the affected file/URL, steps to
reproduce, the impact you expect and your SubjectsPlus, PHP, database and web-server versions.
We aim to acknowledge reports within 5 working days and to publish a fixed release with a changelog entry
crediting the reporter (unless you prefer otherwise).

## Hardening

Deploy with the shipped `.htaccess` files (Apache) or `docs/server/nginx-subjectsplus.conf` (Nginx) and work
through the checklist in [INSTALL.md §17](INSTALL.md#17-hardening-checklist). *Admin → System Health* checks
most of it for you. The audits and the list of fixed issues are in [docs/AUDIT-REPORT.md](docs/AUDIT-REPORT.md).

Release archives are signed by the release workflow (GitHub build-provenance attestation). Verify a download
before installing it:

```sh
sha256sum -c SHA256SUMS.txt
gh attestation verify SubjectsPlus-4.7.1-stable.zip --repo abidfakhrealam/SubjectsPlus
```

## Known limitations

- CKEditor 4.22.1 is the last open-source CKEditor 4 and receives no further free security fixes.
- Guide boxes, record descriptions and FAQ answers are HTML written by staff and are published as written
  (that is how embeds, widgets and custom markup work). Give editing permissions only to trusted staff.
- Guides can be changed by administrators, staff with the *allguides* permission and the guide's own editors
  (4.7.1). Records, FAQs and TalkBack are still per module (anyone with the module permission).
- Even the strict Content-Security-Policy (`$sp_csp = "strict"`) allows inline scripts, because SubjectsPlus 4.x
  pages contain them; it blocks scripts, styles, fonts and connections from other sites. Removing
  `'unsafe-inline'` needs the inline scripts moved into files first (planned with the editor replacement).
- `scrubData()` cleans HTML; it is not an escape for SQL or for HTML attributes. Use prepared statements and
  `sp_h()` when writing new code.
