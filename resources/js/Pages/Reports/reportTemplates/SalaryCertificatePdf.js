import { jsPDF } from 'jspdf';

// Dedicated vector-PDF template for Salary Certificate. No html2canvas, no DOM capture.
// Financial values come exclusively from the existing SalaryCertificateController payload.
const PAGE_WIDTH = 210;
const PAGE_HEIGHT = 297;
const LEFT = 23;
const RIGHT = 23;
const CONTENT_WIDTH = PAGE_WIDTH - LEFT - RIGHT;
const FOOTER_Y = 264;

const textValue = (value) => String(value ?? '')
    .replace(/[\u2018\u2019]/g, "'")
    .replace(/[\u2013\u2014]/g, '-')
    .replace(/\u00a0/g, ' ');

const taka = (value) => (Number(value ?? 0) / 100).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const cellValue = (certificate, index, column) =>
    taka(certificate.cells?.[index]?.[column] ?? 0);

const loadLogo = async () => {
    try {
        const response = await fetch('/logo.png');
        if (!response.ok) return null;
        const blob = await response.blob();
        return await new Promise((resolve) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = () => resolve(null);
            reader.readAsDataURL(blob);
        });
    } catch (_) {
        return null;
    }
};

const textLines = (pdf, value, maxWidth, fontSize, style = 'normal') => {
    pdf.setFont('times', style);
    pdf.setFontSize(fontSize);
    return pdf.splitTextToSize(textValue(value), maxWidth);
};

const richParagraph = (pdf, fragments, y, fontSize = 10.1, leading = 4.4) => {
    let x = LEFT;
    const maxX = LEFT + CONTENT_WIDTH;
    pdf.setFontSize(fontSize);
    fragments.forEach(({value, bold = false}) => {
        pdf.setFont('times', bold ? 'bold' : 'normal');
        const words = textValue(value).match(/\S+|\s+/g) || [];
        words.forEach((part) => {
            const space = /^\s+$/.test(part);
            const width = pdf.getTextWidth(part);
            if (!space && x + width > maxX && x > LEFT) {
                x = LEFT;
                y += leading;
            }
            if (!space || x > LEFT) {
                if (!space) pdf.text(part, x, y);
                x += width;
            }
        });
    });
    return y + leading;
};

const metadata = (pdf, certificate) => {
    const y = 35;
    const h = 19;
    pdf.setDrawColor(221, 221, 221);
    pdf.setLineWidth(0.24);
    pdf.setLineDashPattern([1.5, 1.25], 0);
    pdf.rect(LEFT, y, CONTENT_WIDTH, h);
    pdf.line(LEFT + CONTENT_WIDTH / 2, y, LEFT + CONTENT_WIDTH / 2, y + h);
    pdf.setLineDashPattern([], 0);
    pdf.setTextColor(0, 0, 0);
    const left = [
        ['Name', certificate.name],
        ['Designation', certificate.designation],
        ['Pay Scale', certificate.pay_scale],
        ['Approved Basic', certificate.approved_basic],
    ];
    const right = [
        ['Service ID', certificate.employee_code],
        ['Bank A/C', certificate.bank_account],
        ['Month', certificate.month],
        ['Fixation', certificate.fixation],
    ];
    const fontSize = 8.0;
    const lineHeight = 3.8;
    left.forEach(([label, value], index) => {
        const yLine = y + 4.1 + index * lineHeight;
        // Two separate runs avoid changing the source text or shortening long names.
        const labelText = label + ': ';
        pdf.setFont('times', 'bold');
        pdf.setFontSize(fontSize);
        pdf.text(labelText, LEFT + 2, yLine);
        const labelWidth = pdf.getTextWidth(labelText);
        pdf.setFont('times', 'normal');
        pdf.text(textValue(value), LEFT + 2 + labelWidth, yLine, {maxWidth: CONTENT_WIDTH / 2 - labelWidth - 4});
    });
    right.forEach(([label, value], index) => {
        const yLine = y + 4.1 + index * lineHeight;
        pdf.setFont('times', 'normal');
        pdf.setFontSize(fontSize);
        // Right aligned metadata is identical in principle to the Word two-column design.
        const fullText = `${label}: ${textValue(value)}`;
        pdf.text(fullText, LEFT + CONTENT_WIDTH - 2, yLine, {align: 'right'});
    });
};

