// SUT: Rekonstruksi Riwayat Karir dari Bezetting (daftar proses dan peninjauan nama). Satu berkas uji per fitur
// (ENGINEERING.md §3.5). Data: satu proses sintetis dengan satu nama unit lama (seed-bezetting-backfill.php).
import { test, expect as baseExpect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const expect = baseExpect.configure({ timeout: 30_000 });
const scanMain = (page) => new AxeBuilder({ page }).include('main').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
const FORBIDDEN_TERMS = /AGENTS\.md|DOMAIN\.md|DESIGN\.md|ENGINEERING\.md|SECURITY\.md|SSOT/;

// Every legend starts closed (same on all tabs); open the one on screen.
async function openGuide(page) {
    const summary = page.locator('#rekap details > summary').first();
    await summary.click();
    await expect(page.locator('#rekap details[open]')).toHaveCount(1);
}

async function openRun(page) {
    await page.goto('/admin/employees/riwayat-bezetting', { waitUntil: 'domcontentloaded' });
    await page.locator('tbody tr', { hasText: '2025-01 s.d. 2025-02' }).getByRole('link', { name: 'Buka' }).click();
    await expect(page.getByRole('heading', { name: 'Tinjauan' })).toBeVisible();
}

test('daftar proses: status berlabel, kebijakan empat mata, dan tanpa istilah internal', async ({ page }) => {
    await page.goto('/admin/employees/riwayat-bezetting', { waitUntil: 'domcontentloaded' });

    const row = page.locator('tbody tr', { hasText: '2025-01 s.d. 2025-02' });
    await expect(row).toContainText('Ditinjau');
    await expect(page.getByText('Persetujuan dua pihak (empat mata) aktif.')).toBeVisible();
    expect(await page.locator('body').innerText()).not.toMatch(FORBIDDEN_TERMS);

    // Usage guidance: advice on the number of files beside the upload field and the full workflow, both collapsible.
    await expect(page.getByText('Sebelum mengunggah', { exact: true })).toBeVisible();
    await expect(page.getByText('Alur kerja Rekonstruksi Riwayat Karir', { exact: true })).toBeVisible();
    const advice = page.locator('details[aria-label="Sebelum mengunggah"]');
    // Help starts closed so the upload field and the run list come first; it opens and closes from its title.
    await expect(advice.getByText('Banyaknya berkas')).toBeHidden();
    await advice.locator('summary').click();
    await expect(advice.getByText('Banyaknya berkas')).toBeVisible();
    await page.screenshot({ path: 'test-results/rekonstruksi-daftar-panduan.png', fullPage: true });
    await advice.locator('summary').click();
    await expect(advice.getByText('Banyaknya berkas')).toBeHidden();
    await page.locator('details[aria-label="Alur kerja Rekonstruksi Riwayat Karir"] summary').click();
    await expect(page.getByText('8. Batalkan bila perlu')).toBeVisible();
    const listScan = await scanMain(page);
    expect(listScan.violations, JSON.stringify(listScan.violations.map((v) => `: `))).toEqual([]);

    // "Baca Berkas" sits on the same row as the upload field it acts on, with the same height.
    await page.setViewportSize({ width: 1366, height: 768 });
    // Re-measured until the layout settles after the resize.
    const fieldBox = page.locator('label[for="bezetting-files"] + div').first().locator('div.rounded-xl').first();
    const buttonBox = page.getByRole('button', { name: 'Baca Berkas' });
    await expect.poll(async () => {
        const [field, button] = [await fieldBox.boundingBox(), await buttonBox.boundingBox()];
        return Math.max(Math.abs(button.y - field.y), Math.abs(button.height - field.height));
    }).toBeLessThanOrEqual(1);
    await page.locator('section[aria-labelledby="upload-title"]').screenshot({ path: 'test-results/unggah-bezetting.png' });

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

    // The kind badge is level with the name: the vertical centres of the badge and the first line of the name agree.
    const badgeBox = await nameRow.getByText('Unit kerja', { exact: true }).boundingBox();
    const nameBox = await nameRow.getByText('KEBUN UJI LAMA (K.SAWIT)', { exact: true }).boundingBox();
    expect(Math.abs((badgeBox.y + badgeBox.height / 2) - (nameBox.y + nameBox.height / 2))).toBeLessThanOrEqual(2);
    await nameRow.evaluate((row) => row.scrollIntoView({ block: 'center' }));
    const rowBox = await nameRow.boundingBox();
    await page.screenshot({ path: 'test-results/nama-baris.png', clip: { x: rowBox.x, y: rowBox.y - 6, width: rowBox.width, height: rowBox.height + 12 } });

    // The three decisions are one equal set on one line (no wrapped label, same height).
    const heights = [];
    for (const name of ['Petakan', 'Apa Adanya', 'Lewati']) {
        const box = await nameRow.getByRole('button', { name }).boundingBox();
        heights.push(Math.round(box.height));
    }
    expect(new Set(heights).size).toBe(1);

    // The official-name picker is searchable (the master has far more than 15 names); its panel floats over the table.
    const picker = nameRow.locator('[tabindex="0"]').first();
    await picker.click();
    const options = nameRow.locator('li[data-option-item]');
    await expect(options.first()).toBeVisible();
    // Scrolling the option list must not close the panel (the panel only closes when the page scrolls under it).
    await nameRow.locator('ul[x-ref="optionsList"]').evaluate((list) => { list.scrollTop = 120; });
    await expect(nameRow.getByPlaceholder('Cari nama resmi...')).toBeVisible();
    await nameRow.getByPlaceholder('Cari nama resmi...').fill('Kebun');
    await options.first().click();
    await nameRow.getByRole('button', { name: 'Petakan' }).click();

    await expect(page.getByText('Semua nama sudah diputuskan.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Ajukan untuk Persetujuan' })).toBeVisible();
});

