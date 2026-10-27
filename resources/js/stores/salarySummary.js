import { defineStore } from 'pinia';
import { jsPDF } from 'jspdf';
import autoTable from 'jspdf-autotable';
import * as XLSX from 'xlsx';
import { formatMoney, numberToWordsTaka, MONTH_NAMES } from '@/Pages/Reports/reportTemplates/utils';

// Helper to fetch image as base64 for jsPDF
async function getBase64ImageFromUrl(url) {
    try {
        const res = await fetch(url);
        const blob = await res.blob();
        return new Promise((resolve) => {
            const reader = new FileReader();
            reader.onloadend = () => resolve(reader.result);
            reader.onerror = () => resolve(null);
            reader.readAsDataURL(blob);
        });
    } catch (e) {
        console.warn('Could not load logo for PDF:', e);
        return null;
    }
}

export const useSalarySummaryStore = defineStore('salarySummary', {
    state: () => {
        const now = new Date();
        return {
            exportingExcel: false,
            generatingPdf: false,
            showCustomizer: true,
            headerConfig: {
                title: 'Khulna Agricultural University',
                address: 'Temporary Office: 327, Jessore Road, North Mujgunni, Khalishpur, Khulna-9000',
                subtitle: '',
                date: now.toLocaleDateString('en-US', { day: '2-digit', month: 'short', year: 'numeric' }),
                year: now.getFullYear(),
            },
            titlePresets: [
                'Khulna Agricultural University',
                'Office of the Comptroller of Accounts, KAU',
                'Office of the Registrar, KAU',
                'Finance and Accounts Division, KAU',
            ],
            addressPresets: [
                'Temporary Office: 327, Jessore Road, North Mujgunni, Khalishpur, Khulna-9000',
                'Permanent Campus: Chhoto Boyra, Khulna-9000, Bangladesh',
            ],
        };
    },

    getters: {
        getEffectiveSubtitle: (state) => (monthYearStr) => {
            if (state.headerConfig.subtitle && state.headerConfig.subtitle.trim()) {
                return state.headerConfig.subtitle.trim();
            }
            return `Salary Summary Statement of Components for the month of ${monthYearStr || 'Current Month'}`;
        },
    },

    actions: {
        setHeaderConfig(updated) {
            if (updated.title !== undefined) this.headerConfig.title = updated.title;
            if (updated.address !== undefined) this.headerConfig.address = updated.address;
            if (updated.subtitle !== undefined) this.headerConfig.subtitle = updated.subtitle;
            if (updated.date !== undefined) this.headerConfig.date = updated.date;
            if (updated.year !== undefined) this.headerConfig.year = updated.year;
        },

        setDatePreset(preset, monthId = null) {
            const now = new Date();
            const y = this.headerConfig.year || now.getFullYear();
            const mIndex = (monthId || (now.getMonth() + 1)) - 1;

            if (preset === 'today') {
                this.headerConfig.date = now.toLocaleDateString('en-US', { day: '2-digit', month: 'short', year: 'numeric' });
            } else if (preset === 'first') {
                const d = new Date(y, mIndex, 1);
                this.headerConfig.date = d.toLocaleDateString('en-US', { day: '2-digit', month: 'short', year: 'numeric' });
            } else if (preset === 'last') {
                const d = new Date(y, mIndex + 1, 0);
                this.headerConfig.date = d.toLocaleDateString('en-US', { day: '2-digit', month: 'short', year: 'numeric' });
            }
        },

        resetHeaderConfig(defaultYear = null) {
            const now = new Date();
            this.headerConfig = {
                title: 'Khulna Agricultural University',
                address: 'Temporary Office: 327, Jessore Road, North Mujgunni, Khalishpur, Khulna-9000',
                subtitle: '',
                date: now.toLocaleDateString('en-US', { day: '2-digit', month: 'short', year: 'numeric' }),
                year: defaultYear || now.getFullYear(),
            };
        },

        /**
         * Calculate aggregated salary components (allowances & deductions) for all employees.
         */
        calculateComponentTotals(records = []) {
            if (!records || records.length === 0) {
                return {
                    allowanceRows: [],
                    deductionRows: [],
                    grossAllowance: 0,
                    totalDeductions: 0,
                    netPayable: 0,
                    inWords: 'Zero Taka only.',
                    totalEmployees: 0,
                    isGeneratedAll: true,
                    hasProvisional: false,
                };
            }

            const allowanceMap = new Map();
            const deductionMap = new Map();

            // Track standard Basic Pay specifically so it always appears at SL 1
            let totalBasicPay = 0;

            records.forEach((record) => {
                const allowances = record.allowance_items || [];
                const deductions = record.deduction_items || [];
                const basicVal = Number(record.basic_pay) || 0;

                // Process Allowances
                if (allowances.length > 0) {
                    let recordHasBasicInItems = false;
                    allowances.forEach((item) => {
                        const rawName = (item.name || item.payroll_component_type?.name || 'Allowance').trim();
                        const amt = Number(item.amount ?? item.calculated_amount ?? 0);
                        const lower = rawName.toLowerCase();

                        if (lower.includes('basic')) {
                            recordHasBasicInItems = true;
                            totalBasicPay += amt;
                        } else {
                            const currentSum = allowanceMap.get(rawName) || 0;
                            allowanceMap.set(rawName, currentSum + amt);
                        }
                    });

                    // If basic pay exists on record but wasn't in allowance_items
                    if (!recordHasBasicInItems && basicVal > 0) {
                        totalBasicPay += basicVal;
                    }
                } else if (basicVal > 0) {
                    totalBasicPay += basicVal;
                    const diff = (Number(record.total_allowance) || 0) - basicVal;
                    if (diff > 0) {
                        const cur = allowanceMap.get('Other Allowances') || 0;
                        allowanceMap.set('Other Allowances', cur + diff);
                    }
                } else if (Number(record.total_allowance) > 0) {
                    const amt = Number(record.total_allowance);
                    const cur = allowanceMap.get('General Allowances') || 0;
                    allowanceMap.set('General Allowances', cur + amt);
                }

                // Process Deductions
                if (deductions.length > 0) {
                    deductions.forEach((item) => {
                        const rawName = (item.name || item.payroll_component_type?.name || 'Deduction').trim();
                        const amt = Number(item.amount ?? item.calculated_amount ?? 0);
                        const currentSum = deductionMap.get(rawName) || 0;
                        deductionMap.set(rawName, currentSum + amt);
                    });
                } else {
                    const totalDeduct = Number(record.total_deduction) || 0;
                    const taxDeduct = Number(record.tax_deduction) || 0;
                    const gpfDeduct = Number(record.gpf_deduction) || 0;

                    if (taxDeduct > 0) {
                        const cur = deductionMap.get('Income Tax') || 0;
                        deductionMap.set('Income Tax', cur + taxDeduct);
                    }
                    if (gpfDeduct > 0) {
                        const cur = deductionMap.get('General Provident Fund (GPF)') || 0;
                        deductionMap.set('General Provident Fund (GPF)', cur + gpfDeduct);
                    }
                    const otherDeduct = totalDeduct - (taxDeduct + gpfDeduct);
                    if (otherDeduct > 0) {
                        const cur = deductionMap.get('Other Deductions') || 0;
                        deductionMap.set('Other Deductions', cur + otherDeduct);
                    }
                }
            });

            // Build Allowance Rows: Basic Pay always 1st
            const allowanceRows = [];
            let aSl = 1;

            if (totalBasicPay > 0) {
                allowanceRows.push({
                    sl: aSl++,
                    particulars: 'Basic Pay',
                    current: totalBasicPay,
                    arrear: 0,
                    total: totalBasicPay,
                });
            }

            // Convert remaining allowances map to rows
            Array.from(allowanceMap.entries())
                .sort((a, b) => b[1] - a[1]) // highest to lowest for clean structure
                .forEach(([particulars, amount]) => {
                    allowanceRows.push({
                        sl: aSl++,
                        particulars,
                        current: amount,
                        arrear: 0,
                        total: amount,
                    });
                });

            // If empty, ensure at least one row
            if (allowanceRows.length === 0) {
                allowanceRows.push({
                    sl: 1,
                    particulars: 'Basic Pay',
                    current: 0,
                    arrear: 0,
                    total: 0,
                });
            }

            // Build Deduction Rows
            const deductionRows = [];
            let dSl = 1;

            Array.from(deductionMap.entries())
                .sort((a, b) => b[1] - a[1])
                .forEach(([particulars, amount]) => {
                    deductionRows.push({
                        sl: dSl++,
                        particulars,
                        current: amount,
                        arrear: 0,
                        total: amount,
                    });
                });

            const grossAllowance = allowanceRows.reduce((sum, r) => sum + r.total, 0);
            const totalDeductions = deductionRows.reduce((sum, r) => sum + r.total, 0);
            const netPayable = grossAllowance - totalDeductions;
            const inWords = numberToWordsTaka(netPayable);

            return {
                allowanceRows,
                deductionRows,
                grossAllowance,
                totalDeductions,
                netPayable,
                inWords,
                totalEmployees: records.length,
                isGeneratedAll: records.every(r => r.is_generated),
                hasProvisional: records.some(r => !r.is_generated),
            };
        },

        /**
         * Download Salary Summary Report as a clean component total spreadsheet (.xlsx).
         */
        async exportToExcel(records = [], headerConfig = null, monthYearStr = '') {
            this.exportingExcel = true;
            try {
                const totals = this.calculateComponentTotals(records);

                const title = headerConfig?.title || this.headerConfig.title;
                const address = headerConfig?.address || this.headerConfig.address;
                const subtitle = headerConfig?.subtitle || this.getEffectiveSubtitle(monthYearStr);
                const dateStr = headerConfig?.date || this.headerConfig.date;

                const headerRows = [
                    [title],
                    [address],
                    [subtitle],
                    [`Statement Date: ${dateStr}   |   Period: ${monthYearStr}   |   Total Employees: ${records.length}`],
                    [], // blank line
                ];

                const sheetData = [...headerRows];

                // 1. Allowances Table
                sheetData.push(['ALLOWANCES (EARNINGS)']);
                sheetData.push(['SL.No', 'Particulars', 'Current (BDT)', 'Arrear (BDT)', 'Total (BDT)']);
                totals.allowanceRows.forEach((r) => {
                    sheetData.push([r.sl, r.particulars, r.current, r.arrear, r.total]);
                });
                sheetData.push(['', 'Gross Salary / Total Allowances', totals.grossAllowance, 0, totals.grossAllowance]);
                sheetData.push([]); // blank line

                // 2. Deductions Table
                sheetData.push(['DEDUCTIONS']);
                sheetData.push(['Sl.No', 'Particulars', 'Current (BDT)', 'Arrear (BDT)', 'Total (BDT)']);
                if (totals.deductionRows.length === 0) {
                    sheetData.push(['-', 'No deductions recorded', 0, 0, 0]);
                } else {
                    totals.deductionRows.forEach((r) => {
                        sheetData.push([r.sl, r.particulars, r.current, r.arrear, r.total]);
                    });
                }
                sheetData.push(['', 'Total Deductions', totals.totalDeductions, 0, totals.totalDeductions]);
                sheetData.push([]); // blank line

                // 3. Summary & In Words
                sheetData.push(['', 'NET PAYABLE', totals.netPayable]);
                sheetData.push(['In Word:', totals.inWords]);
                sheetData.push([]); // blank line
                sheetData.push([]); // blank line

                // 4. Signatures
                sheetData.push(['Prepared By', 'Checked By', 'Comptroller of Accounts', 'Vice-Chancellor / Registrar']);

                const worksheet = XLSX.utils.aoa_to_sheet(sheetData);

                // Auto-fit column widths
                worksheet['!cols'] = [
                    { wch: 10 },
                    { wch: 45 },
                    { wch: 20 },
                    { wch: 16 },
                    { wch: 22 },
                ];

                const workbook = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(workbook, worksheet, 'Salary Summary');

                const fileName = `KAU_Salary_Summary_${(monthYearStr || 'Report').replace(/\s+/g, '_')}.xlsx`;
                XLSX.writeFile(workbook, fileName);
            } catch (err) {
                console.error('Failed to export Salary Summary Excel:', err);
            } finally {
                this.exportingExcel = false;
            }
        },

        /**
         * Generate clean A4 printable PDF matching Salary Bill structure for Component Totals.
         */
        async generatePdf({ records = [], isPrint = false, headerConfig = null, monthYearStr = '' } = {}) {
            this.generatingPdf = true;
            try {
                if (!records || records.length === 0) {
                    console.warn('No records to generate Salary Summary PDF.');
                    return;
                }

                const totals = this.calculateComponentTotals(records);

                const doc = new jsPDF({
                    orientation: 'portrait',
                    unit: 'mm',
                    format: 'a4',
                });

                const pageWidth = doc.internal.pageSize.getWidth();
                const pageHeight = doc.internal.pageSize.getHeight();
                const logoBase64 = await getBase64ImageFromUrl('/logo.png');

                const titleText = headerConfig?.title || this.headerConfig.title;
                const addressText = headerConfig?.address || this.headerConfig.address;
                const subtitleText = headerConfig?.subtitle || this.getEffectiveSubtitle(monthYearStr);
                const dateText = headerConfig?.date || this.headerConfig.date;

                // 1. Header with Logo
                if (logoBase64) {
                    try {
                        doc.addImage(logoBase64, 'PNG', 14, 9, 15, 15);
                    } catch (e) {
                        // ignore
                    }
                }

                doc.setFont('helvetica', 'bold');
                doc.setFontSize(13);
                doc.setTextColor(20, 24, 33);
                doc.text(titleText, pageWidth / 2, 13, { align: 'center' });

                doc.setFont('helvetica', 'normal');
                doc.setFontSize(8);
                doc.setTextColor(70, 70, 70);
                doc.text(addressText, pageWidth / 2, 17, { align: 'center' });

                doc.setFont('helvetica', 'bold');
                doc.setFontSize(9.5);
                doc.setTextColor(15, 23, 42);
                doc.text(subtitleText, pageWidth / 2, 22, { align: 'center' });

                // 2. Summary Particulars Box (Similar to Salary Bill particulars grid)
                const gridY = 26;
                const gridH = 15;
                doc.setFillColor(248, 250, 252);
                doc.setDrawColor(203, 213, 225);
                doc.rect(14, gridY, pageWidth - 28, gridH, 'FD');

                doc.setFontSize(7.5);

                // Left column in particulars box
                doc.setFont('helvetica', 'bold');
                doc.text('Statement : ', 18, gridY + 5);
                doc.setFont('helvetica', 'normal');
                doc.text('Salary Summary (Component Breakdown)', 35, gridY + 5);

                doc.setFont('helvetica', 'bold');
                doc.text('Period : ', 18, gridY + 10);
                doc.setFont('helvetica', 'normal');
                doc.text(monthYearStr || 'Current Month', 35, gridY + 10);

                // Middle column in particulars box
                doc.setFont('helvetica', 'bold');
                doc.text('Employees : ', pageWidth / 2 - 10, gridY + 5);
                doc.setFont('helvetica', 'normal');
                doc.text(`${records.length} Employee(s)`, pageWidth / 2 + 10, gridY + 5);

                doc.setFont('helvetica', 'bold');
                doc.text('Status : ', pageWidth / 2 - 10, gridY + 10);
                doc.setFont('helvetica', 'normal');
                doc.text(totals.hasProvisional ? 'Provisional / Estimated' : 'Finalized / Generated', pageWidth / 2 + 10, gridY + 10);

                // Right column in particulars box
                doc.setFont('helvetica', 'bold');
                doc.text('Date : ', pageWidth - 60, gridY + 5);
                doc.setFont('helvetica', 'normal');
                doc.text(dateText, pageWidth - 45, gridY + 5);

                doc.setFont('helvetica', 'bold');
                doc.text('Currency : ', pageWidth - 60, gridY + 10);
                doc.setFont('helvetica', 'normal');
                doc.text('BDT (Taka)', pageWidth - 45, gridY + 10);

                // 3. Allowances Table
                const allowRows = totals.allowanceRows.map((r) => [
                    r.sl,
                    r.particulars,
                    r.current.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
                    '0.00',
                    r.total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
                ]);

                doc.setFont('helvetica', 'bold');
                doc.setFontSize(8.5);
                doc.setTextColor(15, 23, 42);
                doc.text('Allowances (Earnings)', 14, gridY + gridH + 5);

                autoTable(doc, {
                    head: [['SL.No', 'Particulars', 'Current', 'Arrear', 'Total']],
                    body: allowRows,
                    foot: [[
                        '', 
                        'Gross Salary / Total Allowances', 
                        totals.grossAllowance.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }), 
                        '0.00', 
                        totals.grossAllowance.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                    ]],
                    startY: gridY + gridH + 7,
                    margin: { left: 14, right: 14 },
                    theme: 'grid',
                    styles: { fontSize: 7.5, cellPadding: 1.5, lineColor: [203, 213, 225], lineWidth: 0.2 },
                    headStyles: { fillColor: [241, 245, 249], textColor: [15, 23, 42], fontStyle: 'bold' },
                    footStyles: { fillColor: [248, 250, 252], textColor: [15, 23, 42], fontStyle: 'bold' },
                    columnStyles: {
                        0: { halign: 'center', cellWidth: 14 },
                        1: { halign: 'left' },
                        2: { halign: 'right', cellWidth: 30 },
                        3: { halign: 'right', cellWidth: 22 },
                        4: { halign: 'right', cellWidth: 30, fontStyle: 'bold' },
                    },
                });

                const allowFinalY = doc.lastAutoTable.finalY || 100;

                // 4. Deductions Table
                const deductRows = totals.deductionRows.length > 0 
                    ? totals.deductionRows.map((r) => [
                        r.sl,
                        r.particulars,
                        r.current.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
                        '0.00',
                        r.total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
                    ])
                    : [['1', 'No deductions recorded', '0.00', '0.00', '0.00']];

                doc.setFont('helvetica', 'bold');
                doc.setFontSize(8.5);
                doc.setTextColor(15, 23, 42);
                doc.text('Deductions', 14, allowFinalY + 5);

                autoTable(doc, {
                    head: [['Sl.No', 'Particulars', 'Current', 'Arrear', 'Total']],
                    body: deductRows,
                    foot: [[
                        '', 
                        'Total Deductions', 
                        totals.totalDeductions.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }), 
                        '0.00', 
                        totals.totalDeductions.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                    ]],
                    startY: allowFinalY + 7,
                    margin: { left: 14, right: 14 },
                    theme: 'grid',
                    styles: { fontSize: 7.5, cellPadding: 1.5, lineColor: [203, 213, 225], lineWidth: 0.2 },
                    headStyles: { fillColor: [241, 245, 249], textColor: [15, 23, 42], fontStyle: 'bold' },
                    footStyles: { fillColor: [248, 250, 252], textColor: [15, 23, 42], fontStyle: 'bold' },
                    columnStyles: {
                        0: { halign: 'center', cellWidth: 14 },
                        1: { halign: 'left' },
                        2: { halign: 'right', cellWidth: 30 },
                        3: { halign: 'right', cellWidth: 22 },
                        4: { halign: 'right', cellWidth: 30, fontStyle: 'bold' },
                    },
                });

                const deductFinalY = doc.lastAutoTable.finalY || 160;

                // 5. Net Payable Box
                const netBoxY = deductFinalY + 4;
                const netBoxW = 65;
                const netBoxH = 8;
                doc.setFillColor(241, 245, 249);
                doc.setDrawColor(148, 163, 184);
                doc.rect(pageWidth - 14 - netBoxW, netBoxY, netBoxW, netBoxH, 'FD');

                doc.setFont('helvetica', 'bold');
                doc.setFontSize(8.5);
                doc.setTextColor(30, 41, 59);
                doc.text('Net Payable : ', pageWidth - 14 - netBoxW + 4, netBoxY + 5.5);
                doc.text(`Tk. ${totals.netPayable.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`, pageWidth - 18, netBoxY + 5.5, { align: 'right' });

                // 6. In Words Box
                const wordBoxY = netBoxY + netBoxH + 3;
                doc.setFillColor(248, 250, 252);
                doc.setDrawColor(203, 213, 225);
                doc.rect(14, wordBoxY, pageWidth - 28, 7.5, 'FD');

                doc.setFont('helvetica', 'bold');
                doc.setFontSize(7.5);
                doc.setTextColor(30, 41, 59);
                doc.text('In word: ', 17, wordBoxY + 5);

                doc.setFont('helvetica', 'normal');
                doc.setTextColor(51, 65, 85);
                doc.text(totals.inWords, 30, wordBoxY + 5);

                // 7. University Signatures Block
                const sigY = pageHeight - 24;
                const colWidth = (pageWidth - 28) / 4;
                const sigs = ['Prepared By', 'Checked By', 'Comptroller of Accounts', 'Vice-Chancellor / Registrar'];
                sigs.forEach((title, i) => {
                    const x = 14 + i * colWidth;
                    doc.setDrawColor(148, 163, 184);
                    doc.line(x + 4, sigY, x + colWidth - 4, sigY);
                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(7.5);
                    doc.setTextColor(71, 85, 105);
                    doc.text(title, x + colWidth / 2, sigY + 4, { align: 'center' });
                });

                // 8. Footer
                const footerY = pageHeight - 8;
                doc.setFont('helvetica', 'normal');
                doc.setFontSize(7);
                doc.setTextColor(148, 163, 184);
                doc.text(`Generated by: System Administrator • ${dateText}`, 14, footerY);
                doc.setFont('helvetica', 'bold');
                doc.setFontSize(7.5);
                doc.setTextColor(100, 116, 139);
                doc.text('Page 1 of 1', pageWidth - 14, footerY, { align: 'right' });

                if (isPrint) {
                    doc.autoPrint();
                    const blobUrl = doc.output('bloburl');
                    window.open(blobUrl, '_blank');
                } else {
                    doc.save(`KAU_Salary_Summary_${(monthYearStr || 'Report').replace(/\s+/g, '_')}.pdf`);
                }
            } catch (err) {
                console.error('Failed to generate Salary Summary PDF:', err);
            } finally {
                this.generatingPdf = false;
            }
        },
    },
});
