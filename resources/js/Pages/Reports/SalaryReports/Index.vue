<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue';
import html2canvas from 'html2canvas';
import { jsPDF } from 'jspdf';
import * as XLSX from 'xlsx';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import UniversalSearchInput from '@/Components/UniversalSearchInput.vue';
import { useSalaryReportStore } from '@/stores/salaryReport';
import { useSalarySummaryStore } from '@/stores/salarySummary';
import { getReportTemplate } from '@/Pages/Reports/reportTemplates';
import { formatMoney, MONTH_NAMES } from '@/Pages/Reports/reportTemplates/utils';

const props = defineProps({
    reportTypes: { type: Array, default: () => [] },
    payScales: { type: Array, default: () => [] },
    grades: { type: Array, default: () => [] },
    payrollGroupTypes: { type: Array, default: () => [] },
    userCategories: { type: Array, default: () => [] },
    departmentOffices: { type: Array, default: () => [] },
    currentMonth: { type: Number, default: () => (new Date()).getMonth() + 1 },
    currentYear: { type: Number, default: () => (new Date()).getFullYear() },
    officialPdfReady: { type: Boolean, default: false },
    genders: { type: Array, default: () => [] },
    jobStatuses: { type: Array, default: () => [] },
});

const store = useSalaryReportStore();
const summaryStore = useSalarySummaryStore();

// Left pane UI states
const showAdvancedFilters = ref(false);
const previewPage = ref(1);
const isContinuousView = ref(false);

// Month list
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

// Year list
const years = computed(() => {
    const curr = props.currentYear || (new Date()).getFullYear();
    return [curr - 2, curr - 1, curr, curr + 1, curr + 2];
});

// Grades filtered by selected Pay Scale
const filteredGrades = computed(() => {
    if (!store.filters.pay_scale_id) return props.grades;
    return props.grades.filter(g => String(g.pay_scale_id) === String(store.filters.pay_scale_id));
});

// Initial data fetch
onMounted(async () => {
    // Optional deep link from the Fixation Management page: preserve the
    // exact selected employee IDs and avoid selecting unrelated staff.
    const routeQuery = new URLSearchParams(window.location.search);
    if (routeQuery.get('reportType') === 'Salary Fixation Report') {
        store.reportType = 'Salary Fixation Report';
        const pageSize = Number(routeQuery.get('fixationRowsPerPage'));
        if (Number.isInteger(pageSize) && pageSize >= 1 && pageSize <= 15) fixationRowsPerPage.value = pageSize;
        const ids = routeQuery.get('fixationUserIds') || '';
        if (ids && /^\d+(,\d+){0,499}$/.test(ids)) {
            store.filters.fixationUserIds = ids;
            await store.fetchReportData(1);
            await store.fetchAllRecords();
            const intended = new Set(ids.split(','));
            store.selectedUserIds = store.allFilteredRecords.filter(r=>intended.has(String(r.user_id))).map(r=>r.user_id);
            if (routeQuery.get('fixationAutoPdf') === '1') {
                await nextTick();
                await exportFixationPdf(false);
            }
            return;
        }
    }
    await store.fetchReportData(1);
    // Auto-select all filtered records on first load so the entire report is immediately ready
    if (store.selectedUserIds.length === 0 && store.reports.total > 0) {
        await store.selectAllFiltered();
    }
});

// Debounce timer for search
let searchDebounceTimer = null;
const handleSearchInput = () => {
    clearTimeout(searchDebounceTimer);
    searchDebounceTimer = setTimeout(async () => {
        await store.fetchReportData(1);
        // Automatically select all matching filtered records for the new search
        await store.selectAllFiltered();
    }, 350);
};

// Immediate search execution (Enter, chip removal, clear all, paste)
const handleSearchImmediate = async () => {
    clearTimeout(searchDebounceTimer);
    await store.fetchReportData(1);
    await store.selectAllFiltered();
    previewPage.value = 1;
};

// Handle report type change
const handleReportTypeChange = async (type) => {
    if (type !== 'Salary Fixation Report') store.filters.fixationUserIds = '';
    if (type === 'Bonus report' || type === 'Bonus repot' || type === 'Bonus Bank Advice' || type === 'Bonus Bill') {
        store.filters.salaryGeneratedStatus = 'generated';
    }
    await store.setReportType(type);
    previewPage.value = 1;
};

// Apply filters
const applyFilters = async () => {
    await store.fetchReportData(1);
    await store.selectAllFiltered();
    previewPage.value = 1;
};

// Reset filters
const resetFilters = async () => {
    await store.resetFilters();
    await store.selectAllFiltered();
    previewPage.value = 1;
};

// Selection helpers
const toggleSelectUser = (id) => {
    store.toggleSelectUser(id);
};

const isUserSelected = (id) => {
    return store.selectedUserIds.some(uid => String(uid) === String(id));
};

const handleSelectAllCurrent = () => {
    store.selectAllCurrent();
};

const handleSelectAllFiltered = async () => {
    await store.selectAllFiltered();
};

const handleMasterCheckboxToggle = async () => {
    if (store.isAllFilteredSelected) {
        store.clearSelection();
    } else {
        await store.selectAllFiltered();
    }
};

const handleClearSelection = () => {
    store.clearSelection();
};

// All selected record objects from store (supports all filtered records across all pages)
const selectedRecords = computed(() => store.selectedRecords);

// Rows per A4 sheet for tabular reports
const TABULAR_ROWS_PER_PAGE = 15;
const bankAdviceRowsPerPage = ref(15);
const fixationRowsPerPage = ref(15);
const fixationExporting = ref(false);
const isFixationReport = computed(() => store.reportType === 'Salary Fixation Report');
const isSingleFixation = computed(() => isFixationReport.value && selectedRecords.value.length === 1);
const originalSingleFixationUrl = computed(() => selectedRecords.value[0]?.fixation_report?.original_pdf_url ||
    (selectedRecords.value[0]?.user_id ? route('salary-fixation.preview', selectedRecords.value[0].user_id) : null));
const singleFixationUrl = computed(() => originalSingleFixationUrl.value ? originalSingleFixationUrl.value + '?embed=1' : null);


// Active rows per page depending on report type
const activeRowsPerPage = computed(() => {
    if (store.reportType === 'Salary Fixation Report') {
        const value = Number(fixationRowsPerPage.value);
        return Number.isInteger(value) && value >= 1 && value <= 15 ? value : 15;
    }
    if (store.reportType === 'Bank Advice' || store.reportType === 'Bonus Bank Advice') {
        const val = parseInt(bankAdviceRowsPerPage.value, 10);
        return (val && val > 0) ? val : 15;
    }
    return TABULAR_ROWS_PER_PAGE;
});

// Dynamic Template Component
const currentTemplate = computed(() => {
    return getReportTemplate(store.reportType);
});

// Calculate total pages for right pane A4 preview
const totalPreviewPages = computed(() => {
    const totalSelected = selectedRecords.value.length;
    if (totalSelected === 0) return 1;

    if (['Salary Bill','Arrear & Deduction Statement'].includes(store.reportType) || store.reportType === 'Bonus report' || store.reportType === 'Bonus Bill') {
        // 1 employee bill per A4 sheet
        return totalSelected;
    }
    if (store.reportType === 'Salary Summary') {
        // Consolidated component total summary on 1 A4 sheet
        return 1;
    }
    // Tabular reports
    return Math.ceil(totalSelected / activeRowsPerPage.value) || 1;
});

// Watch totalPreviewPages to keep previewPage within valid range
watch(totalPreviewPages, (newTotal) => {
    if (previewPage.value > newTotal) {
        previewPage.value = Math.max(1, newTotal);
    }
});

// Next / Prev A4 preview page
const prevPreviewPage = () => {
    if (previewPage.value > 1) {
        previewPage.value--;
    }
};

const nextPreviewPage = () => {
    if (previewPage.value < totalPreviewPages.value) {
        previewPage.value++;
    }
};

// Active data slice for the current A4 preview page
const currentPageData = computed(() => {
    const records = selectedRecords.value;
    if (records.length === 0) return [];

    if (['Salary Bill','Arrear & Deduction Statement'].includes(store.reportType) || store.reportType === 'Bonus report' || store.reportType === 'Bonus Bill') {
        const activeRecord = records[previewPage.value - 1] || null;
        return activeRecord ? [activeRecord] : [];
    }

    if (store.reportType === 'Salary Summary') {
        return records;
    }

    const rowsPerPage = activeRowsPerPage.value;
    const start = (previewPage.value - 1) * rowsPerPage;
    return records.slice(start, start + rowsPerPage);
});