test('rekap: pratinjau per karyawan dan perlu tindak lanjut, NIK tersamar, lolos pemindaian aksesibilitas', async ({ page }) => {
    await openRun(page);

    // One flat tab bar, one section at a time; no second tab bar inside the recap.
    const sections = page.getByRole('radiogroup', { name: 'Bagian tinjauan' });
    await sections.getByRole('radio', { name: /Riwayat yang Akan Ditulis/ }).click();
    await expect(sections.getByRole('radio', { name: /Riwayat yang Akan Ditulis/ })).toHaveAttribute('aria-checked', 'true');
    await expect(page.locator('#rekap').getByText('Riwayat yang Akan Ditulis', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('Nama yang Perlu Diputuskan', { exact: true })).toBeHidden();
    await expect(page.getByRole('radiogroup')).toHaveCount(1);
    // The recap table fits the run page at 1366px (DESIGN §7.5): no sideways scroll inside the card.
    await page.setViewportSize({ width: 1366, height: 768 });
    await expect.poll(() => page.locator('#rekap .overflow-x-auto').evaluate((box) => box.scrollWidth - box.clientWidth)).toBeLessThanOrEqual(0);
    // The column "Jenis Perubahan" is there and has its own filter.
    await expect(page.locator('#rekap').getByRole('columnheader', { name: 'Jenis Perubahan' })).toBeVisible();
    await expect(page.locator('#rekap').getByLabel('Jenis Perubahan')).toBeAttached();
    // Periode is the last column, left-aligned like the others, and narrow: its widest content ends near the card's right edge.
    const edge = await page.locator('#rekap table').first().evaluate((table) => {
        const th = [...table.querySelectorAll('th')].find((h) => h.textContent.trim() === 'Periode');
        const tableRight = table.getBoundingClientRect().right;
        let widest = 0;
        for (const cell of table.querySelectorAll('tbody tr td:last-child')) {
            const range = document.createRange();
            range.selectNodeContents(cell);
            widest = Math.max(widest, Math.max(...[...range.getClientRects()].map((r) => r.right)));
        }
        return { last: th === table.querySelector('th:last-child'), align: getComputedStyle(th).textAlign, gap: tableRight - widest };
    });
    expect(edge.last).toBe(true);
    expect(edge.align).toBe('left');
    expect(edge.gap).toBeLessThanOrEqual(80);
    // Wide sections use the full page width, below the summary and the decision trail.
    const pageWidth = await page.locator('main').evaluate((m) => m.getBoundingClientRect().width);
    const recapWidth = await page.locator('#rekap').evaluate((r) => r.getBoundingClientRect().width);
    expect(recapWidth / pageWidth).toBeGreaterThan(0.85);
    // Ringkasan stays compact (no stretched white void between KPI cards and secondary counters), and Berkas Terbaca sits directly below it.
    const summaryGap = () => page.evaluate(() => {
        const summary = document.querySelector('section[aria-labelledby="summary-title"]');
        const kpiGrid = summary.querySelector('.grid');
        const counters = summary.querySelector('.border-t');
        return counters.getBoundingClientRect().top - kpiGrid.getBoundingClientRect().bottom;
    });
    await expect.poll(summaryGap).toBeLessThanOrEqual(48);
    await page.getByText('Daftar berkas', { exact: true }).click();
    await expect.poll(summaryGap).toBeLessThanOrEqual(48);
    await page.getByText('Daftar berkas', { exact: true }).click();

    // The decision comes after the review, and its bar stays in reach (sticky) while the page is long.
    const afterReview = await page.evaluate(() => {
        const review = document.querySelector('[aria-labelledby="review-title"]');
        const decision = [...document.querySelectorAll('strong')].find((e) => e.textContent.trim() === 'Keputusan:');
        return Boolean(review.compareDocumentPosition(decision) & Node.DOCUMENT_POSITION_FOLLOWING);
    });
    expect(afterReview).toBe(true);
    const bar = await page.getByText('Keputusan:', { exact: true }).boundingBox();
    expect(bar.y).toBeGreaterThan(0);
    expect(bar.y).toBeLessThan(768);
    await page.screenshot({ path: 'test-results/rekonstruksi-detail.png', fullPage: true });
    await sections.getByRole('radio', { name: /Perlu Tindak Lanjut/ }).click();
    await expect(page.locator('#rekap').getByText('Perlu Tindak Lanjut', { exact: true }).first()).toBeVisible();
    // The follow-up tab says what each type means and what to do; it does not block submitting.
    await expect(page.locator('#rekap details[open]')).toHaveCount(0);
    await openGuide(page);
    await expect(page.getByText('Yang perlu dilakukan:').first()).toBeVisible();
    await expect(page.getByText('Ini tidak menghalangi pengajuan.')).toBeVisible();
    // An open legend stays open when the table re-renders (search), instead of closing by itself.
    await page.getByPlaceholder(/Cari nama/).fill('zzz-tidak-ada');
    await expect(page.getByText('Tidak ada data yang sesuai')).toBeVisible();
    await expect(page.locator('#rekap details[open]')).toHaveCount(1);
    await page.getByPlaceholder(/Cari nama/).fill('');

    // The Dikecualikan tab explains each reason and what to do; accessible and without full NIK.
    await sections.getByRole('radio', { name: /Dikecualikan/ }).click();
    // Wait for the tab's own content before touching its legend (the previous tab's legend is still there until the swap).
    await expect(page.getByRole('cell', { name: /Belum ada pada data karyawan/ }).first()).toBeVisible();
    await expect(page.locator('#rekap details[open]')).toHaveCount(0);
    await openGuide(page);
    await expect(page.locator('#rekap').getByText('Dikecualikan', { exact: true }).first()).toBeVisible();
    await expect(page.getByRole('cell', { name: /Belum ada pada data karyawan/ }).first()).toBeVisible();
    await expect(page.getByText('Yang perlu dilakukan:').first()).toBeVisible();
    expect(await page.locator('body').innerText()).not.toContain('99000088');
    const excludedScan = await scanMain(page);
    expect(excludedScan.violations, JSON.stringify(excludedScan.violations.map((v) => `${v.id}: ${v.nodes.length}`))).toEqual([]);
    await sections.getByRole('radio', { name: /Perlu Tindak Lanjut/ }).click();
    await expect(page.locator('#rekap').getByText('Perlu Tindak Lanjut', { exact: true }).first()).toBeVisible();

    const body = await page.locator('body').innerText();
    expect(body).not.toContain('99000077');
    expect(body).not.toMatch(FORBIDDEN_TERMS);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
    const scan = await scanMain(page);
    expect(scan.violations, JSON.stringify(scan.violations.map((v) => `${v.id}: ${v.nodes.length}`))).toEqual([]);
});

test('tinjauan: kolom Keputusan sejajar bila ada nama dan panduan dapat dibuka dan ditutup', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 768 });
    await openRun(page);

    // The decision column starts at the same x on every row, whatever the length of the name.
    // (Only when names are still open; other tests of this file decide them, so the check is conditional.)
    const lefts = await page.locator('tbody tr td:nth-child(3)').evaluateAll((cells) => cells.map((c) => Math.round(c.getBoundingClientRect().left)));
    expect(new Set(lefts).size).toBeLessThanOrEqual(1);

    // The legend is closed on arrival and opens and closes from its title.
    await page.getByRole('radiogroup', { name: 'Bagian tinjauan' }).getByRole('radio', { name: /Riwayat yang Akan Ditulis/ }).click();
    const guide = page.locator('details[aria-label="Cara membaca"]');
    await expect(guide.getByText('Yang perlu dilakukan:').first()).toBeHidden();
    await guide.locator('summary').click();
    await expect(guide.getByText('Yang perlu dilakukan:').first()).toBeVisible();
    await guide.locator('summary').click();
    await expect(guide.getByText('Yang perlu dilakukan:').first()).toBeHidden();
    const scan = await scanMain(page);
    expect(scan.violations, JSON.stringify(scan.violations.map((v) => `${v.id}: ${v.nodes.length}`))).toEqual([]);
});