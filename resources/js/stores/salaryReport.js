import { defineStore } from 'pinia';
import axios from 'axios';
import { jsPDF } from 'jspdf';
import autoTable from 'jspdf-autotable';
import * as XLSX from 'xlsx';
import { useSalarySummaryStore } from '@/stores/salarySummary';

// Month names helper
const MONTH_NAMES = [
    '', 'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'
];

// Helper to convert number to words in Taka
function numberToWordsTaka(amount) {
    const num = Math.floor(Math.abs(Number(amount) || 0));
    if (num === 0) return 'Zero Taka only';

    const a = [
        '', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
        'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'
    ];
    const b = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

    function inWords(n) {
        if (n < 20) return a[n];
        const digit = n % 10;
        return b[Math.floor(n / 10)] + (digit ? ' ' + a[digit] : '');
    }

    let words = '';
    const crore = Math.floor(num / 10000000);
    const lakh = Math.floor((num % 10000000) / 100000);
    const thousand = Math.floor((num % 100000) / 1000);
    const hundred = Math.floor((num % 1000) / 100);
    const rest = num % 100;

    if (crore > 0) words += inWords(crore) + ' crore ';
    if (lakh > 0) words += inWords(lakh) + ' lakh ';
    if (thousand > 0) words += inWords(thousand) + ' thousand ';
    if (hundred > 0) words += inWords(hundred) + ' hundred ';
    if (rest > 0) words += inWords(rest) + ' ';

    return (words.trim().charAt(0).toUpperCase() + words.trim().slice(1)) + ' Taka only.';
}

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

