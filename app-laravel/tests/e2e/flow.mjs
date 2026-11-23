// End-to-end browser walkthrough with Playwright (headless Chromium), run inside Docker.
// tte-qr_internal has no internet, so install Playwright into a volume first (once):
//
//   docker run --rm -v tte-e2e:/e2e -w /e2e mcr.microsoft.com/playwright:v1.49.1-noble \
//     sh -c "npm init -y && npm i playwright@1.49.1"
//   docker run --rm --network tte-qr_internal -v tte-e2e:/e2e -v "<repo>:/repo" -w /e2e \
//     -e BASE=http://app:8000 -e WAKASEK_SECRET=... -e KEPSEK_SECRET=... \
//     mcr.microsoft.com/playwright:v1.49.1-noble sh -c "cp /repo/app-laravel/tests/e2e/flow.mjs . && node flow.mjs"
//
// Wait 30 s between runs: Fortify rejects a login code that was already used in the same window.
//
// Needs `php artisan tte:demo` data. Screenshots go to /repo/out/e2e.
import { createHmac } from 'node:crypto';
import { mkdirSync, writeFileSync } from 'node:fs';
import { chromium } from 'playwright';

const BASE = process.env.BASE ?? 'http://app:8000';
const OUT = '/repo/out/e2e';
const LOGIN_SECRET = 'JBSWY3DPEHPK3PXP';
mkdirSync(OUT, { recursive: true });

function base32(s) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of s.replace(/=+$/, ''))
        bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
    const bytes = [];
    for (let i = 0; i + 8 <= bits.length; i += 8)
        bytes.push(parseInt(bits.slice(i, i + 8), 2));
    return Buffer.from(bytes);
}
function totp(secret, at = Date.now()) {
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(at / 30000)));
    const h = createHmac('sha1', base32(secret)).update(counter).digest();
    const o = h[h.length - 1] & 0xf;
    return String((h.readUInt32BE(o) & 0x7fffffff) % 1_000_000).padStart(
        6,
        '0',
    );
}
const usedSteps = new Map();
async function freshCode(secret) {
    // Never reuse a step for the same secret (the Signing Service rejects replays).
    let step = Math.floor(Date.now() / 30000);
    if (usedSteps.get(secret) === step) {
        await new Promise((r) =>
            setTimeout(r, (30 - ((Date.now() / 1000) % 30)) * 1000 + 500),
        );
        step = Math.floor(Date.now() / 30000);
    }
    usedSteps.set(secret, step);
    return totp(secret);
}

