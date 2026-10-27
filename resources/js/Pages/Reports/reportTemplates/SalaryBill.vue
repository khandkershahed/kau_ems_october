<script setup>
import { computed } from 'vue';
import { formatMoney, numberToWordsTaka } from './utils';

const props = defineProps({
    isBulk: { type: Boolean, default: false },
    data: { type: Array, default: () => [] },
    allData: { type: Array, default: () => [] },
    organizationName: { type: String, default: 'Khulna Agricultural University' },
    organizationLogo: { type: String, default: '/logo.png' },
    organizationAddress: { type: String, default: 'Temporary Office: 327, Jessore Road, North Mujgunni, Khalishpur, Khulna-9000' },
    date: { type: String, default: () => (new Date()).toLocaleDateString() },
    monthYear: { type: String, default: '' },
    generatedBy: { type: String, default: 'System Admin' },
    pageNumber: { type: Number, default: 1 },
    totalPages: { type: Number, default: 1 },
    reportType: { type: String, default: 'Salary Bill' },
    customTitle: { type: String, default: '' },
    customAddress: { type: String, default: '' },
    customSubtitle: { type: String, default: '' },
    customDate: { type: String, default: '' },
    customYear: { type: [Number, String], default: '' },
});

const displayTitle = computed(() => props.customTitle || props.organizationName);
const displayAddress = computed(() => props.customAddress || props.organizationAddress);
const displayDate = computed(() => props.customDate || props.date);

// Single active employee record for this sheet page
const record = computed(() => {
    if (props.data && props.data.length > 0) {
        return props.data[0];
    }
    return null;
});

// Allowance rows
const allowanceRows = computed(() => {
    if (!record.value) return [];
    const items = record.value.allowance_items || [];
    if (items.length > 0) {
        return items.map((item, idx) => ({
            sl: idx + 1,
            particulars: item.name || item.payroll_component_type?.name || 'Allowance',
            current: (item.arrear_snapshot_id || item.arrear_phase5) ? 0 : Number(item.calculated_amount ?? item.amount ?? 0),
            arrear: (item.arrear_snapshot_id || item.arrear_phase5) ? Number(item.calculated_amount ?? item.amount ?? 0) : 0,
            total: Number(item.calculated_amount ?? item.amount ?? 0),
        }));
    }
    // Fallback if no items but basic pay exists
    const basic = Number(record.value.basic_pay || 0);
    return [
        { sl: 1, particulars: 'Basic Pay', current: basic, arrear: 0, total: basic }
    ];
});

// Allowance gross
const grossAllowance = computed(() => {
    if (!record.value) return 0;
    if (record.value.total_allowance !== undefined && record.value.total_allowance !== null) {
        return Number(record.value.total_allowance);
    }
    return allowanceRows.value.reduce((acc, row) => acc + row.total, 0);
});

// Deduction rows
const deductionRows = computed(() => {
    if (!record.value) return [];
    const items = record.value.deduction_items || [];
    if (items.length > 0) {
        return items.map((item, idx) => ({
            sl: idx + 1,
            particulars: item.name || item.payroll_component_type?.name || 'Deduction',
            current: (item.arrear_snapshot_id || item.arrear_phase5) ? 0 : Number(item.calculated_amount ?? item.amount ?? 0),
            arrear: (item.arrear_snapshot_id || item.arrear_phase5) ? Number(item.calculated_amount ?? item.amount ?? 0) : 0,
            total: Number(item.calculated_amount ?? item.amount ?? 0),
        }));
    }
    const deduct = Number(record.value.total_deduction || 0);
    if (deduct > 0) {
        return [
            { sl: 1, particulars: 'General Deductions', current: deduct, arrear: 0, total: deduct }
        ];
    }
    return [];
});

// Total deduction
const totalDeductions = computed(() => {
    if (!record.value) return 0;
    if (record.value.total_deduction !== undefined && record.value.total_deduction !== null) {
        return Number(record.value.total_deduction);
    }
    return deductionRows.value.reduce((acc, row) => acc + row.total, 0);
});

const grossCurrent = computed(() => allowanceRows.value.reduce((sum, row) => sum + row.current, 0));
const grossArrear = computed(() => allowanceRows.value.reduce((sum, row) => sum + row.arrear, 0));
const deductionCurrent = computed(() => deductionRows.value.reduce((sum, row) => sum + row.current, 0));
const deductionArrear = computed(() => deductionRows.value.reduce((sum, row) => sum + row.arrear, 0));

