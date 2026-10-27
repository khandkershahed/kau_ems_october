<script setup>
import { ref, onMounted, watch, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import Checkbox from '@/Components/Checkbox.vue';
import { useEmployeeStore } from '@/stores/employee';
import { useAdminStore } from '@/stores/admin';
import { useEmployeeBulkUpdateStore } from '@/stores/employeeBulkUpdate';
import { useEmployeeSearchStore } from '@/stores/employeeSearch';
import BulkProfileUpdateModal from './Modal/BulkProfileUpdateModal.vue';
import UniversalSearchInput from '@/Components/UniversalSearchInput.vue';
import { storeToRefs } from 'pinia';
import * as XLSX from 'xlsx';

const props = defineProps({
    departmentOffices: {
        type: Array,
        default: () => []
    },
    payScales: {
        type: Array,
        default: () => []
    },
    grades: {
        type: Array,
        default: () => []
    },
    payrollGroups: {
        type: Array,
        default: () => []
    },
    userCategories: {
        type: Array,
        default: () => []
    },
});

const employeeStore = useEmployeeStore();
const adminStore = useAdminStore();
const bulkUpdateStore = useEmployeeBulkUpdateStore();
const searchStore = useEmployeeSearchStore();
const { employees, loading, filters, sort, activeFilterCount } = storeToRefs(employeeStore);

const isModalOpen = ref(false);
const isSaving = ref(false);
const formErrors = ref({});

const form = ref({
    id: null,
    name: '',
    email: '',
    gender: 'male',
    isActive: true,
    
    // Identifications
    nid_no: '',
    nid_img: '',
    bid_no: '',
    date_of_birth: '',
    bidImg: '',
    tin_no: '',
    tin_img: '',
    
    // Personal Info
    maritial_status: 'single',
    religion: 'Islam',
    childCount: 0,
    boy: 0,
    girl: 0,
    age: '',
    
    // Accommodation & Attributes
    dorm_name: '',
    dorm_type: '',
    dorm_address: '',
    emp_attr_name: '',
    emp_attr_description: '',
    emp_attr_action: false,
    
    // Employee Info & Pay Structure
    employeeCode: '',
    jobStatus: 'permanent',
    joining_date: '',
    incrementDate: '',
    holdIncrement: false,
    pay_scale_id: '',
    grade_id: '',
    payroll_group_id: '',
    
    // Multiple records
    department_offices: [{ department_office_id: '' }],
    user_categories: [{ user_category_id: '' }],
    phone_numbers: [{ phoneNumber: '' }],
    addresses: [{ address: '' }],
    bank_accounts: [{ bank_routing_no: '', bank_account_no: '', bank_type: '', bank_address: '', isSalaryAccount: false }],
    leave_statuses: [{ onLeave: false, leave_reason: '', isPaid: false, leave_isActive: false, leave_duration: 0 }],
    promotions: [{ promoted_to: '', pay_scale_id: '', payroll_group_id: '' }]
});

const addField = (field, defaultObj) => form.value[field].push({ ...defaultObj });
const removeField = (field, index) => form.value[field].splice(index, 1);

// Auto-calculate children count
watch(() => [form.value.boy, form.value.girl], ([boy, girl]) => {
    form.value.childCount = (parseInt(boy) || 0) + (parseInt(girl) || 0);
});

// Cascading Selects
const availableGrades = computed(() => {
    if (!form.value.pay_scale_id) return [];
    return (props.grades || []).filter(g => Number(g.pay_scale_id) === Number(form.value.pay_scale_id));
});

const availablePayrollGroups = computed(() => {
    if (!form.value.grade_id) return [];
    return (props.payrollGroups || []).filter(pg => Number(pg.grade_id) === Number(form.value.grade_id));
});

watch(() => form.value.pay_scale_id, (newScale) => {
    if (!newScale) {
        form.value.grade_id = '';
        form.value.payroll_group_id = '';
        return;
    }
    if (form.value.grade_id) {
        const grade = (props.grades || []).find(g => Number(g.id) === Number(form.value.grade_id));
        if (!grade || Number(grade.pay_scale_id) !== Number(newScale)) {
            form.value.grade_id = '';
            form.value.payroll_group_id = '';
        }
    }
});

watch(() => form.value.grade_id, (newGrade) => {
    if (!newGrade) {
        form.value.payroll_group_id = '';
        return;
    }
    if (form.value.payroll_group_id) {
        const pg = (props.payrollGroups || []).find(p => Number(p.id) === Number(form.value.payroll_group_id));
        if (!pg || Number(pg.grade_id) !== Number(newGrade)) {
            form.value.payroll_group_id = '';
        }
    }
});

// Department & Category Unique Options Filter
const availableDepartments = (currentIndex) => {
    if (!props.departmentOffices) return [];
    const selectedIds = form.value.department_offices
        .filter((_, idx) => idx !== currentIndex)
        .map(d => Number(d.department_office_id))
        .filter(id => Boolean(id));
    return props.departmentOffices.filter(d => !selectedIds.includes(Number(d.id)));
};

const availableUserCategories = (currentIndex) => {
    if (!props.userCategories) return [];
    const selectedIds = form.value.user_categories
        .filter((_, idx) => idx !== currentIndex)
        .map(c => Number(c.user_category_id))
        .filter(id => Boolean(id));
    return props.userCategories.filter(c => !selectedIds.includes(Number(c.id)));
};

// Promotion Dropdown Helpers
const getPromoPayrollGroups = (promo) => {
    if (!props.payrollGroups) return [];
    if (!promo.pay_scale_id) return props.payrollGroups;
    const gradeIds = (props.grades || [])
        .filter(g => Number(g.pay_scale_id) === Number(promo.pay_scale_id))
        .map(g => Number(g.id));
    return props.payrollGroups.filter(pg => gradeIds.includes(Number(pg.grade_id)));
};

const onPromoPayScaleChange = (promo) => {
    if (promo.payroll_group_id) {
        const matching = getPromoPayrollGroups(promo);
        if (!matching.some(pg => Number(pg.id) === Number(promo.payroll_group_id))) {
            promo.payroll_group_id = '';
        }
    }
};

const hasErrors = computed(() => {
    return Object.keys(formErrors.value).length > 0;
});

// Local Search & Filter State
const showAdvancedFilters = ref(false);

const localFilters = ref({
    search: '',
    employeeCode: '',
    department_office_id: '',
    user_category_id: '',
    pay_scale_id: '',
    grade_id: '',
    payroll_group_id: '',
    jobStatus: '',
    gender: '',
    isActive: '',
    holdIncrement: '',
    maritial_status: '',
    religion: '',
    onLeave: ''
});

// Filter Options Constants
const filterGenders = [
    { label: 'Male', value: 'male' },
    { label: 'Female', value: 'female' },
    { label: 'Other', value: 'other' },
];

const filterJobStatuses = [
    { label: 'Permanent', value: 'permanent' },
    { label: 'Ad-Hoc', value: 'ad_hoc' },
    { label: 'Contract', value: 'contract' },
    { label: 'Temporary', value: 'temporary' },
    { label: 'Others', value: 'others' },
];

const filterAccountStatuses = [
    { label: 'Active Only', value: 'true' },
    { label: 'Inactive Only', value: 'false' },
];

const filterHoldIncrementOptions = [
    { label: 'Withheld Only', value: 'true' },
    { label: 'Normal / Released Only', value: 'false' },
];

const filterLeaveStatusOptions = [
    { label: 'Currently On Leave', value: 'true' },
    { label: 'Not On Leave', value: 'false' },
];

const filterMaritialStatuses = [
    { label: 'Single', value: 'single' },
    { label: 'Married', value: 'married' },
    { label: 'Divorced', value: 'divorced' },
];

const filterReligions = [
    'Islam', 'Christianity', 'Hinduism', 'Buddhism', 'Sikhism', 'Judaism', 'Other'
];

// Cascading Filter Dropdowns
const filterAvailableGrades = computed(() => {
    if (!localFilters.value.pay_scale_id) {
        return props.grades || [];
    }
    return (props.grades || []).filter(g => String(g.pay_scale_id) === String(localFilters.value.pay_scale_id));
});

const filterAvailablePayrollGroups = computed(() => {
    if (!localFilters.value.grade_id) {
        if (localFilters.value.pay_scale_id) {
            const scaleGradeIds = filterAvailableGrades.value.map(g => String(g.id));
            return (props.payrollGroups || []).filter(pg => scaleGradeIds.includes(String(pg.grade_id)));
        }
        return props.payrollGroups || [];
    }
    return (props.payrollGroups || []).filter(pg => String(pg.grade_id) === String(localFilters.value.grade_id));
});

watch(() => localFilters.value.pay_scale_id, (newScale) => {
    if (newScale && localFilters.value.grade_id) {
        const valid = (props.grades || []).some(
            g => String(g.id) === String(localFilters.value.grade_id) && String(g.pay_scale_id) === String(newScale)
        );
        if (!valid) {
            localFilters.value.grade_id = '';
            localFilters.value.payroll_group_id = '';
        }
    }
});

watch(() => localFilters.value.grade_id, (newGrade) => {
    if (newGrade && localFilters.value.payroll_group_id) {
        const valid = (props.payrollGroups || []).some(
            pg => String(pg.id) === String(localFilters.value.payroll_group_id) && String(pg.grade_id) === String(newGrade)
        );
        if (!valid) {
            localFilters.value.payroll_group_id = '';
        }
    }
});

// Advanced filter count
const activeAdvancedFilterCount = computed(() => {
    const advKeys = [
        'employeeCode', 'pay_scale_id', 'grade_id', 'payroll_group_id', 
        'jobStatus', 'holdIncrement', 'onLeave', 'maritial_status', 'religion'
    ];
    return advKeys.filter(k => localFilters.value[k] !== '' && localFilters.value[k] !== null && localFilters.value[k] !== undefined).length;
});

// Applied Filter Chips
const activeFilterChips = computed(() => {
    const chips = [];
    if (localFilters.value.search) {
        const terms = localFilters.value.search.split(/[,\n\r]+/).map(t => t.trim()).filter(Boolean);
        if (terms.length > 1) {
            chips.push({ key: 'search', label: `Search (${terms.length} users): ${terms.join(', ')}` });
        } else {
            chips.push({ key: 'search', label: `Search: "${localFilters.value.search}"` });
        }
    }
    if (localFilters.value.employeeCode) {
        chips.push({ key: 'employeeCode', label: `Code: ${localFilters.value.employeeCode}` });
    }
    if (localFilters.value.department_office_id) {
        const d = (props.departmentOffices || []).find(x => String(x.id) === String(localFilters.value.department_office_id));
        chips.push({ key: 'department_office_id', label: `Dept: ${d ? d.name : localFilters.value.department_office_id}` });
    }
    if (localFilters.value.user_category_id) {
        const c = (props.userCategories || []).find(x => String(x.id) === String(localFilters.value.user_category_id));
        chips.push({ key: 'user_category_id', label: `Group: ${c ? c.name : localFilters.value.user_category_id}` });
    }
    if (localFilters.value.pay_scale_id) {
        const ps = (props.payScales || []).find(x => String(x.id) === String(localFilters.value.pay_scale_id));
        chips.push({ key: 'pay_scale_id', label: `Scale: ${ps ? ps.scale_name : localFilters.value.pay_scale_id}` });
    }
    if (localFilters.value.grade_id) {
        const g = (props.grades || []).find(x => String(x.id) === String(localFilters.value.grade_id));
        chips.push({ key: 'grade_id', label: `Grade: ${g ? g.grade_name : localFilters.value.grade_id}` });
    }
    if (localFilters.value.payroll_group_id) {
        const pg = (props.payrollGroups || []).find(x => String(x.id) === String(localFilters.value.payroll_group_id));
        const name = pg?.payroll_group_type?.group_name || `Group #${localFilters.value.payroll_group_id}`;
        chips.push({ key: 'payroll_group_id', label: `Designation: ${name}` });
    }
    if (localFilters.value.jobStatus) {
        const item = filterJobStatuses.find(j => j.value === localFilters.value.jobStatus);
        chips.push({ key: 'jobStatus', label: `Job: ${item ? item.label : localFilters.value.jobStatus}` });
    }
    if (localFilters.value.gender) {
        const item = filterGenders.find(g => g.value === localFilters.value.gender);
        chips.push({ key: 'gender', label: `Gender: ${item ? item.label : localFilters.value.gender}` });
    }
    if (localFilters.value.isActive !== '') {
        const isAct = localFilters.value.isActive === 'true' || localFilters.value.isActive === true;
        chips.push({ key: 'isActive', label: `Status: ${isAct ? 'Active' : 'Inactive'}` });
    }
    if (localFilters.value.holdIncrement !== '') {
        const isHold = localFilters.value.holdIncrement === 'true' || localFilters.value.holdIncrement === true;
        chips.push({ key: 'holdIncrement', label: `Increment: ${isHold ? 'Withheld' : 'Normal'}` });
    }
    if (localFilters.value.maritial_status) {
        chips.push({ key: 'maritial_status', label: `Marital: ${localFilters.value.maritial_status}` });
    }
    if (localFilters.value.religion) {
        chips.push({ key: 'religion', label: `Religion: ${localFilters.value.religion}` });
    }
    if (localFilters.value.onLeave !== '') {
        const isLeave = localFilters.value.onLeave === 'true' || localFilters.value.onLeave === true;
        chips.push({ key: 'onLeave', label: `Leave: ${isLeave ? 'On Leave' : 'Not On Leave'}` });
    }
    return chips;
});

const handleSearch = () => {
    clearSelection();
    employeeStore.setFilters(localFilters.value);
};

const handleReset = () => {
    clearSelection();
    employeeStore.resetFilters();
    localFilters.value = { ...employeeStore.filters };
    searchStore.clearSearch();
};

const removeSingleFilter = (key) => {
    clearSelection();
    localFilters.value[key] = '';
    employeeStore.setFilters({ [key]: '' });
    if (key === 'search') {
        searchStore.clearSearch();
    }
};

onMounted(() => {
    localFilters.value = { ...employeeStore.filters };
    employeeStore.fetchEmployees();
    bulkUpdateStore.setMetadata(props);
});

watch(props, (newProps) => {
    bulkUpdateStore.setMetadata(newProps);
}, { deep: true });

const handleSort = (field) => {
    employeeStore.setSort(field);
};

// Sorted Department Options
const sortedDepartmentOffices = computed(() => {
    return [...(props.departmentOffices || [])].sort((a, b) => (a.name || '').localeCompare(b.name || ''));
});

// Helper to get all related departments for an employee
const getEmployeeDepartments = (emp) => {
    const depts = [];
    if (emp.employee_info?.department_office?.name) {
        depts.push(emp.employee_info.department_office.name);
    }
    if (Array.isArray(emp.department_offices)) {
        emp.department_offices.forEach(d => {
            if (d.name && !depts.includes(d.name)) {
                depts.push(d.name);
            }
        });
    }
    return depts.length ? depts.join(', ') : 'N/A';
};

// Helper to get religion name for an employee safely
const getEmployeeReligion = (emp) => {
    if (!emp) return null;
    if (emp.religion?.religion) return emp.religion.religion;
    if (typeof emp.religion === 'string' && emp.religion.trim() !== '') return emp.religion;
    return null;
};

// Checkbox Selection Logic
const selectedUserIds = ref([]);
const isSelectAllFiltered = ref(false);
const isSelectDropdownOpen = ref(false);

const currentPageIds = computed(() => {
    return (employees.value?.data || []).map(emp => emp.id);
});

const isAllPageSelected = computed(() => {
    if (isSelectAllFiltered.value) return true;
    if (!currentPageIds.value.length) return false;
    return currentPageIds.value.every(id => selectedUserIds.value.includes(id));
});

const isIndeterminate = computed(() => {
    if (isSelectAllFiltered.value) return false;
    if (isAllPageSelected.value) return false;
    return currentPageIds.value.some(id => selectedUserIds.value.includes(id));
});

const selectedCount = computed(() => {
    return isSelectAllFiltered.value ? (employees.value?.total || 0) : selectedUserIds.value.length;
});

const toggleSelectAllPage = (e) => {
    const checked = e.target.checked;
    const pageIds = currentPageIds.value;
    const totalCount = employees.value?.total || 0;
    if (checked) {
        selectedUserIds.value = Array.from(new Set([...selectedUserIds.value, ...pageIds]));
        if (totalCount <= pageIds.length) {
            isSelectAllFiltered.value = true;
        }
    } else {
        selectedUserIds.value = selectedUserIds.value.filter(id => !pageIds.includes(id));
        isSelectAllFiltered.value = false;
    }
};

const toggleEmployeeSelection = (id) => {
    if (isSelectAllFiltered.value) {
        isSelectAllFiltered.value = false;
        selectedUserIds.value = currentPageIds.value.filter(pid => pid !== id);
    } else {
        const idx = selectedUserIds.value.indexOf(id);
        if (idx > -1) {
            selectedUserIds.value.splice(idx, 1);
        } else {
            selectedUserIds.value.push(id);
            if (employees.value?.total && selectedUserIds.value.length >= employees.value.total) {
                isSelectAllFiltered.value = true;
            }
        }
    }
};

const selectAllFilteredRecords = () => {
    isSelectAllFiltered.value = true;
    selectedUserIds.value = Array.from(new Set([...selectedUserIds.value, ...currentPageIds.value]));
};

const selectCurrentPageOnly = () => {
    isSelectAllFiltered.value = false;
    selectedUserIds.value = [...currentPageIds.value];
};

const clearSelection = () => {
    selectedUserIds.value = [];
    isSelectAllFiltered.value = false;
    isSelectDropdownOpen.value = false;
};

const openBulkUpdateModal = () => {
    bulkUpdateStore.openModal({
        selectedIds: selectedUserIds.value,
        isSelectAllFiltered: isSelectAllFiltered.value,
        filters: localFilters.value,
        totalCount: employees.value?.total || 0,
        currentEmployees: employees.value?.data || [],
    });
};

// Excel Export Logic
const isExporting = ref(false);

const capitalize = (str) => {
    if (!str) return '';
    return String(str).charAt(0).toUpperCase() + String(str).slice(1);
};

const exportToExcel = async (mode = 'auto') => {
    if (isExporting.value) return;

    const totalCount = employees.value?.total || 0;
    const isExportingAll = isSelectAllFiltered.value ||
        mode === 'all' ||
        selectedUserIds.value.length === 0 ||
        (totalCount > 0 && selectedUserIds.value.length >= totalCount);

    isExporting.value = true;
    try {
        let records = [];

        if (isExportingAll) {
            records = await employeeStore.fetchAllFilteredEmployees(localFilters.value);
        } else {
            records = await employeeStore.fetchAllFilteredEmployees(localFilters.value, selectedUserIds.value);
        }

        if (!records || records.length === 0) {
            adminStore.showError('Export Notice', 'No employee records found matching the criteria.');
            isExporting.value = false;
            return;
        }

        const filterDescriptions = activeFilterChips.value.map(c => c.label).join(' | ');

        const sheetData = [
            ['KHULNA AGRICULTURAL UNIVERSITY'],
            ['Employee Directory & Staff Management Report'],
            ['Generated On:', new Date().toLocaleString('en-GB'), 'Export Scope:', isExportingAll ? `All Filtered Records (${records.length})` : `Selected Records (${records.length})`],
            ['Active Filters:', filterDescriptions || 'None (All Records)'],
            [],
            [
                'SL#',
                'Employee Code',
                'Employee Name',
                'Email Address',
                'Contact No',
                'Department(s)',
                'Employee Group',
                'Designation',
                'Pay Scale',
                'Grade',
                'Job Status',
                'Status',
                'Gender',
                'Joining Date',
                'Increment Date',
                'Hold Increment',
                'Leave Status',
                'NID No',
                'TIN No',
                'Marital Status',
                'Religion',
                'Bank Account No',
                'Bank Name / Type'
            ]
        ];

        records.forEach((emp, index) => {
            const depts = getEmployeeDepartments(emp);
            const groups = (emp.user_categories || []).map(c => c.name).join(', ') || 'N/A';
            const phones = (emp.phone_numbers || [])
                .map(p => p.phoneNumber)
                .filter(p => p && p !== 'N/A')
                .join(', ') || 'N/A';
            const designation = emp.employee_info?.payroll_group?.payroll_group_type?.group_name || 'N/A';
            const payScale = emp.employee_info?.pay_scale?.scale_name || emp.employee_info?.payroll_group?.grade?.pay_scale?.scale_name || 'N/A';
            const grade = emp.employee_info?.payroll_group?.grade?.grade_name || 'N/A';
            const jobStatus = capitalize(emp.employee_info?.jobStatus || 'permanent');
            const status = emp.isActive ? 'Active' : 'Inactive';
            const gender = capitalize(emp.gender || 'N/A');
            const joiningDate = emp.joining_date?.joining_date || 'N/A';
            const incrementDate = emp.employee_info?.incrementDate || 'N/A';
            const holdIncr = emp.employee_info?.holdIncrement ? 'Yes' : 'No';
            const onLeave = (emp.leave_statuses || []).some(l => Boolean(l.onLeave)) ? 'On Leave' : 'Normal';
            const nid = emp.nid?.nid_no && emp.nid.nid_no !== 'N/A' ? emp.nid.nid_no : 'N/A';
            const tin = emp.tin?.tin_no && emp.tin.tin_no !== 'N/A' ? emp.tin.tin_no : 'N/A';
            const marital = capitalize(emp.maritial_status?.status || 'N/A');
            const religion = emp.religion?.religion || 'N/A';
            const salaryAccount = (emp.bank_accounts || []).find(b => b.isSalaryAccount) || emp.bank_accounts?.[0];
            const bankAcc = salaryAccount?.account_no && salaryAccount.account_no !== 'N/A' ? salaryAccount.account_no : 'N/A';
            const bankType = salaryAccount?.type && salaryAccount.type !== 'N/A' ? salaryAccount.type : 'N/A';

            sheetData.push([
                index + 1,
                emp.employee_info?.employeeCode || 'N/A',
                emp.name,
                emp.email || 'N/A',
                phones,
                depts,
                groups,
                designation,
                payScale,
                grade,
                jobStatus,
                status,
                gender,
                joiningDate,
                incrementDate,
                holdIncr,
                onLeave,
                nid,
                tin,
                marital,
                religion,
                bankAcc,
                bankType
            ]);
        });

        const worksheet = XLSX.utils.aoa_to_sheet(sheetData);

        worksheet['!cols'] = [
            { wch: 6 },   // SL#
            { wch: 14 },  // Code
            { wch: 28 },  // Name
            { wch: 28 },  // Email
            { wch: 18 },  // Contact
            { wch: 32 },  // Department
            { wch: 20 },  // Group
            { wch: 26 },  // Designation
            { wch: 26 },  // Pay Scale
            { wch: 12 },  // Grade
            { wch: 14 },  // Job Status
            { wch: 12 },  // Status
            { wch: 10 },  // Gender
            { wch: 14 },  // Joining Date
            { wch: 14 },  // Increment Date
            { wch: 16 },  // Hold Incr
            { wch: 14 },  // Leave
            { wch: 18 },  // NID
            { wch: 16 },  // TIN
            { wch: 14 },  // Marital
            { wch: 12 },  // Religion
            { wch: 20 },  // Bank Account
            { wch: 18 },  // Bank Type
        ];

        const workbook = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(workbook, worksheet, 'Employees');

        const dateStr = new Date().toISOString().slice(0, 10);
        const fileName = `KAU_Employees_${isExportingAll ? 'Filtered' : 'Selected'}_${dateStr}.xlsx`;

        XLSX.writeFile(workbook, fileName);

        adminStore.showSuccess('Downloaded', `Successfully exported ${records.length} employee record(s) to Excel.`);
    } catch (err) {
        console.error("Excel export error:", err);
        adminStore.showError('Export Failed', 'An error occurred while generating the Excel report.');
    } finally {
        isExporting.value = false;
    }
};

const openEditModal = (emp) => {
    formErrors.value = {};
    
    const payrollGroupId = emp.employee_info?.payroll_group_id || '';
    let gradeId = '';
    if (payrollGroupId && props.payrollGroups) {
        const pg = props.payrollGroups.find(p => Number(p.id) === Number(payrollGroupId));
        if (pg) gradeId = pg.grade_id;
    }

    form.value = {
        id: emp.id,
        name: emp.name || '',
        email: emp.email || '',
        gender: emp.gender || 'male',
        isActive: Boolean(emp.isActive),
        
        nid_no: emp.nid?.nid_no || '',
        nid_img: emp.nid?.nid_img || '',
        bid_no: emp.bid?.bid_no || '',
        date_of_birth: emp.bid?.date_of_birth || '',
        bidImg: emp.bid?.bidImg || '',
        tin_no: emp.tin?.tin_no || '',
        tin_img: emp.tin?.tin_img || '',
        
        maritial_status: emp.maritial_status?.status || emp.maritialStatus?.status || 'single',
        religion: emp.religion?.religion || 'Islam',
        childCount: emp.child_count?.childCount || emp.childCount?.childCount || 0,
        boy: emp.child_count?.boy || emp.childCount?.boy || 0,
        girl: emp.child_count?.girl || emp.childCount?.girl || 0,
        age: emp.age?.age || '',
        
        joining_date: emp.joining_date?.joining_date || emp.joiningDate?.joining_date || '',
        dorm_name: emp.dormitory?.dorm_name || '',
        dorm_type: emp.dormitory?.dorm_type || '',
        dorm_address: emp.dormitory?.dorm_address || '',
        
        emp_attr_name: emp.employee_attribute?.name || emp.employeeAttribute?.name || '',
        emp_attr_description: emp.employee_attribute?.description || emp.employeeAttribute?.description || '',
        emp_attr_action: Boolean(emp.employee_attribute?.isActive ?? emp.employeeAttribute?.isActive),
        
        employeeCode: emp.employee_info?.employeeCode || emp.employeeInfo?.employeeCode || '',
        jobStatus: emp.employee_info?.jobStatus || emp.employeeInfo?.jobStatus || 'permanent',
        incrementDate: emp.employee_info?.incrementDate || emp.employeeInfo?.incrementDate || '',
        holdIncrement: Boolean(emp.employee_info?.holdIncrement ?? emp.employeeInfo?.holdIncrement),
        pay_scale_id: emp.employee_info?.pay_scale_id || emp.employeeInfo?.pay_scale_id || '',
        grade_id: gradeId,
        payroll_group_id: payrollGroupId,

        phone_numbers: (emp.phone_numbers && emp.phone_numbers.length > 0) 
            ? emp.phone_numbers.map(p => ({ phoneNumber: p.phoneNumber || '' })) 
            : [{ phoneNumber: '' }],
            
        department_offices: (emp.department_offices && emp.department_offices.length > 0)
            ? emp.department_offices.map(d => ({ department_office_id: d.id || '' }))
            : [{ department_office_id: '' }],
            
        user_categories: (emp.user_categories && emp.user_categories.length > 0)
            ? emp.user_categories.map(c => ({ user_category_id: c.id || '' }))
            : [{ user_category_id: '' }],
            
        addresses: (emp.addresses && emp.addresses.length > 0)
            ? emp.addresses.map(a => ({ address: a.address || '' }))
            : [{ address: '' }],
            
        bank_accounts: (emp.bank_accounts && emp.bank_accounts.length > 0)
            ? emp.bank_accounts.map(b => ({ 
                bank_routing_no: b.routing_no || '', 
                bank_account_no: b.account_no || '', 
                bank_type: b.type || '', 
                bank_address: b.address || '', 
                isSalaryAccount: Boolean(b.isSalaryAccount) 
            }))
            : [{ bank_routing_no: '', bank_account_no: '', bank_type: '', bank_address: '', isSalaryAccount: false }],
            
        leave_statuses: (emp.leave_statuses && emp.leave_statuses.length > 0)
            ? emp.leave_statuses.map(l => ({ 
                onLeave: Boolean(l.onLeave), 
                leave_reason: l.reason || '', 
                isPaid: Boolean(l.isPaid), 
                leave_isActive: Boolean(l.isActive), 
                leave_duration: l.duration || 0 
            }))
            : [{ onLeave: false, leave_reason: '', isPaid: false, leave_isActive: false, leave_duration: 0 }],
            
        promotions: (emp.promotions && emp.promotions.length > 0)
            ? emp.promotions.map(p => ({ 
                promoted_to: p.promoted_to || '', 
                pay_scale_id: p.pay_scale_id || '', 
                payroll_group_id: p.payroll_group_id || '' 
            }))
            : [{ promoted_to: '', pay_scale_id: '', payroll_group_id: '' }]
    };
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    formErrors.value = {};
};

const saveEmployee = async () => {
    formErrors.value = {};
    isSaving.value = true;
    try {
        await employeeStore.updateEmployee(form.value.id, form.value);
        closeModal();
    } catch (error) {
        console.error("Save failed:", error);
        if (error.response?.data?.errors) {
            formErrors.value = error.response.data.errors;
        }
    } finally {
        isSaving.value = false;
    }
};

const confirmDelete = async (id) => {
    await employeeStore.deleteEmployee(id);
};

const changePage = (page) => {
    if (page >= 1 && page <= employees.value.last_page) {
        employeeStore.fetchEmployees(page);
    }
};
</script>

<template>
    <Head title="Manage Employees" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="font-bold text-2xl text-gray-800 dark:text-gray-100 leading-tight">Manage Employees</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Directory of staff members, payroll configuration & status</p>
                </div>
                <div class="flex flex-wrap items-center gap-2.5">
                    <!-- Excel Export Button -->
                    <button
                        type="button"
                        @click="exportToExcel('auto')"
                        :disabled="isExporting"
                        class="inline-flex items-center gap-1.5 px-3.5 py-2 text-xs font-semibold rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm transition disabled:opacity-50 cursor-pointer"
                        :title="selectedCount > 0 ? `Export ${selectedCount} selected employee(s) to Excel` : `Export all ${employees.total || 0} filtered employee(s) to Excel`"
                    >
                        <svg v-if="!isExporting" class="w-4 h-4 text-emerald-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <svg v-else class="animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span>Excel</span>
                        <span v-if="selectedCount > 0" class="px-1.5 py-0.2 text-[10px] font-bold rounded-full bg-emerald-800 text-white">
                            {{ selectedCount }}
                        </span>
                    </button>

                    <Link :href="route('employees.create')">
                        <PrimaryButton class="inline-flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                            Add New Employee
                        </PrimaryButton>
                    </Link>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <!-- ========================================== -->
            <!-- SEARCH & FILTER SECTION                    -->
            <!-- ========================================== -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700/60 overflow-hidden transition-all duration-300">
                <!-- Header with title and active badge -->
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700/60 flex flex-wrap items-center justify-between gap-3 bg-gray-50/60 dark:bg-gray-800/80">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-400 flex items-center justify-center">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                                Search & Filter Directory
                            </h2>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                Filter employees by institutional structure, payroll attributes, or personal demographics
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <span v-if="activeFilterChips.length > 0" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/40">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                            {{ activeFilterChips.length }} active {{ activeFilterChips.length === 1 ? 'filter' : 'filters' }}
                        </span>
                        <button
                            type="button"
                            @click="showAdvancedFilters = !showAdvancedFilters"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition"
                        >
                            <svg class="w-3.5 h-3.5 text-gray-500 dark:text-gray-400 transition-transform duration-200" :class="{ 'rotate-180': showAdvancedFilters }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                            <span>{{ showAdvancedFilters ? 'Less Filters' : 'More Filters' }}</span>
                            <span v-if="activeAdvancedFilterCount > 0 && !showAdvancedFilters" class="px-1.5 py-0.2 text-[10px] font-bold rounded-full bg-emerald-500 text-white">
                                +{{ activeAdvancedFilterCount }}
                            </span>
                        </button>
                        <button
                            v-if="activeFilterChips.length > 0"
                            type="button"
                            @click="handleReset"
                            class="px-2.5 py-1.5 text-xs font-medium text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 hover:underline transition"
                        >
                            Clear all
                        </button>
                    </div>
                </div>

                <!-- Filter Form -->
                <form @submit.prevent="handleSearch" class="p-5 space-y-4">
                    <!-- Primary Filters Row -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-12 gap-3.5">
                        <!-- Universal Multi-User Search (Name, Email, Code, Phone, NID, TIN) -->
                        <div class="sm:col-span-2 md:col-span-3 lg:col-span-3">
                            <UniversalSearchInput
                                v-model="localFilters.search"
                                :total-matches="employees.total || 0"
                                :loading="loading"
                                @search="handleSearch"
                            />
                        </div>

                        <!-- Department -->
                        <div class="lg:col-span-3">
                            <InputLabel for="filter_dept" value="Department" />
                            <select
                                id="filter_dept"
                                v-model="localFilters.department_office_id"
                                @change="handleSearch"
                                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 focus:ring-emerald-500 text-sm py-2 px-3 transition-colors cursor-pointer"
                            >
                                <option value="">All Departments</option>
                                <option v-for="dept in sortedDepartmentOffices" :key="dept.id" :value="dept.id">
                                    {{ dept.name }}
                                </option>
                            </select>
                        </div>

                        <!-- Employee Group / Category -->
                        <div class="lg:col-span-2">
                            <InputLabel for="filter_category" value="Employee Group" />
                            <select
                                id="filter_category"
                                v-model="localFilters.user_category_id"
                                @change="handleSearch"
                                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 focus:ring-emerald-500 text-sm py-2 px-3 transition-colors cursor-pointer"
                            >
                                <option value="">All Employee Groups</option>
                                <option v-for="cat in userCategories" :key="cat.id" :value="cat.id">
                                    {{ cat.name }}
                                </option>
                            </select>
                        </div>

                        <!-- Gender Filter -->
                        <div class="lg:col-span-2">
                            <InputLabel for="filter_gender" value="Gender" />
                            <select
                                id="filter_gender"
                                v-model="localFilters.gender"
                                @change="handleSearch"
                                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 focus:ring-emerald-500 text-sm py-2 px-3 transition-colors cursor-pointer"
                            >
                                <option value="">All Genders</option>
                                <option v-for="g in filterGenders" :key="g.value" :value="g.value">{{ g.label }}</option>
                            </select>
                        </div>

                        <!-- Account Status -->
                        <div class="lg:col-span-2">
                            <InputLabel for="filter_is_active" value="Status" />
                            <select
                                id="filter_is_active"
                                v-model="localFilters.isActive"
                                @change="handleSearch"
                                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 focus:ring-emerald-500 text-sm py-2 px-3 transition-colors cursor-pointer"
                            >
                                <option value="">All Statuses</option>
                                <option v-for="s in filterAccountStatuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                            </select>
                        </div>
                    </div>

                    <!-- Advanced Filters Collapsible Section -->
                    <div v-show="showAdvancedFilters" class="pt-3 border-t border-gray-100 dark:border-gray-700/60 transition-all">
                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3.5">
                            <!-- Employee Code -->
                            <div>
                                <InputLabel for="filter_code" value="Exact Employee Code" />
                                <TextInput
                                    id="filter_code"
                                    type="text"
                                    v-model="localFilters.employeeCode"
                                    placeholder="e.g. 1002"
                                    class="w-full mt-1 text-sm"
                                />
                            </div>

                            <!-- Pay Scale -->
                            <div>
                                <InputLabel for="filter_scale" value="Pay Scale" />
                                <select
                                    id="filter_scale"
                                    v-model="localFilters.pay_scale_id"
                                    class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 focus:ring-emerald-500 text-sm py-2 px-3 transition-colors"
                                >
                                    <option value="">All Pay Scales</option>
                                    <option v-for="scale in payScales" :key="scale.id" :value="scale.id">
                                        {{ scale.scale_name }}
                                    </option>
                                </select>
                            </div>

                            <!-- Grade (Cascades with Pay Scale) -->
                            <div>
                                <InputLabel for="filter_grade" value="Grade" />
                                <select
                                    id="filter_grade"
                                    v-model="localFilters.grade_id"
                                    class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 focus:ring-emerald-500 text-sm py-2 px-3 transition-colors"
                                >
                                    <option value="">All Grades</option>
                                    <option v-for="g in filterAvailableGrades" :key="g.id" :value="g.id">
                                        {{ g.grade_name }}
                                    </option>
                                </select>
                            </div>

                            <!-- Designation / Payroll Group (Cascades with Grade) -->
                            <div>
                                <InputLabel for="filter_group" value="Designation / Group" />
                                <select
                                    id="filter_group"
                                    v-model="localFilters.payroll_group_id"
                                    class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 focus:ring-emerald-500 text-sm py-2 px-3 transition-colors"
                                >
                                    <option value="">All Designations</option>
                                    <option v-for="pg in filterAvailablePayrollGroups" :key="pg.id" :value="pg.id">
                                        {{ pg.payroll_group_type?.group_name || ('Group #' + pg.id) }}
                                    </option>
                                </select>
                            </div>

                            <!-- Job Status -->
                            <div>
                                <InputLabel for="filter_job_status" value="Job Status" />
                                <select
                                    id="filter_job_status"
                                    v-model="localFilters.jobStatus"
                                    class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 focus:ring-emerald-500 text-sm py-2 px-3 transition-colors"
                                >
                                    <option value="">All Job Statuses</option>
                                    <option v-for="j in filterJobStatuses" :key="j.value" :value="j.value">{{ j.label }}</option>
                                </select>
                            </div>


                            <!-- Hold Increment -->
                            <div>
                                <InputLabel for="filter_hold_increment" value="Increment Status" />
                                <select
                                    id="filter_hold_increment"
                                    v-model="localFilters.holdIncrement"
                                    class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 focus:ring-emerald-500 text-sm py-2 px-3 transition-colors"
                                >
                                    <option value="">All Increment Statuses</option>
                                    <option v-for="opt in filterHoldIncrementOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                </select>
                            </div>

                            <!-- Leave Status -->
                            <div>
                                <InputLabel for="filter_leave" value="Leave Status" />
                                <select
                                    id="filter_leave"
                                    v-model="localFilters.onLeave"
                                    class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 focus:ring-emerald-500 text-sm py-2 px-3 transition-colors"
                                >
                                    <option value="">All Leave Statuses</option>
                                    <option v-for="opt in filterLeaveStatusOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                </select>
                            </div>

                            <!-- Marital Status -->
                            <div>
                                <InputLabel for="filter_marital" value="Marital Status" />
                                <select
                                    id="filter_marital"
                                    v-model="localFilters.maritial_status"
                                    class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 focus:ring-emerald-500 text-sm py-2 px-3 transition-colors"
                                >
                                    <option value="">All Marital Statuses</option>
                                    <option v-for="m in filterMaritialStatuses" :key="m.value" :value="m.value">{{ m.label }}</option>
                                </select>
                            </div>

                            <!-- Religion -->
                            <div>
                                <InputLabel for="filter_religion" value="Religion" />
                                <select
                                    id="filter_religion"
                                    v-model="localFilters.religion"
                                    class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-emerald-500 focus:ring-emerald-500 text-sm py-2 px-3 transition-colors"
                                >
                                    <option value="">All Religions</option>
                                    <option v-for="r in filterReligions" :key="r" :value="r">{{ r }}</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Filter Action Buttons & Clear -->
                    <div class="flex items-center justify-between pt-2">
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            Found <span class="font-semibold text-gray-900 dark:text-white">{{ employees.total || 0 }}</span> matching employee{{ employees.total === 1 ? '' : 's' }}
                        </div>
                        <div class="inline-flex items-center gap-2">
                            <SecondaryButton type="button" @click="handleReset" class="text-xs">
                                Reset
                            </SecondaryButton>
                            <PrimaryButton type="submit" class="text-xs inline-flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                                Apply Filters
                            </PrimaryButton>
                        </div>
                    </div>
                </form>

                <!-- Active Filter Chips / Tags Bar -->
                <div v-if="activeFilterChips.length > 0" class="px-5 py-2.5 bg-gray-50/70 dark:bg-gray-800/90 border-t border-gray-100 dark:border-gray-700/60 flex flex-wrap items-center gap-2">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Active filters:</span>
                    <div
                        v-for="chip in activeFilterChips"
                        :key="chip.key"
                        class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs rounded-full bg-emerald-50 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/50"
                    >
                        <span>{{ chip.label }}</span>
                        <button
                            type="button"
                            @click="removeSingleFilter(chip.key)"
                            class="hover:text-red-500 transition-colors"
                            title="Remove filter"
                        >
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Table Card -->
            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-xl overflow-hidden border border-gray-100 dark:border-gray-700/60 transition-colors">
                
                <!-- Bulk Actions Toolbar -->
                <div
                    v-if="selectedUserIds.length > 0 || isSelectAllFiltered"
                    class="bg-emerald-50 dark:bg-emerald-950/60 border-b border-emerald-200 dark:border-emerald-800/70 px-5 py-3 flex flex-wrap items-center justify-between gap-3 transition-all"
                >
                    <div class="flex items-center gap-2.5 text-xs text-emerald-900 dark:text-emerald-100">
                        <span class="inline-flex items-center justify-center min-w-5 h-5 px-1.5 rounded-md bg-emerald-600 text-white font-bold text-[11px]">
                            {{ isSelectAllFiltered ? (employees.total || 0) : selectedUserIds.length }}
                        </span>
                        <span class="font-medium">
                            <template v-if="isSelectAllFiltered">
                                All <strong>{{ employees.total || 0 }}</strong> matching employee records are selected across all pages
                            </template>
                            <template v-else>
                                <strong>{{ selectedUserIds.length }}</strong> of {{ employees.total || 0 }} employee record(s) selected
                            </template>
                        </span>

                        <!-- Option to select all across all pages -->
                        <span v-if="!isSelectAllFiltered && (employees.total || 0) > selectedUserIds.length" class="text-emerald-700 dark:text-emerald-300 flex items-center gap-1.5 ml-1">
                            &bull;
                            <button
                                type="button"
                                @click="selectAllFilteredRecords"
                                class="font-bold underline hover:text-emerald-900 dark:hover:text-emerald-100 transition cursor-pointer bg-emerald-100/90 dark:bg-emerald-900/60 px-2 py-0.5 rounded text-emerald-800 dark:text-emerald-200"
                            >
                                Select all {{ employees.total }} filtered records
                            </button>
                        </span>
                        <span v-else-if="isSelectAllFiltered" class="text-emerald-700 dark:text-emerald-300 ml-1">
                            &bull;
                            <button
                                type="button"
                                @click="clearSelection"
                                class="underline font-semibold hover:text-emerald-800 dark:hover:text-emerald-200 ml-1 transition cursor-pointer"
                            >
                                Clear selection
                            </button>
                        </span>
                    </div>

                    <div class="flex items-center gap-2">
                        <!-- Bulk Profile Update Action Button -->
                        <button
                            type="button"
                            @click="openBulkUpdateModal"
                            class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg text-xs font-bold text-white bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 shadow-sm shadow-emerald-700/20 hover:shadow-md transition-all active:scale-95 cursor-pointer"
                            :title="`Update profile attributes for ${isSelectAllFiltered ? (employees.total || 0) : selectedUserIds.length} employees`"
                        >
                            <svg class="w-3.5 h-3.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                            <span>Bulk Profile Update ({{ isSelectAllFiltered ? (employees.total || 0) : selectedUserIds.length }})</span>
                        </button>

                        <!-- If all are selected -->
                        <template v-if="isSelectAllFiltered">
                            <button
                                type="button"
                                @click="exportToExcel('all')"
                                :disabled="isExporting"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-700 dark:bg-emerald-600 dark:hover:bg-emerald-500 shadow-sm transition disabled:opacity-50 cursor-pointer"
                                :title="`Download all ${employees.total || 0} filtered records as Excel spreadsheet`"
                            >
                                <svg v-if="!isExporting" class="w-3.5 h-3.5 text-emerald-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <svg v-else class="animate-spin w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                </svg>
                                <span>Download All Records ({{ employees.total || 0 }})</span>
                            </button>
                        </template>

                        <!-- If a subset is selected -->
                        <template v-else>
                            <button
                                type="button"
                                @click="exportToExcel('selected')"
                                :disabled="isExporting"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-700 dark:bg-emerald-600 dark:hover:bg-emerald-500 shadow-sm transition disabled:opacity-50 cursor-pointer"
                                :title="`Download ${selectedUserIds.length} selected records as Excel spreadsheet`"
                            >
                                <svg v-if="!isExporting" class="w-3.5 h-3.5 text-emerald-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <svg v-else class="animate-spin w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                </svg>
                                <span>Download Selected ({{ selectedUserIds.length }})</span>
                            </button>

                            <button
                                v-if="(employees.total || 0) > selectedUserIds.length"
                                type="button"
                                @click="exportToExcel('all')"
                                :disabled="isExporting"
                                class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-medium border border-emerald-300 dark:border-emerald-700 bg-white dark:bg-gray-800 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 shadow-sm transition cursor-pointer"
                                :title="`Export all ${employees.total || 0} filtered records without pagination limit`"
                            >
                                Download All ({{ employees.total || 0 }})
                            </button>
                        </template>

                        <button
                            type="button"
                            @click="clearSelection"
                            class="px-2.5 py-1.5 rounded-lg text-xs font-medium border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 shadow-sm transition cursor-pointer"
                        >
                            Deselect All
                        </button>
                    </div>
                </div>

                <!-- Table -->
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left text-gray-500 dark:text-gray-400">
                        <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700/60 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700">
                            <tr>
                                <th scope="col" class="py-3.5 px-3 w-12 text-center">
                                    <div class="relative inline-flex items-center justify-center">
                                        <input
                                            type="checkbox"
                                            :checked="isAllPageSelected || isSelectAllFiltered"
                                            :indeterminate.prop="isIndeterminate"
                                            @change="toggleSelectAllPage"
                                            class="rounded border-gray-300 dark:border-gray-600 text-emerald-600 focus:ring-emerald-500 dark:bg-gray-700 cursor-pointer"
                                            :title="isSelectAllFiltered ? `All ${employees.total || 0} filtered records selected` : 'Select records'"
                                        />
                                        <button
                                            type="button"
                                            @click.stop="isSelectDropdownOpen = !isSelectDropdownOpen"
                                            class="ml-1 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 focus:outline-none cursor-pointer"
                                            title="Selection menu"
                                        >
                                            <svg class="w-3 h-3 transition-transform" :class="{ 'rotate-180': isSelectDropdownOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                            </svg>
                                        </button>

                                        <!-- Dropdown Menu -->
                                        <div
                                            v-if="isSelectDropdownOpen"
                                            class="absolute left-0 top-full mt-1.5 w-48 bg-white dark:bg-gray-800 rounded-lg shadow-xl border border-gray-100 dark:border-gray-700 py-1 z-30 text-left normal-case font-normal"
                                        >
                                            <button
                                                type="button"
                                                @click="selectAllFilteredRecords(); isSelectDropdownOpen = false"
                                                class="w-full px-3 py-1.5 text-xs text-gray-700 dark:text-gray-200 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 hover:text-emerald-700 dark:hover:text-emerald-300 flex items-center justify-between cursor-pointer"
                                            >
                                                <span>All Filtered ({{ employees.total || 0 }})</span>
                                                <span v-if="isSelectAllFiltered" class="text-emerald-600 font-bold">✓</span>
                                            </button>
                                            <button
                                                type="button"
                                                @click="selectCurrentPageOnly(); isSelectDropdownOpen = false"
                                                class="w-full px-3 py-1.5 text-xs text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 flex items-center justify-between cursor-pointer"
                                            >
                                                <span>Current Page ({{ currentPageIds.length }})</span>
                                                <span v-if="isAllPageSelected && !isSelectAllFiltered" class="text-emerald-600 font-bold">✓</span>
                                            </button>
                                            <button
                                                type="button"
                                                @click="clearSelection(); isSelectDropdownOpen = false"
                                                class="w-full px-3 py-1.5 text-xs text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/30 text-left border-t border-gray-100 dark:border-gray-700 mt-1 pt-1 cursor-pointer"
                                            >
                                                Deselect All
                                            </button>
                                        </div>
                                        <!-- Backdrop to close dropdown -->
                                        <div
                                            v-if="isSelectDropdownOpen"
                                            @click="isSelectDropdownOpen = false"
                                            class="fixed inset-0 z-20"
                                        ></div>
                                    </div>
                                </th>
                                <th scope="col" class="px-5 py-3.5 cursor-pointer select-none hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors" @click="handleSort('id')">
                                    SL 
                                    <span v-if="sort.field === 'id'" class="ml-1">{{ sort.direction === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th scope="col" class="px-5 py-3.5 cursor-pointer select-none hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors" @click="handleSort('employeeCode')">
                                    Code
                                    <span v-if="sort.field === 'employeeCode'" class="ml-1">{{ sort.direction === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th scope="col" class="px-5 py-3.5 cursor-pointer select-none hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors" @click="handleSort('name')">
                                    Employee Name
                                    <span v-if="sort.field === 'name'" class="ml-1">{{ sort.direction === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th scope="col" class="px-5 py-3.5">
                                    Department & Category
                                </th>
                                <th scope="col" class="px-5 py-3.5">
                                    Designation & Scale
                                </th>
                                <th scope="col" class="px-5 py-3.5 cursor-pointer select-none hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors" @click="handleSort('email')">
                                    Email & Contact
                                    <span v-if="sort.field === 'email'" class="ml-1">{{ sort.direction === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th scope="col" class="px-5 py-3.5 text-center cursor-pointer select-none hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors" @click="handleSort('isActive')">
                                    Status
                                    <span v-if="sort.field === 'isActive'" class="ml-1">{{ sort.direction === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th scope="col" class="px-5 py-3.5 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                            <tr v-if="loading" class="bg-white dark:bg-gray-800">
                                <td colspan="9" class="px-6 py-12 text-center text-gray-500">
                                    <div class="inline-flex items-center gap-2">
                                        <svg class="animate-spin h-5 w-5 text-emerald-500" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                        </svg>
                                        <span>Loading employees...</span>
                                    </div>
                                </td>
                            </tr>
                            <tr v-else-if="!employees.data || employees.data.length === 0" class="bg-white dark:bg-gray-800">
                                <td colspan="9" class="px-6 py-14 text-center">
                                    <div class="flex flex-col items-center justify-center">
                                        <div class="w-12 h-12 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center text-gray-400 mb-3">
                                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                                            </svg>
                                        </div>
                                        <p class="text-base font-semibold text-gray-800 dark:text-gray-200">No employees match your criteria</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-sm">Try adjusting your search or clearing active filters to see employees.</p>
                                        <button
                                            v-if="activeFilterChips.length > 0"
                                            type="button"
                                            @click="handleReset"
                                            class="mt-3 inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg text-emerald-700 bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300 transition"
                                        >
                                            Clear all filters
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-else v-for="(emp, index) in employees.data" :key="emp.id" class="bg-white dark:bg-gray-800 hover:bg-gray-50/80 dark:hover:bg-gray-700/50 transition-colors" :class="{ 'bg-emerald-50/40 dark:bg-emerald-950/20': isSelectAllFiltered || selectedUserIds.includes(emp.id) }">
                                <td class="py-4 px-3 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        :value="emp.id"
                                        :checked="isSelectAllFiltered || selectedUserIds.includes(emp.id)"
                                        @change="toggleEmployeeSelection(emp.id)"
                                        class="rounded border-gray-300 dark:border-gray-600 text-emerald-600 focus:ring-emerald-500 dark:bg-gray-700 cursor-pointer"
                                    />
                                </td>
                                <td class="px-5 py-4 font-medium text-gray-900 dark:text-white">{{ (employees.current_page - 1) * 10 + index + 1 }}</td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded font-mono text-xs font-semibold bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200 border border-gray-200 dark:border-gray-600">
                                        {{ emp.employee_info?.employeeCode || 'N/A' }}
                                    </span>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="font-semibold text-gray-900 dark:text-white">{{ emp.name }}</div>
                                    <div class="flex items-center gap-1.5 mt-1 flex-wrap">
                                        <span v-if="emp.gender" class="text-[11px] font-medium text-gray-500 dark:text-gray-400 capitalize">
                                            {{ emp.gender }}
                                        </span>
                                        <span 
                                            class="inline-flex items-center px-1.5 py-0.2 rounded text-[10px] font-medium capitalize border transition-colors"
                                            :class="getEmployeeReligion(emp) 
                                                ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300 border-emerald-200/60 dark:border-emerald-800/40' 
                                                : 'bg-gray-100 text-gray-500 dark:bg-gray-700/60 dark:text-gray-400 border-gray-200/50 dark:border-gray-600/40'"
                                            :title="`Religion: ${getEmployeeReligion(emp) || 'Not Specified'}`"
                                        >
                                            {{ getEmployeeReligion(emp) || 'Religion N/A' }}
                                        </span>
                                        <span v-if="emp.employee_info?.jobStatus" class="inline-flex items-center px-1.5 py-0.2 rounded text-[10px] font-medium bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300 capitalize">
                                            {{ emp.employee_info.jobStatus }}
                                        </span>
                                        <span v-if="emp.employee_info?.holdIncrement" class="inline-flex items-center px-1.5 py-0.2 rounded text-[10px] font-medium bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
                                            Hold Incr.
                                        </span>
                                    </div>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="font-medium text-gray-900 dark:text-gray-200 text-xs">
                                        {{ getEmployeeDepartments(emp) }}
                                    </div>
                                    <div class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">
                                        {{ emp.user_categories && emp.user_categories.length > 0 ? emp.user_categories.map(c => c.name).join(', ') : 'No Group' }}
                                    </div>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="font-medium text-gray-900 dark:text-gray-200 text-xs">
                                        {{ emp.employee_info?.payroll_group?.payroll_group_type?.group_name || 'N/A' }}
                                    </div>
                                    <div class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">
                                        {{ emp.employee_info?.pay_scale?.scale_name || emp.employee_info?.payroll_group?.grade?.pay_scale?.scale_name || '' }}
                                        <span v-if="emp.employee_info?.payroll_group?.grade?.grade_name">
                                            ({{ emp.employee_info.payroll_group.grade.grade_name }})
                                        </span>
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-xs text-gray-600 dark:text-gray-300">
                                    <div>{{ emp.email }}</div>
                                    <div v-if="emp.phone_numbers && emp.phone_numbers.length > 0 && emp.phone_numbers[0].phoneNumber !== 'N/A'" class="text-[11px] text-gray-400 dark:text-gray-400 mt-0.5">
                                        {{ emp.phone_numbers[0].phoneNumber }}
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-center">
                                    <div class="flex flex-col items-center gap-1">
                                        <span :class="emp.isActive ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-400' : 'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-400'" class="text-xs font-semibold px-2.5 py-0.5 rounded-full">
                                            {{ emp.isActive ? 'Active' : 'Inactive' }}
                                        </span>
                                        <span v-if="emp.leave_statuses && emp.leave_statuses.some(l => Boolean(l.onLeave))" class="text-[10px] font-semibold px-1.5 py-0.2 rounded-full bg-purple-100 text-purple-800 dark:bg-purple-950/50 dark:text-purple-300">
                                            On Leave
                                        </span>
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-center">
                                    <div class="inline-flex items-center gap-1.5">
                                        <button
                                            title="Update salary calculation components"
                                            @click="employeeStore.updateEmployeeSalaryCalculations(emp.id)"
                                            class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-amber-700 bg-amber-50 hover:bg-amber-100 dark:bg-amber-950/40 dark:text-amber-300 dark:hover:bg-amber-900/60 rounded-lg transition"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                                            Salary
                                        </button>
                                        <button
                                            title="Edit employee records"
                                            @click="openEditModal(emp)"
                                            class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-emerald-700 bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-900/60 rounded-lg transition"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                            Edit
                                        </button>
                                        <button
                                            title="Delete employee record"
                                            @click="confirmDelete(emp.id)"
                                            class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 dark:bg-red-950/40 dark:text-red-300 dark:hover:bg-red-900/60 rounded-lg transition"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <div class="p-4 border-t border-gray-100 dark:border-gray-700/60 flex items-center justify-between" v-if="employees.last_page > 1">
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        Page <span class="font-semibold text-gray-900 dark:text-white">{{ employees.current_page }}</span> of 
                        <span class="font-semibold text-gray-900 dark:text-white">{{ employees.last_page }}</span> ({{ employees.total }} total employees)
                    </span>
                    
                    <div class="inline-flex gap-1">
                        <button
                            @click="changePage(employees.current_page - 1)"
                            :disabled="employees.current_page === 1"
                            class="px-3 py-1 text-xs font-medium rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 disabled:opacity-40 disabled:cursor-not-allowed transition"
                        >
                            Previous
                        </button>
                        <button
                            @click="changePage(employees.current_page + 1)"
                            :disabled="employees.current_page === employees.last_page"
                            class="px-3 py-1 text-xs font-medium rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 disabled:opacity-40 disabled:cursor-not-allowed transition"
                        >
                            Next
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Employee Modal -->
        <Modal :show="isModalOpen" @close="closeModal" maxWidth="5xl">
            <div class="p-6 max-h-[85vh] overflow-y-auto space-y-6 text-gray-900 dark:text-gray-100 transition-colors">
                
                <!-- Modal Header -->
                <div class="flex items-center justify-between border-b border-gray-200 dark:border-gray-700 pb-4">
                    <div>
                        <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100">
                            Edit Employee Profile
                        </h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                            Update institutional credentials, pay scale, and departmental assignments
                        </p>
                    </div>
                    <button @click="closeModal" class="p-1.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 rounded-lg">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <!-- Validation Error Summary in Modal -->
                <div v-if="hasErrors" class="p-4 bg-red-50 dark:bg-red-950/40 border-l-4 border-red-500 rounded-r-xl text-xs text-red-700 dark:text-red-300 space-y-1">
                    <p class="font-semibold text-sm">Please resolve the following errors:</p>
                    <ul class="list-disc list-inside space-y-0.5">
                        <li v-for="(errList, key) in formErrors" :key="key">{{ Array.isArray(errList) ? errList[0] : errList }}</li>
                    </ul>
                </div>

                <form @submit.prevent="saveEmployee" class="space-y-6">
                    
                    <!-- 1. Account & Security -->
                    <div class="p-5 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-900/60 space-y-4 shadow-sm transition-colors">
                        <div class="flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-gray-100">
                            <span class="p-1.5 bg-emerald-100 dark:bg-emerald-950/60 rounded text-emerald-600 dark:text-emerald-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                            </span>
                            Account & Security
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <div>
                                <InputLabel for="edit_name" value="Name *" />
                                <TextInput id="edit_name" type="text" class="mt-1 block w-full text-sm" v-model="form.name" required />
                                <InputError class="mt-1" :message="formErrors.name?.[0]" />
                            </div>
                            <div>
                                <InputLabel for="edit_email" value="Email Address *" />
                                <TextInput id="edit_email" type="email" class="mt-1 block w-full text-sm" v-model="form.email" required />
                                <InputError class="mt-1" :message="formErrors.email?.[0]" />
                            </div>
                            <div>
                                <InputLabel for="edit_gender" value="Gender *" />
                                <select id="edit_gender" v-model="form.gender" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-sm" required>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                    <option value="other">Other</option>
                                </select>
                                <InputError class="mt-1" :message="formErrors.gender?.[0]" />
                            </div>
                            <div class="flex items-center pt-2">
                                <label class="flex items-center gap-2 cursor-pointer select-none">
                                    <Checkbox id="edit_isActive" v-model:checked="form.isActive" class="text-emerald-600 focus:ring-emerald-500" />
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Account is Active</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- 2. Employment & Pay Structure -->
                    <div class="p-5 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-900/60 space-y-4 shadow-sm transition-colors">
                        <div class="flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-gray-100">
                            <span class="p-1.5 bg-emerald-100 dark:bg-emerald-950/60 rounded text-emerald-600 dark:text-emerald-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
                            </span>
                            Employment & Pay Structure
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <InputLabel for="edit_employeeCode" value="Employee Code / ID *" />
                                <TextInput id="edit_employeeCode" type="text" class="mt-1 block w-full text-sm" v-model="form.employeeCode" required />
                                <InputError class="mt-1" :message="formErrors.employeeCode?.[0]" />
                            </div>
                            <div>
                                <InputLabel for="edit_jobStatus" value="Job Status *" />
                                <select id="edit_jobStatus" v-model="form.jobStatus" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-sm" required>
                                    <option value="permanent">Permanent</option>
                                    <option value="contract">Contract</option>
                                    <option value="temporary">Temporary</option>
                                    <option value="others">Others</option>
                                </select>
                                <InputError class="mt-1" :message="formErrors.jobStatus?.[0]" />
                            </div>
                            <div>
                                <InputLabel for="edit_joining_date" value="Joining Date" />
                                <TextInput id="edit_joining_date" type="date" class="mt-1 block w-full text-sm dark:[color-scheme:dark]" v-model="form.joining_date" />
                                <InputError class="mt-1" :message="formErrors.joining_date?.[0]" />
                            </div>
                            <div>
                                <InputLabel for="edit_pay_scale_id" value="Pay Scale *" />
                                <select id="edit_pay_scale_id" v-model="form.pay_scale_id" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-sm" required>
                                    <option value="" disabled>Select Pay Scale</option>
                                    <option v-for="ps in payScales" :key="ps.id" :value="ps.id">{{ ps.scale_name }}</option>
                                </select>
                                <InputError class="mt-1" :message="formErrors.pay_scale_id?.[0]" />
                            </div>
                            <div>
                                <InputLabel for="edit_grade_id" value="Grade *" />
                                <select id="edit_grade_id" v-model="form.grade_id" :disabled="!form.pay_scale_id" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-sm disabled:opacity-50" required>
                                    <option value="" disabled>{{ form.pay_scale_id ? 'Select Grade' : 'Choose Pay Scale First' }}</option>
                                    <option v-for="g in availableGrades" :key="g.id" :value="g.id">{{ g.grade_name }}</option>
                                </select>
                                <InputError class="mt-1" :message="formErrors.grade_id?.[0]" />
                            </div>
                            <div>
                                <InputLabel for="edit_payroll_group_id" value="Payroll Group *" />
                                <select id="edit_payroll_group_id" v-model="form.payroll_group_id" :disabled="!form.grade_id" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-sm disabled:opacity-50" required>
                                    <option value="" disabled>{{ form.grade_id ? 'Select Payroll Group' : 'Choose Grade First' }}</option>
                                    <option v-for="pg in availablePayrollGroups" :key="pg.id" :value="pg.id">
                                        {{ pg.payroll_group_type?.group_name || ('Group #' + pg.id) }}
                                    </option>
                                </select>
                                <InputError class="mt-1" :message="formErrors.payroll_group_id?.[0]" />
                            </div>
                            <div>
                                <InputLabel for="edit_incrementDate" value="Annual Increment Date" />
                                <TextInput id="edit_incrementDate" type="date" class="mt-1 block w-full text-sm dark:[color-scheme:dark]" v-model="form.incrementDate" />
                                <InputError class="mt-1" :message="formErrors.incrementDate?.[0]" />
                            </div>
                            <div class="flex items-center pt-6">
                                <label class="flex items-center gap-2 cursor-pointer select-none">
                                    <Checkbox id="edit_holdIncrement" v-model:checked="form.holdIncrement" class="text-emerald-600 focus:ring-emerald-500" />
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Hold Increment</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- 3. Departments & Categories Assignments -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Departments -->
                        <div class="p-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-900/60 space-y-3 shadow-sm transition-colors">
                            <div class="flex justify-between items-center">
                                <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Department / Offices *</h3>
                                <PrimaryButton type="button" @click="addField('department_offices', { department_office_id: '' })" class="!px-2.5 !py-1 text-xs">+ Add</PrimaryButton>
                            </div>
                            <div class="space-y-3">
                                <div v-for="(dept, idx) in form.department_offices" :key="'dept_'+idx" class="flex items-center gap-2">
                                    <div class="flex-1">
                                        <select :id="'edit_dept_'+idx" v-model="dept.department_office_id" class="block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-xs" required>
                                            <option value="" disabled>Select Department</option>
                                            <option v-for="d in availableDepartments(idx)" :key="d.id" :value="d.id">{{ d.name }}</option>
                                        </select>
                                    </div>
                                    <button v-if="form.department_offices.length > 1" type="button" @click="removeField('department_offices', idx)" class="p-1.5 text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 transition">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                    </button>
                                </div>
                            </div>
                            <InputError class="mt-1" :message="formErrors.department_offices?.[0]" />
                        </div>

                        <!-- Categories -->
                        <div class="p-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-900/60 space-y-3 shadow-sm transition-colors">
                            <div class="flex justify-between items-center">
                                <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Staff Categories</h3>
                                <PrimaryButton type="button" @click="addField('user_categories', { user_category_id: '' })" class="!px-2.5 !py-1 text-xs">+ Add</PrimaryButton>
                            </div>
                            <div class="space-y-3">
                                <div v-for="(cat, idx) in form.user_categories" :key="'cat_'+idx" class="flex items-center gap-2">
                                    <div class="flex-1">
                                        <select :id="'edit_cat_'+idx" v-model="cat.user_category_id" class="block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-xs">
                                            <option value="" disabled>Select Staff Category</option>
                                            <option v-for="c in availableUserCategories(idx)" :key="c.id" :value="c.id">{{ c.name }}</option>
                                        </select>
                                    </div>
                                    <button v-if="form.user_categories.length > 1" type="button" @click="removeField('user_categories', idx)" class="p-1.5 text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 transition">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 4. Personal Profile & Demographics -->
                    <div class="p-5 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-900/60 space-y-4 shadow-sm transition-colors">
                        <div class="flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-gray-100">
                            <span class="p-1.5 bg-emerald-100 dark:bg-emerald-950/60 rounded text-emerald-600 dark:text-emerald-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
                            </span>
                            Personal Information
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-4">
                            <div>
                                <InputLabel for="edit_date_of_birth" value="Date of Birth" />
                                <TextInput id="edit_date_of_birth" type="date" class="mt-1 block w-full text-sm dark:[color-scheme:dark]" v-model="form.date_of_birth" />
                            </div>
                            <div>
                                <InputLabel for="edit_age" value="Age (Years)" />
                                <TextInput id="edit_age" type="number" class="mt-1 block w-full text-sm" v-model="form.age" />
                            </div>
                            <div>
                                <InputLabel for="edit_maritial_status" value="Marital Status" />
                                <select id="edit_maritial_status" v-model="form.maritial_status" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-sm">
                                    <option value="single">Single</option>
                                    <option value="married">Married</option>
                                    <option value="divorced">Divorced</option>
                                </select>
                            </div>
                            <div>
                                <InputLabel for="edit_religion" value="Religion" />
                                <select id="edit_religion" v-model="form.religion" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-sm">
                                    <option v-for="r in filterReligions" :key="r" :value="r">{{ r }}</option>
                                </select>
                            </div>
                            <div>
                                <InputLabel for="edit_boy" value="Boys" />
                                <TextInput id="edit_boy" type="number" min="0" class="mt-1 block w-full text-sm" v-model="form.boy" />
                            </div>
                            <div>
                                <InputLabel for="edit_girl" value="Girls" />
                                <TextInput id="edit_girl" type="number" min="0" class="mt-1 block w-full text-sm" v-model="form.girl" />
                            </div>
                            <div>
                                <InputLabel for="edit_childCount" value="Total Children" />
                                <TextInput id="edit_childCount" type="number" class="mt-1 block w-full bg-gray-100 dark:bg-gray-800 dark:border-gray-700 text-gray-500 dark:text-gray-400 cursor-not-allowed text-sm" v-model="form.childCount" disabled />
                            </div>
                        </div>
                    </div>

                    <!-- 5. Identifications -->
                    <div class="p-5 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-900/60 space-y-4 shadow-sm transition-colors">
                        <div class="flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-gray-100">
                            <span class="p-1.5 bg-emerald-100 dark:bg-emerald-950/60 rounded text-emerald-600 dark:text-emerald-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2" /></svg>
                            </span>
                            National Identifications
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <InputLabel for="edit_nid_no" value="NID Number" />
                                <TextInput id="edit_nid_no" type="text" class="mt-1 block w-full text-sm" v-model="form.nid_no" />
                            </div>
                            <div>
                                <InputLabel for="edit_nid_img" value="NID Image (URL)" />
                                <TextInput id="edit_nid_img" type="text" class="mt-1 block w-full text-sm" v-model="form.nid_img" />
                            </div>
                            <div>
                                <InputLabel for="edit_bid_no" value="BID Number" />
                                <TextInput id="edit_bid_no" type="text" class="mt-1 block w-full text-sm" v-model="form.bid_no" />
                            </div>
                            <div>
                                <InputLabel for="edit_bidImg" value="BID Image (URL)" />
                                <TextInput id="edit_bidImg" type="text" class="mt-1 block w-full text-sm" v-model="form.bidImg" />
                            </div>
                            <div>
                                <InputLabel for="edit_tin_no" value="TIN Number" />
                                <TextInput id="edit_tin_no" type="text" class="mt-1 block w-full text-sm" v-model="form.tin_no" />
                            </div>
                            <div>
                                <InputLabel for="edit_tin_img" value="TIN Image (URL)" />
                                <TextInput id="edit_tin_img" type="text" class="mt-1 block w-full text-sm" v-model="form.tin_img" />
                            </div>
                        </div>
                    </div>

                    <!-- 6. Contacts, Accommodation & Attributes -->
                    <div class="p-5 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-900/60 space-y-4 shadow-sm transition-colors">
                        <div class="flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-gray-100">
                            <span class="p-1.5 bg-emerald-100 dark:bg-emerald-950/60 rounded text-emerald-600 dark:text-emerald-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" /></svg>
                            </span>
                            Contacts, Accommodation & Attributes
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Phones -->
                            <div class="space-y-3">
                                <div class="flex justify-between items-center">
                                    <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-gray-400">Phone Numbers</h4>
                                    <PrimaryButton type="button" @click="addField('phone_numbers', { phoneNumber: '' })" class="!px-2 !py-0.5 text-xs">+ Add</PrimaryButton>
                                </div>
                                <div v-for="(phone, idx) in form.phone_numbers" :key="'phone_'+idx" class="flex items-center gap-2">
                                    <TextInput :id="'edit_phone_'+idx" type="text" class="block w-full text-xs" v-model="phone.phoneNumber" placeholder="Phone Number" />
                                    <button v-if="form.phone_numbers.length > 1" type="button" @click="removeField('phone_numbers', idx)" class="text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Addresses -->
                            <div class="space-y-3">
                                <div class="flex justify-between items-center">
                                    <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-gray-400">Addresses</h4>
                                    <PrimaryButton type="button" @click="addField('addresses', { address: '' })" class="!px-2 !py-0.5 text-xs">+ Add</PrimaryButton>
                                </div>
                                <div v-for="(addr, idx) in form.addresses" :key="'addr_'+idx" class="flex items-center gap-2">
                                    <TextInput :id="'edit_addr_'+idx" type="text" class="block w-full text-xs" v-model="addr.address" placeholder="Residential Address" />
                                    <button v-if="form.addresses.length > 1" type="button" @click="removeField('addresses', idx)" class="text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Dormitory -->
                        <div class="border-t border-gray-200 dark:border-gray-700 pt-3">
                            <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-gray-400 mb-2">Campus Residence</h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <div>
                                    <InputLabel for="edit_dorm_name" value="Quarter Name" />
                                    <TextInput id="edit_dorm_name" type="text" class="mt-1 block w-full text-xs" v-model="form.dorm_name" />
                                </div>
                                <div>
                                    <InputLabel for="edit_dorm_type" value="Residence Type" />
                                    <TextInput id="edit_dorm_type" type="text" class="mt-1 block w-full text-xs" v-model="form.dorm_type" />
                                </div>
                                <div>
                                    <InputLabel for="edit_dorm_address" value="Flat / Address" />
                                    <TextInput id="edit_dorm_address" type="text" class="mt-1 block w-full text-xs" v-model="form.dorm_address" />
                                </div>
                            </div>
                        </div>

                        <!-- Attributes -->
                        <div class="border-t border-gray-200 dark:border-gray-700 pt-3">
                            <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-gray-400 mb-2">Special Attributes</h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <div>
                                    <InputLabel for="edit_emp_attr_name" value="Attribute Name" />
                                    <TextInput id="edit_emp_attr_name" type="text" class="mt-1 block w-full text-xs" v-model="form.emp_attr_name" />
                                </div>
                                <div>
                                    <InputLabel for="edit_emp_attr_description" value="Description" />
                                    <TextInput id="edit_emp_attr_description" type="text" class="mt-1 block w-full text-xs" v-model="form.emp_attr_description" />
                                </div>
                                <div class="flex items-center pt-5">
                                    <label class="flex items-center gap-2 cursor-pointer select-none">
                                        <Checkbox id="edit_emp_attr_action" v-model:checked="form.emp_attr_action" class="text-emerald-600 focus:ring-emerald-500" />
                                        <span class="text-xs font-medium text-gray-700 dark:text-gray-300">Attribute Active</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 7. Bank Accounts -->
                    <div class="p-5 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-900/60 space-y-4 shadow-sm transition-colors">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-gray-100">
                                <span class="p-1.5 bg-emerald-100 dark:bg-emerald-950/60 rounded text-emerald-600 dark:text-emerald-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" /></svg>
                                </span>
                                Bank Accounts
                            </div>
                            <PrimaryButton type="button" @click="addField('bank_accounts', { bank_routing_no: '', bank_account_no: '', bank_type: '', bank_address: '', isSalaryAccount: false })" class="!px-2.5 !py-1 text-xs">+ Add Account</PrimaryButton>
                        </div>
                        <div class="space-y-3">
                            <div v-for="(account, idx) in form.bank_accounts" :key="'bank_'+idx" class="p-3 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm space-y-3">
                                <div class="flex justify-between items-center text-xs">
                                    <span class="font-semibold text-gray-600 dark:text-gray-300">Account #{{ idx + 1 }}</span>
                                    <button v-if="form.bank_accounts.length > 1" type="button" @click="removeField('bank_accounts', idx)" class="text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">Remove</button>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                                    <div>
                                        <InputLabel :for="'edit_bank_account_no_'+idx" value="Account No" />
                                        <TextInput :id="'edit_bank_account_no_'+idx" type="text" class="mt-1 block w-full text-xs" v-model="account.bank_account_no" />
                                    </div>
                                    <div>
                                        <InputLabel :for="'edit_bank_routing_no_'+idx" value="Routing No" />
                                        <TextInput :id="'edit_bank_routing_no_'+idx" type="text" class="mt-1 block w-full text-xs" v-model="account.bank_routing_no" />
                                    </div>
                                    <div>
                                        <InputLabel :for="'edit_bank_type_'+idx" value="Type" />
                                        <TextInput :id="'edit_bank_type_'+idx" type="text" class="mt-1 block w-full text-xs" v-model="account.bank_type" />
                                    </div>
                                    <div>
                                        <InputLabel :for="'edit_bank_address_'+idx" value="Branch / Address" />
                                        <TextInput :id="'edit_bank_address_'+idx" type="text" class="mt-1 block w-full text-xs" v-model="account.bank_address" />
                                    </div>
                                </div>
                                <div>
                                    <label class="flex items-center gap-2 cursor-pointer select-none text-xs">
                                        <Checkbox :id="'edit_isSalaryAccount_'+idx" v-model:checked="account.isSalaryAccount" class="text-emerald-600 focus:ring-emerald-500" />
                                        <span class="font-medium text-emerald-700 dark:text-emerald-400">Primary Salary Account</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 8. Promotions & Leaves -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Promotions -->
                        <div class="p-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-900/60 space-y-3 shadow-sm transition-colors">
                            <div class="flex justify-between items-center">
                                <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Promotions History</h3>
                                <PrimaryButton type="button" @click="addField('promotions', { promoted_to: '', pay_scale_id: '', payroll_group_id: '' })" class="!px-2.5 !py-1 text-xs">+ Add Promo</PrimaryButton>
                            </div>
                            <div class="space-y-3">
                                <div v-for="(promo, idx) in form.promotions" :key="'promo_'+idx" class="p-3 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm space-y-2">
                                    <div class="flex justify-between items-center text-xs">
                                        <span class="font-semibold text-gray-600 dark:text-gray-300">Promo #{{ idx + 1 }}</span>
                                        <button v-if="form.promotions.length > 1" type="button" @click="removeField('promotions', idx)" class="text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">Remove</button>
                                    </div>
                                    <div>
                                        <InputLabel :for="'edit_promoted_to_'+idx" value="Position / Title Promoted To" />
                                        <TextInput :id="'edit_promoted_to_'+idx" type="text" class="mt-1 block w-full text-xs" v-model="promo.promoted_to" placeholder="e.g. Associate Professor" />
                                    </div>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                        <div>
                                            <InputLabel :for="'edit_promo_scale_'+idx" value="Pay Scale" />
                                            <select :id="'edit_promo_scale_'+idx" v-model="promo.pay_scale_id" @change="onPromoPayScaleChange(promo)" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-xs">
                                                <option value="">Select Scale</option>
                                                <option v-for="ps in payScales" :key="ps.id" :value="ps.id">{{ ps.scale_name }}</option>
                                            </select>
                                        </div>
                                        <div>
                                            <InputLabel :for="'edit_promo_group_'+idx" value="Payroll Group" />
                                            <select :id="'edit_promo_group_'+idx" v-model="promo.payroll_group_id" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-xs">
                                                <option value="">Select Group</option>
                                                <option v-for="pg in getPromoPayrollGroups(promo)" :key="pg.id" :value="pg.id">
                                                    {{ pg.payroll_group_type?.group_name || ('Group #' + pg.id) }}
                                                </option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Leaves -->
                        <div class="p-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-900/60 space-y-3 shadow-sm transition-colors">
                            <div class="flex justify-between items-center">
                                <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Leave History</h3>
                                <PrimaryButton type="button" @click="addField('leave_statuses', { onLeave: false, leave_reason: '', isPaid: false, leave_isActive: false, leave_duration: 0 })" class="!px-2.5 !py-1 text-xs">+ Add Leave</PrimaryButton>
                            </div>
                            <div class="space-y-3">
                                <div v-for="(leave, idx) in form.leave_statuses" :key="'leave_'+idx" class="p-3 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm space-y-2">
                                    <div class="flex justify-between items-center text-xs">
                                        <span class="font-semibold text-gray-600 dark:text-gray-300">Leave #{{ idx + 1 }}</span>
                                        <button v-if="form.leave_statuses.length > 1" type="button" @click="removeField('leave_statuses', idx)" class="text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">Remove</button>
                                    </div>
                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                        <div class="sm:col-span-2">
                                            <InputLabel :for="'edit_leave_reason_'+idx" value="Reason" />
                                            <TextInput :id="'edit_leave_reason_'+idx" type="text" class="mt-1 block w-full text-xs" v-model="leave.leave_reason" />
                                        </div>
                                        <div>
                                            <InputLabel :for="'edit_leave_dur_'+idx" value="Days" />
                                            <TextInput :id="'edit_leave_dur_'+idx" type="number" min="0" class="mt-1 block w-full text-xs" v-model="leave.leave_duration" />
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap gap-3 pt-1 text-xs">
                                        <label class="flex items-center gap-1.5 cursor-pointer text-gray-700 dark:text-gray-300">
                                            <Checkbox :id="'edit_onLeave_'+idx" v-model:checked="leave.onLeave" class="text-emerald-600 focus:ring-emerald-500" />
                                            On Leave
                                        </label>
                                        <label class="flex items-center gap-1.5 cursor-pointer text-gray-700 dark:text-gray-300">
                                            <Checkbox :id="'edit_isPaid_'+idx" v-model:checked="leave.isPaid" class="text-emerald-600 focus:ring-emerald-500" />
                                            Paid Leave
                                        </label>
                                        <label class="flex items-center gap-1.5 cursor-pointer text-gray-700 dark:text-gray-300">
                                            <Checkbox :id="'edit_leave_isActive_'+idx" v-model:checked="leave.leave_isActive" class="text-emerald-600 focus:ring-emerald-500" />
                                            Active
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Modal Actions Footer -->
                    <div class="pt-4 border-t border-gray-200 dark:border-gray-700 flex justify-end gap-3">
                        <SecondaryButton @click="closeModal" type="button" class="!px-4 !py-2">
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton
                            type="submit"
                            :disabled="isSaving"
                            class="!py-2 !px-5 bg-emerald-600 hover:bg-emerald-500 inline-flex items-center gap-2 shadow-sm"
                        >
                            <svg v-if="isSaving" class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            <span>{{ isSaving ? 'Saving Changes...' : 'Save Changes' }}</span>
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Bulk Employee Profile Update Modal -->
        <BulkProfileUpdateModal />
    </AuthenticatedLayout>
</template>