function minimalPdf() {
    const objs = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        null,
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    const text =
        'BT /F1 18 Tf 72 760 Td (SURAT KETERANGAN - uji end-to-end) Tj ET';
    objs[3] = `<< /Length ${text.length} >>\nstream\n${text}\nendstream`;
    let out = '%PDF-1.7\n';
    const offsets = [];
    objs.forEach((body, i) => {
        offsets.push(out.length);
        out += `${i + 1} 0 obj\n${body}\nendobj\n`;
    });
    const xref = out.length;
    out += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n`;
    for (const o of offsets) out += `${String(o).padStart(10, '0')} 00000 n \n`;
    out += `trailer\n<< /Size ${objs.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
    return Buffer.from(out, 'latin1');
}

let n = 0;
async function shot(page, name) {
    await page.screenshot({
        path: `${OUT}/${String(++n).padStart(2, '0')}-${name}.png`,
        fullPage: true,
    });
    console.log(`screenshot ${n} ${name}  ${page.url()}`);
}

async function login(page, email) {
    await page.goto(`${BASE}/login`);
    await page.fill('input[name=email]', email);
    await page.fill('input[name=password]', 'password');
    await page.click('[data-test=login-button]');
    await page.waitForURL(/two-factor-challenge/);
    await page
        .locator('[data-input-otp], input[autocomplete=one-time-code], input')
        .first()
        .focus();
    await page.keyboard.type(await freshCode(LOGIN_SECRET));
    await page
        .getByRole('button', { name: /continue|lanjut/i })
        .first()
        .click()
        .catch(() => {});
    await page.waitForURL(/\/documents/, { timeout: 15000 });
}

const browser = await chromium.launch();
const errors = [];
const ctx = async () => {
    const c = await browser.newContext({
        viewport: { width: 1280, height: 900 },
    });
    c.on('page', (p) =>
        p.on('pageerror', (e) => errors.push(`${p.url()}: ${e.message}`)),
    );
    return c;
};

let c;
let page;
try {
    // 1. Staff submits a document for two sequential signers.
    c = await ctx();
    page = await c.newPage();
    page.on('pageerror', (e) => errors.push(`${page.url()}: ${e.message}`));
    page.on('console', (m) => {
        if (['error', 'warning'].includes(m.type()))
            errors.push(`console.${m.type()}: ${m.text()}`);
    });
    await page.goto(`${BASE}/login`);
    await shot(page, 'login');
    await login(page, 'staf@tte.local');
    await shot(page, 'documents-index-empty');

    await page.goto(`${BASE}/documents/create`);
    writeFileSync('/tmp/surat.pdf', minimalPdf());
    await page.setInputFiles('#file', '/tmp/surat.pdf');
    await page.fill('#title', 'Surat Keterangan Uji E2E');
    await page.fill('#number', '421/E2E/2026');
    await page.selectOption('#pick', {
        label: 'Siti Aminah — Wakil Kepala Sekolah',
    });
    await page.getByRole('button', { name: 'Tambah' }).click();
    await page.selectOption('#pick', {
        label: 'Budi Santoso — Kepala Sekolah',
    });
    await page.getByRole('button', { name: 'Tambah' }).click();
    await page.waitForSelector('canvas');
    await page.waitForTimeout(3000);
    await shot(page, 'create-after-upload');
    await page
        .getByRole('button', { name: 'Taruh di halaman ini' })
        .first()
        .click();
    await page
        .getByRole('button', { name: 'Taruh di halaman ini' })
        .first()
        .click();

    // Drag the second box upwards by 150 px, and nudge the first with the keyboard.
    const box2 = page.getByRole('button', {
        name: /Kotak QR penanda tangan 2/,
    });
    const b = await box2.boundingBox();
    await page.mouse.move(b.x + b.width / 2, b.y + b.height / 2);
    await page.mouse.down();
    await page.mouse.move(b.x + b.width / 2, b.y + b.height / 2 - 150, {
        steps: 10,
    });
    await page.mouse.up();
    await page
        .getByRole('button', { name: /Kotak QR penanda tangan 1/ })
        .focus();
    await page.keyboard.press('Shift+ArrowUp');
    await shot(page, 'create-with-boxes');

    await page
        .getByRole('button', { name: 'Ajukan untuk ditandatangani' })
        .click();
    await page.waitForURL(/\/documents\/[0-9a-f]{32}$/, { timeout: 30000 });
    const docUrl = page.url();
    const publicId = docUrl.split('/').pop();
    await page.waitForTimeout(500);
    await shot(page, 'show-submitted');
    await c.close();

    // 2. Kepsek (second) cannot sign yet; wakasek signs, with one wrong code first.
    c = await ctx();
    page = await c.newPage();
    await login(page, 'kepsek@tte.local');
    await page.goto(docUrl);
    const kepsekCanSignEarly = await page
        .getByRole('heading', { name: 'Tandatangani dokumen' })
        .count();
    console.log(`kepsek sees sign panel before turn: ${kepsekCanSignEarly}`);
    await c.close();

    c = await ctx();
    page = await c.newPage();
    await login(page, 'wakasek@tte.local');
    await page.goto(docUrl);
    await page.locator('#totp_code').focus();
    await page.keyboard.type('000000');
    await page
        .getByRole('button', { name: 'Tandatangani', exact: true })
        .click();
    await page.waitForSelector('text=Kode salah', { timeout: 30000 });
    await shot(page, 'sign-wrong-code');
    await page.locator('#totp_code').focus();
    await page.keyboard.type(await freshCode(process.env.WAKASEK_SECRET));
    await page
        .getByRole('button', { name: 'Tandatangani', exact: true })
        .click();
    await page.waitForSelector('text=Sudah menandatangani', { timeout: 60000 });
    await shot(page, 'signed-by-wakasek');
    await c.close();

    // 3. Kepsek signs and completes the document.
    c = await ctx();
    page = await c.newPage();
    await login(page, 'kepsek@tte.local');
    await shot(page, 'kepsek-index-awaiting');
    await page.goto(docUrl);
    await page.locator('#totp_code').focus();
    await page.keyboard.type(await freshCode(process.env.KEPSEK_SECRET));
    await page
        .getByRole('button', { name: 'Tandatangani', exact: true })
        .click();
    // Locator waits, not waitForFunction: the app's CSP (no unsafe-eval) blocks string evaluation.
    await page
        .locator('li', { hasText: 'Sudah menandatangani' })
        .nth(1)
        .waitFor({ timeout: 60000 });
    await page.waitForTimeout(500);
    await shot(page, 'completed');
    const pdf = await (await c.request.get(`${docUrl}/file`)).body();
    writeFileSync(`${OUT}/signed.pdf`, pdf);
    await c.close();

    // 4. Public verification: QR page and upload check (no login).
    c = await ctx();
    page = await c.newPage();
    await page.goto(`${BASE}/v/${publicId}`);
    await shot(page, 'public-verify');
    await page.goto(`${BASE}/verifikasi`);
    await page.setInputFiles('#file', `${OUT}/signed.pdf`);
    await page.getByRole('button', { name: 'Periksa' }).click();
    await page.waitForSelector('text=Hasil pemeriksaan', { timeout: 30000 });
    await shot(page, 'public-upload-check');
    await c.close();

    // 5. Admin users page and mobile width of the verification page.
    c = await ctx();
    page = await c.newPage();
    await login(page, 'admin@tte.local');
    await page.goto(`${BASE}/admin/users`);
    await shot(page, 'admin-users');
    await page.goto(`${BASE}/settings/security`);
    await shot(page, 'settings-security');
    await c.close();

    c = await browser.newContext({ viewport: { width: 390, height: 844 } });
    page = await c.newPage();
    await page.goto(`${BASE}/v/${publicId}`);
    await shot(page, 'public-verify-mobile');
    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth > window.innerWidth,
    );
    console.log(`mobile horizontal overflow: ${overflow}`);
    await c.close();
} catch (error) {
    await page
        ?.screenshot({ path: `${OUT}/FAILED.png`, fullPage: true })
        .catch(() => {});
    console.log(`FAILED at ${page?.url()}: ${error.message.split('\n')[0]}`);
    process.exitCode = 1;
} finally {
    console.log(
        errors.length ? `JS errors:\n${errors.join('\n')}` : 'JS errors: none',
    );
    await browser.close();
}
