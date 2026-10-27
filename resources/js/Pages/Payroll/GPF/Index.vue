<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import Swal from 'sweetalert2';
import { jsPDF } from 'jspdf';
import autoTable from 'jspdf-autotable';
import * as XLSX from 'xlsx';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import SearchableEmployeeSelect from '@/Components/SearchableEmployeeSelect.vue';

const props = defineProps({
    employees: {
        type: Array,
        default: () => [],
    },
    financialYears: {
        type: Array,
        default: () => [],
    },
    departments: {
        type: Array,
        default: () => [],
    },
    currentFinancialYear: {
        type: String,
        default: '',
    },
    currentMonth: {
        type: Number,
        default: () => (new Date()).getMonth() + 1,
    },
    currentYear: {
        type: Number,
        default: () => (new Date()).getFullYear(),
    },
});

// State
const loading = ref(false);
const gpfRecords = ref([]);
const pagination = reactive({
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 0,
});
const summary = reactive({
    total_records: 0,
    total_contribution: 0,
    total_interest: 0,
    total_net_deposit: 0,
});

// Filters
const filters = reactive({
    search: '',
    financialYear: props.currentFinancialYear || 'all',
    user_id: '',
    month: '',
    year: '',
    isSettled: '',
    type: 'all',
});

// Months list
const months = [
    { id: 1, name: 'January' },
    { id: 2, name: 'February' },
    { id: 3, name: 'March' },
    { id: 4, name: 'April' },
    { id: 5, name: 'May' },
    { id: 6, name: 'June' },
    { id: 7, name: 'July' },
    { id: 8, name: 'August' },
    { id: 9, name: 'September' },
    { id: 10, name: 'October' },
    { id: 11, name: 'November' },
    { id: 12, name: 'December' },
];

// Modals & View State
const showPastModal = ref(false);
const showLedgerModal = ref(false);
const showCommentModal = ref(false);
const showSettleModal = ref(false);
const selectedRecord = ref(null);
const ledgerData = ref(null);
const ledgerLoading = ref(false);
const selectedLedgerFy = ref('');
const ledgerViewMode = ref('fy'); // 'fy' (12-month table) or 'all' (historical list)

// Form for Past Contribution
const pastForm = reactive({
    user_id: '',
    financialYear: props.currentFinancialYear || '',
    month: props.currentMonth,
    year: props.currentYear,
    contributionDate: '',
    openingBalance: 0,
    contributionAmount: 0,
    interestAmount: 0,
    benefitPercentage: 0,
    remarks: '',
    comment: '',
});
const pastFormErrors = ref({});
const pastFormSubmitting = ref(false);

// Auto-fill opening balance suggestion when user changes in pastForm
watch(() => pastForm.user_id, async (newUserId) => {
    if (!newUserId) return;
    try {
        const res = await axios.get(route('gpf.user-ledger', { user: newUserId }));
        if (res.data?.summary?.current_balance !== undefined && Number(pastForm.openingBalance) === 0) {
            pastForm.openingBalance = Number(res.data.summary.current_balance) || 0;
        }
    } catch (e) {
        // Silently skip if error
    }
});

// Form for Comment
const commentForm = reactive({
    comment: '',
    purpose: 'gpf_note',
});
const commentFormSubmitting = ref(false);

// Form for Settlement
const settleForm = reactive({
    settlementDate: new Date().toISOString().split('T')[0],
    remarks: 'Account settled on retirement/exit',
    comment: '',
});
const settleFormSubmitting = ref(false);