// Net payable
const netPayable = computed(() => {
    if (!record.value) return 0;
    if (record.value.salary_total !== undefined && record.value.salary_total !== null) {
        return Number(record.value.salary_total);
    }
    return grossAllowance.value - totalDeductions.value;
});

// In words
const inWords = computed(() => {
    return numberToWordsTaka(netPayable.value);
});
</script>

<template>
    <!-- A4 Paper Container -->
    <div class="a4-sheet bg-white text-slate-900 text-[11px] leading-tight font-sans mx-auto p-8 sm:p-10 shadow-lg border border-slate-200 relative min-h-[297mm] flex flex-col justify-between select-text print:p-6 print:shadow-none print:border-0">
        
        <!-- Top Section -->
        <div>
            <!-- Header with Logo and University Details -->
            <div class="relative flex items-center justify-center pb-4 border-b border-slate-300">
                <div class="absolute left-0 top-0">
                    <img 
                        :src="organizationLogo" 
                        alt="University Logo" 
                        class="h-14 w-14 object-contain"
                        @error="(e) => e.target.style.display = 'none'"
                    />
                </div>
                <div class="text-center px-16">
                    <h1 class="text-base sm:text-lg font-bold text-slate-900 tracking-tight">
                        {{ displayTitle }}
                    </h1>
                    <p class="text-[10px] text-slate-600 mt-0.5">
                        {{ displayAddress }}
                    </p>
                    <div class="inline-block mt-1.5 px-3 py-0.5 font-bold text-xs border border-slate-800 rounded-sm uppercase tracking-wide">
                        {{ reportType === 'Arrear & Deduction Statement' ? 'Arrear & Deduction Statement' : 'Salary Bill' }}
                    </div>
                    <div v-if="customSubtitle" class="text-[10px] font-semibold text-slate-700 mt-1">
                        {{ customSubtitle }}
                    </div>
                </div>
            </div>

            <!-- Graceful empty error notice -->
            <div v-if="!record" class="my-16 p-8 text-center border-2 border-dashed border-slate-300 rounded-md bg-slate-50">
                <svg class="mx-auto h-10 w-10 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <h3 class="mt-2 text-sm font-semibold text-slate-800">No Employee Record Available</h3>
                <p class="mt-1 text-xs text-slate-500">Please select an employee from the left pane to preview the Salary Bill.</p>
            </div>

            <template v-else>
                <!-- Provisional notice if not generated -->
                <div v-if="!record.is_generated" class="mt-2.5 px-3 py-1.5 bg-amber-50 border border-amber-300 rounded text-[10px] text-amber-800 flex items-center justify-between print:hidden">
                    <span class="flex items-center gap-1.5">
                        <span class="font-bold">⚠️ Notice:</span> Salary is not yet finalized/generated for this month. Displaying provisional estimated figures.
                    </span>
                    <span class="font-semibold px-1.5 py-0.2 bg-amber-200 text-amber-900 rounded text-[9px]">Provisional</span>
                </div>

                <!-- Employee Particulars Grid -->
                <div class="grid grid-cols-2 gap-x-6 gap-y-1.5 my-3.5 text-[11px] border border-slate-300 p-2.5 rounded-sm bg-slate-50/50">
                    <div class="space-y-1">
                        <div class="flex">
                            <span class="w-24 font-bold text-slate-800">Name</span>
                            <span class="font-semibold text-slate-900">: {{ record.name }}</span>
                        </div>
                        <div class="flex">
                            <span class="w-24 font-bold text-slate-800">Designation</span>
                            <span class="text-slate-800">: {{ record.designation }}</span>
                        </div>
                        <div class="flex">
                            <span class="w-24 font-bold text-slate-800">Department</span>
                            <span class="text-slate-800">: {{ record.department }}</span>
                        </div>
                        <div class="flex">
                            <span class="w-24 font-bold text-slate-800">Pay Scale</span>
                            <span class="text-slate-800">: {{ record.grade ? record.grade : record.pay_scale }} ({{ formatMoney(record.basic_pay) }})</span>
                        </div>
                        <div v-if="record.user_category" class="flex">
                            <span class="w-24 font-bold text-slate-800">Class</span>
                            <span class="text-slate-800">: {{ record.user_category }}</span>
                        </div>
                        <div v-if="record.religion && record.religion !== 'N/A'" class="flex">
                            <span class="w-24 font-bold text-slate-800">Religion</span>
                            <span class="text-slate-800">: {{ record.religion }}</span>
                        </div>
                    </div>

                    <div class="space-y-1">
                        <div class="flex">
                            <span class="w-28 font-bold text-slate-800">Service ID</span>
                            <span class="text-slate-800">: {{ record.employee_code }}</span>
                        </div>
                        <div v-if="record.email" class="flex">
                            <span class="w-28 font-bold text-slate-800">Email</span>
                            <span class="text-slate-800">: {{ record.email }}</span>
                        </div>
                        <div class="flex">
                            <span class="w-28 font-bold text-slate-800">Basic</span>
                            <span class="text-slate-800">: {{ formatMoney(record.basic_pay) }}</span>
                        </div>
                        <div class="flex">
                            <span class="w-28 font-bold text-slate-800">Increment Date</span>
                            <span class="text-slate-800">: {{ record.joining_date || 'N/A' }}</span>
                        </div>
                        <div class="flex">
                            <span class="w-28 font-bold text-slate-800">Month</span>
                            <span class="text-slate-800">: {{ monthYear || `${record.year}-${String(record.month_id).padStart(2, '0')}` }}</span>
                        </div>
                    </div>
                </div>

                <!-- Allowances Table -->
                <div class="mt-3">
                    <div class="font-bold text-[11px] uppercase tracking-wide text-slate-800 mb-1">
                        Allowances
                    </div>
                    <table class="w-full border-collapse border border-slate-300 text-[10px]">
                        <thead>
                            <tr class="bg-slate-100 text-slate-800 font-bold border-b border-slate-300">
                                <th class="border border-slate-300 px-2 py-1 text-center w-10">SL.No</th>
                                <th class="border border-slate-300 px-2 py-1 text-left">Particulars</th>
                                <th class="border border-slate-300 px-2 py-1 text-right w-24">Current</th>
                                <th class="border border-slate-300 px-2 py-1 text-right w-20">Arrear</th>
                                <th class="border border-slate-300 px-2 py-1 text-right w-24">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in allowanceRows" :key="item.sl" class="border-b border-slate-200">
                                <td class="border border-slate-300 px-2 py-1 text-center text-slate-600">{{ item.sl }}</td>
                                <td class="border border-slate-300 px-2 py-1 text-left font-medium text-slate-800">{{ item.particulars }}</td>
                                <td class="border border-slate-300 px-2 py-1 text-right tabular-nums">{{ formatMoney(item.current) }}</td>
                                <td class="border border-slate-300 px-2 py-1 text-right tabular-nums text-slate-500">{{ formatMoney(item.arrear) }}</td>
                                <td class="border border-slate-300 px-2 py-1 text-right font-medium tabular-nums">{{ formatMoney(item.total) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="bg-slate-50 font-bold border-t-2 border-slate-400 text-slate-900">
                                <td class="border border-slate-300 px-2 py-1 text-center"></td>
                                <td class="border border-slate-300 px-2 py-1 text-left">Gross Salary</td>
                                <td class="border border-slate-300 px-2 py-1 text-right tabular-nums">{{ formatMoney(grossCurrent) }}</td>
                                <td class="border border-slate-300 px-2 py-1 text-right tabular-nums">{{ formatMoney(grossArrear) }}</td>
                                <td class="border border-slate-300 px-2 py-1 text-right tabular-nums">{{ formatMoney(grossAllowance) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Deductions Table -->
                <div class="mt-3.5">
                    <div class="font-bold text-[11px] uppercase tracking-wide text-slate-800 mb-1">
                        Deductions
                    </div>
                    <table class="w-full border-collapse border border-slate-300 text-[10px]">
                        <thead>
                            <tr class="bg-slate-100 text-slate-800 font-bold border-b border-slate-300">
                                <th class="border border-slate-300 px-2 py-1 text-center w-10">Sl.No</th>
                                <th class="border border-slate-300 px-2 py-1 text-left">Particulars</th>
                                <th class="border border-slate-300 px-2 py-1 text-right w-24">Current</th>
                                <th class="border border-slate-300 px-2 py-1 text-right w-20">Arrear</th>
                                <th class="border border-slate-300 px-2 py-1 text-right w-24">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="deductionRows.length === 0" class="border-b border-slate-200">
                                <td class="border border-slate-300 px-2 py-1 text-center text-slate-400">-</td>
                                <td class="border border-slate-300 px-2 py-1 text-left italic text-slate-400">No deductions recorded</td>
                                <td class="border border-slate-300 px-2 py-1 text-right tabular-nums">0.00</td>
                                <td class="border border-slate-300 px-2 py-1 text-right tabular-nums">0.00</td>
                                <td class="border border-slate-300 px-2 py-1 text-right tabular-nums">0.00</td>
                            </tr>
                            <tr v-else v-for="item in deductionRows" :key="item.sl" class="border-b border-slate-200">
                                <td class="border border-slate-300 px-2 py-1 text-center text-slate-600">{{ item.sl }}</td>
                                <td class="border border-slate-300 px-2 py-1 text-left font-medium text-slate-800">{{ item.particulars }}</td>
                                <td class="border border-slate-300 px-2 py-1 text-right tabular-nums">{{ formatMoney(item.current) }}</td>
                                <td class="border border-slate-300 px-2 py-1 text-right tabular-nums text-slate-500">{{ formatMoney(item.arrear) }}</td>
                                <td class="border border-slate-300 px-2 py-1 text-right font-medium tabular-nums">{{ formatMoney(item.total) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="bg-slate-50 font-bold border-t-2 border-slate-400 text-slate-900">
                                <td class="border border-slate-300 px-2 py-1 text-center"></td>
                                <td class="border border-slate-300 px-2 py-1 text-left">Total Deductions</td>
                                <td class="border border-slate-300 px-2 py-1 text-right tabular-nums">{{ formatMoney(deductionCurrent) }}</td>
                                <td class="border border-slate-300 px-2 py-1 text-right tabular-nums">{{ formatMoney(deductionArrear) }}</td>
                                <td class="border border-slate-300 px-2 py-1 text-right tabular-nums">{{ formatMoney(totalDeductions) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Net Payable Summary -->
                <div class="mt-2.5 flex justify-end">
                    <div class="border border-slate-400 bg-slate-100 px-4 py-1.5 flex items-center gap-6 font-bold text-xs rounded-sm">
                        <span class="text-slate-800">Net Payable:</span>
                        <span class="text-slate-900 tabular-nums text-sm">{{ formatMoney(netPayable) }}</span>
                    </div>
                </div>

                <!-- Amount in words -->
                <div class="mt-2 text-[10px] text-slate-800 font-medium">
                    <span class="font-bold">In word:</span> {{ inWords }}
                </div>

                <!-- Bottom Bank & Signatures Box -->
                <div class="mt-8 pt-4 border-t border-slate-300 grid grid-cols-3 items-end text-[10px]">
                    <div class="text-left space-y-1">
                        <div class="font-bold text-slate-800">Bank Account:</div>
                        <div class="text-slate-900 font-medium">{{ record.bank_account_no || 'N/A' }}</div>
                        <div class="text-[9px] text-slate-500">{{ record.bank_address || '' }}</div>
                    </div>

                    <div class="flex justify-center">
                        <div class="w-20 h-14 border border-dashed border-slate-400 flex items-center justify-center text-[9px] text-slate-400 font-bold uppercase tracking-wider">
                            STAMP
                        </div>
                    </div>

                    <div class="text-right space-y-1">
                        <div class="h-10"></div>
                        <div class="border-t border-slate-400 pt-1 font-bold text-slate-800">
                            Signature of Employee
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Sheet Page Footer -->
        <div class="mt-6 pt-3 border-t border-slate-200 flex items-center justify-between text-[9px] text-slate-400">
            <div>
                <span>Generated by: {{ generatedBy }}</span>
                <span class="mx-1.5">•</span>
                <span>{{ displayDate }}</span>
            </div>
            <div class="font-medium text-slate-500">
                Page {{ pageNumber }} of {{ totalPages }}
            </div>
        </div>

    </div>
</template>

<style scoped>
@media print {
    .a4-sheet {
        box-shadow: none !important;
        border: none !important;
        width: 100% !important;
        min-height: 100vh !important;
        margin: 0 !important;
        padding: 10mm !important;
    }
}
</style>