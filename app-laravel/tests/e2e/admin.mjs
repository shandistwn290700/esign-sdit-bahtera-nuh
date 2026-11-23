// Browser walkthrough of the admin user management (edit, certificate guard, deactivate,
// reactivate). Run like flow.mjs (see its header) with `node admin.mjs`; no signing secrets needed.
// Leaves demo data as it found it. Certificate re-issue is covered by PHP tests instead,
// because it would invalidate the demo signing secrets used by flow.mjs.
import { createHmac } from 'node:crypto';
import { mkdirSync } from 'node:fs';
import { chromium } from 'playwright';

const BASE = process.env.BASE ?? 'http://app:8000';
const OUT = '/repo/out/e2e';
const LOGIN_SECRET = 'JBSWY3DPEHPK3PXP';
mkdirSync(OUT, { recursive: true });

function base32(s) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of s) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
    const bytes = [];
    for (let i = 0; i + 8 <= bits.length; i += 8)
        bytes.push(parseInt(bits.slice(i, i + 8), 2));
    return Buffer.from(bytes);
}
function totp(secret) {
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
    const h = createHmac('sha1', base32(secret)).update(counter).digest();
    const o = h[h.length - 1] & 0xf;
    return String((h.readUInt32BE(o) & 0x7fffffff) % 1_000_000).padStart(
        6,
        '0',
    );
}

let n = 0;
const shot = async (page, name) => {
    await page.screenshot({
        path: `${OUT}/admin-${String(++n).padStart(2, '0')}-${name}.png`,
        fullPage: true,
    });
    console.log(`screenshot admin-${n} ${name}`);
};
const row = (page, name) => page.locator('tr', { hasText: name });

const browser = await chromium.launch();
const errors = [];
const page = await (
    await browser.newContext({ viewport: { width: 1280, height: 900 } })
).newPage();
page.on('pageerror', (e) => errors.push(e.message));
page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));

try {
    await page.goto(`${BASE}/login`);
    await page.fill('input[name=email]', 'admin@tte.local');
    await page.fill('input[name=password]', 'password');
    await page.click('[data-test=login-button]');
    await page.waitForURL(/two-factor-challenge/);
    await page.locator('input').first().focus();
    await page.keyboard.type(totp(LOGIN_SECRET));
    await page
        .getByRole('button', { name: /lanjut/i })
        .first()
        .click()
        .catch(() => {});
    await page.waitForURL(/\/documents/);
    await page.goto(`${BASE}/admin/users`);

    // 1. Edit a non-signer: phone number.
    await page.getByRole('button', { name: 'Ubah data Rina Staf' }).click();
    await page.fill('#edit-phone', '081200000001');
    await shot(page, 'edit-dialog');
    await page.getByRole('button', { name: 'Simpan' }).click();
    await page.getByText('Data Rina Staf disimpan.').waitFor();

    // 2. Signer name change without re-issue is refused with an explanation.
    await page.getByRole('button', { name: 'Ubah data Budi Santoso' }).click();
    await page.fill('#edit-name', 'Budi Santoso, S.Pd.');
    await page.getByRole('button', { name: 'Simpan' }).click();
    // The help text also says "tercantum di sertifikat"; wait for the error wording only.
    await page.getByText(/Centang "Terbitkan ulang sertifikat"/).waitFor();
    await shot(page, 'signer-needs-reissue');
    await page.getByRole('button', { name: 'Batal' }).click();
    const unchanged = await row(page, 'kepsek@tte.local')
        .getByText('Budi Santoso', { exact: true })
        .count();
    console.log(`signer name unchanged: ${unchanged === 1}`);

    // 3. Deactivate the staff account (no certificate) and check it cannot log in.
    await page
        .getByRole('button', { name: 'Nonaktifkan akun Rina Staf' })
        .click();
    await page.fill('#deactivate-reason', 'Uji nonaktifkan akun');
    await shot(page, 'deactivate-dialog');
    await page
        .getByRole('button', { name: 'Nonaktifkan', exact: true })
        .click();
    await row(page, 'Rina Staf')
        .getByText('Nonaktif', { exact: true })
        .waitFor();
    await shot(page, 'after-deactivate');

    const guest = await (await browser.newContext()).newPage();
    await guest.goto(`${BASE}/login`);
    await guest.fill('input[name=email]', 'staf@tte.local');
    await guest.fill('input[name=password]', 'password');
    await guest.click('[data-test=login-button]');
    await guest.getByText('Email atau kata sandi salah.').waitFor();
    console.log('deactivated staff login refused: true');

    // 4. Own account has no deactivate button; reactivate staff.
    console.log(
        `self deactivate button: ${await page.getByRole('button', { name: 'Nonaktifkan akun Admin TU' }).count()}`,
    );
    await row(page, 'Rina Staf')
        .getByRole('button', { name: 'Aktifkan kembali' })
        .click();
    await page.getByText('Akun Rina Staf diaktifkan kembali.').waitFor();
    await shot(page, 'after-reactivate');

    // 5. All-documents page: list, search, status tab, mobile width.
    await page.getByRole('link', { name: 'Semua dokumen' }).click();
    await page.waitForURL(/\/admin\/documents/);
    await shot(page, 'documents-all');
    await page.fill('#q', 'uji e2e');
    await page.getByRole('button', { name: 'Cari' }).click();
    await page.waitForURL(/q=uji/);
    await page.getByRole('button', { name: /^Selesai/ }).click();
    await page.waitForURL(/status=completed/);
    console.log(
        `completed docs matching "uji e2e": ${await page.locator('tbody tr').count()}`,
    );
    await shot(page, 'documents-filtered');
    await page.setViewportSize({ width: 390, height: 844 });
    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth > window.innerWidth,
    );
    console.log(`all-documents mobile page overflow: ${overflow}`);
    await shot(page, 'documents-mobile');
} catch (error) {
    await page
        .screenshot({ path: `${OUT}/admin-FAILED.png`, fullPage: true })
        .catch(() => {});
    console.log(`FAILED: ${error.message.split('\n')[0]}`);
    process.exitCode = 1;
} finally {
    console.log(
        errors.length ? `JS errors:\n${errors.join('\n')}` : 'JS errors: none',
    );
    await browser.close();
}
