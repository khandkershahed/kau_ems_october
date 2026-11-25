import { jsPDF } from 'jspdf';
import autoTable from 'jspdf-autotable';
import { MONTH_NAMES } from '@/Pages/Reports/reportTemplates/utils';

const formatTwoDecimals = (value) => {
    if (value === null || value === undefined || value === '' || value === 'N/R' || value === '—') {
        return '—';
    }
    const num = Number(value);
    if (!Number.isFinite(num)) return '—';
    return num.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

async function loadLogoBase64() {
    try {
        const res = await fetch('/logo.png');
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

/**
 * Native jsPDF + jspdf-autotable vector table generator for Horizontal Salary Bill.
 * Renders A4 landscape pages with exact 2-decimal monetary formatting (0.00) and
 * separated individual component columns (no html2canvas).
 */
export async function createHorizontalBillPdf({
    records = [],
    columns = [],
    totals = {},
    year,
    month,
    headerConfig = {},
    rowsPerPage = 15,
    isPrint = false,
} = {}) {
    const doc = new jsPDF({
        orientation: 'landscape',
        unit: 'mm',
        format: 'a4',
        compress: true,
    });

    const pageWidth = doc.internal.pageSize.getWidth();
    const pageHeight = doc.internal.pageSize.getHeight();
    const logoBase64 = await loadLogoBase64();

    const monthName = MONTH_NAMES[Number(month)] || `Month ${month}`;
    const periodLabel = `${monthName} ${year}`;
    const titleText = headerConfig.title || 'Khulna Agricultural University';
    const addressText = headerConfig.address
        || 'Temporary Office: 327, Jessore Road, North Mujgunni, Khalishpur, Khulna-9000';
    const subtitleText = headerConfig.subtitle
        || `Horizontal Salary Bill (Current + Arrear) for ${periodLabel}`;

    const activeCols = columns.length
        ? columns
        : [
            { key: 'basic', label: 'Basic' },
            { key: 'hra', label: 'HRA' },
            { key: 'medical', label: 'MA' },
            { key: 'special_benefit', label: 'S. Benefit' },
            { key: 'education', label: 'Edu' },
            { key: 'gross', label: 'Gross' },
            { key: 'gpf', label: 'GPF' },
            { key: 'income_tax', label: 'Income Tax' },
            { key: 'revenue_stamp', label: 'Rev. Stamp' },
            { key: 'total_deduction', label: 'Tot. Ded.' },
            { key: 'net_arrear', label: 'Net Arrear' },
            { key: 'net_payable', label: 'Net Payable' },
        ];

    const perPage = Math.max(1, Math.min(25, Number(rowsPerPage) || 15));
    const chunks = [];
    for (let i = 0; i < records.length; i += perPage) {
        chunks.push(records.slice(i, i + perPage));
    }
    if (chunks.length === 0) chunks.push([]);

    const headRow = [
        'SL',
        'ID / Grade',
        'Name',
        'Designation',
        ...activeCols.map((c) => c.label),
        'Signature',
    ];

    const numericColStart = 4;
    const numericColEnd = numericColStart + activeCols.length - 1;
    const columnStyles = {
        0: { halign: 'center', cellWidth: 7 },
        1: { halign: 'center', cellWidth: 14 },
        2: { halign: 'left', cellWidth: 26 },
        3: { halign: 'left', cellWidth: 22 },
    };
    for (let c = numericColStart; c <= numericColEnd; c++) {
        columnStyles[c] = { halign: 'right' };
    }
    columnStyles[numericColEnd + 1] = { halign: 'center', cellWidth: 14 };

    const fontSize = activeCols.length >= 16 ? 5.6 : activeCols.length >= 13 ? 6.1 : 6.6;

    const drawHeader = (pageIdx) => {
        if (logoBase64) {
            try {
                doc.addImage(logoBase64, 'PNG', 8, 6, 12, 12);
            } catch (_) {
                // Keep header text intact if logo fails to render.
            }
        }
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(12);
        doc.setTextColor(22, 37, 54);
        doc.text(titleText, pageWidth / 2, 10, { align: 'center' });

        doc.setFont('helvetica', 'normal');
        doc.setFontSize(7.5);
        doc.setTextColor(71, 85, 105);
        doc.text(addressText, pageWidth / 2, 14, { align: 'center' });

        doc.setFont('helvetica', 'bold');
        doc.setFontSize(9);
        doc.setTextColor(15, 23, 42);
        doc.text(subtitleText, pageWidth / 2, 18.5, { align: 'center' });

        doc.setFont('helvetica', 'normal');
        doc.setFontSize(7);
        doc.setTextColor(100, 116, 139);
        doc.text(
            `Period: ${periodLabel}  |  Page ${pageIdx + 1} of ${chunks.length}`,
            pageWidth - 8,
            18.5,
            { align: 'right' },
        );
    };

    chunks.forEach((chunk, pageIdx) => {
        if (pageIdx > 0) doc.addPage('a4', 'landscape');
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
                if (raw === null || raw === undefined || !Number.isFinite(Number(raw))) {
                    pageMissing[col.key] = true;
                    return '—';
                }
                pageTotals[col.key] += Number(raw);
                return formatTwoDecimals(raw);
            });
            return [
                pageIdx * perPage + rowIdx + 1,
                `${r.employee_code || ''}\n${r.grade || ''}`.trim(),
                r.name || '',
                r.designation || '',
                ...monetaryCells,
                '',
            ];
        });

        const pageTotalRow = [
            '',
            '',
            'PAGE TOTAL',
            '',
            ...activeCols.map((col) =>
                pageMissing[col.key] ? '—' : formatTwoDecimals(Math.round(pageTotals[col.key] * 100) / 100),
            ),
            '',
        ];

        const footRows = [pageTotalRow];
        if (pageIdx === chunks.length - 1) {
            const grandTotalRow = [
                '',
                '',
                `GRAND TOTAL (${records.length})`,
                '',
                ...activeCols.map((col) => formatTwoDecimals(totals?.[col.key])),
                '',
            ];
            footRows.push(grandTotalRow);
        }

        autoTable(doc, {
            head: [headRow],
            body: bodyRows,
            foot: footRows,
            startY: 22,
            margin: { left: 7, right: 7, bottom: 12 },
            theme: 'grid',
            styles: {
                fontSize,
                cellPadding: 1.1,
                lineColor: [148, 163, 184],
                lineWidth: 0.15,
                overflow: 'linebreak',
                valign: 'middle',
                textColor: [22, 37, 54],
            },
            headStyles: {
                fillColor: [239, 242, 246],
                textColor: [23, 42, 63],
                fontStyle: 'bold',
                halign: 'center',
            },
            footStyles: {
                fillColor: [237, 245, 239],
                textColor: [15, 23, 42],
                fontStyle: 'bold',
            },
            columnStyles,
        });

        doc.setFont('helvetica', 'normal');
        doc.setFontSize(6.5);
        doc.setTextColor(100, 116, 139);
        doc.text(
            `Khulna Agricultural University • Horizontal Salary Bill (${periodLabel})`,
            7,
            pageHeight - 5,
        );
        doc.text(
            `Page ${pageIdx + 1} of ${chunks.length}`,
            pageWidth - 7,
            pageHeight - 5,
            { align: 'right' },
        );
    });

    const filename = `KAU_Horizontal_Bill_${year}_${String(month).padStart(2, '0')}.pdf`;
    if (isPrint) {
        doc.autoPrint();
        const url = URL.createObjectURL(doc.output('blob'));
        const tab = window.open(url, '_blank');
        if (tab) tab.opener = null;
        else alert('Please enable popups for printing.');
        setTimeout(() => URL.revokeObjectURL(url), 60000);
    } else {
        doc.save(filename);
    }
    return doc;
}
