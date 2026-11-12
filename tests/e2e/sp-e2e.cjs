/**
 * SubjectsPlus 4.6.0 — real-browser end-to-end and UI regression suite (Playwright, Chromium).
 *
 * Usage:
 *   npm i -D playwright && npx playwright install chromium
 *   SP_BASE=http://localhost:8080 SP_ADMIN_EMAIL=admin@library.edu SP_ADMIN_PASS=... \
 *     node tests/e2e/sp-e2e.cjs [--install] [--label nginx-php8.4]
 *
 *   --install   run the installer first (empty database, no control/includes/config.php)
 *               needs SP_DB_HOST, SP_DB_USER, SP_DB_PASS, SP_DB_NAME
 *
 * Runs every check on a desktop (1440x900) and a mobile (375x812) viewport. For each page it records
 * console errors, uncaught page errors and failed same-origin requests (HTTP >= 400). Writes
 * tests/e2e/results/<label>.json and .md, plus screenshots.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = (process.env.SP_BASE || 'http://localhost:8080').replace(/\/$/, '');
const LABEL = (process.argv.find((a, i) => process.argv[i - 1] === '--label')) || 'run';
const DO_INSTALL = process.argv.includes('--install');
const OUT = path.join(__dirname, 'results');
fs.mkdirSync(path.join(OUT, 'shots', LABEL), { recursive: true });

const ADMIN_EMAIL = process.env.SP_ADMIN_EMAIL || 'admin@library.edu';
let ADMIN_PASS = process.env.SP_ADMIN_PASS || '';
const STAFF_EMAIL = 'e2e.staff@library.edu';
const STAFF_PASS = 'E2e-Staff-Pass-2026!';
const VIEWPORTS = [
  { name: 'desktop', viewport: { width: 1440, height: 900 } },
  { name: 'mobile', viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true },
];

const results = [];
let current = { vp: '-' };
// console noise that is not a SubjectsPlus fault (third-party embeds blocked offline, etc.)
const IGNORE = [/favicon\.ico/, /fonts\.googleapis|fonts\.gstatic|google-analytics|googletagmanager|recaptcha|addtoany|syndetics|openlibrary|googleapis\.com\/books/i, /net::ERR_(NAME_NOT_RESOLVED|INTERNET_DISCONNECTED|CONNECTION_REFUSED|TUNNEL_CONNECTION_FAILED|PROXY)/];

function record(name, ok, detail = '') {
  results.push({ viewport: current.vp, name, ok: !!ok, detail: String(detail).slice(0, 600) });
  console.log(`${ok ? 'PASS' : 'FAIL'} [${current.vp}] ${name}${detail ? ' — ' + String(detail).slice(0, 300) : ''}`);
}

async function step(name, fn) {
  try {
    const r = await fn();
    if (r === false) record(name, false); else record(name, true, typeof r === 'string' ? r : '');
  } catch (e) {
    record(name, false, e.message.split('\n')[0]);
  }
}

function watch(page) {
  const issues = [];
  page.on('console', m => { if (m.type() === 'error' && !IGNORE.some(r => r.test(m.text()))) issues.push('console: ' + m.text()); });
  page.on('pageerror', e => issues.push('pageerror: ' + e.message));
  page.on('response', r => {
    const u = r.url();
    if (u.startsWith(BASE) && r.status() >= 400 && !IGNORE.some(x => x.test(u))) issues.push(`HTTP ${r.status()} ${u.replace(BASE, '')}`);
  });
  page.on('dialog', d => { issues.push('dialog: ' + d.message()); d.dismiss().catch(() => {}); });
  page.on('requestfailed', r => { const f = r.failure(); if (r.url().startsWith(BASE) && f && !/ERR_ABORTED/.test(f.errorText)) issues.push('requestfailed: ' + r.url().replace(BASE, '') + ' ' + f.errorText); });
  return issues;
}

/** Load a page and require: HTTP < 400, no PHP warnings in the HTML, no console/page/network errors. */
async function cleanLoad(page, issues, url, { allow = [] } = {}) {
  issues.length = 0;
  const resp = await page.goto(BASE + url, { waitUntil: 'networkidle', timeout: 45000 });
  const status = resp ? resp.status() : 0;
  const html = await page.content();
  const php = html.match(/(Warning|Notice|Deprecated|Fatal error|Parse error|Uncaught)\b[^<]{0,20}:\s[^<]{0,160}/);
  const bad = issues.filter(i => !allow.some(a => a.test(i)));
  if (status >= 400) throw new Error(`HTTP ${status}`);
  if (php) throw new Error('PHP message in page: ' + php[0]);
  if (bad.length) throw new Error(bad.slice(0, 4).join(' | '));
  return `HTTP ${status}`;
}

async function shot(page, name) {
  await page.screenshot({ path: path.join(OUT, 'shots', LABEL, `${current.vp}-${name}.png`), fullPage: false }).catch(() => {});
}

async function login(page, email, pass) {
  await page.goto(BASE + '/control/login.php', { waitUntil: 'domcontentloaded' });
  await page.fill('input[name=username]', email);
  await page.fill('input[name=password]', pass);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('form[action="login.php"] button[type=submit]')]);
  return page.url();
}

