# Publishing SubjectsPlus on GitHub and releasing

## A. First export to GitHub (one time)

Prerequisites: `git` 2.28+, a GitHub account or organisation, and either the GitHub CLI (`gh auth login`) or an
SSH key added to GitHub.

```sh
# 1. unpack the source tree (SubjectsPlus-4.7.0-github-source.zip) and enter it
unzip SubjectsPlus-4.7.0-github-source.zip
cd SubjectsPlus

# 2. make sure no secrets or site data are inside (all of these must print nothing)
ls control/includes/config.php control/includes/install-code.php control/includes/.installed .env 2>/dev/null
git check-ignore -q control/includes/config.php && echo "config.php is ignored: OK"

# 3. create the repository with one clean first commit
git init -b main
git config user.name  "Your Name"
git config user.email "you@library.edu"
git add -A
git status --short | head -20          # review what will be committed
git commit -m "SubjectsPlus 4.7.0"

# 4a. create the GitHub repository and push (GitHub CLI)
gh repo create abidfakhrealam/SubjectsPlus --public --description "Research guides, A-Z databases, staff directory and FAQs for libraries" --source=. --remote=origin --push

# 4b. …or, with an empty repository already created on github.com
git remote add origin git@github.com:abidfakhrealam/SubjectsPlus.git
git push -u origin main
```

`lib/vendor/` (PHP libraries) is committed on purpose, so servers do not need Composer.

### Repository settings (recommended)

```sh
gh repo edit abidfakhrealam/SubjectsPlus --enable-issues --enable-wiki=false --delete-branch-on-merge
gh api -X PUT repos/abidfakhrealam/SubjectsPlus/private-vulnerability-reporting      # SECURITY.md asks for private reports
gh api -X PUT repos/abidfakhrealam/SubjectsPlus/branches/main/protection --input - <<'JSON'
{"required_status_checks":{"strict":true,"contexts":["lint (8.1)","lint (8.2)","lint (8.3)","lint (8.4)","e2e"]},
 "enforce_admins":false,"required_pull_request_reviews":{"required_approving_review_count":1},"restrictions":null}
JSON
```

Under *Settings → Actions → General* allow GitHub Actions and give workflows **read and write** permission
(the release workflow creates the GitHub release).

## B. Publish the release (tag → GitHub Actions)

```sh
git tag -a v4.7.0 -m "SubjectsPlus 4.7.0"
git push origin v4.7.0
gh run watch                                  # follow the "release" workflow
gh release view v4.7.0                        # zip, tar.gz and SHA256SUMS.txt attached
```

The `release` workflow (`.github/workflows/release.yml`) runs `php -l` on PHP 8.1–8.4, checks that the tag
matches `control/includes/version.php`, builds and verifies the CSS/JS bundles, packages
`SubjectsPlus-4.7.0-stable.zip` / `.tar.gz` with `SHA256SUMS.txt`, and publishes them with the 4.7.0 section of
`CHANGELOG.md` as release notes. `ci.yml` runs the PHP lint matrix and the browser suite (Apache + PHP 8.3 +
MariaDB 10.11, strict SQL, installer wizard) on every push and pull request.

### Uploading archives you built yourself instead

```sh
bash build/package-release.sh                     # → dist/
cd dist && sha256sum -c SHA256SUMS.txt && cd ..
gh release create v4.7.0 dist/SubjectsPlus-4.7.0-stable.zip dist/SubjectsPlus-4.7.0-stable.tar.gz dist/SHA256SUMS.txt \
   --title "SubjectsPlus 4.7.0" --notes-file <(awk '/^## \[4.7.0\]/{f=1;next} /^## \[/{if(f)exit} f' CHANGELOG.md)
```

Windows: `powershell -ExecutionPolicy Bypass -File build\package-release.ps1`, then upload the three files from
`dist\` on the release page.

## C. Every later release

1. Update the version in `control/includes/version.php`, `composer.json`, `package.json`,
   `lib/vendor/composer/installed.php`; run `composer update --lock` (lock hash); add a `CHANGELOG.md` section.
2. Test locally:

   ```sh
   npm ci && npx playwright install chromium
   npm run build
   sudo -u www-data php tools/install-code.php        # on a test site without config.php
   SP_BASE=http://localhost:8080 SP_INSTALL_CODE=… SP_DB_USER=… SP_DB_PASS=… SP_DB_NAME=… npm run test:e2e -- --install
   ```

3. `git commit -am "Release X.Y.Z" && git tag -a vX.Y.Z -m "SubjectsPlus X.Y.Z" && git push origin main --follow-tags`