// Read-only export of the exact same landscape statement pages shown in Report Factory.
// Do not use the old autoTable summary path for Fixation Reports.
const fixationPageData = computed(() => {
    const source = selectedRecords.value;
    const size = activeRowsPerPage.value;
    const result = [];
    for (let offset = 0; offset < source.length; offset += size) {
        result.push({
            pageNumber: result.length + 1,
            data: source.slice(offset, offset + size),
            startIndex: offset,
        });
    }
    return result;
});

async function exportFixationPdf(isPrint = false) {
    if (!selectedRecords.value.length || fixationExporting.value) return;
    // Report creation is read-only and is permitted for draft, provisional and
    // approved records. Each template marks unresolved values as unverified.
    if (isSingleFixation.value) {
        if (!originalSingleFixationUrl.value) {
            alert('Employee report URL is unavailable.');
            return;
        }
        // Opening the canonical document synchronously from the user click avoids
        // racing an iframe script that may not have loaded yet.
        const separator = originalSingleFixationUrl.value.includes('?') ? '&' : '?';
        const url = originalSingleFixationUrl.value + separator + 'pdf=' + (isPrint ? 'print' : 'download');
        const tab = window.open(url, '_blank');
        if (tab) tab.opener = null;
        else window.location.assign(url);
        return;
    }
    // Open synchronously on click so browsers do not block a later print tab.
    const printTab = isPrint ? window.open('', '_blank') : null;
    fixationExporting.value = true;
    try {
        await nextTick();
        if (document.fonts?.ready) await document.fonts.ready;
        const nodes = [...document.querySelectorAll('.kau-fixation-export .kau-fixation-statement')];
        if (nodes.length !== fixationPageData.value.length) {
            throw new Error('Fixation PDF pages did not render completely.');
        }
        const doc = new jsPDF({orientation:'landscape',unit:'mm',format:'a4',compress:true});
        for (let i = 0; i < nodes.length; i++) {
            const canvas = await html2canvas(nodes[i], {
                scale: 1.6, backgroundColor:'#ffffff', useCORS:true, logging:false,
                scrollX:0, scrollY:0, windowWidth:Math.max(1500, document.documentElement.clientWidth),
                // The export DOM is parked off-screen. Move the *cloned* document
                // into the print viewport before capture: otherwise some Chrome
                // builds return a blank PDF or a cropped statement.
                onclone: clonedDocument => {
                    const exportStage = clonedDocument.querySelector('.kau-fixation-export');
                    if (exportStage) {
                        exportStage.style.left = '0';
                        exportStage.style.top = '0';
                        exportStage.style.zIndex = '0';
                        exportStage.style.position = 'absolute';
                    }
                },
            });
            if (i) doc.addPage('a4','landscape');
            // Source DOM is already 297mm x 210mm. Preserve the original aspect ratio.
            doc.addImage(canvas.toDataURL('image/jpeg', 0.94),'JPEG',0,0,297,210);
        }
        if (isPrint && printTab) {
            doc.autoPrint();
            printTab.location.href = URL.createObjectURL(doc.output('blob'));
        } else {
            doc.save(`KAU_Pay_Fixation_Statement_${selectedRecords.value.length}_Employees.pdf`);
        }
    } catch (error) {
        console.error('Fixation statement export failed', error);
        if (printTab && !printTab.closed) printTab.close();
        alert('Fixation PDF could not be prepared. Review the browser console for the rendering error.');
    } finally {
        fixationExporting.value = false;
    }
}

function exportFixationExcel() {
    const rows = selectedRecords.value.map((r,i) => {
        const f = r.fixation_report;
        const find = date => f?.stages?.find(s => String(s.effective_date).slice(0,10) === date)?.basic ?? '';
        const glance = f ? [
            `Grade ${f.grade} | 2015: ${f.old_scale_min}-${f.old_scale_max} | 2026: ${f.new_scale_min}-${f.new_scale_max}`,
            `June 2026 Basic ${f.old_basic}; July 2026 Basic ${find('2026-07-01')}; Increment ${f.increment_2026 ?? 'Not verified'}`,
            `January 2027 ${find('2027-01-01')}; July 2027 ${find('2027-07-01')}`
        ].join('\n') : 'No verified fixation calculation available';
        return {
            'SL':i+1,'Employee ID':r.employee_code,'Employee Name':r.name,
            'Designation':r.designation,'Department':r.department,
            'Pay Fixation at a Glance':glance,
        };
    });
    const workbook=XLSX.utils.book_new();
    const sheet=XLSX.utils.json_to_sheet(rows);
    sheet['!cols']=[{wch:6},{wch:15},{wch:28},{wch:24},{wch:26},{wch:100}];
    XLSX.utils.book_append_sheet(workbook,sheet,'Fixation Statement');
    XLSX.writeFile(workbook,'KAU_Pay_Fixation_Statement.xlsx');
}

// Formatted month-year string
const formattedMonthYear = computed(() => {
    const m = MONTH_NAMES[store.filters.monthId] || 'Month';
    return `${m} ${store.filters.salaryGenerationYear}`;
});

// Step 07 — canonical saved salary reporting. The endpoint reads POSTED bills
// only; these actions never mutate salary, GPF, arrears, or employee profiles.
const officialRowsPerPage = ref(25);
const isOfficialSalaryReport = computed(() => ['Salary Bill', 'Arrear & Deduction Statement'].includes(store.reportType));
function officialReportUrl(kind, format = 'html') {
    // Report only actual saved bills. The selection may contain old/provisional
    // records from a previous filter scope; never include estimated salaries.
    // mode=preview: every selected employee is eligible. Ungenerated employees
    // render as DRAFT_REGULAR_ONLY_PREVIEW rows on the server — never fabricated bills.
    const records = selectedRecords.value;
    if (!records.length) throw new Error('Select at least one employee for the report.');
    if (records.length > 500) throw new Error('At most 500 generated employees can be printed in one request.');
    const ids = records.map(r => Number(r.user_id));
    if (ids.some(id => !Number.isSafeInteger(id) || id < 1)) throw new Error('Invalid employee ID in generated selection.');
    if (new Set(ids).size !== ids.length) throw new Error('Duplicate employee selection.');
    const rows = Number(officialRowsPerPage.value);
    if (!Number.isInteger(rows) || rows < 1 || rows > 25) throw new Error('Rows per page must be 1–25.');
    const params = new URLSearchParams({
        ids: ids.join(','), year: String(store.filters.salaryGenerationYear),
        month: String(store.filters.monthId), kind,
        per_page: String(rows), format, mode: 'preview',
    });
    return route('reports.salary-official.index') + '?' + params.toString();
}
function openOfficialReport(kind, asPdf = false) {
    try {
        // No silent exclusion: ungenerated employees are included as draft preview rows.
        const url = officialReportUrl(kind, asPdf && props.officialPdfReady ? 'pdf' : 'html');
        const tab = window.open(url, '_blank');
        if (tab) tab.opener = null;
        else alert('Your browser blocked the report popup. Allow popups for this site and retry.');
    } catch (error) {
        alert(error.message);
    }
}
function officialPreviewUrlFor(record) {
    if (!isOfficialSalaryReport.value || !record?.user_id) return null;
    const kind = store.reportType === 'Arrear & Deduction Statement' ? 'arrear' : 'bill';
    return route('reports.salary-official.index') + '?' + new URLSearchParams({
        ids: String(record.user_id),
        year: String(store.filters.salaryGenerationYear),
        month: String(store.filters.monthId),
        kind, format: 'html', embed: '1', mode: 'preview',
    }).toString();
}
const officialPreviewUrl = computed(() => officialPreviewUrlFor(currentPageData.value[0]));