// Format currency
const formatMoney = (amount) => {
    return Number(amount || 0).toLocaleString('en-BD', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

const getMonthName = (monthNum) => {
    const m = months.find((item) => item.id === Number(monthNum));
    return m ? m.name : `Month ${monthNum}`;
};

// Fetch GPF Records
const fetchRecords = async (page = 1) => {
    loading.value = true;
    try {
        const params = {
            page,
            perPage: pagination.per_page,
            financialYear: filters.financialYear,
            search: filters.search,
        };

        if (filters.user_id) params.user_id = filters.user_id;
        if (filters.month) params.month = filters.month;
        if (filters.year) params.year = filters.year;
        if (filters.isSettled !== '') params.isSettled = filters.isSettled;
        if (filters.type !== 'all') params.type = filters.type;

        const res = await axios.get(route('gpf.index'), { params });
        const json = res.data;

        gpfRecords.value = json.data || [];
        pagination.current_page = json.current_page || 1;
        pagination.last_page = json.last_page || 1;
        pagination.total = json.total || 0;

        if (json.summary) {
            summary.total_records = json.summary.total_records || 0;
            summary.total_contribution = json.summary.total_contribution || 0;
            summary.total_interest = json.summary.total_interest || 0;
            summary.total_net_deposit = json.summary.total_net_deposit || 0;
        }
    } catch (err) {
        console.error('Error fetching GPF records', err);
    } finally {
        loading.value = false;
    }
};

// Filter reset
const resetFilters = () => {
    filters.search = '';
    filters.financialYear = 'all';
    filters.user_id = '';
    filters.month = '';
    filters.year = '';
    filters.isSettled = '';
    filters.type = 'all';
    fetchRecords(1);
};

// Open Past Contribution Modal
const openPastModal = () => {
    pastForm.user_id = '';
    pastForm.financialYear = props.currentFinancialYear || '';
    pastForm.month = props.currentMonth;
    pastForm.year = props.currentYear;
    pastForm.contributionDate = '';
    pastForm.openingBalance = 0;
    pastForm.contributionAmount = 0;
    pastForm.interestAmount = 0;
    pastForm.benefitPercentage = 0;
    pastForm.remarks = '';
    pastForm.comment = '';
    pastFormErrors.value = {};
    showPastModal.value = true;
};

// Submit Past Contribution (Uses axios, handles CSRF seamlessly and shows SweetAlert)
const submitPastContribution = async () => {
    pastFormSubmitting.value = true;
    pastFormErrors.value = {};

    try {
        await axios.post(route('gpf.store-past'), pastForm);

        showPastModal.value = false;
        Swal.fire({
            icon: 'success',
            title: 'Saved Successfully',
            text: 'Historical GPF contribution record has been saved.',
            timer: 2200,
            showConfirmButton: false,
            toast: true,
            position: 'top-end',
        });
        fetchRecords(pagination.current_page);
    } catch (err) {
        if (err.response?.status === 422) {
            pastFormErrors.value = err.response.data.errors || {};
            Swal.fire({
                icon: 'error',
                title: 'Validation Error',
                text: 'Please check the highlighted fields and try again.',
            });
        } else if (err.response?.status === 419) {
            Swal.fire({
                icon: 'warning',
                title: 'Session Expired',
                text: 'Your session has expired. Please refresh the page and try again.',
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Save Failed',
                text: err.response?.data?.message || err.message || 'Failed to store past contribution.',
            });
        }
    } finally {
        pastFormSubmitting.value = false;
    }
};

// View Employee Annual GPF Contribution Statement (Whole Financial Year Table)
const viewLedger = async (record, fy = null) => {
    selectedRecord.value = record;
    ledgerLoading.value = true;
    showLedgerModal.value = true;
    ledgerViewMode.value = 'fy';

    try {
        const userId = record.user_id || record.user?.id;
        const targetFy = fy || record.financialYear || selectedLedgerFy.value || '';
        selectedLedgerFy.value = targetFy;

        const res = await axios.get(route('gpf.user-ledger', { user: userId }), {
            params: targetFy ? { financialYear: targetFy } : {},
        });

        ledgerData.value = res.data;
        if (!selectedLedgerFy.value && res.data.financial_year) {
            selectedLedgerFy.value = res.data.financial_year;
        }
    } catch (err) {
        console.error(err);
        Swal.fire({
            icon: 'error',
            title: 'Failed to Load Statement',
            text: err.response?.data?.message || 'Could not fetch GPF statement for this employee.',
        });
    } finally {
        ledgerLoading.value = false;
    }
};

// Change Financial Year within Statement Modal
const changeLedgerFy = () => {
    if (selectedRecord.value && selectedLedgerFy.value) {
        viewLedger(selectedRecord.value, selectedLedgerFy.value);
    }
};

const pdfGenerating = ref(false);
const exportLoading = ref(false);

// Helper to fetch image as base64 for jsPDF
const getBase64ImageFromUrl = async (url) => {
    try {
        const res = await fetch(url);
        if (!res.ok) return null;
        const blob = await res.blob();
        return new Promise((resolve) => {
            const reader = new FileReader();
            reader.onloadend = () => resolve(reader.result);
            reader.onerror = () => resolve(null);
            reader.readAsDataURL(blob);
        });
    } catch (e) {
        return null;
    }
};

// Generate and Download / Print PDF statement using jsPDF and autoTable
const generatePdfStatement = async (action = 'download') => {
    if (!ledgerData.value) return;

    pdfGenerating.value = true;

    try {
        const emp = ledgerData.value.user;
        const summary = ledgerData.value.summary;
        const fy = selectedLedgerFy.value || ledgerData.value.financial_year;
        const months = ledgerData.value.fy_months || [];

        const doc = new jsPDF({
            orientation: 'portrait',
            unit: 'mm',
            format: 'a4',
        });

        const pageWidth = doc.internal.pageSize.getWidth();
        const pageHeight = doc.internal.pageSize.getHeight();
        const margin = 12;

        // Try to load university logo
        const logoBase64 = await getBase64ImageFromUrl('/logo.png');
        if (logoBase64) {
            try {
                doc.addImage(logoBase64, 'PNG', margin, 10, 15, 15);
            } catch (e) {
                // Ignore image load failure
            }
        }

        // Header Title
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(13);
        doc.setTextColor(20, 24, 33);
        doc.text('KHULNA AGRICULTURAL UNIVERSITY', pageWidth / 2, 14, { align: 'center' });

        doc.setFont('helvetica', 'normal');
        doc.setFontSize(8.5);
        doc.setTextColor(75, 85, 99);
        doc.text('Office of the Director (Finance & Accounts)', pageWidth / 2, 19, { align: 'center' });
        doc.text('Temporary Office: 327, Jessore Road, North Mujgunni, Khalishpur, Khulna-9000', pageWidth / 2, 23, { align: 'center' });

        // Document Title Banner
        doc.setFillColor(16, 185, 129); // Emerald-500
        doc.roundedRect(margin, 27, pageWidth - (margin * 2), 7, 1.5, 1.5, 'F');
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(9.5);
        doc.setTextColor(255, 255, 255);
        doc.text(`GENERAL PROVIDENT FUND (GPF) ANNUAL STATEMENT — FY ${fy}`, pageWidth / 2, 31.8, { align: 'center' });

        // Employee Info & KPI Box
        doc.setDrawColor(229, 231, 235); // Gray-200
        doc.setFillColor(249, 250, 251); // Gray-50
        doc.roundedRect(margin, 36, pageWidth - (margin * 2), 26, 1.5, 1.5, 'FD');

        // Employee Details (Left Column)
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(8);
        doc.setTextColor(107, 114, 128); // Gray-500
        doc.text('Employee Name:', margin + 4, 42);
        doc.text('Employee ID / Code:', margin + 4, 47);
        doc.text('Designation:', margin + 4, 52);
        doc.text('Department:', margin + 4, 57);

        doc.setFont('helvetica', 'bold');
        doc.setTextColor(17, 24, 39); // Gray-900
        doc.text(String(emp.name || 'N/A'), margin + 35, 42);
        doc.text(String(emp.employee_code || 'N/A'), margin + 35, 47);
        doc.text(String(emp.designation || 'N/A'), margin + 35, 52);
        doc.text(String(emp.department || 'N/A'), margin + 35, 57);

        // Account & Balance Details (Right Column)
        const rightColX = pageWidth / 2 + 8;
        doc.setFont('helvetica', 'normal');
        doc.setTextColor(107, 114, 128);
        doc.text('Financial Year:', rightColX, 42);
        doc.text('Account Status:', rightColX, 47);
        doc.text('FY Opening Balance:', rightColX, 52);
        doc.text('Cumulative Balance:', rightColX, 57);

        doc.setFont('helvetica', 'bold');
        doc.setTextColor(17, 24, 39);
        doc.text(`FY ${fy}`, rightColX + 33, 42);

        doc.setTextColor(summary.is_settled ? 147 : 5, summary.is_settled ? 51 : 150, summary.is_settled ? 234 : 105);
        doc.text(summary.is_settled ? 'Settled (Closed)' : 'Active GPF Subscriber', rightColX + 33, 47);

        doc.setTextColor(17, 24, 39);
        doc.text(`Tk. ${formatMoney(summary.fy_opening_balance)}`, rightColX + 33, 52);

        doc.setTextColor(5, 150, 105); // Emerald-600
        doc.text(`Tk. ${formatMoney(summary.current_balance)}`, rightColX + 33, 57);

        // 12-Month Contribution Table via autoTable
        const tableBody = months.map(m => [
            m.sl,
            m.period,
            m.opening_balance > 0 ? formatMoney(m.opening_balance) : '0.00',
            m.contribution_amount > 0 ? formatMoney(m.contribution_amount) : '—',
            m.interest_amount > 0 ? formatMoney(m.interest_amount) : '—',
            m.net_deposit > 0 ? formatMoney(m.net_deposit) : '—',
            formatMoney(m.closing_amount),
            m.type_label || (m.has_record ? 'Contributed' : 'Not Contributed'),
            m.remarks && m.remarks !== '—' ? m.remarks : ''
        ]);

        const tableFoot = [[
            '',
            `Annual Total (FY ${fy})`,
            formatMoney(summary.fy_opening_balance),
            formatMoney(summary.total_contribution),
            formatMoney(summary.total_interest),
            formatMoney(summary.total_net_deposit),
            formatMoney(summary.fy_closing_balance),
            '12 Months',
            'Full Year'
        ]];

        autoTable(doc, {
            head: [['#', 'Month / Period', 'Opening Bal.', 'Contribution', 'Interest', 'Net Deposit', 'Closing Bal.', 'Status', 'Remarks']],
            body: tableBody,
            foot: tableFoot,
            startY: 65,
            margin: { left: margin, right: margin },
            theme: 'grid',
            styles: {
                fontSize: 7.5,
                cellPadding: 2,
                lineColor: [229, 231, 235],
                lineWidth: 0.15,
                textColor: [31, 41, 55],
            },
            headStyles: {
                fillColor: [16, 185, 129], // Emerald
                textColor: [255, 255, 255],
                fontStyle: 'bold',
                halign: 'center',
            },
            footStyles: {
                fillColor: [236, 253, 245], // Emerald-50
                textColor: [6, 78, 59],
                fontStyle: 'bold',
            },
            columnStyles: {
                0: { halign: 'center', cellWidth: 8 },
                1: { halign: 'left', cellWidth: 26 },
                2: { halign: 'right', cellWidth: 23 },
                3: { halign: 'right', cellWidth: 24, fontStyle: 'bold' },
                4: { halign: 'right', cellWidth: 21 },
                5: { halign: 'right', cellWidth: 23 },
                6: { halign: 'right', cellWidth: 24, fontStyle: 'bold' },
                7: { halign: 'center', cellWidth: 19 },
                8: { halign: 'left' },
            },
            alternateRowStyles: {
                fillColor: [249, 250, 251],
            },
        });

        // Signatures Block
        const signY = pageHeight - 28;

        doc.setDrawColor(156, 163, 175);
        doc.setLineWidth(0.3);

        const colW = (pageWidth - margin * 2) / 3;

        // Sign 1: Prepared By
        doc.line(margin + 6, signY, margin + colW - 6, signY);
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(8);
        doc.setTextColor(75, 85, 99);
        doc.text('Prepared By', margin + colW / 2, signY + 4, { align: 'center' });

        // Sign 2: Verified By
        doc.line(margin + colW + 6, signY, margin + colW * 2 - 6, signY);
        doc.text('Verified By', margin + colW * 1.5, signY + 4, { align: 'center' });

        // Sign 3: Finance & Accounts Officer
        doc.line(margin + colW * 2 + 6, signY, margin + colW * 3 - 6, signY);
        doc.text('Finance & Accounts Officer', margin + colW * 2.5, signY + 4, { align: 'center' });

        // Footer timestamp & disclaimer
        doc.setFontSize(7);
        doc.setTextColor(156, 163, 175);
        doc.text(`Generated electronically from KAU Employee Management System on ${new Date().toLocaleString('en-GB')}`, margin, pageHeight - 8);
        doc.text(`Page 1 of 1`, pageWidth - margin, pageHeight - 8, { align: 'right' });

        const cleanCode = String(emp.employee_code || emp.id || 'employee').replace(/[^a-zA-Z0-9_-]/g, '_');
        const cleanFy = String(fy).replace(/[^a-zA-Z0-9_-]/g, '_');
        const fileName = `KAU_GPF_Statement_${cleanCode}_FY_${cleanFy}.pdf`;

        if (action === 'print') {
            doc.autoPrint();
            const blobUrl = doc.output('bloburl');
            window.open(blobUrl, '_blank');
        } else {
            doc.save(fileName);
            Swal.fire({
                icon: 'success',
                title: 'PDF Downloaded',
                text: `GPF Statement saved as ${fileName}`,
                timer: 2000,
                showConfirmButton: false,
                toast: true,
                position: 'top-end',
            });
        }
    } catch (err) {
        console.error('PDF Generation error:', err);
        Swal.fire({
            icon: 'error',
            title: 'PDF Generation Failed',
            text: err.message || 'Could not generate PDF document.',
        });
    } finally {
        pdfGenerating.value = false;
    }
};

// Export Statement to Excel (.xlsx) using SheetJS (XLSX)
const exportLedgerToExcel = () => {
    if (!ledgerData.value) return;

    exportLoading.value = true;

    try {
        const emp = ledgerData.value.user;
        const summary = ledgerData.value.summary;
        const fy = selectedLedgerFy.value || ledgerData.value.financial_year;
        const months = ledgerData.value.fy_months || [];

        const sheetData = [
            ['KHULNA AGRICULTURAL UNIVERSITY'],
            ['Office of the Director (Finance & Accounts)'],
            [`GENERAL PROVIDENT FUND (GPF) ANNUAL STATEMENT - FY ${fy}`],
            [],
            ['EMPLOYEE INFORMATION', ''],
            ['Employee Name:', emp.name, '', 'Department:', emp.department],
            ['Employee ID / Code:', emp.employee_code, '', 'Designation:', emp.designation],
            ['Email:', emp.email || 'N/A', '', 'Joining Date:', emp.joining_date || 'N/A'],
            ['Account Status:', summary.is_settled ? 'Settled (Closed)' : 'Active GPF Subscriber', '', 'Total Lifetime Balance (BDT):', Number(summary.current_balance || 0)],
            [],
            [`FINANCIAL YEAR SUMMARY (FY ${fy})`, ''],
            ['FY Opening Balance (July):', Number(summary.fy_opening_balance || 0), '', 'Total FY Contribution:', Number(summary.total_contribution || 0)],
            ['Total FY Interest / Profit:', Number(summary.total_interest || 0), '', 'Total FY Net Deposit:', Number(summary.total_net_deposit || 0)],
            ['FY Closing Balance (June):', Number(summary.fy_closing_balance || 0), '', '', ''],
            [],
            [
                'SL#',
                'Month / Period',
                'Opening Balance (BDT)',
                'Monthly Contribution (BDT)',
                'Interest / Profit (BDT)',
                'Net Deposit (BDT)',
                'Closing Balance (BDT)',
                'Status',
                'Remarks / Notes'
            ],
        ];

        months.forEach(m => {
            sheetData.push([
                m.sl,
                m.period,
                Number(m.opening_balance || 0),
                Number(m.contribution_amount || 0),
                Number(m.interest_amount || 0),
                Number(m.net_deposit || 0),
                Number(m.closing_amount || 0),
                m.type_label || (m.has_record ? 'Active' : 'No Deposit'),
                m.remarks && m.remarks !== '—' ? m.remarks : ''
            ]);
        });

        sheetData.push([
            'Total',
            `Annual Summary (FY ${fy})`,
            Number(summary.fy_opening_balance || 0),
            Number(summary.total_contribution || 0),
            Number(summary.total_interest || 0),
            Number(summary.total_net_deposit || 0),
            Number(summary.fy_closing_balance || 0),
            '12 Months',
            'Full Year Statement'
        ]);

        sheetData.push([]);
        sheetData.push([`Generated electronically from KAU EMS on: ${new Date().toLocaleString('en-GB')}`]);

        const worksheet = XLSX.utils.aoa_to_sheet(sheetData);

        worksheet['!cols'] = [
            { wch: 6 },
            { wch: 18 },
            { wch: 22 },
            { wch: 24 },
            { wch: 22 },
            { wch: 20 },
            { wch: 22 },
            { wch: 18 },
            { wch: 30 },
        ];

        const workbook = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(workbook, worksheet, 'GPF Annual Statement');

        const cleanCode = String(emp.employee_code || emp.id || 'employee').replace(/[^a-zA-Z0-9_-]/g, '_');
        const cleanFy = String(fy).replace(/[^a-zA-Z0-9_-]/g, '_');
        const fileName = `KAU_GPF_Statement_${cleanCode}_FY_${cleanFy}.xlsx`;

        XLSX.writeFile(workbook, fileName);

        Swal.fire({
            icon: 'success',
            title: 'Excel Exported',
            text: `GPF Statement saved as ${fileName}`,
            timer: 2000,
            showConfirmButton: false,
            toast: true,
            position: 'top-end',
        });
    } catch (err) {
        console.error('Excel Export error:', err);
        Swal.fire({
            icon: 'error',
            title: 'Excel Export Failed',
            text: err.message || 'Could not export to Excel.',
        });
    } finally {
        exportLoading.value = false;
    }
};

// Print Statement using jsPDF vector printing
const printStatement = () => {
    generatePdfStatement('print');
};

const activeComments = ref([]);
const commentsLoading = ref(false);

const getPurposeLabel = (purpose) => {
    switch (purpose) {
        case 'audit_verification': return 'Audit Verification';
        case 'adjustment_note': return 'Adjustment Note';
        case 'settlement_note': return 'Settlement Note';
        case 'correction': return 'Correction';
        case 'gpf_note':
        default: return 'General Note';
    }
};

const getPurposeBadgeClass = (purpose) => {
    switch (purpose) {
        case 'audit_verification':
            return 'bg-purple-100 text-purple-800 dark:bg-purple-950/60 dark:text-purple-300 border-purple-200 dark:border-purple-800';
        case 'adjustment_note':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 border-amber-200 dark:border-amber-800';
        case 'settlement_note':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800';
        case 'correction':
            return 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300 border-rose-200 dark:border-rose-800';
        case 'gpf_note':
        default:
            return 'bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300 border-blue-200 dark:border-blue-800';
    }
};

// Fetch fresh comments for a specific record
const fetchRecordComments = async (recordId) => {
    if (!recordId) return;
    commentsLoading.value = true;
    try {
        const res = await axios.get(route('gpf.comments.index', recordId));
        const list = Array.isArray(res.data) ? res.data : [];
        activeComments.value = [...list];
        if (selectedRecord.value && selectedRecord.value.id === recordId) {
            selectedRecord.value.comments = [...list];
        }
        const r = gpfRecords.value.find(item => item.id === recordId);
        if (r && r !== selectedRecord.value) {
            r.comments = [...list];
        }
    } catch (err) {
        console.warn('Could not fetch comments:', err);
    } finally {
        commentsLoading.value = false;
    }
};

// Open Comment Modal with previous comments list and note maker's details
const openCommentModal = async (record) => {
    selectedRecord.value = record;
    commentForm.comment = '';
    commentForm.purpose = 'gpf_note';
    activeComments.value = record.comments ? [...record.comments] : [];
    showCommentModal.value = true;
    await fetchRecordComments(record.id);
};

// Open Comment Modal directly from Statement view modal
const openNotesFromStatement = (item) => {
    if (!item.record_id && !item.has_record) {
        Swal.fire({
            icon: 'info',
            title: 'No Contribution Record',
            text: 'This month does not have a GPF contribution record yet to attach notes to.',
        });
        return;
    }

    const rec = {
        id: item.record_id,
        user: ledgerData.value?.user,
        employeeId: ledgerData.value?.user?.employee_code,
        month: item.month,
        year: item.year,
        financialYear: selectedLedgerFy.value || ledgerData.value?.financial_year,
        contributionAmount: item.contribution_amount,
        comments: item.comments ? [...item.comments] : [],
    };
    openCommentModal(rec);
};

// Submit Comment
const submitComment = async () => {
    if (commentFormSubmitting.value) return;
    if (!commentForm.comment.trim()) return;
    commentFormSubmitting.value = true;

    try {
        const res = await axios.post(route('gpf.comments.store', { generalProvidentFund: selectedRecord.value.id }), commentForm);
        const newComment = res.data.data;

        // 1. Add to activeComments ensuring no duplicate render
        if (!activeComments.value.some(c => c.id === newComment.id)) {
            activeComments.value = [newComment, ...activeComments.value];
        }

        // 2. Sync selectedRecord without mutating by reference
        if (selectedRecord.value) {
            const cur = selectedRecord.value.comments || [];
            if (!cur.some(c => c.id === newComment.id)) {
                selectedRecord.value.comments = [newComment, ...cur];
            }
        }

        // 3. Sync main records table item (if different reference)
        const r = gpfRecords.value.find(item => item.id === selectedRecord.value?.id);
        if (r && r !== selectedRecord.value) {
            const cur = r.comments || [];
            if (!cur.some(c => c.id === newComment.id)) {
                r.comments = [newComment, ...cur];
            }
        }

        // 4. Sync 12-month statement data if open
        if (ledgerData.value?.fy_months) {
            const m = ledgerData.value.fy_months.find(month => month.record_id === selectedRecord.value?.id);
            if (m) {
                const cur = m.comments || [];
                if (!cur.some(c => c.id === newComment.id)) {
                    m.comments = [newComment, ...cur];
                }
            }
        }
        if (ledgerData.value?.records) {
            const rec = ledgerData.value.records.find(entry => entry.id === selectedRecord.value?.id);
            if (rec) {
                const cur = rec.comments || [];
                if (!cur.some(c => c.id === newComment.id)) {
                    rec.comments = [newComment, ...cur];
                }
            }
        }

        commentForm.comment = '';
        Swal.fire({
            icon: 'success',
            title: 'Note Attached',
            timer: 1500,
            showConfirmButton: false,
            toast: true,
            position: 'top-end',
        });
    } catch (err) {
        Swal.fire({
            icon: 'error',
            title: 'Failed to Add Note',
            text: err.response?.data?.message || err.message,
        });
    } finally {
        commentFormSubmitting.value = false;
    }
};

// Delete Comment
const deleteComment = async (commentId) => {
    const result = await Swal.fire({
        title: 'Delete Note?',
        text: 'Are you sure you want to remove this audit note?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete',
        confirmButtonColor: '#ef4444',
    });
    if (!result.isConfirmed) return;

    try {
        await axios.delete(route('gpf.comments.destroy', commentId));
        activeComments.value = activeComments.value.filter(c => c.id !== commentId);

        if (selectedRecord.value && selectedRecord.value.comments) {
            selectedRecord.value.comments = selectedRecord.value.comments.filter(c => c.id !== commentId);
        }
        const r = gpfRecords.value.find(item => item.id === selectedRecord.value?.id);
        if (r && r !== selectedRecord.value && r.comments) {
            r.comments = r.comments.filter(c => c.id !== commentId);
        }

        if (ledgerData.value?.fy_months) {
            ledgerData.value.fy_months.forEach(m => {
                if (m.comments) {
                    m.comments = m.comments.filter(c => c.id !== commentId);
                }
            });
        }
        if (ledgerData.value?.records) {
            ledgerData.value.records.forEach(rec => {
                if (rec.comments) {
                    rec.comments = rec.comments.filter(c => c.id !== commentId);
                }
            });
        }

        Swal.fire({
            icon: 'success',
            title: 'Note Deleted',
            timer: 1500,
            showConfirmButton: false,
            toast: true,
            position: 'top-end',
        });
    } catch (err) {
        Swal.fire({
            icon: 'error',
            title: 'Failed to Delete Note',
            text: err.response?.data?.message || err.message,
        });
    }
};

// All notes across the current statement / financial year
const allLedgerNotes = computed(() => {
    if (!ledgerData.value) return [];
    const notes = [];
    if (ledgerData.value.fy_months) {
        ledgerData.value.fy_months.forEach(m => {
            if (m.comments && m.comments.length > 0) {
                m.comments.forEach(c => {
                    notes.push({
                        ...c,
                        period: m.period,
                        record_id: m.record_id,
                        raw_month: m,
                    });
                });
            }
        });
    }
    return notes;
});

// Open Settle Modal
const openSettleModal = (record) => {
    selectedRecord.value = record;
    settleForm.settlementDate = new Date().toISOString().split('T')[0];
    settleForm.remarks = 'Settled on exit/retirement';
    settleForm.comment = '';
    showSettleModal.value = true;
};

// Submit Settle
const submitSettle = async () => {
    settleFormSubmitting.value = true;

    try {
        await axios.post(route('gpf.settle', { generalProvidentFund: selectedRecord.value.id }), settleForm);

        showSettleModal.value = false;
        Swal.fire({
            icon: 'success',
            title: 'Account Settled',
            text: 'Employee GPF account has been marked as settled.',
        });
        fetchRecords(pagination.current_page);
    } catch (err) {
        Swal.fire({
            icon: 'error',
            title: 'Settlement Failed',
            text: err.response?.data?.message || err.message,
        });
    } finally {
        settleFormSubmitting.value = false;
    }
};

// Delete Record
const deleteRecord = async (record) => {
    const result = await Swal.fire({
        title: 'Delete GPF Entry?',
        text: `Are you sure you want to delete this GPF entry for ${record.user?.name || record.employeeId}?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, Delete',
    });

    if (!result.isConfirmed) return;

    try {
        await axios.delete(route('gpf.destroy', { generalProvidentFund: record.id }));
        Swal.fire({
            icon: 'success',
            title: 'Record Deleted',
            timer: 1500,
            showConfirmButton: false,
            toast: true,
            position: 'top-end',
        });
        fetchRecords(pagination.current_page);
    } catch (err) {
        Swal.fire({
            icon: 'error',
            title: 'Delete Failed',
            text: err.response?.data?.message || err.message,
        });
    }
};

onMounted(() => {
    fetchRecords(1);
});
</script>

<template>
    <Head title="General Provident Fund (GPF)" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                        <svg class="w-6 h-6 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        General Provident Fund (GPF)
                    </h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        Track monthly deductions, record historical contributions, inspect employee ledgers, and manage settlements.
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <PrimaryButton @click="openPastModal" class="bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-xs">
                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Add Past Contribution
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="py-6 max-w-9xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <!-- Summary Stats Banner -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white dark:bg-gray-800 p-5 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total Subscriptions</span>
                        <div class="text-2xl font-bold font-mono text-gray-900 dark:text-gray-100 mt-1">
                            ৳{{ formatMoney(summary.total_contribution) }}
                        </div>
                        <span class="text-[11px] text-emerald-600 dark:text-emerald-400">Employee deductions</span>
                    </div>
                    <div class="p-3 bg-emerald-50 dark:bg-emerald-950/40 rounded-lg text-emerald-600 dark:text-emerald-400">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 p-5 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Profit / Interest</span>
                        <div class="text-2xl font-bold font-mono text-indigo-600 dark:text-indigo-400 mt-1">
                            ৳{{ formatMoney(summary.total_interest) }}
                        </div>
                        <span class="text-[11px] text-gray-500 dark:text-gray-400">Cumulative credited</span>
                    </div>
                    <div class="p-3 bg-indigo-50 dark:bg-indigo-950/40 rounded-lg text-indigo-600 dark:text-indigo-400">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                        </svg>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 p-5 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Net Deposit</span>
                        <div class="text-2xl font-bold font-mono text-emerald-600 dark:text-emerald-400 mt-1">
                            ৳{{ formatMoney(summary.total_net_deposit) }}
                        </div>
                        <span class="text-[11px] text-gray-500 dark:text-gray-400">Total credited</span>
                    </div>
                    <div class="p-3 bg-teal-50 dark:bg-teal-950/40 rounded-lg text-teal-600 dark:text-teal-400">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 p-5 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total Ledger Entries</span>
                        <div class="text-2xl font-bold font-mono text-gray-900 dark:text-gray-100 mt-1">
                            {{ summary.total_records }}
                        </div>
                        <span class="text-[11px] text-gray-500 dark:text-gray-400">Active records in database</span>
                    </div>
                    <div class="p-3 bg-amber-50 dark:bg-amber-950/40 rounded-lg text-amber-600 dark:text-amber-400">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Filter Panel -->
            <div class="bg-white dark:bg-gray-800 p-4 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">

                    <!-- Search -->
                    <div class="lg:col-span-2">
                        <InputLabel value="Search Employee" class="text-xs" />
                        <TextInput
                            v-model="filters.search"
                            type="text"
                            placeholder="Name, ID, or Email..."
                            class="mt-1 w-full text-xs"
                            @input="fetchRecords(1)"
                        />
                    </div>

                    <!-- Financial Year -->
                    <div>
                        <InputLabel value="Financial Year" class="text-xs" />
                        <select
                            v-model="filters.financialYear"
                            class="mt-1 w-full rounded-md border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-xs shadow-sm focus:border-emerald-500 focus:ring-emerald-500"
                            @change="fetchRecords(1)"
                        >
                            <option value="all">All Financial Years</option>
                            <option v-for="fy in financialYears" :key="fy" :value="fy">FY {{ fy }}</option>
                        </select>
                    </div>

                    <!-- Month -->
                    <div>
                        <InputLabel value="Month" class="text-xs" />
                        <select
                            v-model="filters.month"
                            class="mt-1 w-full rounded-md border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-xs shadow-sm focus:border-emerald-500 focus:ring-emerald-500"
                            @change="fetchRecords(1)"
                        >
                            <option value="">All Months</option>
                            <option v-for="m in months" :key="m.id" :value="m.id">{{ m.name }}</option>
                        </select>
                    </div>

                    <!-- Settlement Status -->
                    <div>
                        <InputLabel value="Account Status" class="text-xs" />
                        <select
                            v-model="filters.isSettled"
                            class="mt-1 w-full rounded-md border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-xs shadow-sm focus:border-emerald-500 focus:ring-emerald-500"
                            @change="fetchRecords(1)"
                        >
                            <option value="">All Statuses</option>
                            <option value="0">Active (Unsettled)</option>
                            <option value="1">Settled / Closed</option>
                        </select>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-end gap-2">
                        <SecondaryButton @click="resetFilters" class="w-full justify-center text-xs py-2">
                            Reset
                        </SecondaryButton>
                    </div>

                </div>
            </div>

            <!-- GPF Data Table -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-xs">
                        <thead class="bg-gray-50 dark:bg-gray-900/50 text-gray-700 dark:text-gray-300 font-semibold uppercase tracking-wider">
                            <tr>
                                <th class="px-4 py-3 text-left w-12">SL#</th>
                                <th class="px-4 py-3 text-left">Employee</th>
                                <th class="px-4 py-3 text-left">Period</th>
                                <th class="px-4 py-3 text-right">Opening Bal.</th>
                                <th class="px-4 py-3 text-right">Contribution</th>
                                <th class="px-4 py-3 text-right">Interest</th>
                                <th class="px-4 py-3 text-right">Net Deposit</th>
                                <th class="px-4 py-3 text-right font-bold">Closing Bal.</th>
                                <th class="px-4 py-3 text-center">Type</th>
                                <th class="px-4 py-3 text-center">Status</th>
                                <th class="px-4 py-3 text-center w-36">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-800">
                            <tr v-if="loading">
                                <td colspan="11" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                                    <div class="flex items-center justify-center gap-2">
                                        <svg class="animate-spin h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                        </svg>
                                        Loading GPF ledger records...
                                    </div>
                                </td>
                            </tr>
                            <tr v-else-if="gpfRecords.length === 0">
                                <td colspan="11" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                                    No General Provident Fund records found for selected filters.
                                </td>
                            </tr>
                            <tr
                                v-for="(record, idx) in gpfRecords"
                                :key="record.id"
                                class="hover:bg-gray-50/80 dark:hover:bg-gray-700/40 transition-colors"
                            >
                                <td class="px-4 py-3 text-gray-500 dark:text-gray-400">
                                    {{ (pagination.current_page - 1) * pagination.per_page + idx + 1 }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-900 dark:text-gray-100">
                                        {{ record.user?.name || 'N/A' }}
                                    </div>
                                    <div class="text-[11px] text-gray-500 dark:text-gray-400 flex items-center gap-1.5 mt-0.5">
                                        <span class="font-mono bg-gray-100 dark:bg-gray-700 px-1 py-0.5 rounded text-[10px]">
                                            {{ record.employeeId || record.user?.employee_info?.employeeCode }}
                                        </span>
                                        <span>•</span>
                                        <span>{{ record.user?.employee_info?.department_office?.name || 'General' }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                                    <div class="font-semibold text-gray-900 dark:text-gray-100">
                                        {{ getMonthName(record.month) }} {{ record.year }}
                                    </div>
                                    <span class="inline-block mt-0.5 text-[10px] px-1.5 py-0.5 bg-blue-50 dark:bg-blue-950/40 text-blue-700 dark:text-blue-300 rounded font-mono">
                                        FY {{ record.financialYear }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right font-mono text-gray-600 dark:text-gray-400">
                                    ৳{{ formatMoney(record.openingBalance) }}
                                </td>
                                <td class="px-4 py-3 text-right font-mono font-semibold text-emerald-600 dark:text-emerald-400">
                                    ৳{{ formatMoney(record.contributionAmount) }}
                                </td>
                                <td class="px-4 py-3 text-right font-mono text-indigo-600 dark:text-indigo-400">
                                    ৳{{ formatMoney(record.interestAmount) }}
                                </td>
                                <td class="px-4 py-3 text-right font-mono text-gray-700 dark:text-gray-300">
                                    ৳{{ formatMoney(record.netDeposit) }}
                                </td>
                                <td class="px-4 py-3 text-right font-mono font-bold text-gray-900 dark:text-gray-100 bg-gray-50/50 dark:bg-gray-900/30">
                                    ৳{{ formatMoney(record.closingAmount) }}
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span
                                        :class="[
                                            record.type === 'historical_entry'
                                                ? 'bg-amber-50 dark:bg-amber-950/50 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-800'
                                                : 'bg-emerald-50 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800',
                                            'px-2 py-0.5 text-[10px] font-medium rounded-full border'
                                        ]"
                                    >
                                        {{ record.type === 'historical_entry' ? 'Historical' : 'Monthly' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span
                                        :class="[
                                            record.isSettled
                                                ? 'bg-purple-50 dark:bg-purple-950/50 text-purple-700 dark:text-purple-300 border-purple-200 dark:border-purple-800'
                                                : 'bg-green-50 dark:bg-green-950/50 text-green-700 dark:text-green-300 border-green-200 dark:border-green-800',
                                            'px-2 py-0.5 text-[10px] font-medium rounded-full border'
                                        ]"
                                    >
                                        {{ record.isSettled ? 'Settled' : 'Active' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex items-center justify-center gap-1">
                                        <button
                                            @click="viewLedger(record)"
                                            title="View Employee Ledger"
                                            class="p-1.5 text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 rounded transition-colors"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                            </svg>
                                        </button>
                                        <button
                                            @click="openCommentModal(record)"
                                            title="Add Audit Note"
                                            class="p-1.5 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-950/40 rounded transition-colors relative"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                                            </svg>
                                            <span v-if="record.comments && record.comments.length > 0" class="absolute -top-1 -right-1 bg-blue-500 text-white text-[9px] w-3.5 h-3.5 rounded-full flex items-center justify-center font-bold">
                                                {{ record.comments.length }}
                                            </span>
                                        </button>
                                        <button
                                            v-if="!record.isSettled"
                                            @click="openSettleModal(record)"
                                            title="Settle Account"
                                            class="p-1.5 text-purple-600 hover:bg-purple-50 dark:hover:bg-purple-950/40 rounded transition-colors"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </button>
                                        <button
                                            @click="deleteRecord(record)"
                                            title="Delete Record"
                                            class="p-1.5 text-red-600 hover:bg-red-50 dark:hover:bg-red-950/40 rounded transition-colors"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="pagination.total > pagination.per_page" class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        Showing {{ (pagination.current_page - 1) * pagination.per_page + 1 }} to {{ Math.min(pagination.current_page * pagination.per_page, pagination.total) }} of {{ pagination.total }} records
                    </div>
                    <div class="flex items-center gap-2">
                        <SecondaryButton
                            :disabled="pagination.current_page <= 1"
                            @click="fetchRecords(pagination.current_page - 1)"
                            class="text-xs py-1 px-2.5"
                        >
                            Previous
                        </SecondaryButton>
                        <span class="text-xs text-gray-600 dark:text-gray-300 font-medium">
                            Page {{ pagination.current_page }} of {{ pagination.last_page }}
                        </span>
                        <SecondaryButton
                            :disabled="pagination.current_page >= pagination.last_page"
                            @click="fetchRecords(pagination.current_page + 1)"
                            class="text-xs py-1 px-2.5"
                        >
                            Next
                        </SecondaryButton>
                    </div>
                </div>
            </div>

        </div>

        <!-- Modal: Add Past Contribution -->
        <Modal :show="showPastModal" @close="showPastModal = false" max-width="2xl">
            <div class="p-6">
                <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2 border-b border-gray-200 dark:border-gray-700 pb-3">
                    <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Add Past / Historical GPF Contribution
                </h3>

                <form @submit.prevent="submitPastContribution" class="mt-4 space-y-4">
                    <!-- Employee (Searchable component) -->
                    <div>
                        <InputLabel value="Select Employee *" class="text-xs font-semibold" />
                        <SearchableEmployeeSelect
                            v-model="pastForm.user_id"
                            :employees="employees"
                            :error="pastFormErrors.user_id"
                            placeholder="Type to search employee by name, ID, department..."
                            class="mt-1"
                        />
                        <InputError :message="pastFormErrors.user_id" class="mt-1" />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <!-- Financial Year -->
                        <div>
                            <InputLabel value="Financial Year *" class="text-xs font-semibold" />
                            <TextInput
                                v-model="pastForm.financialYear"
                                placeholder="e.g. 2023-2024"
                                required
                                class="mt-1 w-full text-xs"
                            />
                            <InputError :message="pastFormErrors.financialYear" class="mt-1" />
                        </div>

                        <!-- Month -->
                        <div>
                            <InputLabel value="Month *" class="text-xs font-semibold" />
                            <select
                                v-model="pastForm.month"
                                required
                                class="mt-1 w-full rounded-md border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-xs shadow-sm focus:border-emerald-500 focus:ring-emerald-500"
                            >
                                <option v-for="m in months" :key="m.id" :value="m.id">{{ m.name }}</option>
                            </select>
                            <InputError :message="pastFormErrors.month" class="mt-1" />
                        </div>

                        <!-- Year -->
                        <div>
                            <InputLabel value="Calendar Year *" class="text-xs font-semibold" />
                            <TextInput
                                v-model.number="pastForm.year"
                                type="number"
                                required
                                class="mt-1 w-full text-xs font-mono"
                            />
                            <InputError :message="pastFormErrors.year" class="mt-1" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <!-- Opening Balance -->
                        <div>
                            <InputLabel value="Opening Balance (৳)" class="text-xs font-semibold" />
                            <TextInput
                                v-model.number="pastForm.openingBalance"
                                type="number"
                                step="0.01"
                                class="mt-1 w-full text-xs font-mono"
                            />
                            <InputError :message="pastFormErrors.openingBalance" class="mt-1" />
                        </div>

                        <!-- Contribution Amount -->
                        <div>
                            <InputLabel value="Monthly Contribution (৳) *" class="text-xs font-semibold" />
                            <TextInput
                                v-model.number="pastForm.contributionAmount"
                                type="number"
                                step="0.01"
                                required
                                class="mt-1 w-full text-xs font-mono"
                            />
                            <InputError :message="pastFormErrors.contributionAmount" class="mt-1" />
                        </div>

                        <!-- Interest / Profit Credited -->
                        <div>
                            <InputLabel value="Profit / Interest (৳)" class="text-xs font-semibold" />
                            <TextInput
                                v-model.number="pastForm.interestAmount"
                                type="number"
                                step="0.01"
                                class="mt-1 w-full text-xs font-mono"
                            />
                            <InputError :message="pastFormErrors.interestAmount" class="mt-1" />
                        </div>
                    </div>

                    <!-- Calculated Closing Balance Preview -->
                    <div class="p-3 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 rounded-lg flex items-center justify-between text-xs">
                        <span class="font-medium text-emerald-800 dark:text-emerald-300">Resulting Closing Balance:</span>
                        <span class="font-mono font-bold text-sm text-emerald-900 dark:text-emerald-100">
                            ৳{{ formatMoney((Number(pastForm.openingBalance) || 0) + (Number(pastForm.contributionAmount) || 0) + (Number(pastForm.interestAmount) || 0)) }}
                        </span>
                    </div>

                    <!-- Remarks -->
                    <div>
                        <InputLabel value="Remarks / Reference Note" class="text-xs font-semibold" />
                        <TextInput
                            v-model="pastForm.remarks"
                            placeholder="e.g. Backlog entry prior to ERP deployment"
                            class="mt-1 w-full text-xs"
                        />
                    </div>

                    <!-- Initial Comment -->
                    <div>
                        <InputLabel value="Audit Comment / Supporting Notes" class="text-xs font-semibold" />
                        <textarea
                            v-model="pastForm.comment"
                            rows="2"
                            placeholder="Optional notes for audit verification..."
                            class="mt-1 w-full rounded-md border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-xs shadow-sm focus:border-emerald-500 focus:ring-emerald-500"
                        ></textarea>
                    </div>

                    <div class="flex justify-end gap-3 pt-3 border-t border-gray-200 dark:border-gray-700">
                        <SecondaryButton @click="showPastModal = false">Cancel</SecondaryButton>
                        <PrimaryButton :disabled="pastFormSubmitting" class="bg-emerald-600 hover:bg-emerald-700">
                            {{ pastFormSubmitting ? 'Saving...' : 'Save Historical Contribution' }}
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Modal: Annual GPF Contribution Statement (Whole Financial Year 12-Month Table) -->
        <Modal :show="showLedgerModal" @close="showLedgerModal = false" max-width="5xl">
            <div class="p-6">
                <!-- Modal Header -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-gray-200 dark:border-gray-700 pb-4">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                            <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            Annual GPF Contribution Statement
                        </h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                            Fiscal Year: <span class="font-semibold text-emerald-700 dark:text-emerald-400">FY {{ selectedLedgerFy || ledgerData?.financial_year }}</span> (July — June)
                        </p>
                    </div>

                    <div class="flex items-center gap-2 flex-wrap">
                        <!-- FY Selector Dropdown -->
                        <div class="flex items-center gap-1.5" v-if="ledgerData">
                            <span class="text-xs text-gray-500 font-medium">Fiscal Year:</span>
                            <select
                                v-model="selectedLedgerFy"
                                @change="changeLedgerFy"
                                class="text-xs font-semibold rounded-md border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 py-1.5 px-2.5"
                            >
                                <option
                                    v-for="fy in (ledgerData.available_financial_years || financialYears)"
                                    :key="fy"
                                    :value="fy"
                                >
                                    FY {{ fy }}
                                </option>
                            </select>
                        </div>

                        <!-- Export Excel Button -->
                        <button
                            type="button"
                            @click="exportLedgerToExcel"
                            :disabled="!ledgerData || exportLoading"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-emerald-700 dark:text-emerald-300 bg-emerald-50 dark:bg-emerald-950/50 border border-emerald-300 dark:border-emerald-800 rounded-md hover:bg-emerald-100 dark:hover:bg-emerald-900/60 transition-colors shadow-sm disabled:opacity-50 disabled:cursor-not-allowed"
                            title="Export statement with employee information to Excel (.xlsx)"
                        >
                            <svg class="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <span>{{ exportLoading ? 'Exporting...' : 'Export Excel' }}</span>
                        </button>

                        <!-- Download PDF Button -->
                        <button
                            type="button"
                            @click="generatePdfStatement('download')"
                            :disabled="!ledgerData || pdfGenerating"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-rose-700 dark:text-rose-300 bg-rose-50 dark:bg-rose-950/50 border border-rose-300 dark:border-rose-800 rounded-md hover:bg-rose-100 dark:hover:bg-rose-900/60 transition-colors shadow-sm disabled:opacity-50 disabled:cursor-not-allowed"
                            title="Generate and download A4 PDF Statement using jsPDF"
                        >
                            <svg class="w-3.5 h-3.5 text-rose-600 dark:text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                            </svg>
                            <span>{{ pdfGenerating ? 'Generating...' : 'PDF' }}</span>
                        </button>

                        <!-- Print Statement Button (jsPDF Vector Print) -->
                        <button
                            type="button"
                            @click="generatePdfStatement('print')"
                            :disabled="!ledgerData || pdfGenerating"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-md hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors shadow-sm disabled:opacity-50 disabled:cursor-not-allowed"
                            title="Print Statement using jsPDF vector printing"
                        >
                            <svg class="w-3.5 h-3.5 text-gray-500 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                            </svg>
                            <span>Print</span>
                        </button>

                        <!-- Close Button -->
                        <button
                            @click="showLedgerModal = false"
                            class="p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                </div>

                <!-- Loading State -->
                <div v-if="ledgerLoading" class="py-16 text-center text-gray-500">
                    <div class="flex items-center justify-center gap-2">
                        <svg class="animate-spin h-6 w-6 text-emerald-600" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span class="text-sm font-medium">Loading annual GPF contribution statement...</span>
                    </div>
                </div>

                <div v-else-if="ledgerData" id="printable-gpf-statement" class="mt-4 space-y-4">
                    <!-- Supplemental GPF source is shown separately; this is not a certified closing balance. -->
                    <div v-if="ledgerData.supplementary_arrear_gpf?.length" class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900">
                        <strong>Supplementary arrear GPF (separate journal): ৳{{ formatMoney(ledgerData.supplementary_arrear_gpf_total) }}</strong>
                        <p class="mt-1">These entries are NOT included in historical GPF closing balances. Reconcile and authorize ledger adjustments before treating them as an updated balance.</p>
                        <div v-for="entry in ledgerData.supplementary_arrear_gpf" :key="entry.id" class="mt-1">
                            Bill #{{ entry.bill_id }} · {{ entry.pay_month }}/{{ entry.pay_year }} · ৳{{ formatMoney(entry.amount) }} · Entitlement: {{ entry.entitlement_months.join(', ') }}
                        </div>
                    </div>
                    <!-- Employee Profile Header -->
                    <div class="p-4 bg-gray-50 dark:bg-gray-900/60 rounded-xl border border-gray-200 dark:border-gray-700 flex flex-col md:flex-row md:items-center justify-between gap-4 text-xs">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-emerald-600 text-white font-bold flex items-center justify-center text-sm shrink-0 shadow-sm">
                                {{ (ledgerData.user.name || 'E').substring(0, 2).toUpperCase() }}
                            </div>
                            <div>
                                <div class="text-sm font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                                    {{ ledgerData.user.name }}
                                    <span class="font-mono text-[10px] px-1.5 py-0.5 rounded bg-emerald-100 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 font-semibold border border-emerald-200 dark:border-emerald-800">
                                        ID: {{ ledgerData.user.employee_code }}
                                    </span>
                                </div>
                                <div class="text-gray-500 dark:text-gray-400 mt-0.5">
                                    {{ ledgerData.user.designation }} • {{ ledgerData.user.department }}
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-6 border-t md:border-t-0 pt-2 md:pt-0 border-gray-200 dark:border-gray-700">
                            <div>
                                <span class="text-gray-400 block text-[10px] uppercase font-semibold">Account Status</span>
                                <span
                                    :class="[
                                        ledgerData.summary.is_settled
                                            ? 'text-purple-600 dark:text-purple-400'
                                            : 'text-emerald-600 dark:text-emerald-400',
                                        'font-bold inline-flex items-center gap-1 mt-0.5'
                                    ]"
                                >
                                    <span class="w-1.5 h-1.5 rounded-full" :class="ledgerData.summary.is_settled ? 'bg-purple-600' : 'bg-emerald-600'"></span>
                                    {{ ledgerData.summary.is_settled ? 'Settled (Closed)' : 'Active GPF Subscriber' }}
                                </span>
                            </div>
                            <div>
                                <span class="text-gray-400 block text-[10px] uppercase font-semibold">Total Lifetime Balance</span>
                                <span class="font-mono font-extrabold text-sm text-gray-900 dark:text-gray-100 mt-0.5 block">
                                    ৳{{ formatMoney(ledgerData.summary.current_balance) }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Financial Year Summary KPI Cards (4 Cards) -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <div class="p-3 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm">
                            <span class="text-[11px] font-medium text-gray-500 dark:text-gray-400 block">FY Opening Bal. (July)</span>
                            <div class="text-base font-bold font-mono text-gray-800 dark:text-gray-200 mt-1">
                                ৳{{ formatMoney(ledgerData.summary.fy_opening_balance) }}
                            </div>
                        </div>

                        <div class="p-3 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm">
                            <span class="text-[11px] font-medium text-emerald-600 dark:text-emerald-400 block">Total FY Contribution</span>
                            <div class="text-base font-bold font-mono text-emerald-600 dark:text-emerald-400 mt-1">
                                ৳{{ formatMoney(ledgerData.summary.total_contribution) }}
                            </div>
                        </div>

                        <div class="p-3 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm">
                            <span class="text-[11px] font-medium text-indigo-600 dark:text-indigo-400 block">Total FY Interest / Profit</span>
                            <div class="text-base font-bold font-mono text-indigo-600 dark:text-indigo-400 mt-1">
                                ৳{{ formatMoney(ledgerData.summary.total_interest) }}
                            </div>
                        </div>

                        <div class="p-3 bg-white dark:bg-gray-800 rounded-lg border border-emerald-300 dark:border-emerald-700 shadow-sm bg-gradient-to-br from-white to-emerald-50/40 dark:from-gray-800 dark:to-emerald-950/20">
                            <span class="text-[11px] font-semibold text-emerald-700 dark:text-emerald-300 block">FY Closing Bal. (June)</span>
                            <div class="text-base font-black font-mono text-emerald-700 dark:text-emerald-300 mt-1">
                                ৳{{ formatMoney(ledgerData.summary.fy_closing_balance) }}
                            </div>
                        </div>
                    </div>

                    <!-- View Mode Toggle -->
                    <div class="flex items-center justify-between pt-1">
                        <div class="text-xs font-semibold text-gray-700 dark:text-gray-300">
                            {{ ledgerViewMode === 'fy' ? 'All Months Contribution Table (July to June)' : 'All Historical GPF Records' }}
                        </div>

                        <div class="flex items-center rounded-lg border border-gray-200 dark:border-gray-700 p-0.5 bg-gray-100 dark:bg-gray-800 text-xs">
                            <button
                                type="button"
                                @click="ledgerViewMode = 'fy'"
                                :class="[
                                    'px-2.5 py-1 rounded-md transition-all font-medium',
                                    ledgerViewMode === 'fy'
                                        ? 'bg-white dark:bg-gray-900 text-emerald-600 dark:text-emerald-400 shadow-sm font-semibold'
                                        : 'text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'
                                ]"
                            >
                                12-Month FY View
                            </button>
                            <button
                                type="button"
                                @click="ledgerViewMode = 'all'"
                                :class="[
                                    'px-2.5 py-1 rounded-md transition-all font-medium',
                                    ledgerViewMode === 'all'
                                        ? 'bg-white dark:bg-gray-900 text-emerald-600 dark:text-emerald-400 shadow-sm font-semibold'
                                        : 'text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'
                                ]"
                            >
                                All Records ({{ ledgerData.records?.length || 0 }})
                            </button>
                        </div>
                    </div>

                    <!-- 12-MONTH FINANCIAL YEAR SIMPLE TABLE (Requirement 3) -->
                    <div v-if="ledgerViewMode === 'fy'" class="overflow-x-auto border border-gray-200 dark:border-gray-700 rounded-lg shadow-sm">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-xs">
                            <thead class="bg-gray-100 dark:bg-gray-900 font-semibold text-gray-700 dark:text-gray-300">
                                <tr>
                                    <th class="px-3 py-2.5 text-center w-10">#</th>
                                    <th class="px-3 py-2.5 text-left">Month (Period)</th>
                                    <th class="px-3 py-2.5 text-right">Opening Bal. (৳)</th>
                                    <th class="px-3 py-2.5 text-right font-bold text-emerald-700 dark:text-emerald-300">Contribution (৳)</th>
                                    <th class="px-3 py-2.5 text-right text-indigo-700 dark:text-indigo-300">Interest (৳)</th>
                                    <th class="px-3 py-2.5 text-right">Net Deposit (৳)</th>
                                    <th class="px-3 py-2.5 text-right font-bold text-gray-900 dark:text-gray-100">Closing Bal. (৳)</th>
                                    <th class="px-3 py-2.5 text-center">Status</th>
                                    <th class="px-3 py-2.5 text-left">Remarks</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-800">
                                <tr
                                    v-for="item in ledgerData.fy_months"
                                    :key="item.sl"
                                    :class="[
                                        item.has_record
                                            ? 'hover:bg-emerald-50/30 dark:hover:bg-emerald-950/20 transition-colors'
                                            : 'bg-gray-50/40 dark:bg-gray-900/30 text-gray-400'
                                    ]"
                                >
                                    <!-- Sequence Number -->
                                    <td class="px-3 py-2.5 text-center font-mono text-gray-400 font-medium">
                                        {{ item.sl }}
                                    </td>

                                    <!-- Month / Period -->
                                    <td class="px-3 py-2.5">
                                        <div :class="['font-semibold', item.has_record ? 'text-gray-900 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400']">
                                            {{ item.period }}
                                        </div>
                                        <div class="text-[10px] text-gray-400">
                                            Month {{ item.sl }} of FY {{ selectedLedgerFy || ledgerData.financial_year }}
                                        </div>
                                    </td>

                                    <!-- Opening Balance -->
                                    <td class="px-3 py-2.5 text-right font-mono text-gray-600 dark:text-gray-300">
                                        ৳{{ formatMoney(item.opening_balance) }}
                                    </td>

                                    <!-- Monthly Contribution -->
                                    <td class="px-3 py-2.5 text-right font-mono font-bold" :class="item.contribution_amount > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-400'">
                                        <span v-if="item.contribution_amount > 0">৳{{ formatMoney(item.contribution_amount) }}</span>
                                        <span v-else>—</span>
                                    </td>

                                    <!-- Interest -->
                                    <td class="px-3 py-2.5 text-right font-mono" :class="item.interest_amount > 0 ? 'text-indigo-600 dark:text-indigo-400 font-semibold' : 'text-gray-400'">
                                        <span v-if="item.interest_amount > 0">৳{{ formatMoney(item.interest_amount) }}</span>
                                        <span v-else>—</span>
                                    </td>

                                    <!-- Net Deposit -->
                                    <td class="px-3 py-2.5 text-right font-mono text-gray-700 dark:text-gray-300">
                                        <span v-if="item.net_deposit > 0">৳{{ formatMoney(item.net_deposit) }}</span>
                                        <span v-else>—</span>
                                    </td>

                                    <!-- Closing Balance -->
                                    <td class="px-3 py-2.5 text-right font-mono font-bold" :class="item.has_record ? 'text-gray-900 dark:text-gray-100 bg-gray-50/60 dark:bg-gray-900/40' : 'text-gray-500'">
                                        ৳{{ formatMoney(item.closing_amount) }}
                                    </td>

                                    <!-- Status / Type Badge -->
                                    <td class="px-3 py-2.5 text-center">
                                        <span
                                            :class="[
                                                item.type === 'historical_entry'
                                                    ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 border-amber-200 dark:border-amber-800'
                                                    : item.type === 'monthly_deduction' || item.has_record
                                                    ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800'
                                                    : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400 border-gray-200 dark:border-gray-700',
                                                'px-2 py-0.5 rounded-full text-[10px] font-semibold border inline-block whitespace-nowrap'
                                            ]"
                                        >
                                            {{ item.type_label }}
                                        </span>
                                    </td>

                                    <!-- Remarks / Comments -->
                                    <td class="px-3 py-2.5 text-gray-500 dark:text-gray-400">
                                        <div v-if="item.remarks && item.remarks !== '—'" class="truncate max-w-xs text-[11px] text-gray-700 dark:text-gray-300 font-medium">
                                            {{ item.remarks }}
                                        </div>

                                        <!-- If comments exist for this month -->
                                        <div v-if="item.comments && item.comments.length > 0" class="mt-1 space-y-1">
                                            <button
                                                type="button"
                                                @click="openNotesFromStatement(item)"
                                                class="inline-flex items-center gap-1.5 px-2 py-0.5 text-[10px] font-medium text-blue-700 dark:text-blue-300 bg-blue-50 dark:bg-blue-950/60 hover:bg-blue-100 dark:hover:bg-blue-900 border border-blue-200 dark:border-blue-800 rounded transition"
                                                :title="'Click to view all ' + item.comments.length + ' note(s)'"
                                            >
                                                <svg class="w-3 h-3 text-blue-600 dark:text-blue-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                                                </svg>
                                                <span class="font-bold">{{ item.comments.length }} note{{ item.comments.length > 1 ? 's' : '' }}</span>
                                                <span class="text-blue-500 dark:text-blue-400">• {{ item.comments[0].author || 'Admin' }}</span>
                                            </button>
                                            <div class="text-[10px] text-gray-500 dark:text-gray-400 truncate max-w-xs italic pl-1">
                                                "{{ item.comments[0].comment }}"
                                            </div>
                                        </div>

                                        <!-- If no comments yet, but month has a record, offer Add Note button -->
                                        <div v-else-if="item.has_record || item.record_id" class="mt-0.5">
                                            <button
                                                type="button"
                                                @click="openNotesFromStatement(item)"
                                                class="text-[10px] text-gray-400 hover:text-blue-600 dark:hover:text-blue-400 inline-flex items-center gap-1 hover:underline"
                                            >
                                                <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                                </svg>
                                                Add note
                                            </button>
                                        </div>
                                        <span v-else-if="!item.remarks || item.remarks === '—'" class="text-[10px] text-gray-400">—</span>
                                    </td>
                                </tr>
                            </tbody>

                            <!-- Summary Footer Row -->
                            <tfoot class="bg-gray-100 dark:bg-gray-900 border-t-2 border-gray-300 dark:border-gray-600 font-bold text-gray-900 dark:text-gray-100">
                                <tr>
                                    <td colspan="2" class="px-3 py-3 text-left uppercase text-[11px] tracking-wider font-extrabold text-emerald-800 dark:text-emerald-400">
                                        Annual Total (FY {{ selectedLedgerFy || ledgerData.financial_year }})
                                    </td>
                                    <td class="px-3 py-3 text-right font-mono text-xs">
                                        ৳{{ formatMoney(ledgerData.summary.fy_opening_balance) }}
                                    </td>
                                    <td class="px-3 py-3 text-right font-mono text-xs text-emerald-700 dark:text-emerald-300 font-extrabold">
                                        ৳{{ formatMoney(ledgerData.summary.total_contribution) }}
                                    </td>
                                    <td class="px-3 py-3 text-right font-mono text-xs text-indigo-700 dark:text-indigo-300 font-extrabold">
                                        ৳{{ formatMoney(ledgerData.summary.total_interest) }}
                                    </td>
                                    <td class="px-3 py-3 text-right font-mono text-xs text-gray-800 dark:text-gray-200 font-extrabold">
                                        ৳{{ formatMoney(ledgerData.summary.total_net_deposit) }}
                                    </td>
                                    <td class="px-3 py-3 text-right font-mono text-xs text-emerald-700 dark:text-emerald-300 font-black bg-emerald-50 dark:bg-emerald-950/40">
                                        ৳{{ formatMoney(ledgerData.summary.fy_closing_balance) }}
                                    </td>
                                    <td class="px-3 py-3 text-center text-[10px] uppercase text-gray-500 font-semibold">
                                        12 Months
                                    </td>
                                    <td class="px-3 py-3 text-left text-gray-400 text-[10px]">
                                        Full Year Statement
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- ALL HISTORICAL RECORDS TABLE (Alternative Tab) -->
                    <div v-else class="overflow-x-auto max-h-96 overflow-y-auto border border-gray-200 dark:border-gray-700 rounded-lg shadow-sm">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-xs">
                            <thead class="bg-gray-100 dark:bg-gray-900 sticky top-0 font-semibold text-gray-700 dark:text-gray-300">
                                <tr>
                                    <th class="px-3 py-2 text-left">Period</th>
                                    <th class="px-3 py-2 text-left">FY</th>
                                    <th class="px-3 py-2 text-right">Opening Bal.</th>
                                    <th class="px-3 py-2 text-right">Contribution</th>
                                    <th class="px-3 py-2 text-right">Interest</th>
                                    <th class="px-3 py-2 text-right font-bold">Closing Bal.</th>
                                    <th class="px-3 py-2 text-left">Type / Notes</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-800">
                                <tr v-for="entry in ledgerData.records" :key="entry.id" class="hover:bg-gray-50/50">
                                    <td class="px-3 py-2 font-medium">{{ getMonthName(entry.month) }} {{ entry.year }}</td>
                                    <td class="px-3 py-2 font-mono text-gray-600 dark:text-gray-400">{{ entry.financial_year }}</td>
                                    <td class="px-3 py-2 text-right font-mono">৳{{ formatMoney(entry.opening_balance) }}</td>
                                    <td class="px-3 py-2 text-right font-mono font-semibold text-emerald-600">৳{{ formatMoney(entry.contribution_amount) }}</td>
                                    <td class="px-3 py-2 text-right font-mono text-indigo-600">৳{{ formatMoney(entry.interest_amount) }}</td>
                                    <td class="px-3 py-2 text-right font-mono font-bold text-gray-900 dark:text-gray-100 bg-gray-50/30">৳{{ formatMoney(entry.closing_amount) }}</td>
                                    <td class="px-3 py-2 text-gray-500">
                                        <div class="truncate max-w-xs">{{ entry.remarks || entry.type }}</div>
                                        <div v-if="entry.comments && entry.comments.length > 0" class="mt-1">
                                            <button
                                                type="button"
                                                @click="openCommentModal(entry)"
                                                class="inline-flex items-center gap-1 text-[10px] text-blue-600 dark:text-blue-400 hover:underline font-medium"
                                            >
                                                💬 {{ entry.comments.length }} note{{ entry.comments.length > 1 ? 's' : '' }} ({{ entry.comments[0].author || 'User' }})
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Related Audit Notes & Comments for this Statement -->
                    <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="text-xs font-bold uppercase tracking-wider text-gray-700 dark:text-gray-300 flex items-center gap-2">
                                <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                                </svg>
                                Related Audit Notes & Comments
                                <span class="bg-blue-100 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300 px-2 py-0.5 rounded-full text-[10px] font-bold">
                                    {{ allLedgerNotes.length }}
                                </span>
                            </h4>
                        </div>

                        <!-- Notes List in Statement -->
                        <div v-if="allLedgerNotes.length > 0" class="space-y-2 max-h-56 overflow-y-auto pr-1">
                            <div
                                v-for="note in allLedgerNotes"
                                :key="note.id"
                                class="p-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800/80 hover:bg-gray-50 dark:hover:bg-gray-700 transition shadow-sm text-xs"
                            >
                                <div class="flex items-center justify-between gap-2 flex-wrap mb-1.5">
                                    <div class="flex items-center gap-2">
                                        <div class="w-6 h-6 rounded-full bg-blue-600 text-white font-bold flex items-center justify-center text-[10px] shrink-0">
                                            {{ (note.author || 'U').substring(0, 1).toUpperCase() }}
                                        </div>
                                        <div class="flex items-baseline gap-1.5">
                                            <span class="font-bold text-gray-900 dark:text-gray-100">{{ note.author }}</span>
                                            <span v-if="note.author_email" class="text-[10px] text-gray-400">({{ note.author_email }})</span>
                                        </div>
                                        <span
                                            :class="[
                                                getPurposeBadgeClass(note.purpose),
                                                'px-1.5 py-0.5 rounded text-[9px] font-semibold border'
                                            ]"
                                        >
                                            {{ getPurposeLabel(note.purpose) }}
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-2 text-[10px] text-gray-400">
                                        <span class="font-mono bg-gray-100 dark:bg-gray-700 px-1.5 py-0.5 rounded text-gray-600 dark:text-gray-300">
                                            {{ note.period }}
                                        </span>
                                        <span>{{ note.created_at || note.formatted_created_at }}</span>
                                        <button
                                            v-if="note.record_id"
                                            type="button"
                                            @click="openNotesFromStatement(note.raw_month)"
                                            class="text-blue-600 dark:text-blue-400 hover:underline font-medium ml-1"
                                            title="View/Add notes for this period"
                                        >
                                            View / Add
                                        </button>
                                        <button
                                            type="button"
                                            @click="deleteComment(note.id)"
                                            class="text-red-500 hover:text-red-700 dark:hover:text-red-400 p-0.5 rounded transition"
                                            title="Delete note"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                                <p class="text-gray-700 dark:text-gray-200 text-xs pl-8 whitespace-pre-wrap">{{ note.comment }}</p>
                            </div>
                        </div>
                        <div v-else class="p-4 text-center rounded-lg border border-dashed border-gray-300 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-900/30 text-xs text-gray-400">
                            No audit notes or comments have been attached to this fiscal year's contributions yet.
                        </div>
                    </div>

                    <!-- Bottom Action Buttons -->
                    <div class="flex justify-between items-center pt-2 border-t border-gray-200 dark:border-gray-700">
                        <div class="text-[11px] text-gray-500 dark:text-gray-400 flex items-center gap-3">
                            <span>Statement generated on {{ new Date().toLocaleDateString('en-GB') }}</span>
                            <span>•</span>
                            <button
                                type="button"
                                @click="exportLedgerToExcel"
                                :disabled="exportLoading"
                                class="text-emerald-600 dark:text-emerald-400 hover:underline font-medium inline-flex items-center gap-1"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                Export Excel (.xlsx)
                            </button>
                            <span>•</span>
                            <button
                                type="button"
                                @click="generatePdfStatement('download')"
                                :disabled="pdfGenerating"
                                class="text-rose-600 dark:text-rose-400 hover:underline font-medium inline-flex items-center gap-1"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                </svg>
                                Download PDF
                            </button>
                        </div>
                        <SecondaryButton @click="showLedgerModal = false">Close</SecondaryButton>
                    </div>
                </div>
            </div>
        </Modal>

        <!-- Modal: Add / View Comments -->
        <Modal :show="showCommentModal" @close="showCommentModal = false" max-width="2xl">
            <div class="p-6">
                <!-- Modal Header -->
                <div class="flex items-center justify-between border-b border-gray-200 dark:border-gray-700 pb-3">
                    <div class="flex items-center gap-2">
                        <div class="p-2 rounded-lg bg-blue-50 dark:bg-blue-950/60 text-blue-600 dark:text-blue-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">
                                Audit Notes & Comments
                            </h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                View previous remarks and attach official notes to this contribution entry.
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        @click="showCommentModal = false"
                        class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Record Details Banner -->
                <div v-if="selectedRecord" class="mt-3 p-3 bg-blue-50/50 dark:bg-blue-950/40 border border-blue-200 dark:border-blue-900 rounded-lg flex items-center justify-between text-xs flex-wrap gap-2">
                    <div class="flex items-center gap-2">
                        <span class="font-bold text-gray-900 dark:text-gray-100">
                            {{ selectedRecord.user?.name || selectedRecord.userName || 'Employee' }}
                        </span>
                        <span class="font-mono text-[10px] px-1.5 py-0.5 rounded bg-blue-100 dark:bg-blue-900/60 text-blue-800 dark:text-blue-200 font-semibold border border-blue-200 dark:border-blue-800">
                            ID: {{ selectedRecord.user?.employee_code || selectedRecord.user?.employeeInfo?.employeeCode || selectedRecord.employeeId }}
                        </span>
                    </div>
                    <div class="text-gray-600 dark:text-gray-300 flex items-center gap-2">
                        <span>Period: <strong>{{ getMonthName(selectedRecord.month) }} {{ selectedRecord.year }}</strong></span>
                        <span>•</span>
                        <span class="font-mono">FY {{ selectedRecord.financialYear }}</span>
                    </div>
                </div>

                <!-- Section 1: Previous Notes History -->
                <div class="mt-4">
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-gray-700 dark:text-gray-300 flex items-center gap-1.5">
                            <span>Previous Notes</span>
                            <span class="bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 px-2 py-0.5 rounded-full text-[10px] font-bold border border-gray-200 dark:border-gray-700">
                                {{ activeComments.length }}
                            </span>
                        </h4>
                        <span v-if="commentsLoading" class="text-[11px] text-blue-600 flex items-center gap-1">
                            <svg class="animate-spin h-3.5 w-3.5" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            Syncing...
                        </span>
                    </div>

                    <!-- Scrollable list of comments -->
                    <div v-if="activeComments.length > 0" class="space-y-2.5 max-h-56 overflow-y-auto pr-1">
                        <div
                            v-for="c in activeComments"
                            :key="c.id"
                            class="p-3 bg-gray-50 dark:bg-gray-900/70 border border-gray-200 dark:border-gray-700 rounded-lg text-xs hover:border-gray-300 dark:hover:border-gray-600 transition"
                        >
                            <div class="flex items-center justify-between gap-2 flex-wrap mb-1.5">
                                <div class="flex items-center gap-2">
                                    <div class="w-6 h-6 rounded-full bg-blue-600 text-white font-bold flex items-center justify-center text-[10px] shrink-0 shadow-sm">
                                        {{ ((c.author_name || c.author || c.user?.name || 'U')).substring(0, 1).toUpperCase() }}
                                    </div>
                                    <div class="flex items-baseline gap-1">
                                        <span class="font-bold text-gray-900 dark:text-gray-100">
                                            {{ c.author_name || c.author || c.user?.name || 'System User' }}
                                        </span>
                                        <span v-if="c.user?.email || c.author_email" class="text-[10px] text-gray-400">
                                            ({{ c.user?.email || c.author_email }})
                                        </span>
                                    </div>
                                    <span
                                        :class="[
                                            getPurposeBadgeClass(c.purpose),
                                            'px-1.5 py-0.5 rounded text-[9px] font-semibold border'
                                        ]"
                                    >
                                        {{ getPurposeLabel(c.purpose) }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-2 text-[10px] text-gray-400">
                                    <span>{{ c.formatted_created_at || c.created_at || 'Just now' }}</span>
                                    <button
                                        type="button"
                                        @click="deleteComment(c.id)"
                                        class="text-gray-400 hover:text-red-600 dark:hover:text-red-400 p-0.5 rounded transition"
                                        title="Delete note"
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            <p class="text-gray-700 dark:text-gray-200 pl-8 whitespace-pre-wrap leading-relaxed">
                                {{ c.comment }}
                            </p>
                        </div>
                    </div>

                    <!-- Empty state if no previous comments -->
                    <div v-else-if="!commentsLoading" class="p-4 text-center rounded-lg border border-dashed border-gray-300 dark:border-gray-700 bg-gray-50/60 dark:bg-gray-900/30 text-xs text-gray-500 dark:text-gray-400">
                        No previous notes attached to this contribution yet.
                    </div>
                </div>

                <!-- Section 2: Add New Comment Form -->
                <div class="mt-5 pt-4 border-t border-gray-200 dark:border-gray-700">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-gray-700 dark:text-gray-300 mb-3 flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Add New Audit Note
                    </h4>

                    <form @submit.prevent="submitComment" class="space-y-3">
                        <div>
                            <InputLabel value="Purpose / Category" class="text-xs font-semibold" />
                            <select
                                v-model="commentForm.purpose"
                                class="mt-1 w-full rounded-md border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-xs shadow-sm focus:border-emerald-500 focus:ring-emerald-500"
                            >
                                <option value="gpf_note">General Note</option>
                                <option value="audit_verification">Audit Verification</option>
                                <option value="adjustment_note">Adjustment Note</option>
                                <option value="settlement_note">Settlement Note</option>
                                <option value="correction">Correction / Update</option>
                            </select>
                        </div>

                        <div>
                            <InputLabel value="Note / Comment *" class="text-xs font-semibold" />
                            <textarea
                                v-model="commentForm.comment"
                                required
                                rows="3"
                                placeholder="Enter audit notes, verification details, or reference numbers..."
                                class="mt-1 w-full rounded-md border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-xs shadow-sm focus:border-emerald-500 focus:ring-emerald-500"
                            ></textarea>
                        </div>

                        <div class="flex justify-end gap-3 pt-2">
                            <SecondaryButton @click="showCommentModal = false">Close</SecondaryButton>
                            <PrimaryButton :disabled="commentFormSubmitting || !commentForm.comment.trim()" class="bg-emerald-600 hover:bg-emerald-700">
                                <span v-if="commentFormSubmitting" class="flex items-center gap-1">
                                    <svg class="animate-spin h-3.5 w-3.5" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                    </svg>
                                    Saving...
                                </span>
                                <span v-else>+ Add Note</span>
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </Modal>

        <!-- Modal: Settle Account -->
        <Modal :show="showSettleModal" @close="showSettleModal = false" max-width="md">
            <div class="p-6">
                <h3 class="text-base font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2 border-b border-gray-200 dark:border-gray-700 pb-3">
                    <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Settle Employee GPF Account
                </h3>

                <form @submit.prevent="submitSettle" class="mt-4 space-y-4">
                    <p class="text-xs text-gray-600 dark:text-gray-400">
                        Marking this account as settled indicates final disbursement/closure upon retirement or separation.
                    </p>

                    <div>
                        <InputLabel value="Settlement Date *" class="text-xs font-semibold" />
                        <TextInput
                            v-model="settleForm.settlementDate"
                            type="date"
                            required
                            class="mt-1 w-full text-xs"
                        />
                    </div>

                    <div>
                        <InputLabel value="Settlement Remarks" class="text-xs font-semibold" />
                        <TextInput
                            v-model="settleForm.remarks"
                            class="mt-1 w-full text-xs"
                        />
                    </div>

                    <div>
                        <InputLabel value="Audit Note / Settlement Order" class="text-xs font-semibold" />
                        <textarea
                            v-model="settleForm.comment"
                            rows="2"
                            placeholder="Reference office order or disbursement receipt number..."
                            class="mt-1 w-full rounded-md border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-xs shadow-sm"
                        ></textarea>
                    </div>

                    <div class="flex justify-end gap-3 pt-3">
                        <SecondaryButton @click="showSettleModal = false">Cancel</SecondaryButton>
                        <PrimaryButton :disabled="settleFormSubmitting" class="bg-purple-600 hover:bg-purple-700">
                            {{ settleFormSubmitting ? 'Settling...' : 'Confirm Settlement' }}
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </Modal>

    </AuthenticatedLayout>
</template>

<style scoped>
@media print {
    :global(body *) {
        visibility: hidden !important;
    }
    :global(#printable-gpf-statement),
    :global(#printable-gpf-statement *) {
        visibility: visible !important;
    }
    :global(#printable-gpf-statement) {
        position: fixed !important;
        left: 0 !important;
        top: 0 !important;
        width: 100vw !important;
        padding: 24px !important;
        background: white !important;
        color: black !important;
        box-shadow: none !important;
        border: none !important;
    }
}
</style>
