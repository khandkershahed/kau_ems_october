import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { createHorizontalBillPdf } from '../../../resources/js/Pages/Reports/reportTemplates/HorizontalBillPdf.js';

const payload = JSON.parse(fs.readFileSync('storage/app/bridge-qa/november.json'));
const { records, horizontal_columns: columns, horizontal_totals: totals } = payload;
const filename = 'KAU_Horizontal_Bill_2026_11.pdf';
const fmt = value => value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const sum = (rows, key) => rows.reduce((acc, row) => acc + Math.round(100 * row.horizontal_columns[key]), 0) / 100;
const values = page => page.match(/-?\d[\d,]*\.\d{2}(?!\d)/g) || [];
const logs = [];
const log = console.log;
console.log = (...args) => logs.push(args.join(' '));
globalThis.fetch = async () => ({ ok: false });
process.chdir('storage/app/bridge-qa');
for (const rowsPerPage of [25, 15]) {
    const doc = await createHorizontalBillPdf({ records, columns, totals, year: 2026, month: 11, rowsPerPage });
    assert.equal(doc.getNumberOfPages(), Math.ceil(412 / rowsPerPage));
    const { columns: renderedColumns, settings } = doc.lastAutoTable;
    const tableRight = settings.margin.left + renderedColumns.reduce((width, column) => width + column.width, 0);
    const rightMargin = doc.internal.pageSize.getWidth() - tableRight;
    assert.ok(rightMargin >= settings.margin.right - 0.001, 'Table within margin');
    assert.equal(renderedColumns.at(-1).width, 13);
    const extracted = execFileSync('pdftotext', ['-raw', '-clip', filename, '-'], { encoding: 'utf8' });
    const pages = extracted.split('\f').filter(page => page.trim());
    assert.equal(pages.length, doc.getNumberOfPages());
    for (const [i, page] of pages.entries()) {
        assert.match(page, /\bSignature\b/, 'Signature is readable on page '+(i+1));
        const rows = records.slice(i * rowsPerPage, (i + 1) * rowsPerPage);
        const expected = rows.flatMap(row => columns.map(({key}) => fmt(row.horizontal_columns[key])));
        expected.push(...columns.map(({key}) => fmt(sum(rows,key))));
        if (i === pages.length - 1) expected.push(...columns.map(({key}) => fmt(totals[key])));
        assert.deepEqual(values(page), expected, 'Monetary values on page '+(i+1));
    }
    log(JSON.stringify({ rowsPerPage, pages: pages.length, moneyColumns: columns.length, rightMarginMm: rightMargin.toFixed(2), signatureWidthMm: renderedColumns.at(-1).width, moneyMismatches: 0 }));
}
assert.ok(!logs.some(log => log.includes('could not fit page')));

