// storage/app/bridge-qa/pdf-input.mjs
import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import fs from "node:fs";

// resources/js/Pages/Reports/reportTemplates/HorizontalBillPdf.js
import { jsPDF } from "jspdf";
import autoTable from "jspdf-autotable";

// resources/js/Pages/Reports/reportTemplates/utils.js
var MONTH_NAMES = [
  "",
  "January",
  "February",
  "March",
  "April",
  "May",
  "June",
  "July",
  "August",
  "September",
  "October",
  "November",
  "December"
];

// resources/js/Pages/Reports/reportTemplates/HorizontalBillPdf.js
var formatTwoDecimals = (value) => {
  if (value === null || value === void 0 || value === "" || value === "N/R" || value === "\u2014") {
    return "\u2014";
  }
  const num = Number(value);
  if (!Number.isFinite(num)) return "\u2014";
  return num.toLocaleString("en-US", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });
};
async function loadLogoBase64() {
  try {
    const res = await fetch("/logo.png");
    if (!res.ok) return null;
    const blob = await res.blob();
    return await new Promise((resolve) => {
      const reader = new FileReader();
      reader.onloadend = () => resolve(reader.result);
      reader.onerror = () => resolve(null);
      reader.readAsDataURL(blob);
    });
  } catch (_) {
    return null;
  }
}
async function createHorizontalBillPdf({
  records: records2 = [],
  columns: columns2 = [],
  totals: totals2 = {},
  year,
  month,
  headerConfig = {},
  rowsPerPage = 15,
  isPrint = false
} = {}) {
  const doc = new jsPDF({
    orientation: "landscape",
    unit: "mm",
    format: "a4",
    compress: true
  });
  const pageWidth = doc.internal.pageSize.getWidth();
  const pageHeight = doc.internal.pageSize.getHeight();
  const logoBase64 = await loadLogoBase64();
  const monthName = MONTH_NAMES[Number(month)] || `Month ${month}`;
  const periodLabel = `${monthName} ${year}`;
  const titleText = headerConfig.title || "Khulna Agricultural University";
  const addressText = headerConfig.address || "Temporary Office: 327, Jessore Road, North Mujgunni, Khalishpur, Khulna-9000";
  const subtitleText = headerConfig.subtitle || `Horizontal Salary Bill (Current + Arrear) for ${periodLabel}`;
  const activeCols = columns2.length ? columns2 : [
    { key: "basic", label: "Basic" },
    { key: "hra", label: "HRA" },
    { key: "medical", label: "MA" },
    { key: "special_benefit", label: "S. Benefit" },
    { key: "education", label: "Edu" },
    { key: "gross", label: "Gross" },
    { key: "gpf", label: "GPF" },
    { key: "income_tax", label: "Income Tax" },
    { key: "revenue_stamp", label: "Rev. Stamp" },
    { key: "total_deduction", label: "Tot. Ded." },
    { key: "net_arrear", label: "Net Arrear" },
    { key: "net_payable", label: "Net Payable" }
  ];
  const perPage = Math.max(1, Math.min(25, Number(rowsPerPage) || 15));
  const chunks = [];
  for (let i = 0; i < records2.length; i += perPage) {
    chunks.push(records2.slice(i, i + perPage));
  }
  if (chunks.length === 0) chunks.push([]);
  const headRow = [
    "SL",
    "ID / Grade",
    "Name",
    "Designation",
    ...activeCols.map((c) => c.label),
    "Signature"
  ];
  const numericColStart = 4;
  const numericColEnd = numericColStart + activeCols.length - 1;
  const columnStyles = {
    0: { halign: "center", cellWidth: 7 },
    1: { halign: "center", cellWidth: 13 },
    2: { halign: "left", cellWidth: 25 },
    3: { halign: "left", cellWidth: 21 }
  };
  for (let c = numericColStart; c <= numericColEnd; c++) {
    columnStyles[c] = { halign: "right" };
  }
  columnStyles[numericColEnd + 1] = { halign: "center", cellWidth: 13 };
  const fontSize = activeCols.length >= 16 ? 5.3 : activeCols.length >= 13 ? 5.8 : 6.3;
  const drawHeader = (pageIdx) => {
    if (logoBase64) {
      try {
        doc.addImage(logoBase64, "PNG", 8, 6, 12, 12);
      } catch (_) {
      }
    }
    doc.setFont("helvetica", "bold");
    doc.setFontSize(12);
    doc.setTextColor(22, 37, 54);
    doc.text(titleText, pageWidth / 2, 10, { align: "center" });
    doc.setFont("helvetica", "normal");
    doc.setFontSize(7.5);
    doc.setTextColor(71, 85, 105);
    doc.text(addressText, pageWidth / 2, 14, { align: "center" });
    doc.setFont("helvetica", "bold");
    doc.setFontSize(9);
    doc.setTextColor(15, 23, 42);
    doc.text(subtitleText, pageWidth / 2, 18.5, { align: "center" });
    doc.setFont("helvetica", "normal");
    doc.setFontSize(7);
    doc.setTextColor(100, 116, 139);
    doc.text(
      `Period: ${periodLabel}  |  Page ${pageIdx + 1} of ${chunks.length}`,
      pageWidth - 8,
      18.5,
      { align: "right" }
    );
  };
  chunks.forEach((chunk, pageIdx) => {
    if (pageIdx > 0) doc.addPage("a4", "landscape");
    drawHeader(pageIdx);
    const pageTotals = {};
    const pageMissing = {};
    activeCols.forEach((col) => {
      pageTotals[col.key] = 0;
      pageMissing[col.key] = false;
    });
    const bodyRows = chunk.map((r, rowIdx) => {
      const hCols = r.horizontal_columns || {};
      const monetaryCells = activeCols.map((col) => {
        const raw = hCols[col.key];
        if (raw === null || raw === void 0 || !Number.isFinite(Number(raw))) {
          pageMissing[col.key] = true;
          return "\u2014";
        }
        pageTotals[col.key] += Number(raw);
        return formatTwoDecimals(raw);
      });
      return [
        pageIdx * perPage + rowIdx + 1,
        `${r.employee_code || ""}
${r.grade || ""}`.trim(),
        r.name || "",
        r.designation || "",
        ...monetaryCells,
        ""
      ];
    });
    const pageTotalRow = [
      "",
      "",
      "PAGE TOTAL",
      "",
      ...activeCols.map(
        (col) => pageMissing[col.key] ? "\u2014" : formatTwoDecimals(Math.round(pageTotals[col.key] * 100) / 100)
      ),
      ""
    ];
    const footRows = [pageTotalRow];
    if (pageIdx === chunks.length - 1) {
      const grandTotalRow = [
        "",
        "",
        `GRAND TOTAL (${records2.length})`,
        "",
        ...activeCols.map((col) => formatTwoDecimals(totals2?.[col.key])),
        ""
      ];
      footRows.push(grandTotalRow);
    }
    autoTable(doc, {
      head: [headRow],
      body: bodyRows,
      foot: footRows,
      startY: 22,
      margin: { left: 7, right: 7, bottom: 12 },
      theme: "grid",
      styles: {
        fontSize,
        cellPadding: 0.8,
        lineColor: [148, 163, 184],
        lineWidth: 0.15,
        overflow: "linebreak",
        valign: "middle",
        textColor: [22, 37, 54]
      },
      headStyles: {
        fillColor: [239, 242, 246],
        textColor: [23, 42, 63],
        fontStyle: "bold",
        halign: "center"
      },
      footStyles: {
        fillColor: [237, 245, 239],
        textColor: [15, 23, 42],
        fontStyle: "bold"
      },
      columnStyles
    });
    doc.setFont("helvetica", "normal");
    doc.setFontSize(6.5);
    doc.setTextColor(100, 116, 139);
    doc.text(
      `Khulna Agricultural University \u2022 Horizontal Salary Bill (${periodLabel})`,
      7,
      pageHeight - 5
    );
    doc.text(
      `Page ${pageIdx + 1} of ${chunks.length}`,
      pageWidth - 7,
      pageHeight - 5,
      { align: "right" }
    );
  });
  const filename2 = `KAU_Horizontal_Bill_${year}_${String(month).padStart(2, "0")}.pdf`;
  if (isPrint) {
    doc.autoPrint();
    const url = URL.createObjectURL(doc.output("blob"));
    const tab = window.open(url, "_blank");
    if (tab) tab.opener = null;
    else alert("Please enable popups for printing.");
    setTimeout(() => URL.revokeObjectURL(url), 6e4);
  } else {
    doc.save(filename2);
  }
  return doc;
}

