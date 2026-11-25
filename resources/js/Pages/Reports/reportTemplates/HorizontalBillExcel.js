import * as XLSX from 'xlsx';

export function downloadHorizontalBillExcel(payload, activeColumns, filename) {
    const headers = [
        "SL",
        "ID",
        "Name",
        "Designation",
        "Grade",
        ...activeColumns.map((c) => c.label),
        "Source",
        "Signature",
    ];

    const moneyNum = (v) => {
        if (v === null || v === undefined || v === "") return "N/R";
        const n = Number(v);
        return Number.isFinite(n) ? Math.round(n * 100) / 100 : "N/R";
    };

    const body = payload.records.map((r, i) => {
        const hCols = r.horizontal_columns || {};
        return [
            i + 1,
            String(r.employee_code),
            r.name,
            r.designation,
            r.grade,
            ...activeColumns.map((col) => moneyNum(hCols[col.key])),
            r.source,
            "",
        ];
    });

    const serverTotals = payload.horizontal_totals || {};
    const totalsRow = [
        "",
        "",
        `GRAND TOTAL (${body.length} employees)`,
        "",
        "",
        ...activeColumns.map((col) => moneyNum(serverTotals[col.key])),
        "",
        "",
    ];

    const workbook = XLSX.utils.book_new();
    const sheet = XLSX.utils.aoa_to_sheet([headers, ...body, totalsRow]);

    // Enforce exact 2-decimal display format (#,##0.00) on all monetary cells & grand totals.
    const firstMoneyCol = 5;
    const lastMoneyCol = firstMoneyCol + activeColumns.length - 1;
    for (let rowIdx = 1; rowIdx <= body.length + 1; rowIdx++) {
        for (let colIdx = firstMoneyCol; colIdx <= lastMoneyCol; colIdx++) {
            const cellRef = XLSX.utils.encode_cell({ r: rowIdx, c: colIdx });
            const cell = sheet[cellRef];
            if (cell && typeof cell.v === "number") {
                cell.t = "n";
                cell.z = "#,##0.00";
            }
        }
    }

    sheet["!cols"] = headers.map((_, i) => ({
        wch: i === 2 ? 32 : i === 3 ? 28 : i === 0 ? 6 : 14,
    }));
    const lastColLetter = XLSX.utils.encode_col(headers.length - 1);
    sheet["!autofilter"] = { ref: `A1:${lastColLetter}${body.length + 1}` };
    XLSX.utils.book_append_sheet(workbook, sheet, "Horizontal Bill");
    XLSX.writeFile(workbook, filename);
}