const header = (pdf, logo, certificate) => {
    if (logo) {
        try {
            pdf.addImage(logo, 'PNG', LEFT + 1, 13, 13.5, 15);
        } catch (_) {
            // The institutional name remains visible if the image is unavailable.
        }
    }
    pdf.setFont('times', 'bold');
    pdf.setFontSize(13);
    pdf.text('Khulna Agricultural University', PAGE_WIDTH / 2, 19, {align: 'center'});
    pdf.setFont('times', 'normal');
    pdf.setFontSize(8.7);
    pdf.text('Temporary Office: 327, Jessore Road, North Mujgunni, Khalishpur, Khulna-9000',
        PAGE_WIDTH / 2, 24, {align: 'center'});
    metadata(pdf, certificate);
};

const tableRows = (certificate) => {
    const allowances = (certificate.allowance_rows || []).map((row) => ([
        String(row.sl ?? ''), textValue(row.particulars),
        taka(row.current), taka(row.arrear), taka(row.total),
    ]));
    const deductions = (certificate.deduction_rows || []).map((row) => ([
        String(row.sl ?? ''), textValue(row.particulars),
        taka(row.current), taka(row.arrear), taka(row.total),
    ]));
    return [
        {type: 'section', cells: ['Allowances / Payable']},
        {type: 'head', cells: ['Sl. No', 'Particulars', 'Current', 'Arrear', 'Total']},
        ...allowances.map((cells) => ({type: 'normal', cells})),
        {type: 'total', cells: ['', 'Gross Salary / Payable', cellValue(certificate, 9, 'current'),
            cellValue(certificate, 9, 'arrear'), cellValue(certificate, 9, 'total')]},
        {type: 'section', cells: ['Deductions / Recoveries']},
        {type: 'head', cells: ['Sl. No', 'Particulars', 'Current', 'Arrear', 'Total']},
        ...deductions.map((cells) => ({type: 'normal', cells})),
        {type: 'total', cells: ['', 'Total Deductions', cellValue(certificate, 18, 'current'),
            cellValue(certificate, 18, 'arrear'), cellValue(certificate, 18, 'total')]},
        {type: 'total', cells: ['', 'Net Payable:', cellValue(certificate, 19, 'current'),
            cellValue(certificate, 19, 'arrear'), cellValue(certificate, 19, 'total')]},
    ];
};

const renderFinancialTable = (pdf, certificate, startY) => {
    const rows = tableRows(certificate);
    const widths = [13, 55, 32, 32, 32];
    const fontSize = rows.length > 23 ? 7.6 : 8.4;
    const baseHeight = rows.length > 23 ? 3.8 : 4.4;
    const leading = fontSize * 0.39;
    const prepared = rows.map((row) => {
        if (row.type === 'section') {
            return {...row, lines: [[row.cells[0]]], height: baseHeight + 0.2};
        }
        const cells = row.cells.map((value, index) =>
            textLines(pdf, value, widths[index] - 2.6, fontSize,
                row.type === 'normal' ? 'normal' : 'bold'));
        const longest = Math.max(...cells.map((lines) => lines.length));
        return {...row, lines: cells, height: Math.max(baseHeight, longest * leading + 1.25)};
    });
    const height = prepared.reduce((total, row) => total + row.height, 0);
    // Keep all rows, original labels, original monetary amounts and one-page layout.
    // Scale the table only if a certificate has unusually many component rows.
    const availableHeight = Math.max(1, 241 - startY);
    const scale = Math.min(1, availableHeight / height);
    let y = startY;
    pdf.setDrawColor(0, 0, 0);
    pdf.setLineWidth(0.16);
    for (const row of prepared) {
        const h = row.height * scale;
        if (row.type === 'section') {
            pdf.rect(LEFT, y, CONTENT_WIDTH, h);
            pdf.setFont('times', 'bold');
            pdf.setFontSize(fontSize * scale);
            pdf.text(row.cells[0], LEFT + 1.3, y + h / 2 + 0.8 * scale);
            y += h;
            continue;
        }
        let x = LEFT;
        row.lines.forEach((lines, index) => {
            const width = widths[index];
            pdf.rect(x, y, width, h);
            const bold = row.type !== 'normal';
            pdf.setFont('times', bold ? 'bold' : 'normal');
            pdf.setFontSize(fontSize * scale);
            const lineH = leading * scale;
            const allHeight = (lines.length - 1) * lineH;
            const baseline = y + h / 2 + fontSize * 0.15 * scale - allHeight / 2;
            const tx = index === 0 ? x + width / 2 : index === 1 ? x + 1.35 : x + width - 1.35;
            pdf.text(lines, tx, baseline, {align: index === 0 ? 'center' : index === 1 ? 'left' : 'right',
                lineHeightFactor: 1.02});
            x += width;
        });
        y += h;
    }
    return y;
};