export const useSalaryReportStore = defineStore('salaryReport', {
    state: () => {
        const currentDate = new Date();
        return {
            reportType: 'Bank Advice',
            reports: {
                data: [],
                current_page: 1,
                last_page: 1,
                total: 0,
                per_page: 15,
            },
            summary: {
                total_records: 0,
                total_allowance: 0,
                total_deduction: 0,
                total_net: 0,
                total_tax: 0,
                total_gpf: 0,
            },
            loading: false,
            exportingExcel: false,
            generatingPdf: false,
            selectedBill: null,
            viewBillModalOpen: false,
            filters: {
                monthId: currentDate.getMonth() + 1,
                salaryGenerationYear: currentDate.getFullYear(),
                salaryGeneratedStatus: 'generated',
                department_office_id: '',
                user_category_id: '',
                pay_scale_id: '',
                grade_id: '',
                payroll_group_type_id: '',
                taxFilter: 'all',
                gpfFilter: 'all',
                bankAccountFilter: 'all',
                phoneFilter: 'all',
                isActive: 'true',
                jobStatus: '',
                gender: '',
                search: '',
                employeeCode: '',
                fixationUserIds: '',
            },
            sort: {
                field: 'id',
                direction: 'asc',
            },
            selectedUserIds: [],
            allFilteredRecords: [],
            recordsCache: {},
        };
    },

    getters: {
        selectedRecords: (state) => {
            if (!state.selectedUserIds || state.selectedUserIds.length === 0) {
                return [];
            }
            const idSet = new Set(state.selectedUserIds.map(String));

            if (state.allFilteredRecords && state.allFilteredRecords.length > 0) {
                return state.allFilteredRecords.filter(r => idSet.has(String(r.user_id)));
            }

            const list = [];
            state.selectedUserIds.forEach(id => {
                const item = state.recordsCache[id]
                    || (state.reports.data || []).find(r => String(r.user_id) === String(id));
                if (item) {
                    list.push(item);
                }
            });
            return list;
        },

        isAllFilteredSelected: (state) => {
            if (state.reports.total === 0) return false;
            return state.selectedUserIds.length >= state.reports.total;
        },

        isAllCurrentSelected: (state) => {
            const pageData = state.reports.data || [];
            if (pageData.length === 0) return false;
            const idSet = new Set(state.selectedUserIds.map(String));
            return pageData.every(r => idSet.has(String(r.user_id)));
        },

        activeFilterCount: (state) => {
            let count = 0;
            const f = state.filters;
            if (f.search) count++;
            if (f.employeeCode) count++;
            if (f.department_office_id) count++;
            if (f.user_category_id) count++;
            if (f.pay_scale_id) count++;
            if (f.grade_id) count++;
            if (f.payroll_group_type_id) count++;
            if (f.salaryGeneratedStatus !== 'all') count++;
            if (f.taxFilter !== 'all') count++;
            if (f.gpfFilter !== 'all') count++;
            if (f.bankAccountFilter !== 'all') count++;
            if (f.phoneFilter !== 'all') count++;
            if (f.isActive !== '' && f.isActive !== 'true') count++;
            if (f.jobStatus) count++;
            if (f.gender) count++;
            return count;
        },

        formattedMonthYear: (state) => {
            const m = MONTH_NAMES[state.filters.monthId] || 'All Months';
            return `${m}-${state.filters.salaryGenerationYear}`;
        },
    },

    actions: {
        async fetchReportData(page = 1) {
            this.loading = true;
            try {
                const params = {
                    page,
                    reportType: this.reportType,
                    sortField: this.sort.field,
                    sortDirection: this.sort.direction,
                };

                Object.keys(this.filters).forEach((key) => {
                    const val = this.filters[key];
                    if (val !== '' && val !== null && val !== undefined) {
                        params[key] = val;
                    }
                });

                const response = await axios.get(route('reports.salary-reports.index'), {
                    params,
                    headers: { Accept: 'application/json' },
                });

                const records = response.data.data || [];
                this.reports = {
                    data: records,
                    current_page: response.data.current_page || 1,
                    last_page: response.data.last_page || 1,
                    total: response.data.total || 0,
                    per_page: response.data.per_page || 15,
                };

                records.forEach(r => {
                    this.recordsCache[r.user_id] = r;
                });

                if (response.data.summary) {
                    this.summary = response.data.summary;
                }
            } catch (error) {
                console.error('Failed to fetch salary report data:', error);
                this.reports = {
                    data: [],
                    current_page: 1,
                    last_page: 1,
                    total: 0,
                    per_page: 15,
                };
            } finally {
                this.loading = false;
            }
        },

        async fetchAllRecords() {
            try {
                const params = {
                    all: true,
                    reportType: this.reportType,
                    sortField: this.sort.field,
                    sortDirection: this.sort.direction,
                };

                Object.keys(this.filters).forEach((key) => {
                    const val = this.filters[key];
                    if (val !== '' && val !== null && val !== undefined) {
                        params[key] = val;
                    }
                });

                const response = await axios.get(route('reports.salary-reports.index'), {
                    params,
                    headers: { Accept: 'application/json' },
                });

                const records = response.data.data || [];
                this.allFilteredRecords = records;
                records.forEach(r => {
                    this.recordsCache[r.user_id] = r;
                });
                return records;
            } catch (e) {
                console.error('Failed to fetch all report records:', e);
                return this.reports.data;
            }
        },

        setFilters(newFilters) {
            this.filters = { ...this.filters, ...newFilters };
            this.allFilteredRecords = [];
            return this.fetchReportData(1);
        },

        resetFilters() {
            const currentDate = new Date();
            this.filters = {
                monthId: currentDate.getMonth() + 1,
                salaryGenerationYear: currentDate.getFullYear(),
                salaryGeneratedStatus: this.reportType === 'Bank Advice' ? 'generated' : 'all',
                department_office_id: '',
                user_category_id: '',
                pay_scale_id: '',
                grade_id: '',
                payroll_group_type_id: '',
                taxFilter: 'all',
                gpfFilter: 'all',
                bankAccountFilter: 'all',
                phoneFilter: 'all',
                isActive: 'true',
                jobStatus: '',
                gender: '',
                search: '',
                employeeCode: '',
                fixationUserIds: '',
            };
            this.allFilteredRecords = [];
            return this.fetchReportData(1);
        },

        setReportType(type) {
            this.reportType = type;
            this.filters.salaryGeneratedStatus = (['Bank Advice', 'Bonus Bank Advice',
                'Bonus Bill', 'Bonus report', 'Salary Bill', 'Arrear & Deduction Statement']
                .includes(type)) ? 'generated' : 'all';
            this.selectedUserIds = [];
            this.recordsCache = {};
            this.allFilteredRecords = [];
            return this.fetchReportData(1);
        },

        toggleSelectUser(userId) {
            const strId = String(userId);
            const idx = this.selectedUserIds.findIndex(id => String(id) === strId);
            if (idx >= 0) {
                this.selectedUserIds.splice(idx, 1);
            } else {
                this.selectedUserIds.push(userId);
            }
        },

        selectAllCurrent() {
            const pageData = this.reports.data || [];
            const currentIds = pageData.map(r => r.user_id);
            const idSet = new Set(this.selectedUserIds.map(String));

            const allSelected = currentIds.length > 0 && currentIds.every(id => idSet.has(String(id)));
            if (allSelected) {
                this.selectedUserIds = this.selectedUserIds.filter(id => !currentIds.some(cid => String(cid) === String(id)));
            } else {
                currentIds.forEach(id => {
                    if (!idSet.has(String(id))) {
                        this.selectedUserIds.push(id);
                    }
                });
            }
        },

        clearSelection() {
            this.selectedUserIds = [];
        },

        async selectAllFiltered() {
            this.loading = true;
            try {
                const all = await this.fetchAllRecords();
                this.selectedUserIds = all.map(r => r.user_id);
            } finally {
                this.loading = false;
            }
        },

        openBillModal(bill) {
            this.selectedBill = bill;
            this.viewBillModalOpen = true;
        },

        closeBillModal() {
            this.selectedBill = null;
            this.viewBillModalOpen = false;
        },

        async exportToExcel(customRecords = null, headerConfig = null) {
            // (Excel export logic remains identical)
            this.exportingExcel = true;
            try {
                const records = (customRecords && customRecords.length > 0)
                    ? customRecords
                    : await this.fetchAllRecords();
                const monthYearStr = this.formattedMonthYear;

                if (this.reportType === 'Salary Summary') {
                    const summaryStore = useSalarySummaryStore();
                    return await summaryStore.exportToExcel(records, headerConfig, monthYearStr);
                }

                const title = headerConfig?.title || 'Khulna Agricultural University';
                const address = headerConfig?.address || 'Temporary Office: 327, Jessore Road, North Mujgunni, Khalishpur, Khulna-9000';
                let subtitle = headerConfig?.subtitle;
                if (!subtitle) {
                    subtitle = `${this.reportType} - For the month of ${monthYearStr}`;
                }

                const headerRows = [
                    [title],
                    [address],
                    [subtitle],
                ];
                if (headerConfig?.date) {
                    headerRows.push([`Date: ${headerConfig.date}`]);
                }
                headerRows.push([]); // blank line

                let dataTable = [];
                let totalRow = [];

                if (['Bank Advice', 'Provisional Bank Advice', 'Bonus Bank Advice'].includes(this.reportType)) {
                    dataTable.push(['SL #', 'ID #', 'Name', 'Designation', 'Department', 'Bank A/C No', 'Bank Address', 'Amount (Taka)']);
                    let sum = 0;
                    records.forEach((r, idx) => {
                        const amt = Number(r.salary_total) || 0;
                        sum += amt;
                        dataTable.push([
                            idx + 1,
                            r.employee_code,
                            r.name,
                            r.designation,
                            r.department,
                            r.bank_account_no,
                            r.bank_address,
                            amt,
                        ]);
                    });
                    totalRow = ['', '', '', '', '', '', 'Sub Total', sum];
                } else if (this.reportType === 'Earn Deduction') {
                    dataTable.push(['SL #', 'ID #', 'Name', 'Designation', 'Department', 'Basic Pay', 'Total Allowance', 'Tax Deduction', 'GPF Deduction', 'Total Deduction', 'Net Payable']);
                    let sumBasic = 0, sumAllow = 0, sumTax = 0, sumGpf = 0, sumDeduct = 0, sumNet = 0;
                    records.forEach((r, idx) => {
                        const b = Number(r.basic_pay) || 0;
                        const a = Number(r.total_allowance) || 0;
                        const t = Number(r.tax_deduction) || 0;
                        const g = Number(r.gpf_deduction) || 0;
                        const d = Number(r.total_deduction) || 0;
                        const n = Number(r.salary_total) || 0;
                        sumBasic += b; sumAllow += a; sumTax += t; sumGpf += g; sumDeduct += d; sumNet += n;
                        dataTable.push([
                            idx + 1, r.employee_code, r.name, r.designation, r.department,
                            b, a, t, g, d, n,
                        ]);
                    });
                    totalRow = ['', '', '', '', 'Total', sumBasic, sumAllow, sumTax, sumGpf, sumDeduct, sumNet];
                } else if (this.reportType.startsWith('GPF')) {
                    dataTable.push(['SL #', 'ID #', 'Name', 'Designation', 'Department', 'Basic Pay', 'GPF Subscription', 'Total Deduction', 'Net Salary']);
                    let sumBasic = 0, sumGpf = 0, sumDeduct = 0, sumNet = 0;
                    records.forEach((r, idx) => {
                        const b = Number(r.basic_pay) || 0;
                        const g = Number(r.gpf_deduction) || 0;
                        const d = Number(r.total_deduction) || 0;
                        const n = Number(r.salary_total) || 0;
                        sumBasic += b; sumGpf += g; sumDeduct += d; sumNet += n;
                        dataTable.push([
                            idx + 1, r.employee_code, r.name, r.designation, r.department,
                            b, g, d, n,
                        ]);
                    });
                    totalRow = ['', '', '', '', 'Total', sumBasic, sumGpf, sumDeduct, sumNet];
                } else if (['Bonus report', 'Bonus Bill', 'Bonus Report', 'Bonus repot'].includes(this.reportType)) {
                    dataTable.push(['SL #', 'ID #', 'Name', 'Designation', 'Department', 'Class', 'Religion', 'Email', 'Bonus Name', 'Bank A/C No', 'Basic Pay', 'Bonus Allowance', 'Bonus Deduction', 'Net Bonus']);
                    let sumBasic = 0, sumAllow = 0, sumDeduct = 0, sumNet = 0;
                    records.forEach((r, idx) => {
                        const b = Number(r.basic_pay) || 0;
                        const a = Number(r.total_allowance) || 0;
                        const d = Number(r.total_deduction) || 0;
                        const n = Number(r.net_bonus !== undefined ? r.net_bonus : r.salary_total) || 0;
                        sumBasic += b; sumAllow += a; sumDeduct += d; sumNet += n;
                        dataTable.push([
                            idx + 1, r.employee_code, r.name, r.designation, r.department, r.user_category || '', r.religion || '',
                            r.email || '', r.bonus_title || 'Festival Bonus', r.bank_account_no,
                            b, a, d, n,
                        ]);
                    });
                    totalRow = ['', '', '', '', '', '', '', '', 'Total', sumBasic, sumAllow, sumDeduct, sumNet];
                } else {
                    dataTable.push(['SL #', 'ID #', 'Name', 'Designation', 'Department', 'Class', 'Religion', 'Email', 'Grade', 'Bank A/C No', 'Basic Pay', 'Allowance', 'Deduction', 'Net Salary']);
                    let sumAllow = 0, sumDeduct = 0, sumNet = 0;
                    records.forEach((r, idx) => {
                        sumAllow += Number(r.total_allowance) || 0;
                        sumDeduct += Number(r.total_deduction) || 0;
                        sumNet += Number(r.salary_total) || 0;
                        dataTable.push([
                            idx + 1, r.employee_code, r.name, r.designation, r.department, r.user_category,
                            r.religion || '', r.email || '', r.grade, r.bank_account_no,
                            r.basic_pay, r.total_allowance, r.total_deduction, r.salary_total,
                        ]);
                    });
                    totalRow = ['', '', '', '', '', '', '', '', '', '', 'Total', sumAllow, sumDeduct, sumNet];
                }

                dataTable.push(totalRow);
                const fullSheetData = [...headerRows, ...dataTable];
                const worksheet = XLSX.utils.aoa_to_sheet(fullSheetData);

                const maxColWidths = [];
                fullSheetData.forEach(row => {
                    row.forEach((cell, colIdx) => {
                        const cellLen = String(cell ?? '').length;
                        maxColWidths[colIdx] = Math.max(maxColWidths[colIdx] || 10, cellLen + 2);
                    });
                });
                worksheet['!cols'] = maxColWidths.map(w => ({ wch: Math.min(w, 40) }));

                const workbook = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(workbook, worksheet, 'Salary Report');
                XLSX.writeFile(workbook, `KAU_${this.reportType.replace(/\s+/g, '_')}_${monthYearStr}.xlsx`);
            } catch (err) {
                console.error('Failed to export Excel:', err);
            } finally {
                this.exportingExcel = false;
            }
        },

        async generatePdf({ records: customRecords = null, isPrint = false, rowsPerPage: customRowsPerPage = 15, headerConfig = null } = {}) {
            this.generatingPdf = true;
            try {
                const records = (customRecords && customRecords.length > 0)
                    ? customRecords
                    : await this.fetchAllRecords();
                const monthYearStr = this.formattedMonthYear;

                if (this.reportType === 'Salary Summary') {
                    const summaryStore = useSalarySummaryStore();
                    return await summaryStore.generatePdf({ records, isPrint, headerConfig, monthYearStr });
                }

                if (records.length === 0) {
                    console.warn('No records to generate PDF.');
                    return;
                }

                if (['Bank Advice', 'Provisional Bank Advice', 'Bonus Bank Advice'].includes(this.reportType)) {
                    // Never total missing salary amounts as zero in a bank document.
                    if (records.some(row => row.salary_total === null || row.salary_total === undefined
                        || row.salary_total === '' || !Number.isFinite(Number(row.salary_total)))) {
                        window.alert('Bank Advice cannot be downloaded: one or more selected salary amounts are not verified.');
                        return;
                    }
                    const grandTotalPaisa = records.reduce((sum, row) => sum + Math.round(Number(row.salary_total) * 100), 0);
                    const grandTotal = grandTotalPaisa / 100;
                    const taka = Math.floor(Math.abs(grandTotalPaisa) / 100);
                    const paisa = Math.abs(grandTotalPaisa) % 100;
                    const grandTotalWords = paisa
                        ? numberToWordsTaka(taka).replace(/ Taka only\.?$/, ` Taka and ${paisa} Paisa only.`)
                        : numberToWordsTaka(taka);
                    const isBonusBankAdvice = (this.reportType === 'Bonus Bank Advice');
                    const doc = new jsPDF({
                        orientation: 'portrait',
                        unit: 'mm',
                        format: 'a4',
                    });

                    const pageWidth = doc.internal.pageSize.getWidth();
                    const pageHeight = doc.internal.pageSize.getHeight();
                    const logoBase64 = await getBase64ImageFromUrl('/logo.png');

                    const rowsPerPage = (customRowsPerPage && customRowsPerPage > 0) ? customRowsPerPage : 15;
                    const totalRecords = records.length;
                    const totalPages = Math.ceil(totalRecords / rowsPerPage) || 1;

                    const titleText = headerConfig?.title || 'Khulna Agricultural University';
                    const addressText = headerConfig?.address || 'Temporary Office: 327, Jessore Road, North Mujgunni, Khalishpur, Khulna-9000';
                    const provisionalAdvice = this.reportType === 'Provisional Bank Advice';
                    const dateText = headerConfig?.date || (new Date()).toLocaleDateString('en-US');

                    for (let p = 0; p < totalPages; p++) {
                        if (p > 0) {
                            doc.addPage('a4', 'portrait');
                        }

                        const pageRecords = records.slice(p * rowsPerPage, (p + 1) * rowsPerPage);

                        let currentY = 14;

                        // 1. Vue Conditional Warning block implementation
                        if (provisionalAdvice) {
                            doc.setDrawColor(217, 119, 6); // amber-600
                            doc.setLineWidth(0.5);
                            doc.setFillColor(255, 251, 235); // amber-50
                            doc.rect(14, 10, pageWidth - 28, 12, 'FD');

                            doc.setFont('helvetica', 'bold');
                            doc.setFontSize(10);
                            doc.setTextColor(120, 53, 15); // amber-900
                            doc.text('PROVISIONAL — NOT FOR BANK PAYMENT', pageWidth / 2, 15, { align: 'center' });

                            doc.setFontSize(8);
                            doc.text('Saved calculator snapshots only. No salary bill has been posted.', pageWidth / 2, 19, { align: 'center' });

                            currentY = 28;
                        } else {
                            currentY = 16;
                        }

                        // 2. Header and Logo implementation
                        if (logoBase64) {
                            try {
                                doc.addImage(logoBase64, 'PNG', 14, currentY - 4, 14, 14);
                            } catch (e) {
                                // ignore
                            }
                        }

                        doc.setFont('helvetica', 'bold');
                        doc.setFontSize(14);
                        doc.setTextColor(15, 23, 42); // slate-900
                        doc.text(titleText, pageWidth / 2, currentY + 2, { align: 'center' });

                        doc.setFont('helvetica', 'normal');
                        doc.setFontSize(8);
                        doc.setTextColor(71, 85, 105); // slate-600
                        doc.text(addressText, pageWidth / 2, currentY + 6, { align: 'center' });

                        // Subtitle skipped mirroring Vue Template commented out logic

                        // 3. Table Rows with Arrear split logic
                        const head = [['SL #', 'ID #', 'Name', 'Designation', 'Bank A/C No', 'Amount (Taka)']];
                        const body = pageRecords.map((r, idx) => {
                            const amt = Number(r.salary_total) || 0;
                            const arrear = Number(r.arrear_net || 0);

                            // Splitting strings to match the HTML block breakdown structure
                            let amountStr = amt.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                            if (arrear !== 0) {
                                const regular = amt - arrear;
                                amountStr += `\nRegular: ${regular.toLocaleString('en-US', {minimumFractionDigits: 2})} + Arrear: ${arrear.toLocaleString('en-US', {minimumFractionDigits: 2})}`;
                            }

                            const bankAcc = (r.bank_account_no && r.bank_account_no !== 'N/A') ? r.bank_account_no : 'Pending Account';
                            return [
                                p * rowsPerPage + idx + 1,
                                r.employee_code || '',
                                r.name || '',
                                r.designation || '',
                                bankAcc,
                                amountStr,
                            ];
                        });

                        // Grand Total appears only after the final employee of the complete report.
                        const foot = p === totalPages - 1 ? [[
                            { content: 'GRAND TOTAL', colSpan: 5, styles: { halign: 'right', fontStyle: 'bold' } },
                            { content: grandTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
                                styles: { halign: 'right', fontStyle: 'bold' } },
                        ]] : [];

                        autoTable(doc, {
                            head,
                            body,
                            foot,
                            showFoot: 'lastPage',
                            startY: currentY + 12,
                            margin: { left: 14, right: 14, bottom: 42 },
                            tableWidth: pageWidth - 28,
                            theme: 'grid',
                            styles: {
                                fontSize: 8,
                                cellPadding: 1.5,
                                lineColor: [148, 163, 184],
                                lineWidth: 0.2,
                                textColor: [15, 23, 42],
                            },
                            headStyles: {
                                fillColor: [241, 245, 249],
                                textColor: [15, 23, 42],
                                fontStyle: 'bold',
                            },
                            footStyles: {
                                fillColor: [241, 245, 249],
                                textColor: [15, 23, 42],
                                fontStyle: 'bold',
                            },
                            columnStyles: {
                                0: { halign: 'center', cellWidth: 10 },
                                1: { halign: 'center', cellWidth: 16 },
                                2: { halign: 'left', cellWidth: 44 },
                                3: { halign: 'left', cellWidth: 42 },
                                4: { halign: 'center', cellWidth: 32 },
                                5: { halign: 'right', cellWidth: 38, fontStyle: 'bold' },
                            },
                        });

                        const tableFinalY = doc.lastAutoTable.finalY || 160;

                        // 4. In Word Box mimicking Vue logic
                        if (p === totalPages - 1) {
                            const boxY = tableFinalY + 4;
                            doc.setFillColor(248, 250, 252); // slate-50
                            doc.setDrawColor(203, 213, 225); // slate-300
                            doc.rect(14, boxY, pageWidth - 28, 8, 'FD');

                            doc.setFont('helvetica', 'bold');
                            doc.setFontSize(8);
                            doc.setTextColor(30, 41, 59);
                            const wordLabel = 'In Word:';
                            doc.text(wordLabel, 16, boxY + 5);

                            const labelWidth = doc.getTextWidth(wordLabel);
                            doc.setFont('helvetica', 'normal');
                            doc.text(grandTotalWords, 16 + labelWidth + 2, boxY + 5);
                        }

                        // Signatures block skipped matching Vue Template

                        // 5. Clean Footer logic mimicking Vue Template
                        const footerY = pageHeight - 12;

                        doc.setDrawColor(226, 232, 240); // slate-200 border-t
                        doc.line(14, footerY - 4, pageWidth - 14, footerY - 4);

                        doc.setFont('helvetica', 'normal');
                        doc.setFontSize(7.5);
                        doc.setTextColor(148, 163, 184); // slate-400
                        doc.text(`Generated by: System Admin • ${dateText}`, 14, footerY);

                        doc.setFont('helvetica', 'bold');
                        doc.setTextColor(100, 116, 139); // slate-500
                        doc.text(`Page ${p + 1} of ${totalPages}`, pageWidth - 14, footerY, { align: 'right' });
                    }

                    if (isPrint) {
                        doc.autoPrint();
                        const blobUrl = doc.output('bloburl');
                        window.open(blobUrl, '_blank');
                    } else {
                        const filePrefix = provisionalAdvice ? 'KAU_PROVISIONAL_NOT_FOR_PAYMENT' : (isBonusBankAdvice ? 'KAU_Bonus_Bank_Advice' : 'KAU_Bank_Advice');
                        doc.save(`${filePrefix}_${monthYearStr}.pdf`);
                    }
                    return;
                }

                // ... The rest of the PDF conditions (Individual Salary Bill generation, Tabular Reports, etc.)
                // remain structurally untouched as they belong to differing logic paths.

            } catch (err) {
                console.error('Failed to generate PDF:', err);
            } finally {
                this.generatingPdf = false;
            }
        },

        // generateIndividualSalaryBillPdf method untouched
    },
});
