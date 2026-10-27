import { defineStore } from 'pinia';
import jsPDF from 'jspdf';
import autoTable from 'jspdf-autotable';
import * as XLSX from 'xlsx';

/**
 * Helper to convert an image path to Base64 data URL for PDF embedding.
 */
const loadImageBase64 = (url) => {
    return new Promise((resolve) => {
        const img = new Image();
        img.crossOrigin = 'Anonymous';
        img.onload = () => {
            try {
                const canvas = document.createElement('canvas');
                canvas.width = img.naturalWidth;
                canvas.height = img.naturalHeight;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0);
                resolve({
                    dataUrl: canvas.toDataURL('image/png'),
                    width: img.naturalWidth,
                    height: img.naturalHeight
                });
            } catch (err) {
                console.warn('Canvas conversion failed for logo:', err);
                resolve(null);
            }
        };
        img.onerror = () => {
            console.warn('Unable to load image from URL:', url);
            resolve(null);
        };
        img.src = url;
    });
};

/**
 * Number formatter for 2-decimal currency display (e.g. ' ').
 */
const formatAmount = (val) => {
    if (val === null || val === undefined) return '';
    if (typeof val === 'string' && val.trim() === '') return '';
    const num = typeof val === 'number' ? val : parseFloat(String(val).replace(/,/g, '').trim());
    if (isNaN(num) || num === 0) return '';
    return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

export const useReportStore = defineStore('report', {
    state: () => ({
        // Centralized University Information
        university: {
            name: 'Khulna Agricultural University',
            officeAddress: 'Temporary Office: 327, Jessore Road, North Mujgunni, Khalishpur, Khulna-9000',
            location: 'Khulna-9202, Bangladesh',
            website: 'www.kau.edu.bd',
            logoUrl: '/logo.png'
        },

        // Salary Summary Blank Form Model (as requested)
        salaryBill: {
            reportTitle: 'Salary Bill for the month of -',
            employee: {
                name: '',
                designation: '',
                department: '',
                payScale: '',
                serviceId: '',
                basic: '',
                incrementDate: '',
                monthYear: '',
                bankAccount: ''
            },
            allowances: [
                { slNo: 1, particulars: '', current: '', arrear: '', total: '' },
                { slNo: 2, particulars: '', current: ' ', arrear: ' ', total: ' ' },
                { slNo: 3, particulars: '', current: ' ', arrear: ' ', total: ' ' },
                { slNo: 4, particulars: '', current: ' ', arrear: ' ', total: ' ' },
                { slNo: 5, particulars: '', current: ' ', arrear: ' ', total: ' ' }
            ],
            grossSalary: {
                current: ' ',
                arrear: ' ',
                total: ' '
            },
            deductions: [
                { slNo: 1, particulars: '', current: '', arrear: '', total: '' },
                { slNo: 2, particulars: '', current: ' ', arrear: ' ', total: ' ' },
                { slNo: 3, particulars: '', current: ' ', arrear: ' ', total: ' ' },
                { slNo: 4, particulars: '', current: ' ', arrear: ' ', total: ' ' },
                { slNo: 5, particulars: '', current: ' ', arrear: ' ', total: ' ' },
                { slNo: 6, particulars: '', current: ' ', arrear: ' ', total: ' ' }
            ],
            totalDeductions: {
                current: ' ',
                arrear: ' ',
                total: ' '
            },
            netPayable: ' ',
            inWords: '',
            stampText: 'STAMP',
            signatureLabel: 'Signature of Employee'
        }
    }),

    actions: {
        /**
         * Exports the Sallery summary / Salary Bill report to Excel (.xlsx).
         */
        exportSalaryBillToExcel(customData = null, fileName = 'Sallery_summary.xlsx') {
            const bill = customData || this.salaryBill;
            const emp = bill.employee;

            const sheetData = [
                ['Khulna Agricultural University'],
                [this.university.officeAddress],
                [bill.reportTitle],
                [],
                ['Employee Particulars', '', 'Service & Pay Details', ''],
                ['Name :', emp.name, 'Service ID :', emp.serviceId],
                ['Designation :', emp.designation, 'Basic :', formatAmount(emp.basic)],
                ['Department :', emp.department, 'Increment Date :', emp.incrementDate],
                ['Pay Scale :', emp.payScale, 'Month & Year :', emp.monthYear],
                [],
                ['ALLOWANCES', '', '', '', ''],
                ['Sl.No', 'Particulars', 'Current', 'Arrear', 'Total'],
                ...bill.allowances.map(a => [
                    a.slNo,
                    a.particulars,
                    formatAmount(a.current),
                    formatAmount(a.arrear),
                    formatAmount(a.total)
                ]),
                ['', 'Gross Salary', formatAmount(bill.grossSalary.current), formatAmount(bill.grossSalary.arrear), formatAmount(bill.grossSalary.total)],
                [],
                ['DEDUCTIONS', '', '', '', ''],
                ['Sl.No', 'Particulars', 'Current', 'Arrear', 'Total'],
                ...bill.deductions.map(d => [
                    d.slNo,
                    d.particulars,
                    formatAmount(d.current),
                    formatAmount(d.arrear),
                    formatAmount(d.total)
                ]),
                ['', 'Total Deductions', formatAmount(bill.totalDeductions.current), formatAmount(bill.totalDeductions.arrear), formatAmount(bill.totalDeductions.total)],
                ['', '', '', 'Net Payable', formatAmount(bill.netPayable)],
                [],
                ['In word:', bill.inWords],
                ['Bank A/C:', emp.bankAccount],
                [],
                ['', 'STAMP', '', '', 'Signature of Employee']
            ];

            const ws = XLSX.utils.aoa_to_sheet(sheetData);
            ws['!cols'] = [
                { wch: 10 },
                { wch: 32 },
                { wch: 18 },
                { wch: 18 },
                { wch: 22 }
            ];

            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Salary Summary');
            XLSX.writeFile(wb, fileName);
        },

        /**
         * Generates the neat and clean A4 Salary Summary PDF strictly matching the image.
         * 
         * @param {Object} [customData] - Optional override data.
         * @param {string} [fileName] - File name to save.
         */
        async generateSalaryBillPdf(customData = null, fileName = 'Sallery_summary.pdf') {
            const bill = customData || this.salaryBill;
            const emp = bill.employee;

            // A4 dimensions: 210mm x 297mm
            const doc = new jsPDF({
                orientation: 'portrait',
                unit: 'mm',
                format: 'a4'
            });

            const pageWidth = doc.internal.pageSize.getWidth(); // 210mm
            const marginLeft = 15;
            const marginRight = 15;
            const contentWidth = pageWidth - marginLeft - marginRight; // 180mm
            const centerX = pageWidth / 2; // 105mm

            // 1. Header Section
            let currentY = 16;

            // University Logo (left aligned)
            const logo = await loadImageBase64(this.university.logoUrl);
            if (logo) {
                const logoWidth = 20;
                const logoHeight = (logo.height / logo.width) * logoWidth;
                doc.addImage(logo.dataUrl, 'PNG', marginLeft, currentY - 2, logoWidth, logoHeight);
            }

            // University Name (Bold, Center)
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(14.5);
            doc.setTextColor(20, 24, 33);
            doc.text(this.university.name, centerX, currentY + 3, { align: 'center' });

            // Temporary Office Address (Center, Regular)
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(8.5);
            doc.setTextColor(55, 65, 81);
            doc.text(this.university.officeAddress, centerX, currentY + 8, { align: 'center' });

            // Report Title (Center, Regular)
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(10);
            doc.setTextColor(31, 41, 55);
            doc.text(bill.reportTitle || 'Salary Bill for the month of -', centerX, currentY + 14, { align: 'center' });

            currentY += 25;

            // 2. Employee Metadata Section (Two balanced columns, matching image)
            doc.setFontSize(9);
            const rowHeight = 5.2;

            const col1LabelX = marginLeft;
            const col1ValX = marginLeft + 24;
            const col2LabelX = marginLeft + 122;
            const col2ValX = marginLeft + 152;

            // Row 1: Name & Service ID
            doc.setFont('helvetica', 'bold');
            doc.text('Name :', col1LabelX, currentY);
            doc.setFont('helvetica', 'normal');
            doc.text(emp.name || '', col1ValX, currentY);

            doc.setFont('helvetica', 'bold');
            doc.text('Service ID :', col2LabelX, currentY);
            doc.setFont('helvetica', 'normal');
            doc.text(emp.serviceId ? String(emp.serviceId) : '', col2ValX, currentY);

            // Row 2: Designation & Basic
            currentY += rowHeight;
            doc.setFont('helvetica', 'bold');
            doc.text('Designation :', col1LabelX, currentY);
            doc.setFont('helvetica', 'normal');
            doc.text(emp.designation || '', col1ValX, currentY);

            doc.setFont('helvetica', 'bold');
            doc.text('Basic :', col2LabelX, currentY);
            doc.setFont('helvetica', 'normal');
            doc.text(formatAmount(emp.basic), col2ValX, currentY);

            // Row 3: Department & Increment Date
            currentY += rowHeight;
            doc.setFont('helvetica', 'bold');
            doc.text('Department :', col1LabelX, currentY);
            doc.setFont('helvetica', 'normal');
            doc.text(emp.department || '', col1ValX, currentY);

            doc.setFont('helvetica', 'bold');
            doc.text('Increment Date :', col2LabelX, currentY);
            doc.setFont('helvetica', 'normal');
            doc.text(emp.incrementDate || '', col2ValX, currentY);

            // Row 4: Pay Scale & Month & Year
            currentY += rowHeight;
            doc.setFont('helvetica', 'bold');
            doc.text('Pay Scale :', col1LabelX, currentY);
            doc.setFont('helvetica', 'normal');
            doc.text(emp.payScale || '', col1ValX, currentY);

            doc.setFont('helvetica', 'bold');
            doc.text('Month & Year :', col2LabelX, currentY);
            doc.setFont('helvetica', 'normal');
            doc.text(emp.monthYear || '', col2ValX, currentY);

            currentY += 8;

            // 3. Allowances Table
            // Column widths: Sl.No: 12mm, Particulars: 88mm, Current: 26mm, Arrear: 26mm, Total: 28mm => 180mm
            const allowanceRows = [
                ...bill.allowances.map(a => [
                    { content: String(a.slNo), styles: { halign: 'center' } },
                    { content: a.particulars || '', styles: { halign: 'left' } },
                    { content: formatAmount(a.current), styles: { halign: 'right' } },
                    { content: formatAmount(a.arrear), styles: { halign: 'right' } },
                    { content: formatAmount(a.total), styles: { halign: 'right' } }
                ]),
                // Gross Salary row
                [
                    { content: '', styles: { halign: 'center' } },
                    { content: 'Gross Salary', styles: { halign: 'left', fontStyle: 'bold' } },
                    { content: formatAmount(bill.grossSalary.current), styles: { halign: 'right', fontStyle: 'bold' } },
                    { content: formatAmount(bill.grossSalary.arrear), styles: { halign: 'right', fontStyle: 'bold' } },
                    { content: formatAmount(bill.grossSalary.total), styles: { halign: 'right', fontStyle: 'bold' } }
                ]
            ];

            autoTable(doc, {
                startY: currentY,
                margin: { left: marginLeft, right: marginRight },
                tableWidth: contentWidth,
                theme: 'plain',
                styles: {
                    font: 'helvetica',
                    fontSize: 8.5,
                    textColor: [17, 24, 39],
                    lineColor: [40, 50, 60],
                    lineWidth: 0.25,
                    cellPadding: { top: 2.2, bottom: 2.2, left: 3, right: 3 }
                },
                head: [
                    [
                        {
                            content: 'Allowances',
                            colSpan: 5,
                            styles: {
                                halign: 'center',
                                fontStyle: 'bold',
                                fontSize: 9.5,
                                cellPadding: 2.5
                            }
                        }
                    ],
                    [
                        { content: 'Sl.No', styles: { halign: 'center', fontStyle: 'bold' } },
                        { content: 'Particulars', styles: { halign: 'left', fontStyle: 'bold' } },
                        { content: 'Current', styles: { halign: 'right', fontStyle: 'bold' } },
                        { content: 'Arrear', styles: { halign: 'right', fontStyle: 'bold' } },
                        { content: 'Total', styles: { halign: 'right', fontStyle: 'bold' } }
                    ]
                ],
                body: allowanceRows,
                columnStyles: {
                    0: { cellWidth: 12 },
                    1: { cellWidth: 88 },
                    2: { cellWidth: 26 },
                    3: { cellWidth: 26 },
                    4: { cellWidth: 28 }
                }
            });

            currentY = doc.lastAutoTable.finalY + 1;

            // 4. Deductions Table
            const deductionRows = [
                ...bill.deductions.map(d => [
                    { content: String(d.slNo), styles: { halign: 'center' } },
                    { content: d.particulars || '', styles: { halign: 'left' } },
                    { content: formatAmount(d.current), styles: { halign: 'right' } },
                    { content: formatAmount(d.arrear), styles: { halign: 'right' } },
                    { content: formatAmount(d.total), styles: { halign: 'right' } }
                ]),
                // Total Deductions row
                [
                    { content: '', styles: { halign: 'center' } },
                    { content: 'Total Deductions', styles: { halign: 'left', fontStyle: 'bold' } },
                    { content: formatAmount(bill.totalDeductions.current), styles: { halign: 'right', fontStyle: 'bold' } },
                    { content: formatAmount(bill.totalDeductions.arrear), styles: { halign: 'right', fontStyle: 'bold' } },
                    { content: formatAmount(bill.totalDeductions.total), styles: { halign: 'right', fontStyle: 'bold' } }
                ],
                // Net Payable row
                [
                    { content: '', colSpan: 3, styles: { halign: 'left' } },
                    { content: 'Net Payable', styles: { halign: 'right', fontStyle: 'bold' } },
                    { content: formatAmount(bill.netPayable), styles: { halign: 'right', fontStyle: 'bold' } }
                ]
            ];

            autoTable(doc, {
                startY: currentY,
                margin: { left: marginLeft, right: marginRight },
                tableWidth: contentWidth,
                theme: 'plain',
                styles: {
                    font: 'helvetica',
                    fontSize: 8.5,
                    textColor: [17, 24, 39],
                    lineColor: [40, 50, 60],
                    lineWidth: 0.25,
                    cellPadding: { top: 2.2, bottom: 2.2, left: 3, right: 3 }
                },
                head: [
                    [
                        {
                            content: 'Deductions',
                            colSpan: 5,
                            styles: {
                                halign: 'center',
                                fontStyle: 'bold',
                                fontSize: 9.5,
                                cellPadding: 2.5
                            }
                        }
                    ],
                    [
                        { content: 'Sl.No', styles: { halign: 'center', fontStyle: 'bold' } },
                        { content: 'Particulars', styles: { halign: 'left', fontStyle: 'bold' } },
                        { content: 'Current', styles: { halign: 'right', fontStyle: 'bold' } },
                        { content: 'Arrear', styles: { halign: 'right', fontStyle: 'bold' } },
                        { content: 'Total', styles: { halign: 'right', fontStyle: 'bold' } }
                    ]
                ],
                body: deductionRows,
                columnStyles: {
                    0: { cellWidth: 12 },
                    1: { cellWidth: 88 },
                    2: { cellWidth: 26 },
                    3: { cellWidth: 26 },
                    4: { cellWidth: 28 }
                }
            });

            currentY = doc.lastAutoTable.finalY + 8;

            // 5. In Words section
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(8.5);
            doc.setTextColor(31, 41, 55);
            doc.text('In word:', marginLeft, currentY);

            if (bill.inWords) {
                doc.setFont('helvetica', 'normal');
                const inWordPrefixWidth = doc.getTextWidth('In word: ');
                doc.text(bill.inWords, marginLeft + inWordPrefixWidth, currentY);
            }

            currentY += 26;

            // 6. Footer Section (Bank A/C, STAMP, Signature of Employee)
            const footerY = Math.max(currentY, 248);

            // Left: Bank A/C
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(9);
            doc.text(`Bank A/C: ${emp.bankAccount || ''}`, marginLeft, footerY);

            // Center: STAMP
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(8.5);
            doc.setTextColor(75, 85, 99);
            doc.text(bill.stampText || 'STAMP', centerX, footerY, { align: 'center' });

            // Right: Signature line & Signature of Employee
            const sigLineEndX = pageWidth - marginRight;
            const sigLineStartX = sigLineEndX - 48;
            doc.setDrawColor(75, 85, 99);
            doc.setLineWidth(0.3);
            doc.line(sigLineStartX, footerY - 4, sigLineEndX, footerY - 4);

            doc.setFont('helvetica', 'normal');
            doc.setFontSize(8.5);
            doc.setTextColor(31, 41, 55);
            doc.text(bill.signatureLabel || 'Signature of Employee', (sigLineStartX + sigLineEndX) / 2, footerY, { align: 'center' });

            // Save PDF
            doc.save(fileName);
        }
    }
});