async function exportOfficialExcel() {
    try {
        const url = officialReportUrl(officialReportKind(), 'json');
        const response = await fetch(url, { credentials: 'same-origin', headers: {Accept: 'application/json'} });
        if (!response.ok) throw new Error(`Official report failed (${response.status}). Check posted Bill source consistency.`);
        const payload = await response.json();
        if (!Array.isArray(payload.records)) throw new Error('Invalid posted report data.');
        const wb = XLSX.utils.book_new();
        const summary = [['Employee Code','Name','Grade','Designation','Basic','Current Gross','Current Deductions','Current Net','Arrear Payable','Arrear Deductions','Net Arrear','Final Net','Source']];
        const components = [['Employee Code','Component','Direction','Current','Arrear','Total']];
        const arrears = [['Employee Code','Arrear Item','Code','Direction','Jul 2026','Aug 2026','Sep 2026','Posted Total','Evidence']];
        for (const r of payload.records) {
            const amount = n => n === null || n === undefined ? 'UNKNOWN' : Number(n);
            summary.push([r.employee_code, r.name, r.grade, r.designation, amount(r.basic), amount(r.current_gross),
                amount(r.current_deductions), amount(r.current_net), amount(r.arrear_payable),
                amount(r.arrear_deductions), amount(r.net_arrear), amount(r.net), r.source]);
            for (const direction of ['allowances','deductions']) {
                for (const line of r[direction] || []) {
                    components.push([r.employee_code, line.title, direction,
                        amount(line.current), amount(line.arrear), amount(line.total)]);
                }
            }
            for (const line of r.arrear_rows || []) {
                const month = key => line.month_amounts && Object.hasOwn(line.month_amounts,key)
                    ? amount(line.month_amounts[key]) : 'NOT ALLOCATED';
                arrears.push([r.employee_code, line.title, line.code, line.direction,
                    month('2026-07'),month('2026-08'),month('2026-09'),amount(line.total),line.evidence || '']);
            }
        }
        for (const [name, table] of [['Posted Bills',summary],['Component Lines',components],['Arrear Sources',arrears]]) {
            const sheet = XLSX.utils.aoa_to_sheet(table);
            sheet['!cols'] = table[0].map((_, i) => ({wch: i === 1 ? 36 : 17}));
            XLSX.utils.book_append_sheet(wb, sheet, name);
        }
        XLSX.writeFile(wb, `KAU_Official_Salary_${store.filters.salaryGenerationYear}_${store.filters.monthId}.xlsx`);
    } catch (error) { console.error(error); alert(error.message); }
}

function openOfficialCsv() {
    try { const tab = window.open(officialReportUrl(officialReportKind(), 'csv'), '_blank'); if (tab) tab.opener = null; else alert('Please allow popups for report downloads.'); }
    catch (error) { alert(error.message); }
}
function officialReportKind() {
    if (store.reportType === 'Arrear & Deduction Statement') return 'arrear';
    return selectedRecords.value.length === 1 ? 'bill' : 'bulk';
}

// Header Customization States
const defaultHeaderTitle = 'Khulna Agricultural University';
const defaultHeaderAddress = 'Temporary Office: 327, Jessore Road, North Mujgunni, Khalishpur, Khulna-9000';

const showHeaderCustomizer = ref(false);

const titlePresets = [
    'Khulna Agricultural University',
    'Office of the Comptroller of Accounts, KAU',
    'Office of the Registrar, KAU',
    'Finance and Accounts Division, KAU',
];

const addressPresets = [
    'Temporary Office: 327, Jessore Road, North Mujgunni, Khalishpur, Khulna-9000',
    'Permanent Campus: Chhoto Boyra, Khulna-9000, Bangladesh',
];

// Available years for selection
const availableYears = computed(() => {
    const curr = props.currentYear || (new Date()).getFullYear();
    const list = [];
    for (let y = curr - 5; y <= curr + 3; y++) {
        list.push(y);
    }
    return list;
});

const headerConfig = ref({
    title: defaultHeaderTitle,
    address: defaultHeaderAddress,
    subtitle: '',
    date: (new Date()).toLocaleDateString('en-US', { day: '2-digit', month: 'short', year: 'numeric' }),
    year: props.currentYear || (new Date()).getFullYear(),
});
const bankAdviceConfig = headerConfig; // alias

// Keep year aligned with store.filters.salaryGenerationYear when filter changes
watch(() => store.filters.salaryGenerationYear, (newYear) => {
    if (newYear) {
        headerConfig.value.year = newYear;
    }
});

// Helper to format category plural name nicely for report subtitles
function formatCategoryPlural(catName) {
    if (!catName) return '';
    const trimmed = catName.trim();
    const lower = trimmed.toLowerCase();
    if (lower === 'teacher') return 'Teachers';
    if (lower === 'officer') return 'Officers';
    if (lower === 'staff') return 'Staff';
    if (lower === 'faculty') return 'Faculty Members';
    if (lower.startsWith('class') || lower.startsWith('grade')) return `${trimmed} Employees`;
    if (lower.endsWith('s')) return trimmed;
    return `${trimmed}s`;
}

// Active filtered entity names
const activeCategoryName = computed(() => {
    if (!store.filters.user_category_id) return '';
    const cat = props.userCategories.find(c => String(c.id) === String(store.filters.user_category_id));
    return cat ? cat.name : '';
});

const activeDepartmentName = computed(() => {
    if (!store.filters.department_office_id) return '';
    const dept = props.departmentOffices.find(d => String(d.id) === String(store.filters.department_office_id));
    return dept ? dept.name : '';
});

const activeGroupName = computed(() => {
    if (!store.filters.payroll_group_type_id) return '';
    const g = props.payrollGroupTypes.find(item => String(item.id) === String(store.filters.payroll_group_type_id));
    return g ? g.group_name : '';
});

const activeGradeName = computed(() => {
    if (!store.filters.grade_id) return '';
    const gr = props.grades.find(item => String(item.id) === String(store.filters.grade_id));
    return gr ? gr.grade_name : '';
});

// Dynamic target text computed from active filters (e.g. "Teachers", "Officers", "Class 3 Employees", etc.)
const filterTargetDescription = computed(() => {
    const cat = activeCategoryName.value ? formatCategoryPlural(activeCategoryName.value) : '';
    const dept = activeDepartmentName.value;
    const group = activeGroupName.value;
    const grade = activeGradeName.value;

    let subject = '';

    if (cat) {
        subject = group ? `${group} ${cat}` : cat;
    } else if (group) {
        subject = `${group} Employees`;
    }

    if (grade) {
        if (subject) {
            subject = `${subject} (${grade})`;
        } else {
            subject = `${grade} Employees`;
        }
    }

    if (dept) {
        if (subject) {
            subject = `${subject} (${dept})`;
        } else {
            subject = `Teachers, Officers and Staff (${dept})`;
        }
    }

    return subject;
});

// Whether report has an eligible customizable header
const isHeaderCustomizable = computed(() => {
    return [
        'Bank Advice',
        'Bonus Bank Advice',
        'Salary Bill',
        'Bonus Bill',
        'Bonus report',
        'Salary Summary',
    ].includes(store.reportType);
});

// Subtitle presets based on report type, month, configured year, and active filters
const subtitlePresets = computed(() => {
    const m = MONTH_NAMES[store.filters.monthId] || 'Month';
    const y = headerConfig.value.year || store.filters.salaryGenerationYear;
    const target = filterTargetDescription.value || 'Teachers, Officers and Staff';
    const isFiltered = Boolean(filterTargetDescription.value);

    if (store.reportType === 'Salary Summary') {
        return [
            `Salary Summary Statement of ${target} for the month of ${m} ${y}`,
            `Consolidated Salary Summary for ${m} ${y}`,
            `Component-wise Monthly Salary Statement for ${m} ${y}`,
            `Salary Summary Statement for the month of ${m} ${y}`,
        ];
    }
    if (store.reportType === 'Bonus Bank Advice') {
        const presets = [
            `Bonus Bank Advice of ${target} for the month of ${m} ${y}`,
            `Festival Bonus Bank Advice of ${target} for the month of ${m} ${y}`,
            `Bonus Bank Advice for the month of ${m} ${y}`,
        ];
        if (!isFiltered) {
            presets.push(
                `Bonus Bank Advice of Faculty Members for the month of ${m} ${y}`,
                `Bonus Bank Advice of Administrative Officers for the month of ${m} ${y}`,
                `Bonus Bank Advice of Support Staff for the month of ${m} ${y}`,
            );
        }
        return presets;
    }
    if (store.reportType === 'Bonus report' || store.reportType === 'Bonus Bill') {
        return [
            `Festival Bonus Bill of ${target} for the month of ${m} ${y}`,
            `Bonus Bill Statement of ${target} for the month of ${m} ${y}`,
            `Individual Bonus Bill for ${m} ${y}`,
            `Bonus Disbursement Statement for ${m} ${y}`,
        ];
    }
    if (store.reportType === 'Salary Bill') {
        return [
            `Salary Bill of ${target} for the month of ${m} ${y}`,
            `Salary Bill for the month of ${m} ${y}`,
            `Individual Salary Bill Statement for ${m} ${y}`,
        ];
    }
    // Default: Bank Advice
    const advicePresets = [
        `Bank Advice of ${target} for the month of ${m} ${y}`,
        `Salary Bank Advice of ${target} for the month of ${m} ${y}`,
        `Bank Advice for the month of ${m} ${y}`,
    ];
    if (!isFiltered) {
        advicePresets.push(
            `Bank Advice of Faculty Members for the month of ${m} ${y}`,
            `Bank Advice of Administrative Officers for the month of ${m} ${y}`,
            `Bank Advice of Support Staff for the month of ${m} ${y}`,
            `Bank Advice of Contractual & Project Staff for the month of ${m} ${y}`,
        );
    }
    return advicePresets;
});

