# Releasing SubjectsPlus

## One-time: create the GitHub repository and push `main`

```sh
cd SubjectsPlus                                   # the source folder
git init -b main
git add -A
git commit -m "SubjectsPlus 4.6.0"
gh repo create <owner>/SubjectsPlus --public --source=. --remote=origin --push
#   or, without the GitHub CLI:
#   git remote add origin git@github.com:<owner>/SubjectsPlus.git && git push -u origin main
```

## Every release

1. Set the version in `control/includes/version.php`, `composer.json`, `package.json`
   and `lib/vendor/composer/installed.php`; add a section to `CHANGELOG.md`.
2. Run the checks:

   ```sh
   npm ci && npx playwright install chromium
   npm run build
   SP_BASE=http://localhost:8080 SP_ADMIN_EMAIL=admin@library.edu SP_ADMIN_PASS='…' npm run test:e2e
   ```

3. Commit, tag and push — the `release` workflow lints on PHP 8.1–8.4, builds, packages and publishes:

   ```sh
   git commit -am "Release 4.6.0"
   git tag -a v4.6.0 -m "SubjectsPlus 4.6.0"
   git push origin main --follow-tags
   ```

## Building the archives locally

```sh
bash build/package-release.sh                                  # Linux / macOS
powershell -ExecutionPolicy Bypass -File build\package-release.ps1   # Windows
```

Output in `dist/`: `SubjectsPlus-<version>-stable.zip`, `SubjectsPlus-<version>-stable.tar.gz`, `SHA256SUMS.txt`.
Verify with `cd dist && sha256sum -c SHA256SUMS.txt`. Only committed files are packed.
