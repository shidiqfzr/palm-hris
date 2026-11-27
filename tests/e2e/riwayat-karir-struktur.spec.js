// SUT: kolom "Struktur yang Berlaku" pada Riwayat Karir di profil karyawan (riwayat jabatan terhadap versi struktur).
// Basis data E2E memuat dua versi SK (352 pada 1 Sep 2026 dan 401 pada 1 Okt 2026) dan karyawan uji Budi dengan dua penempatan
// (seed-structure-versions.php).
import { test, expect as baseExpect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

// Server uji PHP berutas tunggal: halaman dapat memakan belasan detik di bawah beban, jadi batas tunggu 30 detik.
const expect = baseExpect.configure({ timeout: 30_000 });

async function openCareerTab(page) {
    await page.goto('/admin/employees/90000001/detail', { waitUntil: 'domcontentloaded' });
    await page.getByRole('tab', { name: 'Riwayat Karir' }).click();
    await expect(page.getByRole('columnheader', { name: 'Struktur yang Berlaku' })).toBeVisible();
}

test('riwayat karir: setiap penempatan menampilkan SK struktur yang berlaku pada tanggal mulainya', async ({ page }) => {
    await openCareerTab(page);

    const lama = page.getByRole('row', { name: /SK\/MUTASI\/UJI\/1/ });
    await expect(lama).toContainText('DPSB/SKPTS/352/IX/2026');

    const sekarang = page.getByRole('row').filter({ hasText: 'Sekarang' }).first();
    await expect(sekarang).toContainText('DPSB/SKPTS/401/X/2026');
    await expect(page.getByText('Tidak tercantum pada struktur SK')).toHaveCount(0);
});

test('riwayat karir: tabel muat tanpa gulir horizontal pada 1366px dan unit tampil di bawah jabatan', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 768 });
    await openCareerTab(page);

    await expect(page.getByRole('columnheader', { name: /Jabatan & Unit/ })).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'Mutasi & SK' })).toBeVisible();
    const sideways = await page.getByRole('columnheader', { name: 'Struktur yang Berlaku' }).evaluate((th) => {
        const box = th.closest('.overflow-x-auto');
        return box.scrollWidth - box.clientWidth;
    });
    expect(sideways).toBeLessThanOrEqual(0);
});

test('riwayat karir: aksi baris berupa menu kebab dan hapus membuka dialog beralasan', async ({ page }) => {
    await openCareerTab(page);

    const lama = page.getByRole('row', { name: /SK\/MUTASI\/UJI\/1/ });
    await lama.getByRole('button', { name: /Aksi untuk riwayat/ }).click();
    await expect(page.getByRole('menuitem', { name: 'Ubah Riwayat' })).toBeVisible();
    await page.getByRole('menuitem', { name: 'Hapus Riwayat' }).click();

    const dialog = page.getByRole('dialog', { name: 'Hapus Riwayat Jabatan' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel(/Alasan/)).toBeVisible();
    await dialog.getByRole('button', { name: 'Batal' }).click();
    await expect(dialog).toBeHidden();

    const sekarang = page.getByRole('row').filter({ hasText: 'Sekarang' }).first();
    await sekarang.getByRole('button', { name: /Aksi untuk riwayat/ }).click();
    await expect(page.getByRole('menuitem', { name: 'Hapus Riwayat' })).toHaveCount(0);
});

test('ubah riwayat: formulir memakai komponen bersama, wajib beralasan, dan lolos pemindaian aksesibilitas', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 768 });
    await openCareerTab(page);

    const lama = page.getByRole('row', { name: /SK\/MUTASI\/UJI\/1/ });
    await lama.getByRole('button', { name: /Aksi untuk riwayat/ }).click();
    await page.getByRole('menuitem', { name: 'Ubah Riwayat' }).click();
    await page.waitForURL(/\/job-history\/\d+\/edit/, { timeout: 60_000 });

    await expect(page.getByRole('heading', { level: 1, name: 'Ubah Riwayat Jabatan' })).toBeVisible({ timeout: 60_000 });
    await expect(page.getByLabel(/Alasan perubahan/)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Simpan Perubahan' })).toBeVisible();
    await expect(page.getByText(/Format baku \[Gol\]\/\[Berkala\]/)).toBeVisible();
    // Jabatan and Unit Kerja are Master Formasi pick lists, not free-text inputs.
    await expect(page.locator('input[type=text][name=jabatan], input[type=text][name=unit_kerja]')).toHaveCount(0);
    await expect(page.getByPlaceholder('Cari atau pilih unit kerja...')).toBeVisible();
    // The position list follows the unit's formasi on TMT Awal, and the note says which structure it comes from.
    await expect(page.getByText(/^Formasi .+ menurut (struktur SK .+|Master Formasi saat ini)\.$/)).toBeVisible();
    // One field style per form: both pickers match the standard text input (height and corner radius).
    const look = (locator) => locator.evaluate((el) => { const s = getComputedStyle(el); return [Math.round(el.getBoundingClientRect().height), s.borderTopLeftRadius]; });
    const golongan = await look(page.locator('input[name=golongan]'));
    expect(await look(page.getByPlaceholder('Cari atau pilih unit kerja...'))).toEqual(golongan);
    expect(await look(page.locator('[data-field=jabatan] [tabindex="0"]'))).toEqual(golongan);
    await page.screenshot({ path: 'test-results/ubah-riwayat-jabatan.png', fullPage: true });

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
    const scan = await new AxeBuilder({ page }).include('main').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(scan.violations, JSON.stringify(scan.violations.map((v) => `${v.id}: ${v.nodes.length}`))).toEqual([]);
    expect(await page.locator('body').innerText()).not.toMatch(/AGENTS\.md|DOMAIN\.md|DESIGN\.md|ENGINEERING\.md|SECURITY\.md|SSOT/);
});

test('riwayat karir: kolom struktur lolos pemindaian aksesibilitas dan tanpa istilah internal', async ({ page }) => {
    await openCareerTab(page);

    const scan = await new AxeBuilder({ page }).include('main').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(scan.violations, JSON.stringify(scan.violations.map((v) => `${v.id}: ${v.nodes.length}`))).toEqual([]);

    const text = await page.locator('body').innerText();
    expect(text).not.toMatch(/AGENTS\.md|DOMAIN\.md|DESIGN\.md|ENGINEERING\.md|SECURITY\.md|SSOT/);
});