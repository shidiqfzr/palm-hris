// SUT: Rekonstruksi Riwayat Karir dari Bezetting (daftar proses dan peninjauan nama). Satu berkas uji per fitur
// (ENGINEERING.md §3.5). Data: satu proses sintetis dengan satu nama unit lama (seed-bezetting-backfill.php).
import { test, expect as baseExpect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const expect = baseExpect.configure({ timeout: 30_000 });
const scanMain = (page) => new AxeBuilder({ page }).include('main').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
const FORBIDDEN_TERMS = /AGENTS\.md|DOMAIN\.md|DESIGN\.md|ENGINEERING\.md|SECURITY\.md|SSOT/;

async function openRun(page) {
    await page.goto('/admin/employees/riwayat-bezetting', { waitUntil: 'domcontentloaded' });
    await page.locator('tbody tr', { hasText: '2025-01 s.d. 2025-02' }).getByRole('link', { name: 'Buka' }).click();
    await expect(page.getByText('Nama yang Perlu Diputuskan', { exact: true })).toBeVisible();
}

test('daftar proses: status berlabel, kebijakan empat mata, dan tanpa istilah internal', async ({ page }) => {
    await page.goto('/admin/employees/riwayat-bezetting', { waitUntil: 'domcontentloaded' });

    const row = page.locator('tbody tr', { hasText: '2025-01 s.d. 2025-02' });
    await expect(row).toContainText('Ditinjau');
    await expect(page.getByText('Persetujuan dua pihak (empat mata) aktif.')).toBeVisible();
    expect(await page.locator('body').innerText()).not.toMatch(FORBIDDEN_TERMS);

    const scan = await scanMain(page);
    expect(scan.violations).toEqual([]);
});

test('peninjauan: nama lama dipetakan ke unit resmi, NIK tidak tampil utuh, tanpa overflow', async ({ page }) => {
    await openRun(page);

    const body = await page.locator('body').innerText();
    expect(body).not.toContain('99000077');
    expect(body).not.toMatch(FORBIDDEN_TERMS);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);

    const scan = await scanMain(page);
    expect(scan.violations).toEqual([]);

    const nameRow = page.locator('tbody tr', { hasText: 'KEBUN UJI LAMA (K.SAWIT)' });
    const target = nameRow.getByRole('combobox');
    const firstOfficial = await target.locator('option').nth(1).getAttribute('value');
    await target.selectOption(firstOfficial);
    await nameRow.getByRole('button', { name: 'Petakan' }).click();

    await expect(page.getByText('Semua nama sudah diputuskan.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Ajukan untuk Persetujuan' })).toBeVisible();
});