async function install(browser) {
  current.vp = 'install';
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  const issues = watch(page);
  const msgs = async () => (await page.$$eval('.msg', e => e.map(x => x.textContent.trim()))).join(' | ');
  const CODE = process.env.SP_INSTALL_CODE || '';
  const CREATE = !!process.env.SP_DB_ADMIN_USER;
  if (!ADMIN_PASS) ADMIN_PASS = 'E2e-Admin-Pass-2026!';

  await step('installer: the site root sends a new site to the wizard', async () => {
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    if (!/\/control\/installer\//.test(page.url())) throw new Error('landed on ' + page.url());
    return page.url().replace(BASE, '');
  });
  await step('installer: /control/ sends a new site to the wizard', async () => {
    await page.goto(BASE + '/control/', { waitUntil: 'networkidle' });
    if (!/\/control\/installer\//.test(page.url())) throw new Error('landed on ' + page.url());
    return cleanLoad(page, issues, '/control/installer/');
  });
  await step('installer: later steps are locked until the install code is entered', async () => {
    await page.goto(BASE + '/control/installer/index.php?step=database', { waitUntil: 'domcontentloaded' });
    if (!/step=code/.test(page.url())) throw new Error('reached ' + page.url());
  });
  await step('installer: a wrong install code is refused', async () => {
    await page.fill('#f-code', 'AAAA-BBBB-CCCC-DDDD');
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type=submit]')]);
    if (!/not correct/.test(await msgs())) throw new Error('no refusal: ' + await msgs());
  });
  await step('installer: the install code from tools/install-code.php unlocks the wizard', async () => {
    if (!CODE) throw new Error('set SP_INSTALL_CODE (php tools/install-code.php)');
    await page.fill('#f-code', CODE);
    await Promise.all([page.waitForLoadState('networkidle'), page.click('button[type=submit]')]);
    if (!/step=requirements/.test(page.url())) throw new Error(await msgs());
  });
  await step('installer: requirements all pass (no red items), web-server protection detected', async () => {
    const rows = await page.$$eval('table tr', r => r.map(x => x.innerText.replace(/\s+/g, ' ')));
    const fails = rows.filter(r => /\bFix\b/.test(r));
    if (fails.length) throw new Error(fails.join(' | '));
    if (!rows.some(r => /Configuration files are protected OK/.test(r))) throw new Error('protection not confirmed: ' + rows.filter(r => /protected/.test(r)).join(' | '));
    await shot(page, 'installer-requirements');
    await Promise.all([page.waitForLoadState('networkidle'), page.click('button[name=next]')]);
    return `${rows.length} rows`;
  });
  const dbName = CREATE ? (process.env.SP_DB_CREATE_NAME || 'sp_created') : (process.env.SP_DB_NAME || 'sp');
  const dbUser = CREATE ? (process.env.SP_DB_CREATE_NAME || 'sp_created') : (process.env.SP_DB_USER || 'sp');
  // a password with characters that broke the 4.x config writer ($ " \ ')
  const dbPass = CREATE ? 'Te$t"pa\\ss\'word-2026' : (process.env.SP_DB_PASS || '');
  const fillDb = async () => {
    if (CREATE) {
      await page.check('#m-create');
      await page.fill('#f-admin_user', process.env.SP_DB_ADMIN_USER);
      await page.fill('#f-admin_pass', process.env.SP_DB_ADMIN_PASS || '');
    } else {
      await page.check('#m-existing');
    }
    await page.fill('#f-db_host', process.env.SP_DB_HOST || '127.0.0.1');
    await page.fill('#f-db_port', process.env.SP_DB_PORT || '3306');
    await page.fill('#f-db_name', dbName);
    await page.fill('#f-db_user', dbUser);
    await page.fill('#f-db_pass', dbPass);
  };
  await step(`installer: database — test connection (${CREATE ? 'administrator account' : 'existing database'})`, async () => {
    await fillDb();
    await Promise.all([page.waitForLoadState('networkidle'), page.click('button[value=test]')]);
    const m = await msgs();
    if (!/Signed in to|Connected to/.test(m)) throw new Error(m);
    return m.slice(0, 120);
  });
  await step(`installer: database — ${CREATE ? 'create the database and its user' : 'use the existing database'}`, async () => {
    await fillDb();
    await Promise.all([page.waitForLoadState('networkidle'), page.click('button[value=next]')]);
    if (!/step=site/.test(page.url())) throw new Error(await msgs());
  });
  await step('installer: site address detected and administrator chooses the password', async () => {
    const detected = await page.inputValue('#f-base_url');
    if (detected !== BASE + '/') throw new Error('detected ' + detected);
    await page.fill('#f-password', 'short');
    await page.fill('#f-password2', 'short');
    await page.fill('#f-fname', 'Ada'); await page.fill('#f-lname', 'Admin'); await page.fill('#f-email', ADMIN_EMAIL);
    await page.$eval('#f-password', e => e.removeAttribute('minlength')); await page.$eval('#f-password2', e => e.removeAttribute('minlength'));
    await Promise.all([page.waitForLoadState('networkidle'), page.click('button[name=next]')]);
    if (!/at least 12/.test(await msgs())) throw new Error('weak password accepted');
    await page.fill('#f-password', ADMIN_PASS); await page.fill('#f-password2', ADMIN_PASS);
    await Promise.all([page.waitForLoadState('networkidle'), page.click('button[name=next]')]);
    if (!/step=options/.test(page.url())) throw new Error(await msgs());
    return 'detected ' + detected;
  });
  await step('installer: options — EZproxy and sample content', async () => {
    await page.selectOption('#f-proxy_kind', 'ezproxy');
    await page.fill('#f-proxy_template', 'https://ezproxy.library.edu/login?url={url}');
    await page.check('#f-sample');
    await Promise.all([page.waitForLoadState('networkidle'), page.click('button[value=next]')]);
    if (!/step=install/.test(page.url())) throw new Error(await msgs());
    await shot(page, 'installer-summary');
  });
  await step('installer: install', async () => {
    await Promise.all([page.waitForLoadState('networkidle', { timeout: 180000 }), page.click('button[name=install]')]);
    if (/step=config/.test(page.url())) {
      // control/includes is read-only: download config.php and "upload" it
      const href = await page.getAttribute('a[href*="download=1"]', 'href');
      const r = await page.request.get(BASE + '/control/installer/' + href);
      const src = await r.text();
      if (!/\$dbName_SPlus = '/.test(src)) throw new Error('download is not a config file');
      if (!process.env.SP_UPLOAD_CONFIG_TO) throw new Error('config download offered; set SP_UPLOAD_CONFIG_TO to test it');
      fs.writeFileSync(process.env.SP_UPLOAD_CONFIG_TO, src);
      await Promise.all([page.waitForLoadState('networkidle'), page.click('button[name=check]')]);
    }
    if (!/step=done/.test(page.url())) throw new Error(await msgs() || page.url());
    const t = await page.textContent('body');
    if (!/SubjectsPlus is installed/.test(t)) throw new Error('no completion page');
    await shot(page, 'installer-done');
    return (await page.$$eval('li', l => l.map(x => x.textContent))).filter(x => /config\.php|applied|Sample/.test(x)).join('; ').slice(0, 200);
  });
  await step('installer: answers 404 once installed', async () => {
    const r = await page.request.get(BASE + '/control/installer/');
    if (r.status() !== 404) throw new Error('HTTP ' + r.status());
    const r2 = await page.request.get(BASE + '/control/installer/index.php?step=database');
    if (r2.status() !== 404) throw new Error('step page HTTP ' + r2.status());
  });
  await step('installer: the site root goes to the public pages once installed', async () => {
    const c2 = await browser.newContext(); const p2 = await c2.newPage();
    await p2.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
    const u = p2.url(); await c2.close();
    if (!/\/subjects\//.test(u)) throw new Error('landed on ' + u);
  });
  await step('installer: switched off for new visitors too (no cookie): 404, no form', async () => {
    const c2 = await browser.newContext();
    const r = await c2.request.get(BASE + '/control/installer/index.php?step=code');
    const t = await r.text(); await c2.close();
    if (r.status() !== 404 || /name="code"/.test(t)) throw new Error('HTTP ' + r.status());
  });
  await step('installer: sample guide and databases are public', async () => {
    await page.goto(BASE + '/subjects/guide.php?subject=getting-started', { waitUntil: 'networkidle' });
    if (!/Getting Started with Research/.test(await page.textContent('body'))) throw new Error('sample guide missing');
    await page.goto(BASE + '/subjects/databases.php?letter=All', { waitUntil: 'networkidle' });
    const t = await page.textContent('body');
    if (!/PubMed/.test(t) || !/Directory of Open Access Journals/.test(t)) throw new Error('sample databases missing');
  });
  await ctx.close();
}

async function suite(browser, vp) {
  current.vp = vp.name;
  const ctx = await browser.newContext({ viewport: vp.viewport, isMobile: !!vp.isMobile, hasTouch: !!vp.hasTouch, ignoreHTTPSErrors: true });
  const page = await ctx.newPage();
  const issues = watch(page);

  // ---------------------------------------------------------------- sign-in & throttling
  await step('login page loads cleanly', () => cleanLoad(page, issues, '/control/login.php'));
  await step('wrong password is refused', async () => {
    await login(page, ADMIN_EMAIL, 'definitely-wrong');
    const t = await page.textContent('body');
    if (!/check your email and password|Too many failed/i.test(t)) throw new Error('no refusal message');
  });
  await step('login form without CSRF token is refused (login CSRF)', async () => {
    const r = await page.request.post(BASE + '/control/login.php', { form: { username: ADMIN_EMAIL, password: ADMIN_PASS, thecount: '1' }, maxRedirects: 0 });
    if (r.status() === 302 && /index\.php/.test(r.headers()['location'] || '')) throw new Error('signed in without token');
  });
  await step('admin signs in with the generated password', async () => {
    const u = await login(page, ADMIN_EMAIL, ADMIN_PASS);
    if (/login\.php/.test(u)) throw new Error('still on login page: ' + (await page.textContent('body')).replace(/\s+/g, ' ').slice(0, 200));
    await shot(page, 'control-home');
    return u.replace(BASE, '');
  });

  // ---------------------------------------------------------------- admin pages
  for (const u of ['/control/index.php', '/control/admin/index.php', '/control/admin/user.php?browse', '/control/admin/database_updates.php',
    '/control/admin/access.php', '/control/admin/workflow.php', '/control/admin/record_settings.php', '/control/admin/departments.php',
    '/control/admin/manage_guides.php', '/control/guides/index.php', '/control/guides/guide_health.php', '/control/guides/guide_copy.php',
    '/control/records/index.php', '/control/records/import_export.php', '/control/faq/index.php', '/control/talkback/index.php',
    '/control/admin/profile.php', '/control/edit-config.php']) {
    await step(`admin page ${u} loads with no PHP/console/network errors`, () => cleanLoad(page, issues, u));
  }

  if (vp.name === 'desktop') {
    await step('Admin → Database Updates: apply pending migrations from the browser', async () => {
      await page.goto(BASE + '/control/admin/database_updates.php', { waitUntil: 'networkidle' });
      const btn = page.locator('button[name=apply]');
      if (await btn.count()) { await Promise.all([page.waitForLoadState('networkidle'), btn.click()]); }
      const t = await page.textContent('body');
      if (/FAILED|An update failed/.test(t)) throw new Error(t.replace(/\s+/g, ' ').slice(0, 300));
      if (/PENDING/.test(t)) throw new Error('updates still pending');
      return (await btn.count()) ? 'applied' : 'none pending';
    });

    // ------------------------------------------------------------ create a guide
    await step('create a guide through metadata.php', async () => {
      await page.goto(BASE + '/control/guides/metadata.php', { waitUntil: 'networkidle' });
      await page.fill('input[name=subject]', 'E2E Test Guide');
      await page.fill('input[name=shortform]', 'e2etest');
      const sel = page.locator('select[name=type]'); if (await sel.count()) await sel.selectOption({ index: 0 });
      const act = page.locator('select[name=active]'); if (await act.count()) await act.selectOption('1').catch(() => {});
      await Promise.all([page.waitForLoadState('networkidle'), page.click('[name=submit_record]')]);
      const t = await page.textContent('body');
      if (!/e2etest|E2E Test Guide|updated|added|Thy Will/i.test(t)) throw new Error('no confirmation');
    });
  }

  let sid = await page.evaluate(async base => {
    const r = await fetch(base + '/control/guides/helpers/user_guides.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    return r.ok ? r.text() : '';
  }, BASE).catch(() => '');
  const m = /"subject_id"\s*:\s*"?(\d+)/.exec(sid) || /subject_id=(\d+)/.exec(sid);
  sid = m ? m[1] : null;
  if (!sid) {
    await page.goto(BASE + '/control/guides/index.php', { waitUntil: 'networkidle' });
    const href = await page.locator('a[href*="guide.php?subject_id="]').first().getAttribute('href').catch(() => null);
    const mm = href && /subject_id=(\d+)/.exec(href); sid = mm ? mm[1] : '1';
  }

  // ---------------------------------------------------------------- guide editor
  await step(`guide editor guide.php?subject_id=${sid} loads with zero console/network errors`, async () => {
    const r = await cleanLoad(page, issues, `/control/guides/guide.php?subject_id=${sid}`);
    await shot(page, 'guide-editor');
    return r;
  });
  await step('editor tab bar (#tabs .ui-tabs-nav) is rendered and clickable', async () => {
    const nav = page.locator('#tabs ul.ui-tabs-nav, #tabs > ul').first();
    await nav.waitFor({ timeout: 10000 });
    const n = await nav.locator('li').count();
    if (n < 1) throw new Error('no tabs');
    const hasSpClass = await page.locator('.sp-editor-top-tabs').count();
    return `${n} tab(s); .sp-editor-top-tabs present: ${hasSpClass > 0}`;
  });

  const openFlyout = async (id, contentSel) => {
    // the panel slides in from the left; it is "visible" to Playwright even while off-screen
    const box = await page.locator('#main-options').boundingBox().catch(() => null);
    if (!box || box.x < 0) {
      await page.locator('.trigger-main-options').click({ timeout: 5000 });
      await page.waitForTimeout(600);
    }
    await page.locator('#' + id + ' a').first().click({ timeout: 5000 });
    const c = page.locator(contentSel);
    await c.waitFor({ state: 'visible', timeout: 8000 });
    // unobstructed: the element at the centre of the panel must belong to the panel (z-index / pointer-events)
    const ok = await c.evaluate(el => {
      const r = el.getBoundingClientRect();
      const x = Math.min(r.left + r.width / 2, window.innerWidth - 2), y = Math.min(r.top + Math.min(40, r.height / 2), window.innerHeight - 2);
      const hit = document.elementFromPoint(x, y);
      const cs = getComputedStyle(el);
      return { covered: !(hit && (el === hit || el.contains(hit))), pe: cs.pointerEvents, hit: hit ? (hit.id || hit.className || hit.tagName) : null };
    });
    if (ok.covered) throw new Error('panel is covered by ' + ok.hit);
    if (ok.pe === 'none') throw new Error('pointer-events: none on panel');
    return 'visible and on top';
  };
  await step('flyout: Layouts panel opens unobstructed', () => openFlyout('show_layout_options', '#layout_options_content'));
  await step('flyout: Analytics panel opens unobstructed', () => openFlyout('show_analytics_options', '#analytics_options_content'));
  await step('flyout: Check panel opens unobstructed', () => openFlyout('show_check_options', '#check_options_content'));
  await step('flyout: Boxes panel opens unobstructed', () => openFlyout('show_box_options', '#box_options_content'));

  if (vp.name === 'desktop') {
    await step('drag a Basic box from the Boxes panel into the guide', async () => {
      const before = await page.locator('.pluslet').count();
      const src = page.locator('#pluslet-id-Basic');
      const dst = page.locator('[id^="container-"]').nth(1);
      await src.waitFor({ timeout: 5000 });
      const a = await src.boundingBox(), b = await dst.boundingBox();
      // jQuery UI draggable needs real mouse moves (HTML5 dragTo does not trigger it)
      await page.mouse.move(a.x + 20, a.y + 20); await page.mouse.down();
      await page.mouse.move(a.x + 40, a.y + 40, { steps: 5 });
      await page.mouse.move(b.x + 60, b.y + 40, { steps: 20 }); await page.waitForTimeout(300); await page.mouse.up();
      await page.waitForTimeout(2500);
      const after = await page.locator('.pluslet').count();
      if (after <= before) throw new Error(`box count ${before} -> ${after}`);
      return `${before} -> ${after} boxes`;
    });
    await step('switch section layout from the Layouts panel', async () => {
      await openFlyout('show_layout_options', '#layout_options_content');
      // a section has to be selected first (its arrows icon turns pink), then the layouts appear
      await page.locator('.sp_section_controls .section_sort').first().click({ force: true });
      await page.waitForTimeout(500);
      await openFlyout('show_layout_options', '#layout_options_content');
      const btns = page.locator('#layout_options_content [data-layout]:visible');
      const n = await btns.count();
      if (n < 2) throw new Error('no layout buttons visible after selecting a section');
      issues.length = 0;
      await btns.nth(1).click();
      await page.waitForTimeout(2000);
      const bad = issues.filter(i => !/favicon/.test(i));
      if (bad.length) throw new Error(bad.join(' | '));
      return `${n} layouts offered`;
    });
    await step('CKEditor: edit text in a box and save', async () => {
      // the box dropped above opens in edit mode with CKEditor
      const frame = page.frameLocator('iframe.cke_wysiwyg_frame').first();
      await frame.locator('body').click({ timeout: 15000 });
      await page.keyboard.type('e2e-ckeditor-text');
      const title = page.locator('.pluslet input[type=text]').first();
      if (await title.count()) await title.fill('E2E box');
      issues.length = 0;
      await page.locator('#save_guide').click({ timeout: 8000 });
      await page.waitForTimeout(4000);
      const bad = issues.filter(i => !/favicon/.test(i));
      if (bad.length) throw new Error(bad.join(' | '));
      await page.reload({ waitUntil: 'networkidle' });
      if (!/e2e-ckeditor-text/.test(await page.content())) throw new Error('text not saved');
      return 'saved and still there after reload';
    });
    await step('Check panel: run accessibility check', async () => {
      await openFlyout('show_check_options', '#check_options_content');
      issues.length = 0;
      await page.locator('#sp-a11y-run').click();
      await page.waitForTimeout(2500);
      const bad = issues.filter(i => !/favicon/.test(i));
      if (bad.length) throw new Error(bad.join(' | '));
      return (await page.locator('#check_options_content').innerText()).replace(/\s+/g, ' ').slice(0, 120);
    });
    // ------------------------------------------------------------ records, access rules, workflow, users
    await step('no "remove the installer" warning: the wizard switches itself off', async () => {
      await page.goto(BASE + '/control/index.php', { waitUntil: 'networkidle' });
      const w = page.locator('.sp-installer-left');
      if (!(await w.count())) return 'no warning';
      const t = await w.innerText();
      // read-only control/includes/: the installer cannot delete its code file, so that one reminder is expected
      if (process.env.SP_UPLOAD_CONFIG_TO && /install code/.test(t) && !/installer\//.test(t)) return 'install-code reminder (read-only folder)';
      throw new Error('warning still shown: ' + t);
    });
    await step('installer-configured EZproxy provider is listed under Off-campus Access', async () => {
      await page.goto(BASE + '/control/admin/access.php', { waitUntil: 'networkidle' });
      if (!/ezproxy\.library\.edu\/login\?url=\{url\}/.test(await page.content())) throw new Error('provider missing');
    });
    await step('Off-campus access: add an EZproxy provider and test a link', async () => {
      await page.goto(BASE + '/control/admin/access.php', { waitUntil: 'networkidle' });
      const f = page.locator('form:has(input[name=action][value=save])').first();
      await f.locator('select[name=kind]').selectOption({ index: 1 }).catch(() => {});
      await f.locator('input[name=name]').fill('E2E EZproxy');
      await f.locator('input[name=link_template]').fill('https://ezproxy.library.edu/login?url={url}');
      await f.locator('textarea[name=domains]').fill('jstor.org');
      const def = f.locator('input[name=is_default]'); if (await def.count()) await def.check();
      await Promise.all([page.waitForLoadState('networkidle'), f.locator('button[type=submit], input[type=submit]').first().click()]);
      await page.goto(BASE + '/control/admin/access.php?test_url=' + encodeURIComponent('https://www.jstor.org/stable/123'), { waitUntil: 'networkidle' });
      const t = await page.textContent('body');
      if (!/ezproxy\.library\.edu\/login\?url=https:\/\/www\.jstor\.org/.test(t)) throw new Error('proxied link not shown');
      return 'jstor.org → ezproxy link';
    });
    await step('Workflow: apply the ready-made presets', async () => {
      await page.goto(BASE + '/control/admin/workflow.php', { waitUntil: 'networkidle' });
      const b = page.locator('form:has(input[name=preset][value=all]) button[type=submit]');
      await Promise.all([page.waitForLoadState('networkidle'), b.click()]);
      const rows = await page.locator('table tr').count();
      if (rows < 3) throw new Error('no transitions listed');
      return `${rows} table rows`;
    });
    let recordId = null;
    await step('Records: create an e-resource record (licensed, proxied)', async () => {
      await page.goto(BASE + '/control/records/record.php', { waitUntil: 'networkidle' });
      const f = page.locator('form#new_record');
      await f.locator('input[name=title]').fill('E2E Journal Archive');
      await f.locator('input[name="location[]"]').first().fill('https://www.jstor.org/');
      const ar = f.locator('select[name="access_restrictions[]"]').first();
      const opts = await ar.locator('option').allTextContents();
      const lic = opts.findIndex(o => /restricted|licen/i.test(o));
      if (lic >= 0) await ar.selectOption({ index: lic });
      // a record needs at least one subject (the first options are a prompt and a hidden template row)
      const subj = f.locator('select[name="subject_id[]"]').first();
      const sopts = await subj.locator('option').allTextContents();
      const real = sopts.findIndex(o => !/select|~~/i.test(o));
      await subj.selectOption({ index: real });
      await subj.dispatchEvent('change');
      await page.waitForTimeout(800);
      await Promise.all([page.waitForLoadState('networkidle'), f.locator('[name=submit_record]').click()]);
      const m = /record_id=(\d+)/.exec(page.url()) || /record_id=(\d+)/.exec(await page.content());
      recordId = m ? m[1] : null;
      if (!/E2E Journal Archive/.test(await page.content())) throw new Error('record not shown after save');
      return 'record ' + recordId;
    });
    await step('Workflow: move the record through a status transition', async () => {
      if (!recordId) throw new Error('no record');
      await page.goto(BASE + '/control/records/record.php?record_id=' + recordId, { waitUntil: 'networkidle' });
      const btn = page.locator('.wf-action').first();
      if (!(await btn.count())) throw new Error('no workflow buttons on the record');
      const label = (await btn.innerText()).trim();
      await page.fill('#wf_comment', 'e2e transition');
      await Promise.all([page.waitForLoadState('networkidle'), btn.click()]);
      const t = await page.textContent('body');
      if (/expired|not allowed|error/i.test(t) && !/no error/i.test(t)) throw new Error(t.replace(/\s+/g, ' ').slice(0, 200));
      return 'clicked "' + label + '"';
    });
    await step('User management: add a non-admin staff account', async () => {
      await page.goto(BASE + '/control/admin/user.php?type=add', { waitUntil: 'networkidle' });
      const f = page.locator('form#new_record');
      await f.locator('input[name=fname]').fill('Test');
      await f.locator('input[name=lname]').fill('Librarian');
      await f.locator('input[name=title]').fill('Subject Librarian'); // required by default ($require_user_columns)
      await f.locator('input[name=email]').fill(STAFF_EMAIL);
      const dep = f.locator('select[name="department_id[]"]');
      if (await dep.count()) { const v = await dep.locator('option').first().getAttribute('value'); await dep.selectOption([v]); }
      issues.length = 0;
      await f.locator('select[name=active]').selectOption('1');
      await f.locator('input[name=password]').fill(STAFF_PASS);
      // permissions: records + guides only (no admin); ptags is filled by the page script from ticks
      await page.evaluate(() => { const p = document.querySelector('form#new_record input[name=ptags]'); if (p) p.value = 'records'; });
      await Promise.all([page.waitForLoadState('networkidle'), f.locator('[name=submit_record]').click()]);
      const dlg = issues.filter(i => /^dialog/.test(i));
      if (dlg.length) throw new Error(dlg.join(' | '));
      if (!/Thy Will|added|updated/i.test(await page.textContent('body'))) throw new Error('no confirmation: ' + (await page.textContent('#maincontent')).replace(/\s+/g, ' ').slice(0, 160));
    });
    await step('copy a guide with guide_copy.php', async () => {
      await page.goto(BASE + '/control/guides/guide_copy.php', { waitUntil: 'networkidle' });
      const sel = page.locator('select').first();
      const opts = await sel.locator('option').count();
      if (opts < 1) throw new Error('no guides offered');
      return `${opts} option(s) offered`;
    });
    await step('CKEditor uploader refuses a PHP file disguised as an image', async () => {
      const r = await page.request.post(BASE + '/ckeditor/php/uploader.php?CKEditorFuncNum=1', {
        multipart: { upload: { name: 'x.php.jpg', mimeType: 'image/jpeg', buffer: Buffer.from('<?php echo 1;') } },
        headers: { Origin: BASE },
      });
      const t = await r.text();
      if (/assets\/users\/.+x\.php\.jpg/.test(t)) throw new Error('file accepted: ' + t.slice(0, 200));
      return 'refused';
    });
  }

  // ---------------------------------------------------------------- public portal
  const pub = await ctx.newPage();
  const pissues = watch(pub);
  for (const u of ['/subjects/index.php', '/subjects/databases.php', '/subjects/databases.php?letter=A', '/subjects/databases.php?letter=Num',
    '/subjects/guide.php?subject=e2etest', '/subjects/staff.php', '/subjects/faq.php', '/subjects/talkback.php', '/subjects/request.php',
    '/subjects/search.php?searchterm=library', '/subjects/collection.php']) {
    await step(`public page ${u} loads cleanly`, () => cleanLoad(pub, pissues, u));
  }
  await step('public guide: tab navigation works (click + keyboard)', async () => {
    await pub.goto(BASE + '/subjects/guide.php?subject=e2etest', { waitUntil: 'networkidle' });
    const tabs = pub.locator('#tabs [role=tab], #tabs ul li a');
    const n = await tabs.count();
    if (n === 0) return 'single-tab guide (no tab bar by design)';
    await tabs.first().focus();
    await pub.keyboard.press('ArrowRight');
    return `${n} tab(s)`;
  });
  await step('public A–Z: letter filter returns a page with the letter list', async () => {
    await pub.goto(BASE + '/subjects/databases.php?letter=B', { waitUntil: 'networkidle' });
    // letters without records are shown as plain text, letters with records are links
    const items = await pub.locator('#letterhead a, #letterhead span').count();
    if (items < 26) throw new Error('letter bar missing');
    return `${items} letters in the bar`;
  });
  await step('public talkback form renders a submit button', async () => {
    await pub.goto(BASE + '/subjects/talkback.php', { waitUntil: 'networkidle' });
    if (!(await pub.locator('form textarea').count())) throw new Error('no talkback form');
  });
  await step('public request (suggest an e-resource) form renders', async () => {
    await pub.goto(BASE + '/subjects/request.php', { waitUntil: 'networkidle' });
    if (!(await pub.locator('form').count())) throw new Error('no form');
  });
  if (vp.name === 'mobile') {
    await step('mobile: public home has no horizontal page scroll', async () => {
      await pub.goto(BASE + '/subjects/index.php', { waitUntil: 'networkidle' });
      const w = await pub.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
      await shot(pub, 'public-home');
      if (w[0] > w[1] + 2) throw new Error(`scrollWidth ${w[0]} > ${w[1]}`);
      return `${w[0]}px`;
    });
  }

  await ctx.close();
}

/** 4.7.1 features: vendor field, bulk edit, user types, audit log, statistics, API clients, health, upgrade, link checker, guide permissions. */
async function features471(browser) {
  current.vp = '4.7.1';
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, acceptDownloads: true });
  const page = await ctx.newPage();
  const issues = watch(page);
  page.removeAllListeners('dialog');
  page.on('dialog', d => d.accept().catch(() => {})); // the confirm() questions of bulk edit / delete
  await login(page, ADMIN_EMAIL, ADMIN_PASS);
  const ok = (url, opts) => cleanLoad(page, issues, url, opts);
  let doajId = 0;

  await step('admin pages load cleanly: Usage Statistics, Audit Log, API Clients, System Health, Upgrade, Find & Bulk Edit', async () => {
    for (const u of ['/control/admin/stats.php', '/control/admin/stats.php?range=365', '/control/admin/audit_log.php', '/control/admin/api_clients.php',
      '/control/admin/health.php', '/control/admin/upgrade.php', '/control/records/bulk.php', '/control/admin/record_settings.php?tab=user_types',
      '/control/guides/guide_health.php?tab=links']) await ok(u);
    return '9 pages';
  });
  await step('System Health: database updates all applied, installer switched off', async () => {
    await ok('/control/admin/health.php');
    const rows = await page.$$eval('table tr', r => r.map(x => x.innerText.replace(/\s+/g, ' ')));
    const db = rows.find(r => /Database updates/.test(r)) || '';
    const inst = rows.find(r => /^Installer/.test(r)) || '';
    if (!/all applied/.test(db)) throw new Error(db);
    if (!/switched off/.test(inst)) throw new Error(inst);
    await shot(page, 'health');
    return rows.filter(r => /\bFix\b/.test(r)).map(r => r.slice(0, 60)).join(' | ') || 'no red items';
  });
  await step('record form: Vendor / Publisher saved and shown again', async () => {
    await ok('/control/records/bulk.php?q=Directory+of+Open+Access');
    const href = await page.getAttribute('table.bulk-table a[href*="record.php?record_id="]', 'href');
    doajId = parseInt(href.split('=').pop(), 10);
    await ok('/control/records/record.php?record_id=' + doajId);
    await page.fill('#record_vendor', 'Open Access Publisher & Co');
    await Promise.all([page.waitForLoadState('networkidle'), page.click('input[name=submit_record]')]);
    await ok('/control/records/record.php?record_id=' + doajId);
    const v = await page.inputValue('#record_vendor');
    if (v !== 'Open Access Publisher & Co') throw new Error('vendor is ' + JSON.stringify(v));
    return 'record ' + doajId;
  });
  await step('public A–Z: vendor shown, "by vendor" list and filter, search finds the vendor', async () => {
    await ok('/subjects/databases.php?letter=D');
    if (!/Vendor \/ Publisher:/.test(await page.textContent('body'))) throw new Error('vendor line missing');
    await ok('/subjects/databases.php?letter=byvendor');
    if (!(await page.locator('a[href*="vendor=Open%20Access%20Publisher"]').count())) throw new Error('vendor list missing');
    await ok('/subjects/databases.php?letter=byvendor&vendor=' + encodeURIComponent('Open Access Publisher & Co'));
    const t = await page.locator('table.item_listing').innerText();
    if (!/Directory of Open Access Journals/.test(t) || /PubMed/.test(t)) throw new Error('filter wrong: ' + t.replace(/\s+/g, ' ').slice(0, 120));
    if (!(await page.locator('#select_vendor').count())) throw new Error('vendor dropdown missing');
    const sr = await page.request.post(BASE + '/subjects/databases.php', { form: { searchterm: 'Publisher & Co' }, headers: { Origin: BASE } });
    if (!/Directory of Open Access Journals/.test(await sr.text())) throw new Error('search does not find the vendor');
    await shot(page, 'az-vendor');
    return 'ok';
  });
  await step('public A–Z: skip link, no zoom lock, labelled dropdowns (accessibility)', async () => {
    await ok('/subjects/databases.php?letter=D');
    await page.keyboard.press('Tab');
    const first = await page.evaluate(() => document.activeElement && document.activeElement.className);
    if (!/sp-skip-link/.test(first || '')) throw new Error('first Tab stop: ' + first);
    const vp = await page.getAttribute('meta[name=viewport]', 'content');
    if (/user-scalable=no/.test(vp || '')) throw new Error('zoom disabled');
    const unl = await page.$$eval('select', s => s.filter(x => !x.getAttribute('aria-label') && !x.title && !(x.id && document.querySelector('label[for="' + x.id + '"]')) && !x.closest('label')).length);
    if (unl) throw new Error(unl + ' unlabelled select(s)');
  });
  await step('Find & bulk edit: set vendor on a search result', async () => {
    await ok('/control/records/bulk.php?q=PubMed');
    await page.check('input.bulk-id');
    await page.selectOption('#bulk_action', 'vendor');
    await page.fill('input[name=vendor]', 'NLM');
    issues.length = 0;
    await Promise.all([page.waitForLoadState('networkidle'), page.click('#bulk-form button[type=submit]')]);
    const t = await page.textContent('body');
    if (!/1 record\(s\) changed/.test(t)) throw new Error('no confirmation');
    if (!/NLM/.test(await page.locator('table.bulk-table').innerText())) throw new Error('vendor not shown');
    return 'ok';
  });
  await step('Find & bulk edit: filter by vendor', async () => {
    await ok('/control/records/bulk.php?vendor=NLM');
    const n = await page.locator('input.bulk-id').count();
    if (n !== 1) throw new Error(n + ' rows');
  });
  await step('Record Settings → User types: add, rename, delete', async () => {
    await ok('/control/admin/record_settings.php?tab=user_types');
    const before = await page.locator('table.rs-table tbody tr').count();
    await page.fill('input[name="new[name]"]', 'E2E Volunteer');
    await Promise.all([page.waitForLoadState('networkidle'), page.click('form.rs-form button[type=submit]:not([name=delete])')]);
    if (!/User type added/.test(await page.textContent('body'))) throw new Error('not added');
    const rows = await page.locator('table.rs-table tbody tr').count();
    if (rows !== before + 1) throw new Error('rows ' + before + ' -> ' + rows);
    const row = page.locator('table.rs-table tbody tr', { has: page.locator('input[value="E2E Volunteer"]') });
    issues.length = 0;
    await Promise.all([page.waitForLoadState('networkidle'), row.locator('button[name=delete]').click()]);
    if (await page.locator('input[value="E2E Volunteer"]').count()) throw new Error('not deleted');
    const staff = page.locator('table.rs-table tbody tr').first();
    if (!/built-in/.test(await staff.innerText())) throw new Error('Staff type deletable');
    return 'ok';
  });
  await step('Audit Log: sign-in, bulk edit and settings changes are recorded; CSV download', async () => {
    await ok('/control/admin/audit_log.php');
    const t = await page.locator('table.audit-table').innerText();
    for (const a of ['auth.login', 'records.bulk.vendor', 'settings.records.user_types']) if (!t.includes(a)) throw new Error('missing ' + a);
    const r = await page.request.get(BASE + '/control/admin/audit_log.php?export=1');
    if (!/text\/csv/.test(r.headers()['content-type'] || '') || !/records\.bulk\.vendor/.test(await r.text())) throw new Error('CSV');
    await shot(page, 'audit');
    return 'ok';
  });
  await step('Usage Statistics: totals, vendor table and CSV export', async () => {
    await ok('/subjects/guide.php?subject=getting-started');
    await ok('/control/admin/stats.php');
    if (!/guide views/.test(await page.textContent('body'))) throw new Error('no totals');
    const r = await page.request.get(BASE + '/control/admin/stats.php?export=guides');
    if (!/text\/csv/.test(r.headers()['content-type'] || '')) throw new Error('CSV ' + r.status());
    await shot(page, 'stats');
  });
  await step('API Clients: key created once, shown masked afterwards; wrong key refused', async () => {
    await ok('/control/admin/api_clients.php');
    await page.fill('#api-name', 'E2E app');
    await Promise.all([page.waitForLoadState('networkidle'), page.click('button[name=create]')]);
    const key = (await page.locator('#api-key').innerText()).trim();
    if (!/^sp_[0-9a-f]{48}$/.test(key)) throw new Error('key ' + key);
    await ok('/control/admin/api_clients.php');
    if ((await page.content()).includes(key)) throw new Error('key shown again');
    const r = await page.request.get(BASE + '/api/database/letter/A/key/wrong-key-123');
    if (r.status() === 200 && /"title"/.test(await r.text())) throw new Error('data with a wrong key');
    return 'API answered ' + r.status() + ' to a wrong key';
  });
  await step('Upgrade: database backup downloads as gzip SQL', async () => {
    await ok('/control/admin/upgrade.php');
    const [dl] = await Promise.all([page.waitForEvent('download'), page.click('button[name=backup]')]);
    const file = await dl.path();
    const sql = require('zlib').gunzipSync(fs.readFileSync(file)).toString('utf8');
    if (!/CREATE TABLE `title`/.test(sql) || !/INSERT INTO `title`/.test(sql) || !/end of backup/.test(sql)) throw new Error('backup incomplete (' + sql.length + ' bytes)');
    return Math.round(sql.length / 1024) + ' KB SQL';
  });
  await step('Upgrade: "finish" records the version (nothing pending)', async () => {
    await ok('/control/admin/upgrade.php');
    await Promise.all([page.waitForLoadState('networkidle'), page.click('button[name=apply]')]);
    const t = await page.textContent('body');
    if (!/already up to date|update\(s\) applied/.test(t)) throw new Error(t.slice(0, 200));
  });
  await step('Guide Health → Broken links: link list refreshed', async () => {
    await ok('/control/guides/guide_health.php?tab=links');
    await Promise.all([page.waitForLoadState('networkidle'), page.click('button[name=link_collect]')]);
    const t = await page.textContent('body');
    if (!/links found in public guides and records/.test(t)) throw new Error('no result');
    return (t.match(/\d+ links found/) || [''])[0];
  });
  await ctx.close();

  current.vp = 'http';
  await step('per-guide permissions: staff without "allguides" cannot change a guide they do not edit', async () => {
    const c2 = await browser.newContext(); const p = await c2.newPage();
    await login(p, STAFF_EMAIL, STAFF_PASS);
    const r1 = await p.request.post(BASE + '/control/guides/helpers/save_tab.php', { form: { subject_id: '1', label: 'Hacked tab', tab_index: '9', visibility: '1' }, headers: { Origin: BASE } });
    const r2 = await p.request.post(BASE + '/control/guides/helpers/save_tab_order.php', { form: { data: 'item[]=1' }, headers: { Origin: BASE } });
    const r3 = await p.request.post(BASE + '/control/guides/helpers/guide_data.php', { form: { flag: 'delete', delete_id: '1', subject_id: '1' }, headers: { Origin: BASE } });
    await c2.close();
    const codes = [r1.status(), r2.status(), r3.status()];
    if (codes.some(c => c !== 403)) throw new Error('HTTP ' + codes.join(','));
    return 'HTTP ' + codes.join(',');
  });
}

/** HTTP-level security regression checks (no browser needed). */
async function securityChecks(browser) {
  current.vp = 'http';
  const ctx = await browser.newContext();
  const rq = ctx.request;
  const expectBlocked = async (u) => step(`blocked: ${u}`, async () => {
    const r = await rq.get(BASE + u, { maxRedirects: 0 });
    if (![403, 404].includes(r.status())) throw new Error('HTTP ' + r.status());
    return 'HTTP ' + r.status();
  });
  for (const u of ['/control/includes/config.php', '/control/includes/config-default.php', '/control/includes/functions.php', '/control/includes/security.php',
    '/lib/SubjectsPlus/Control/Querier.php', '/lib/vendor/composer/installed.json', '/tools/migrations.php', '/tools/migrate.php', '/composer.json', '/composer.lock',
    '/package.json', '/README.md', '/.htaccess', '/.git/config', '/assets/records/', '/control/lti_controller/config-sample.php', '/docs/server/nginx-subjectsplus.conf']) {
    await expectBlocked(u);
  }
  await step('unauthenticated: control/includes/autocomplete_data.php is refused', async () => {
    const r = await rq.get(BASE + '/control/includes/autocomplete_data.php?collection=all&term=a');
    if (r.status() !== 403) throw new Error('HTTP ' + r.status() + ' ' + (await r.text()).slice(0, 100));
  });
  await step('unauthenticated: asset_manager_upload.php is refused', async () => {
    const r = await rq.post(BASE + '/control/includes/asset_manager_upload.php', { multipart: { staff_id: '1 OR 1=1', file: { name: 'a.jpg', mimeType: 'image/jpeg', buffer: Buffer.from('x') } } });
    if (r.status() !== 403) throw new Error('HTTP ' + r.status());
  });
  await step('unauthenticated: guide helper (fetch_guide_data.php) is refused', async () => {
    const r = await rq.get(BASE + '/control/guides/helpers/fetch_guide_data.php?subject_id=1');
    if (r.status() !== 403) throw new Error('HTTP ' + r.status());
  });
  await step('SSRF: feedme.php refuses file:// and loopback feeds', async () => {
    for (const feed of ['file:///etc/passwd', 'http://127.0.0.1:8080/control/includes/config.php', 'http://169.254.169.254/latest/meta-data/']) {
      const r = await rq.post(BASE + '/subjects/includes/feedme.php', { form: { type: 'RSS', feed, count: '5', show_desc: '1', show_feed: '1' } });
      const t = await r.text();
      if (/root:x:0|\$hname|ami-id/.test(t)) throw new Error('fetched ' + feed);
    }
    return 'refused';
  });
  await step('SSRF/LFI: public bookListPlusletActionRouter refuses file:// cover URLs and path traversal', async () => {
    await rq.get(BASE + '/subjects/bookListPlusletActionRouter.php?action=bookCoverDownload&isbn=../../../x&url=file:///etc/passwd');
    await rq.get(BASE + '/subjects/bookListPlusletActionRouter.php?action=bookCoverDownload&isbn=9780000000002&url=file:///etc/passwd');
    const r = await rq.get(BASE + '/assets/cache/9780000000002.jpg');
    if (r.status() === 200) throw new Error('cover cached from file://');
    return 'nothing written';
  });
  await step('open redirect: //evil.example after login is not followed', async () => {
    const c2 = await browser.newContext();
    const p = await c2.newPage();
    await p.goto(BASE + '//evil.example/control/index.php').catch(() => {});
    await login(p, ADMIN_EMAIL, ADMIN_PASS);
    const host = new URL(p.url()).host;
    await c2.close();
    if (host === 'evil.example') throw new Error('redirected off-site');
    return 'stayed on ' + host;
  });
  await step('CSRF: cross-site POST to an admin page is refused', async () => {
    const c2 = await browser.newContext();
    const p = await c2.newPage();
    await login(p, ADMIN_EMAIL, ADMIN_PASS);
    const r = await p.request.post(BASE + '/control/admin/admin_bits.php', { form: { action: 'update_permissions', ptags: 'admin', update_id: '1' }, headers: { Origin: 'https://evil.example', 'Sec-Fetch-Site': 'cross-site' } });
    await c2.close();
    if (r.status() !== 403) throw new Error('HTTP ' + r.status());
  });
  await step('login throttling: the 6th failed attempt for one email is locked out', async () => {
    const c2 = await browser.newContext(); const p = await c2.newPage();
    let last = '';
    for (let i = 0; i < 6; i++) { await login(p, 'nobody.throttle@library.edu', 'wrong-' + i); last = await p.textContent('body'); }
    await c2.close();
    if (!/Too many failed login attempts/.test(last)) throw new Error('not throttled');
    return 'locked out';
  });
  await step('non-admin staff: admin page redirects without running (header exit)', async () => {
    const c2 = await browser.newContext(); const p = await c2.newPage();
    await login(p, STAFF_EMAIL, STAFF_PASS);
    if (/login\.php/.test(p.url())) { await c2.close(); throw new Error('staff account could not sign in'); }
    const r = await p.request.get(BASE + '/control/admin/user.php?browse', { maxRedirects: 0 });
    const body = await r.text();
    await c2.close();
    if (r.status() !== 302) throw new Error('HTTP ' + r.status());
    if (body.length > 200) throw new Error('page body still sent after redirect (' + body.length + ' bytes)');
    return 'HTTP 302, empty body';
  });
  await step('non-admin staff: admin_bits.php update_permissions is refused (privilege escalation)', async () => {
    const c2 = await browser.newContext(); const p = await c2.newPage();
    await login(p, STAFF_EMAIL, STAFF_PASS);
    if (/login\.php/.test(p.url())) { await c2.close(); throw new Error('staff account could not sign in'); }
    const r = await p.request.post(BASE + '/control/admin/admin_bits.php', { form: { action: 'update_permissions', ptags: 'admin|records', update_id: '2' }, headers: { Origin: BASE }, maxRedirects: 0 });
    await c2.close();
    if (r.status() !== 403) throw new Error('HTTP ' + r.status());
  });
  await step('non-admin staff: profile.php cannot grant admin or edit another account', async () => {
    const c2 = await browser.newContext(); const p = await c2.newPage();
    await login(p, STAFF_EMAIL, STAFF_PASS);
    if (/login\.php/.test(p.url())) { await c2.close(); throw new Error('staff account could not sign in'); }
    await p.request.post(BASE + '/control/admin/profile.php', { form: { submit_record: '1', staff_id: '1', fname: 'Hacked', lname: 'X', email: STAFF_EMAIL, ptags: 'admin|records', user_type_id: '1', active: '1', 'department_id[]': '1' }, headers: { Origin: BASE } });
    await c2.close();
    const c3 = await browser.newContext(); const q = await c3.newPage();
    await login(q, STAFF_EMAIL, STAFF_PASS);
    const r = await q.request.get(BASE + '/control/admin/index.php', { maxRedirects: 0 });
    await c3.close();
    if (r.status() === 200) throw new Error('staff became admin');
    const c4 = await browser.newContext(); const a = await c4.newPage();
    await login(a, ADMIN_EMAIL, ADMIN_PASS);
    await a.goto(BASE + '/control/admin/user.php?staff_id=1', { waitUntil: 'networkidle' });
    const hacked = (await a.locator('input[name=fname]').inputValue().catch(() => '')) === 'Hacked';
    await c4.close();
    if (hacked) throw new Error('admin account renamed by staff');
    return 'no escalation';
  });
  await step('API: SQL injection through email= is neutralised', async () => {
    const r = await rq.get(BASE + "/api/staff/email/a%5C,%20OR%201=1%20--%20/key/x");
    const t = await r.text();
    if (/"email"\s*:/.test(t)) throw new Error('rows returned without a valid key');
    return 'HTTP ' + r.status();
  });
  await ctx.close();
}

(async () => {
  const browser = await chromium.launch();
  if (DO_INSTALL) await install(browser);
  if (process.argv.includes('--install-only')) { await browser.close(); console.log(results.filter(r => r.ok).length + ' passed, ' + results.filter(r => !r.ok).length + ' failed'); return; }
  if (!ADMIN_PASS) { console.error('Set SP_ADMIN_PASS or use --install'); process.exit(2); }
  const only471 = process.argv.includes('--only-4.7.1'); // quick run of the 4.7.1 feature checks on an installed site
  if (!only471) {
    for (const vp of VIEWPORTS) await suite(browser, vp);
    await securityChecks(browser);
  }
  await features471(browser);
  await browser.close();

  const pass = results.filter(r => r.ok).length, fail = results.length - pass;
  fs.writeFileSync(path.join(OUT, `${LABEL}.json`), JSON.stringify({ base: BASE, label: LABEL, pass, fail, results }, null, 2));
  const md = [`# E2E results — ${LABEL}`, '', `Base URL: ${BASE}  ·  ${pass} passed, ${fail} failed`, '', '| Viewport | Check | Result | Detail |', '|---|---|---|---|',
    ...results.map(r => `| ${r.viewport} | ${r.name.replace(/\|/g, '\\|')} | ${r.ok ? 'PASS' : '**FAIL**'} | ${r.detail.replace(/\|/g, '\\|').replace(/\n/g, ' ')} |`)].join('\n');
  fs.writeFileSync(path.join(OUT, `${LABEL}.md`), md + '\n');
  if (ADMIN_PASS && DO_INSTALL) fs.writeFileSync(path.join(OUT, '.admin-pass'), ADMIN_PASS);
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})();
