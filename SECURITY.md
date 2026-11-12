# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 4.6.x | Yes |
| < 4.6 | No — upgrade (see [INSTALL.md §7](INSTALL.md#7-upgrading-an-existing-4x-site)) |

## Reporting a vulnerability

Please **do not open a public issue**. Use GitHub's private vulnerability reporting
(*Security → Report a vulnerability* on the repository) and include the affected file/URL, steps to
reproduce, the impact you expect and your SubjectsPlus, PHP, database and web-server versions.
We aim to acknowledge reports within 5 working days and to publish a fixed release with a changelog entry
crediting the reporter (unless you prefer otherwise).

## Hardening

Deploy with the shipped `.htaccess` files (Apache) or `docs/server/nginx-subjectsplus.conf` (Nginx) and work
through the checklist in [INSTALL.md §17](INSTALL.md#17-hardening-checklist). The 4.6.0 audit and the list of
fixed issues are in [docs/AUDIT-REPORT.md](docs/AUDIT-REPORT.md).

## Known limitations

- CKEditor 4.22.1 is the last open-source CKEditor 4 and receives no further free security fixes.
- Guide boxes, record descriptions and FAQ answers are HTML written by staff and are published as written
  (that is how embeds, widgets and custom markup work). Give editing permissions only to trusted staff.
- Editing permission is per module (any staff member with the *records* permission can edit any guide),
  as in upstream SubjectsPlus; there is no per-guide access control.
- The baseline Content-Security-Policy does not restrict scripts, because SubjectsPlus 4.x uses inline
  scripts and third-party embeds.