// Effective subtitle reflecting active filters or custom override
const effectiveHeaderSubtitle = computed(() => {
    if (headerConfig.value.subtitle && headerConfig.value.subtitle.trim()) {
        return headerConfig.value.subtitle.trim();
    }
    const m = MONTH_NAMES[store.filters.monthId] || 'Month';
    const y = headerConfig.value.year || store.filters.salaryGenerationYear;
    const target = filterTargetDescription.value;

    if (store.reportType === 'Salary Summary') {
        return target
            ? `Salary Summary Statement of ${target} for the month of ${m} ${y}`
            : `Salary Summary Statement for the month of ${m} ${y}`;
    }
    if (store.reportType === 'Bonus Bank Advice') {
        return target
            ? `Bonus Bank Advice of ${target} for the month of ${m} ${y}`
            : `Bonus Bank Advice of Teachers, Officers and Staff for the month of ${m} ${y}`;
    }
    if (store.reportType === 'Bonus report' || store.reportType === 'Bonus Bill') {
        return target
            ? `Bonus Bill Statement of ${target} for the month of ${m} ${y}`
            : `Bonus Bill Statement for the month of ${m} ${y}`;
    }
    if (store.reportType === 'Salary Bill') {
        return target
            ? `Salary Bill of ${target} for the month of ${m} ${y}`
            : `Salary Bill for the month of ${m} ${y}`;
    }
    return target
        ? `Bank Advice of ${target} for the month of ${m} ${y}`
        : `Bank Advice of Teachers, Officers and Staff for the month of ${m} ${y}`;
});
const effectiveBankAdviceSubtitle = effectiveHeaderSubtitle;

const setDatePreset = (preset) => {
    const now = new Date();
    const y = headerConfig.value.year || store.filters.salaryGenerationYear || now.getFullYear();
    const mIndex = (store.filters.monthId || (now.getMonth() + 1)) - 1;

    if (preset === 'today') {
        headerConfig.value.date = now.toLocaleDateString('en-US', { day: '2-digit', month: 'short', year: 'numeric' });
    } else if (preset === 'first') {
        const d = new Date(y, mIndex, 1);
        headerConfig.value.date = d.toLocaleDateString('en-US', { day: '2-digit', month: 'short', year: 'numeric' });
    } else if (preset === 'last') {
        const d = new Date(y, mIndex + 1, 0); // last day of month
        headerConfig.value.date = d.toLocaleDateString('en-US', { day: '2-digit', month: 'short', year: 'numeric' });
    }
};

const resetHeader = () => {
    headerConfig.value.title = defaultHeaderTitle;
    headerConfig.value.address = defaultHeaderAddress;
    headerConfig.value.subtitle = '';
    headerConfig.value.date = (new Date()).toLocaleDateString('en-US', { day: '2-digit', month: 'short', year: 'numeric' });
    headerConfig.value.year = store.filters.salaryGenerationYear || props.currentYear;
};
const resetBankAdviceHeader = resetHeader;

// Standardized props for the active single A4 sheet
const currentTemplateProps = computed(() => {
    const rowsPerPage = activeRowsPerPage.value;
    return {
        isBulk: selectedRecords.value.length > 1,
        data: currentPageData.value,
        allData: selectedRecords.value,
        startIndex: (previewPage.value - 1) * rowsPerPage,
        rowsPerPage: rowsPerPage,
        organizationName: headerConfig.value.title,
        organizationLogo: '/logo.png',
        organizationAddress: headerConfig.value.address,
        date: headerConfig.value.date,
        monthYear: formattedMonthYear.value,
        generatedBy: 'System Administrator',
        pageNumber: previewPage.value,
        totalPages: totalPreviewPages.value,
        reportType: store.reportType,
        customTitle: headerConfig.value.title,
        customAddress: headerConfig.value.address,
        customSubtitle: effectiveHeaderSubtitle.value,
        customDate: headerConfig.value.date,
        customYear: headerConfig.value.year,
    };
});

// Continuous multi-page slices for "Continuous View / Print Mode"
const continuousPages = computed(() => {
    const pages = [];
    const totalPages = totalPreviewPages.value;
    const records = selectedRecords.value;
    const rowsPerPage = activeRowsPerPage.value;

    for (let p = 1; p <= totalPages; p++) {
        let pageData = [];
        if (['Salary Bill','Arrear & Deduction Statement'].includes(store.reportType) || store.reportType === 'Bonus report' || store.reportType === 'Bonus Bill') {
            const r = records[p - 1];
            if (r) pageData = [r];
        } else if (store.reportType === 'Salary Summary') {
            pageData = records;
        } else {
            const start = (p - 1) * rowsPerPage;
            pageData = records.slice(start, start + rowsPerPage);
        }

        pages.push({
            pageNumber: p,
            props: {
                isBulk: records.length > 1,
                data: pageData,
                allData: records,
                startIndex: (p - 1) * rowsPerPage,
                rowsPerPage: rowsPerPage,
                organizationName: headerConfig.value.title,
                organizationLogo: '/logo.png',
                organizationAddress: headerConfig.value.address,
                date: headerConfig.value.date,
                monthYear: formattedMonthYear.value,
                generatedBy: 'System Administrator',
                pageNumber: p,
                totalPages: totalPages,
                reportType: store.reportType,
                customTitle: headerConfig.value.title,
                customAddress: headerConfig.value.address,
                customSubtitle: effectiveHeaderSubtitle.value,
                customDate: headerConfig.value.date,
                customYear: headerConfig.value.year,
            }
        });
    }

    return pages;
});

// Export handlers
const handleExportExcel = () => {
    if (isOfficialSalaryReport.value) return exportOfficialExcel();
    if (isFixationReport.value) return exportFixationExcel();
    const customHeader = {
        title: headerConfig.value.title,
        address: headerConfig.value.address,
        subtitle: effectiveHeaderSubtitle.value,
        date: headerConfig.value.date,
        year: headerConfig.value.year,
    };

    if (store.reportType === 'Salary Summary') {
        summaryStore.exportToExcel(selectedRecords.value, customHeader, formattedMonthYear.value);
        return;
    }

    store.exportToExcel(selectedRecords.value, customHeader);
};

const handleDownloadPdf = () => {
    if (isOfficialSalaryReport.value) return openOfficialReport(officialReportKind(), true);
    if (isFixationReport.value) return exportFixationPdf(false);
    const customHeader = {
        title: headerConfig.value.title,
        address: headerConfig.value.address,
        subtitle: effectiveHeaderSubtitle.value,
        date: headerConfig.value.date,
        year: headerConfig.value.year,
    };

    if (store.reportType === 'Salary Summary') {
        summaryStore.generatePdf({
            records: selectedRecords.value,
            isPrint: false,
            headerConfig: customHeader,
            monthYearStr: formattedMonthYear.value,
        });
        return;
    }

    store.generatePdf({
        records: selectedRecords.value,
        isPrint: false,
        rowsPerPage: activeRowsPerPage.value,
        headerConfig: customHeader,
    });
};

const handlePrintPdf = () => {
    if (isOfficialSalaryReport.value) return openOfficialReport(officialReportKind(), false);
    if (isFixationReport.value) return exportFixationPdf(true);
    const customHeader = {
        title: headerConfig.value.title,
        address: headerConfig.value.address,
        subtitle: effectiveHeaderSubtitle.value,
        date: headerConfig.value.date,
        year: headerConfig.value.year,
    };

    if (store.reportType === 'Salary Summary') {
        summaryStore.generatePdf({
            records: selectedRecords.value,
            isPrint: true,
            headerConfig: customHeader,
            monthYearStr: formattedMonthYear.value,
        });
        return;
    }

    store.generatePdf({
        records: selectedRecords.value,
        isPrint: true,
        rowsPerPage: activeRowsPerPage.value,
        headerConfig: customHeader,
    });
};
</script>