const signatures = (pdf) => {
    pdf.setDrawColor(221, 221, 221);
    pdf.setLineWidth(0.22);
    pdf.setLineDashPattern([1.6, 1.35], 0);
    const width = CONTENT_WIDTH / 3;
    for (let i = 0; i < 3; i++) {
        pdf.rect(LEFT + i * width, FOOTER_Y, width, 17.5);
    }
    pdf.setLineDashPattern([], 0);
};

const renderPage = (pdf, certificate, logo) => {
    pdf.setTextColor(0, 0, 0);
    header(pdf, logo, certificate);
    pdf.setFont('times', 'bold');
    pdf.setFontSize(14);
    pdf.text('Salary Certificate', PAGE_WIDTH / 2, 63, {align: 'center'});
    let y = 71;
    y = richParagraph(pdf, [
        {value: 'This is to certify that '},
        {value: `${certificate.prefix} ${certificate.name}`, bold: true},
        {value: ' is serving as an '},
        {value: certificate.designation, bold: true},
        {value: ` in the Department of ${certificate.department} at Khulna Agricultural University.`},
    ], y) + 3;
    y = richParagraph(pdf, [
        {value: `Currently, ${certificate.possessive} salary is fixed under `},
        {value: certificate.grade_range, bold: true},
        {value: ` of the Government National Pay Scale ${certificate.pay_scale_year}. The detailed salary statement, including perquisites and allowances, is presented below:`},
    ], y) + 2;
    y = renderFinancialTable(pdf, certificate, y);
    pdf.setFont('times', 'normal');
    pdf.setFontSize(9.1);
    let words = `In words: ${textValue(certificate.in_words)}`;
    if (!words.endsWith('.')) words += '.';
    const wordLines = textLines(pdf, words, CONTENT_WIDTH, 9.1);
    pdf.text(wordLines, LEFT, y + 4);
    const wordsEnd = y + 4 + (wordLines.length - 1) * 3.6;
    pdf.setFontSize(10.0);
    const courtesy = `I wish ${textValue(certificate.object_pronoun)} continued success, good health, and prosperity in ${textValue(certificate.possessive)} future endeavors.`;
    const courtesyLines = textLines(pdf, courtesy, CONTENT_WIDTH, 10.0);
    pdf.text(courtesyLines, LEFT, Math.min(wordsEnd + 12, 253));
    signatures(pdf);
};

export async function createSalaryCertificatesPdf(certificates) {
    const pdf = new jsPDF({orientation: 'portrait', unit: 'mm', format: 'a4', compress: true});
    const logo = await loadLogo();
    certificates.forEach((certificate, index) => {
        if (index > 0) pdf.addPage('a4', 'portrait');
        renderPage(pdf, certificate, logo);
    });
    return pdf;
}