// storage/app/bridge-qa/pdf-input.mjs
var payload = JSON.parse(fs.readFileSync("storage/app/bridge-qa/november.json"));
var { records, horizontal_columns: columns, horizontal_totals: totals } = payload;
var filename = "KAU_Horizontal_Bill_2026_11.pdf";
var fmt = (value) => value.toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
var sum = (rows, key) => rows.reduce((acc, row) => acc + Math.round(100 * row.horizontal_columns[key]), 0) / 100;
var values = (page) => page.match(/-?\d[\d,]*\.\d{2}(?!\d)/g) || [];
var logs = [];
var log = console.log;
console.log = (...args) => logs.push(args.join(" "));
globalThis.fetch = async () => ({ ok: false });
process.chdir("storage/app/bridge-qa");
for (const rowsPerPage of [25, 15]) {
  const doc = await createHorizontalBillPdf({ records, columns, totals, year: 2026, month: 11, rowsPerPage });
  assert.equal(doc.getNumberOfPages(), Math.ceil(412 / rowsPerPage));
  const { columns: renderedColumns, settings } = doc.lastAutoTable;
  const tableRight = settings.margin.left + renderedColumns.reduce((width, column) => width + column.width, 0);
  const rightMargin = doc.internal.pageSize.getWidth() - tableRight;
  assert.ok(rightMargin >= settings.margin.right - 1e-3, "Table within margin");
  assert.equal(renderedColumns.at(-1).width, 13);
  const extracted = execFileSync("pdftotext", ["-raw", "-clip", filename, "-"], { encoding: "utf8" });
  const pages = extracted.split("\f").filter((page) => page.trim());
  assert.equal(pages.length, doc.getNumberOfPages());
  for (const [i, page] of pages.entries()) {
    assert.match(page, /\bSignature\b/, "Signature is readable on page " + (i + 1));
    const rows = records.slice(i * rowsPerPage, (i + 1) * rowsPerPage);
    const expected = rows.flatMap((row) => columns.map(({ key }) => fmt(row.horizontal_columns[key])));
    expected.push(...columns.map(({ key }) => fmt(sum(rows, key))));
    if (i === pages.length - 1) expected.push(...columns.map(({ key }) => fmt(totals[key])));
    assert.deepEqual(values(page), expected, "Monetary values on page " + (i + 1));
  }
  log(JSON.stringify({ rowsPerPage, pages: pages.length, moneyColumns: columns.length, rightMarginMm: rightMargin.toFixed(2), signatureWidthMm: renderedColumns.at(-1).width, moneyMismatches: 0 }));
}
assert.ok(!logs.some((log2) => log2.includes("could not fit page")));