<template>
    <Head title="Report Factory" />

    <AuthenticatedLayout>
        <div class="py-5 px-3 sm:px-6 lg:px-8 max-w-[1720px] mx-auto space-y-4">

            <!-- Page Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-white dark:bg-gray-800 p-4 sm:p-5 rounded-md shadow-sm border border-gray-100 dark:border-gray-700/60">
                <div class="flex items-center gap-3">
                    <div class="p-2.5 rounded-md bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold text-gray-900 dark:text-white tracking-tight">
                            Report Factory
                        </h1>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Customizable institutional reports, live A4 sheet preview, multi-record filtering, and PDF / Excel export.
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2 text-xs">
                    <span class="px-2.5 py-1 rounded-md bg-slate-100 dark:bg-gray-700 text-slate-700 dark:text-slate-200 font-medium">
                        Period: <strong class="text-emerald-600 dark:text-emerald-400">{{ formattedMonthYear }}</strong>
                    </span>
                    <span class="px-2.5 py-1 rounded-md bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 font-medium">
                        Selected: <strong>{{ selectedRecords.length }}</strong> of {{ store.reports.total }}
                    </span>
                </div>
            </div>

            <!-- Two Horizontal Pane Layout -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">

                <!-- LEFT PANE: Filters & Employee Directory (5 cols on lg, 4 cols on xl) -->
                <div class="lg:col-span-5 xl:col-span-4 space-y-4">

                    <!-- Card 1: Configuration & Filter Controls -->
                    <div class="bg-white dark:bg-gray-800 rounded-md shadow-sm border border-gray-100 dark:border-gray-700/60 p-4 space-y-3.5">

                        <!-- Report Type Selector -->
                        <div>
                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1.5 uppercase tracking-wider">
                                Choose report type
                            </label>
                            <select
                                :value="store.reportType"
                                @change="handleReportTypeChange($event.target.value)"
                                class="w-full text-xs font-semibold rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-emerald-500 focus:ring-emerald-500 py-2 shadow-sm"
                            >
                                <option v-for="type in props.reportTypes" :key="type" :value="type">
                                    {{ type }}
                                </option>
                            </select>
                        </div>

                        <!-- Month, Year, and Profile Status Pickers -->
                        <div class="grid grid-cols-3 gap-2">
                            <div>
                                <label class="block text-[11px] font-medium text-gray-600 dark:text-gray-400 mb-1">
                                    Month
                                </label>
                                <select
                                    v-model.number="store.filters.monthId"
                                    @change="applyFilters"
                                    class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-emerald-500 focus:ring-emerald-500 py-1.5"
                                >
                                    <option v-for="m in months" :key="m.id" :value="m.id">
                                        {{ m.name }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[11px] font-medium text-gray-600 dark:text-gray-400 mb-1">
                                    Year
                                </label>
                                <select
                                    v-model.number="store.filters.salaryGenerationYear"
                                    @change="applyFilters"
                                    class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-emerald-500 focus:ring-emerald-500 py-1.5"
                                >
                                    <option v-for="y in years" :key="y" :value="y">
                                        {{ y }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[11px] font-medium text-gray-600 dark:text-gray-400 mb-1">
                                    Profile Status
                                </label>
                                <select
                                    v-model="store.filters.isActive"
                                    @change="applyFilters"
                                    class="w-full text-xs font-semibold rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-emerald-500 focus:ring-emerald-500 py-1.5 shadow-xs"
                                >
                                    <option value="true">Active</option>
                                    <option value="false">Inactive</option>
                                    <option value="">All</option>
                                </select>
                            </div>
                        </div>

                        <!-- Fixation statement pagination: A4 landscape, 10/15/custom per sheet. -->
                        <div v-if="isFixationReport" class="rounded-md border border-emerald-200 bg-emerald-50 p-3">
                            <label for="fixationRowsPerPage" class="text-xs font-semibold text-slate-700">Fixation Statement — Employees per Page</label>
                            <div class="mt-1 flex items-center gap-2">
                                <button type="button" @click="fixationRowsPerPage=10;previewPage=1"
                                    class="rounded border border-slate-300 bg-white px-3 py-1.5 text-xs">10</button>
                                <button type="button" @click="fixationRowsPerPage=15;previewPage=1"
                                    class="rounded border border-slate-300 bg-white px-3 py-1.5 text-xs">15</button>
                                <input id="fixationRowsPerPage" type="number" min="1" max="15" step="1"
                                    v-model.number="fixationRowsPerPage" @change="previewPage=1"
                                    class="w-20 rounded-md border-slate-300 px-2 py-1.5 text-sm text-center"
                                    title="Custom employees per A4 landscape page (1–15)" />
                                <span class="text-xs text-slate-600 whitespace-nowrap">A4 Landscape</span>
                            </div>
                        </div>

                        <!-- Universal Multi-User Search -->
                        <div>
                            <UniversalSearchInput
                                v-model="store.filters.search"
                                :total-matches="store.reports.total || 0"
                                :loading="store.loading"
                                input-id="reports_search"
                                placeholder="Name, email, code, phone, NID..."
                                @update:model-value="handleSearchInput"
                                @search="handleSearchImmediate"
                            />
                        </div>

                        <!-- Advanced Filter Drawer Toggle -->
                        <div class="pt-1">
                            <button
                                type="button"
                                @click="showAdvancedFilters = !showAdvancedFilters"
                                class="flex items-center justify-between w-full text-xs font-semibold text-gray-600 dark:text-gray-300 hover:text-emerald-600 dark:hover:text-emerald-400 py-1"
                            >
                                <span class="flex items-center gap-1.5">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                                    </svg>
                                    Advanced Filters
                                    <span v-if="store.activeFilterCount > 0" class="px-1.5 py-0.2 rounded-full text-[10px] bg-emerald-100 dark:bg-emerald-900/60 text-emerald-800 dark:text-emerald-200">
                                        {{ store.activeFilterCount }}
                                    </span>
                                </span>
                                <svg
                                    class="w-4 h-4 transition-transform duration-200"
                                    :class="showAdvancedFilters ? 'rotate-180' : ''"
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>

                            <!-- Advanced Filters Panel -->
                            <div v-show="showAdvancedFilters" class="mt-2.5 pt-2.5 border-t border-gray-100 dark:border-gray-700/60 space-y-2.5">
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-[11px] font-medium text-gray-600 dark:text-gray-400 mb-1">Department / Office</label>
                                        <select
                                            v-model="store.filters.department_office_id"
                                            @change="applyFilters"
                                            class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white py-1.5"
                                        >
                                            <option value="">All Departments</option>
                                            <option v-for="d in props.departmentOffices" :key="d.id" :value="d.id">{{ d.name }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-medium text-gray-600 dark:text-gray-400 mb-1">Employee Class</label>
                                        <select
                                            v-model="store.filters.user_category_id"
                                            @change="applyFilters"
                                            class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white py-1.5"
                                        >
                                            <option value="">All Employee Classes</option>
                                            <option v-for="c in props.userCategories" :key="c.id" :value="c.id">{{ c.name }}</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-[11px] font-medium text-gray-600 dark:text-gray-400 mb-1">Profile Status</label>
                                        <select
                                            v-model="store.filters.isActive"
                                            @change="applyFilters"
                                            class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white py-1.5"
                                        >
                                            <option value="true">Active Only</option>
                                            <option value="false">Inactive Only</option>
                                            <option value="">All Statuses</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-medium text-gray-600 dark:text-gray-400 mb-1">Bill Status</label>
                                        <select
                                            v-model="store.filters.salaryGeneratedStatus"
                                            :disabled="store.reportType === 'Bank Advice'"
                                            @change="applyFilters"
                                            class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white py-1.5"
                                        >
                                            <option value="all">All Records</option>
                                            <option value="generated">Generated Only</option>
                                            <option value="not_generated">Ungenerated Only</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-[11px] font-medium text-gray-600 dark:text-gray-400 mb-1">Tax Filter</label>
                                        <select
                                            v-model="store.filters.taxFilter"
                                            @change="applyFilters"
                                            class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white py-1.5"
                                        >
                                            <option value="all">All</option>
                                            <option value="has_tax">With Tax</option>
                                            <option value="no_tax">Without Tax</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-medium text-gray-600 dark:text-gray-400 mb-1">GPF Filter</label>
                                        <select
                                            v-model="store.filters.gpfFilter"
                                            @change="applyFilters"
                                            class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white py-1.5"
                                        >
                                            <option value="all">All</option>
                                            <option value="has_gpf">With GPF</option>
                                            <option value="no_gpf">Without GPF</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="flex justify-end pt-1">
                                    <button
                                        type="button"
                                        @click="resetFilters"
                                        class="text-xs text-rose-600 hover:text-rose-700 dark:text-rose-400 dark:hover:text-rose-300 font-medium hover:underline transition-colors"
                                    >
                                        Reset Filters
                                    </button>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- Card 2: Employee Selection Directory -->
                    <div class="bg-white dark:bg-gray-800 rounded-md shadow-sm border border-gray-100 dark:border-gray-700/60 overflow-hidden">

                        <!-- Directory Top Header -->
                        <div class="p-3 bg-slate-50 dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700/60 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    :checked="store.isAllFilteredSelected"
                                    @change="handleMasterCheckboxToggle"
                                    id="masterCheckbox"
                                    class="h-4 w-4 rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-emerald-600 focus:ring-emerald-500 cursor-pointer"
                                />
                                <label for="masterCheckbox" class="text-xs font-bold text-gray-800 dark:text-gray-200 cursor-pointer">
                                    Select All ({{ store.reports.total }})
                                </label>
                            </div>

                            <div class="flex items-center gap-1.5">
                                <button
                                    type="button"
                                    @click="handleSelectAllFiltered"
                                    title="Select all matching employees across all pages"
                                    class="text-[11px] px-2.5 py-1 rounded-md bg-emerald-600 hover:bg-emerald-700 text-white font-semibold shadow-xs transition flex items-center gap-1"
                                >
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                    </svg>
                                    Select All ({{ store.reports.total }})
                                </button>
                                <button
                                    type="button"
                                    @click="handleSelectAllCurrent"
                                    title="Select only the records on current page"
                                    class="text-[11px] px-2 py-1 rounded-md bg-slate-100 dark:bg-gray-700 text-slate-700 dark:text-slate-200 font-medium hover:bg-slate-200 dark:hover:bg-gray-600 transition-colors"
                                >
                                    Current Page
                                </button>
                                <button
                                    v-if="store.selectedUserIds.length > 0"
                                    type="button"
                                    @click="handleClearSelection"
                                    class="text-[11px] px-2 py-1 rounded-md bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-300 hover:bg-rose-100 dark:hover:bg-rose-900/60 font-medium transition-colors"
                                >
                                    Clear
                                </button>
                            </div>
                        </div>

                        <!-- Employee List -->
                        <div class="divide-y divide-gray-100 dark:divide-gray-700/50 max-h-[520px] overflow-y-auto">
                            <div
                                v-if="store.reports.data.length === 0"
                                class="p-8 text-center text-xs text-gray-400 dark:text-gray-500"
                            >
                                No employees found matching criteria.
                            </div>

                            <div
                                v-for="user in store.reports.data"
                                :key="user.user_id"
                                @click="toggleSelectUser(user.user_id)"
                                :class="[
                                    isUserSelected(user.user_id)
                                        ? 'bg-emerald-50/50 dark:bg-emerald-950/30'
                                        : 'hover:bg-slate-50/70 dark:hover:bg-gray-700/40'
                                ]"
                                class="p-2.5 flex items-center justify-between gap-2.5 cursor-pointer transition-colors"
                            >
                                <div class="flex items-start gap-2.5 min-w-0 flex-1">
                                    <input
                                        type="checkbox"
                                        :checked="isUserSelected(user.user_id)"
                                        @click.stop
                                        @change="toggleSelectUser(user.user_id)"
                                        class="h-3.5 w-3.5 mt-0.5 rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-emerald-600 focus:ring-emerald-500 cursor-pointer shrink-0"
                                    />
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-1.5 flex-wrap">
                                            <span class="text-xs font-semibold text-gray-900 dark:text-white truncate">
                                                {{ user.name }}
                                            </span>
                                            <span class="font-mono text-[10px] text-emerald-700 dark:text-emerald-400 font-medium shrink-0">
                                                #{{ user.employee_code }}
                                            </span>
                                        </div>
                                        <div class="text-[10px] text-gray-500 dark:text-gray-400 truncate flex items-center gap-1 mt-0.5">
                                            <span class="truncate">{{ ((store.reportType === 'Bonus report' || store.reportType === 'Bonus Bill' || store.reportType === 'Bonus Bank Advice') && user.bonus_title) ? user.bonus_title : user.designation }}</span>
                                            <span v-if="user.department && user.department !== 'N/A'">•</span>
                                            <span v-if="user.department && user.department !== 'N/A'" class="truncate text-gray-400 dark:text-gray-500">{{ user.department }}</span>
                                        </div>
                                        <div v-if="user.email" class="text-[9.5px] text-gray-500 dark:text-gray-400 truncate flex items-center gap-1 mt-0.5" :title="user.email">
                                            <svg class="w-2.5 h-2.5 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                            </svg>
                                            <span class="truncate">{{ user.email }}</span>
                                        </div>
                                        <div class="flex items-center gap-1.5 flex-wrap mt-1">
                                            <span
                                                v-if="user.user_category && user.user_category !== 'N/A'"
                                                class="px-1.5 py-0.2 rounded text-[9px] font-medium bg-blue-50 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300 border border-blue-200/60 dark:border-blue-800/40"
                                                title="Employee Class"
                                            >
                                                {{ user.user_category }}
                                            </span>
                                            <span
                                                v-if="user.religion && user.religion !== 'N/A'"
                                                class="px-1.5 py-0.2 rounded text-[9px] font-medium bg-purple-50 text-purple-700 dark:bg-purple-950/60 dark:text-purple-300 border border-purple-200/60 dark:border-purple-800/40"
                                                title="Religion"
                                            >
                                                {{ user.religion }}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div class="text-right flex-shrink-0">
                                    <div class="text-xs font-bold text-gray-900 dark:text-white tabular-nums">
                                        Tk. {{ formatMoney(user.salary_total) }}
                                    </div>
                                    <span
                                        v-if="user.is_generated"
                                        class="inline-block px-1.5 py-0.2 text-[9px] font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-300 rounded"
                                    >
                                        Generated
                                    </span>
                                    <span
                                        v-else
                                        class="inline-block px-1.5 py-0.2 text-[9px] font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-300 rounded"
                                    >
                                        Provisional
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Left-Pane Pagination -->
                        <div class="p-2.5 bg-slate-50 dark:bg-gray-800 border-t border-gray-100 dark:border-gray-700/60 flex items-center justify-between text-xs">
                            <span class="text-gray-500 dark:text-gray-400 text-[11px]">
                                Page {{ store.reports.current_page }} of {{ store.reports.last_page }}
                            </span>
                            <div class="flex items-center gap-1">
                                <button
                                    type="button"
                                    :disabled="store.reports.current_page <= 1"
                                    @click="store.fetchReportData(store.reports.current_page - 1)"
                                    class="px-2 py-0.5 rounded border border-gray-300 dark:border-gray-600 text-[11px] text-gray-700 dark:text-gray-200 disabled:opacity-40 hover:bg-white dark:hover:bg-gray-700 dark:bg-gray-700/50 transition-colors"
                                >
                                    Prev
                                </button>
                                <button
                                    type="button"
                                    :disabled="store.reports.current_page >= store.reports.last_page"
                                    @click="store.fetchReportData(store.reports.current_page + 1)"
                                    class="px-2 py-0.5 rounded border border-gray-300 dark:border-gray-600 text-[11px] text-gray-700 dark:text-gray-200 disabled:opacity-40 hover:bg-white dark:hover:bg-gray-700 dark:bg-gray-700/50 transition-colors"
                                >
                                    Next
                                </button>
                            </div>
                        </div>

                    </div>

                </div>

                <!-- RIGHT PANE: A4 Sheet Paper Simulation & Live Preview -->
                <div class="lg:col-span-7 xl:col-span-8 space-y-3">

                    <!-- Right Toolbar -->
                    <div class="bg-white dark:bg-gray-800 rounded-md shadow-sm border border-gray-100 dark:border-gray-700/60 p-3 sm:p-4 flex flex-wrap items-center justify-between gap-3 sticky top-3 z-20">

                        <!-- Report Info & Page Navigator -->
                        <div class="flex items-center gap-3 flex-wrap">
                            <div>
                                <span class="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wider">
                                    {{ store.reportType }}
                                </span>
                                <span class="ml-2 px-2 py-0.5 rounded-full text-[10px] bg-slate-100 dark:bg-gray-700 text-slate-700 dark:text-slate-300">
                                    {{ selectedRecords.length }} records
                                </span>
                            </div>

                            <!-- Bank Advice / Bonus Bank Advice Rows per Page Selector -->
                            <div v-if="store.reportType === 'Bank Advice' || store.reportType === 'Bonus Bank Advice'" class="flex items-center gap-1.5 pl-3 border-l border-gray-200 dark:border-gray-700">
                                <label for="bankAdviceRowsPerPage" class="text-xs font-semibold text-gray-700 dark:text-gray-300 whitespace-nowrap">
                                    Rows / Page:
                                </label>
                                <input
                                    id="bankAdviceRowsPerPage"
                                    type="number"
                                    min="1"
                                    max="100"
                                    v-model.number="bankAdviceRowsPerPage"
                                    class="w-16 text-xs text-center py-1 px-1.5 rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:border-emerald-500 focus:ring-emerald-500 font-semibold shadow-xs"
                                    title="Records per page for Bank Advice"
                                />
                            </div>

                            <!-- A4 Pagination Buttons (When in single page preview) -->
                            <div v-if="!isContinuousView && totalPreviewPages > 1" class="flex items-center gap-1.5 pl-3 border-l border-gray-200 dark:border-gray-700">
                                <button
                                    type="button"
                                    :disabled="previewPage <= 1"
                                    @click="prevPreviewPage"
                                    class="p-1 rounded border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-30"
                                    title="Previous A4 Page"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                                    </svg>
                                </button>

                                <span class="text-xs font-semibold text-gray-700 dark:text-gray-300 px-1">
                                    Page {{ previewPage }} of {{ totalPreviewPages }}
                                </span>

                                <button
                                    type="button"
                                    :disabled="previewPage >= totalPreviewPages"
                                    @click="nextPreviewPage"
                                    class="p-1 rounded border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-30"
                                    title="Next A4 Page"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                    </svg>
                                </button>
                            </div>

                            <!-- Continuous view toggle if multi-page -->
                            <button
                                v-if="totalPreviewPages > 1"
                                type="button"
                                @click="isContinuousView = !isContinuousView"
                                :class="isContinuousView ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200' : 'bg-slate-100 text-slate-700 dark:bg-gray-700 dark:text-gray-300 hover:bg-slate-200 dark:hover:bg-gray-600'"
                                class="text-[11px] font-medium px-2 py-1 rounded transition-colors"
                            >
                                {{ isContinuousView ? 'Show Single Page' : 'Show All Pages' }}
                            </button>

                            <!-- Customize Header toggle button -->
                            <button
                                v-if="isHeaderCustomizable"
                                type="button"
                                @click="showHeaderCustomizer = !showHeaderCustomizer"
                                :class="showHeaderCustomizer ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200 border-emerald-300' : 'bg-slate-100 text-slate-700 dark:bg-gray-700 dark:text-gray-300 border-gray-300'"
                                class="text-[11px] font-medium px-2.5 py-1 rounded border transition-colors flex items-center gap-1.5"
                                title="Toggle Report Header & Date Customization panel"
                            >
                                <svg class="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                                <span>Customize Header</span>
                            </button>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex items-center gap-2">
                            <!-- Download Excel -->
                            <button
                                type="button"
                                :disabled="selectedRecords.length === 0 || store.exportingExcel"
                                @click="handleExportExcel"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-teal-600 hover:bg-teal-700 text-white text-xs font-medium shadow-sm transition disabled:opacity-40"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <span>{{ store.exportingExcel ? 'Exporting...' : 'Excel' }}</span>
                            </button>

                            <!-- Step 07: read-only bulk salary overview, one A4 landscape sheet per selected page. -->
                            <template v-if="isOfficialSalaryReport">
                                <label class="text-[11px] text-gray-600 dark:text-gray-300">Rows/page</label>
                                <input type="number" min="1" max="25" step="1" v-model.number="officialRowsPerPage"
                                    class="w-14 text-xs rounded border-gray-300 dark:bg-gray-700 dark:text-white py-1" title="Custom rows per A4 landscape page (1–25)" />
                                <button type="button" :disabled="!selectedRecords.length"
                                    @click="openOfficialReport('bulk', false)"
                                    class="px-3 py-1.5 text-xs text-white bg-slate-700 hover:bg-slate-800 rounded disabled:opacity-40">
                                    Bulk Overview
                                </button>
                                <button type="button" :disabled="!selectedRecords.length" @click="openOfficialReport(officialReportKind(), false)"
                                    class="px-3 py-1.5 text-xs text-white bg-teal-700 hover:bg-teal-800 rounded disabled:opacity-40">
                                    Official Print
                                </button>
                                <button type="button" :disabled="!selectedRecords.length" @click="openOfficialCsv()"
                                    class="px-3 py-1.5 text-xs text-white bg-indigo-700 hover:bg-indigo-800 rounded disabled:opacity-40">
                                    CSV
                                </button>
                                <button type="button" :disabled="!selectedRecords.length" @click="openOfficialReport('bulk', true)"
                                    class="px-3 py-1.5 text-xs text-white bg-rose-700 hover:bg-rose-800 rounded disabled:opacity-40">
                                    Bulk PDF
                                </button>
                            </template>

                            <!-- Download PDF -->
                            <button
                                type="button"
                                :disabled="selectedRecords.length === 0 || store.generatingPdf"
                                @click="handleDownloadPdf"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-rose-600 hover:bg-rose-700 text-white text-xs font-medium shadow-sm transition disabled:opacity-40"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                </svg>
                                <span>{{ store.generatingPdf ? 'Generating...' : 'PDF' }}</span>
                            </button>

                            <!-- Print A4 -->
                            <button
                                type="button"
                                :disabled="selectedRecords.length === 0 || store.generatingPdf"
                                @click="handlePrintPdf"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-medium shadow-sm transition disabled:opacity-40"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                                </svg>
                                <span>Print A4</span>
                            </button>
                        </div>

                    </div>

                    <!-- Header Customization Panel -->
                    <div
                        v-if="isHeaderCustomizable && showHeaderCustomizer"
                        class="bg-white dark:bg-gray-800 rounded-md shadow-sm border border-emerald-200/80 dark:border-emerald-800/60 p-3.5 sm:p-4 transition-all space-y-3"
                    >
                        <div class="flex items-center justify-between border-b border-gray-100 dark:border-gray-700/60 pb-2.5">
                            <div class="flex items-center gap-2">
                                <span class="p-1.5 rounded bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                </span>
                                <div>
                                    <h3 class="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wider">
                                        {{ store.reportType }} Header &amp; Metadata
                                    </h3>
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400">
                                        Select presets or type custom values to customize the report header.
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    @click="resetHeader"
                                    class="text-[11px] px-2.5 py-1 rounded bg-slate-100 dark:bg-gray-700 hover:bg-slate-200 text-slate-700 dark:text-slate-200 font-medium transition-colors"
                                    title="Reset header fields to default"
                                >
                                    Reset Default
                                </button>
                                <button
                                    type="button"
                                    @click="showHeaderCustomizer = !showHeaderCustomizer"
                                    class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 p-1"
                                    title="Collapse header settings"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- Inputs Grid -->
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-12 gap-3 text-xs">

                            <!-- 1. Organization Title (4 cols) -->
                            <div class="lg:col-span-4 space-y-1">
                                <label class="block text-[11px] font-bold text-gray-700 dark:text-gray-300">
                                    Title (Institution / Office)
                                </label>
                                <select
                                    @change="bankAdviceConfig.title = $event.target.value"
                                    class="w-full text-xs rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-emerald-500 focus:ring-emerald-500 py-1 mb-1 shadow-xs"
                                >
                                    <option value="" disabled>-- Select Title Preset --</option>
                                    <option v-for="t in titlePresets" :key="t" :value="t" :selected="bankAdviceConfig.title === t">
                                        {{ t }}
                                    </option>
                                </select>
                                <input
                                    type="text"
                                    v-model="bankAdviceConfig.title"
                                    placeholder="Or type custom title..."
                                    class="w-full text-xs rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-emerald-500 focus:ring-emerald-500 py-1 shadow-xs font-semibold"
                                />
                            </div>

                            <!-- 2. Organization Address (4 cols) -->
                            <div class="lg:col-span-4 space-y-1">
                                <label class="block text-[11px] font-bold text-gray-700 dark:text-gray-300">
                                    Office / Campus Address
                                </label>
                                <select
                                    @change="bankAdviceConfig.address = $event.target.value"
                                    class="w-full text-xs rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-emerald-500 focus:ring-emerald-500 py-1 mb-1 shadow-xs"
                                >
                                    <option value="" disabled>-- Select Address Preset --</option>
                                    <option v-for="a in addressPresets" :key="a" :value="a" :selected="bankAdviceConfig.address === a">
                                        {{ a }}
                                    </option>
                                </select>
                                <input
                                    type="text"
                                    v-model="bankAdviceConfig.address"
                                    placeholder="Or type custom address..."
                                    class="w-full text-xs rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-emerald-500 focus:ring-emerald-500 py-1 shadow-xs"
                                />
                            </div>

                            <!-- 3. Subtitle (4 cols) -->
                            <div class="lg:col-span-4 space-y-1">
                                <label class="block text-[11px] font-bold text-gray-700 dark:text-gray-300">
                                    Report Subtitle
                                </label>
                                <select
                                    @change="bankAdviceConfig.subtitle = $event.target.value"
                                    class="w-full text-xs rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-emerald-500 focus:ring-emerald-500 py-1 mb-1 shadow-xs"
                                >
                                    <option value="" disabled>-- Select Subtitle Preset --</option>
                                    <option v-for="s in subtitlePresets" :key="s" :value="s" :selected="bankAdviceConfig.subtitle === s">
                                        {{ s }}
                                    </option>
                                </select>
                                <input
                                    type="text"
                                    v-model="bankAdviceConfig.subtitle"
                                    :placeholder="effectiveHeaderSubtitle"
                                    class="w-full text-xs rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-emerald-500 focus:ring-emerald-500 py-1 shadow-xs"
                                />
                            </div>

                            <!-- 4. Year & Date Controls (12 cols) -->
                            <div class="lg:col-span-12 grid grid-cols-1 sm:grid-cols-12 gap-3 pt-1 border-t border-gray-100 dark:border-gray-700/40">
                                <!-- Year -->
                                <div class="sm:col-span-4 space-y-1">
                                    <label class="block text-[11px] font-bold text-gray-700 dark:text-gray-300">
                                        Advice Year
                                    </label>
                                    <div class="flex items-center gap-2">
                                        <select
                                            v-model.number="bankAdviceConfig.year"
                                            class="w-1/2 text-xs rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-emerald-500 focus:ring-emerald-500 py-1 shadow-xs font-semibold"
                                        >
                                            <option v-for="y in availableYears" :key="y" :value="y">
                                                {{ y }}
                                            </option>
                                        </select>
                                        <input
                                            type="number"
                                            v-model.number="bankAdviceConfig.year"
                                            placeholder="Year"
                                            class="w-1/2 text-xs rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-emerald-500 focus:ring-emerald-500 py-1 shadow-xs font-semibold"
                                        />
                                    </div>
                                </div>

                                <!-- Date -->
                                <div class="sm:col-span-8 space-y-1">
                                    <label class="block text-[11px] font-bold text-gray-700 dark:text-gray-300">
                                        Report Date
                                    </label>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <input
                                            type="text"
                                            v-model="bankAdviceConfig.date"
                                            placeholder="e.g. 18 Sep 2026"
                                            class="flex-1 min-w-[140px] text-xs rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-emerald-500 focus:ring-emerald-500 py-1 shadow-xs"
                                        />
                                        <div class="flex items-center gap-1">
                                            <button
                                                type="button"
                                                @click="setDatePreset('today')"
                                                class="px-2 py-1 text-[10px] font-medium rounded bg-emerald-50 hover:bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:hover:bg-emerald-900/60 dark:text-emerald-300 transition-colors"
                                            >
                                                Today
                                            </button>
                                            <button
                                                type="button"
                                                @click="setDatePreset('first')"
                                                class="px-2 py-1 text-[10px] font-medium rounded bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-gray-700 dark:text-slate-300 transition-colors"
                                            >
                                                1st of Month
                                            </button>
                                            <button
                                                type="button"
                                                @click="setDatePreset('last')"
                                                class="px-2 py-1 text-[10px] font-medium rounded bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-gray-700 dark:text-slate-300 transition-colors"
                                            >
                                                Month End
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- A4 Sheet Stage Canvas -->
                    <div class="bg-slate-200/80 dark:bg-slate-900/90 rounded-md p-4 sm:p-6 lg:p-8 min-h-[800px] overflow-x-auto flex flex-col items-center border border-slate-300/60 dark:border-gray-800">

                        <!-- Graceful Error / Empty State when 0 records selected -->
                        <div
                            v-if="selectedRecords.length === 0"
                            class="my-auto max-w-md w-full bg-white dark:bg-gray-800 rounded-md p-8 text-center shadow-lg border border-slate-200 dark:border-gray-700 space-y-3"
                        >
                            <div class="w-12 h-12 rounded-full bg-amber-100 dark:bg-amber-900/40 text-amber-600 dark:text-amber-400 mx-auto flex items-center justify-center">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <h3 class="text-base font-bold text-gray-900 dark:text-white">
                                No Employees Selected
                            </h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                Please select one or more employees from the directory on the left pane to render the <strong>{{ store.reportType }}</strong> on an authentic A4 sheet.
                            </p>
                            <div class="pt-2 flex flex-wrap items-center justify-center gap-2">
                                <button
                                    type="button"
                                    @click="handleSelectAllFiltered"
                                    class="px-4 py-2 rounded-md bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold shadow transition"
                                >
                                    Select All ({{ store.reports.total }}) Records
                                </button>
                                <button
                                    type="button"
                                    @click="handleSelectAllCurrent"
                                    class="px-3 py-2 rounded-md bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-gray-700 dark:text-slate-200 text-xs font-medium transition"
                                >
                                    Current Page
                                </button>
                            </div>
                        </div>

                        <!-- Continuous Multi-Page View -->
                        <div
                            v-else-if="isContinuousView"
                            class="space-y-8 w-full flex flex-col items-center"
                        >
                            <div
                                v-for="p in continuousPages"
                                :key="p.pageNumber"
                                class="w-full relative" :style="(isFixationReport && !isSingleFixation) || (isOfficialSalaryReport && store.reportType === 'Arrear & Deduction Statement') ? {maxWidth: '297mm',overflowX: 'auto'} : {maxWidth:'210mm'}"
                            >
                                <div class="absolute -top-5 left-2 text-[10px] font-bold text-slate-500 tracking-wider uppercase">
                                    A4 Page {{ p.pageNumber }} of {{ totalPreviewPages }}
                                </div>
                                <iframe v-if="isSingleFixation && singleFixationUrl" :src="singleFixationUrl" title="Official Pay Fixation Form" class="fixation-single-frame w-full border-0 bg-white" style="height:297mm"></iframe>
                                <iframe v-else-if="officialPreviewUrlFor(p.props.data?.[0])" :src="officialPreviewUrlFor(p.props.data?.[0])" title="Saved Official Salary Report" class="w-full border-0 bg-white" :style="{height: store.reportType === 'Arrear & Deduction Statement' ? '210mm' : '297mm'}"></iframe>
                                <component v-else :is="currentTemplate" v-bind="p.props" />
                            </div>
                        </div>

                        <!-- Single Page Preview -->
                        <div
                            v-else
                            class="w-full" :style="(isFixationReport && !isSingleFixation) || (isOfficialSalaryReport && store.reportType === 'Arrear & Deduction Statement') ? {maxWidth: '297mm', overflowX:'auto'} : {maxWidth:'210mm'}"
                        >
                            <div v-if="isSingleFixation" class="bg-white shadow-sm">
                                <iframe v-if="singleFixationUrl" :src="singleFixationUrl" title="Official Pay Fixation Form" class="fixation-single-frame w-full border-0" style="height:297mm"></iframe>
                                <p v-else class="p-5 text-rose-700">No employee fixation form URL available.</p>
                            </div>
                            <iframe v-else-if="officialPreviewUrl" :src="officialPreviewUrl" title="Saved Official Salary Report" class="w-full border-0 bg-white" :style="{height: store.reportType === 'Arrear & Deduction Statement' ? '210mm' : '297mm'}"></iframe>
                            <component v-else :is="currentTemplate" v-bind="currentTemplateProps" />
                        </div>

                    </div>

                </div>

            </div>

        </div>
        <!-- Off-screen statement pages: the same Vue component as preview, all pages
             rendered for PDF export without changing current page/filters. -->
        <div v-if="isFixationReport && !isSingleFixation && selectedRecords.length" class="kau-fixation-export" aria-hidden="true">
            <component v-for="page in fixationPageData" :key="'pdf-'+page.pageNumber"
                :is="currentTemplate" :data="page.data" :startIndex="page.startIndex"
                :allData="selectedRecords" :rowsPerPage="activeRowsPerPage"
                :pageNumber="page.pageNumber" :totalPages="totalPreviewPages"
                organizationLogo="/logo.png" />
        </div>
    </AuthenticatedLayout>
</template>

<style>
.kau-fixation-export { position:fixed; left:-20000px; top:0; width:297mm; z-index:-1; pointer-events:none; }
@media print {
    body * {
        visibility: hidden;
    }
    .a4-sheet, .a4-sheet * {
        visibility: visible;
    }
    .a4-sheet {
        position: absolute;
        left: 0;
        top: 0;
        width: 100% !important;
        margin: 0 !important;
        padding: 10mm !important;
    }
}
</style>
