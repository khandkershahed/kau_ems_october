<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    employee: { type: Object, default: null },
    year: { type: Number, default: 2026 },
    month: { type: Number, default: 11 },
});

const emit = defineEmits(['close']);

const loading = ref(false);
const errorMessage = ref('');
const setupData = ref(null);
const printAreaRef = ref(null);

const monthNames = [
    '',
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

const periodCode = computed(() => `${props.year}-${String(props.month).padStart(2, '0')}`);
const periodTitle = computed(() => `${monthNames[Number(props.month)] || props.month} ${props.year}`);

const formatMoney = (val) => {
    const num = Number(val || 0);
    return num.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

const activeAllowances = computed(() => {
    const list = setupData.value?.allowances || [];
    return list.filter(
        (r) =>
            Math.abs(Number(r.current_amount || 0)) >= 0.005 ||
            Math.abs(Number(r.arrear_amount || 0)) >= 0.005 ||
            Math.abs(Number(r.total_amount || 0)) >= 0.005,
    );
});

const activeDeductions = computed(() => {
    const list = setupData.value?.deductions || [];
    return list.filter(
        (r) =>
            Math.abs(Number(r.current_amount || 0)) >= 0.005 ||
            Math.abs(Number(r.arrear_amount || 0)) >= 0.005 ||
            Math.abs(Number(r.total_amount || 0)) >= 0.005,
    );
});

const basicPay = computed(() => {
    const basicRow = (setupData.value?.allowances || []).find((r) =>
        /\bbasic(?:\s+pay|\s+salary)?\b/i.test(String(r.name || '')),
    );
    return Number(basicRow?.current_amount ?? props.employee?.basic_pay ?? 0);
});

const numberToWordsUnder1000 = (n) => {
    const ones = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
        'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
        'Seventeen', 'Eighteen', 'Nineteen',
    ];
    const tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    let words = '';
    if (n >= 100) {
        words += `${ones[Math.floor(n / 100)]} Hundred `;
        n %= 100;
    }
    if (n >= 20) {
        words += `${tens[Math.floor(n / 10)]} `;
        n %= 10;
    }
    if (n > 0) {
        words += `${ones[n]} `;
    }
    return words.trim();
};

const amountInWords = computed(() => {
    const raw = Math.abs(Number(setupData.value?.net_payable ?? props.employee?.net_payable ?? 0));
    const whole = Math.floor(raw);
    const paisa = Math.round((raw - whole) * 100);

    if (whole === 0 && paisa === 0) {
        return 'Taka Zero only.';
    }

    const parts = [];
    const crore = Math.floor(whole / 10000000);
    const lakh = Math.floor((whole % 10000000) / 100000);
    const thousand = Math.floor((whole % 100000) / 1000);
    const rest = whole % 1000;

    if (crore > 0) parts.push(`${numberToWordsUnder1000(crore)} Crore`);
    if (lakh > 0) parts.push(`${numberToWordsUnder1000(lakh)} Lakh`);
    if (thousand > 0) parts.push(`${numberToWordsUnder1000(thousand)} Thousand`);
    if (rest > 0) parts.push(numberToWordsUnder1000(rest));

    let text = `Taka ${parts.join(' ')}`;
    if (paisa > 0) {
        text += ` and Paisa ${numberToWordsUnder1000(paisa)}`;
    }
    return `${text} only.`;
});

const fetchBreakdown = async () => {
    if (!props.employee?.user_id) return;
    loading.value = true;
    errorMessage.value = '';
    try {
        const { data } = await axios.get(`/salary-setup/${props.employee.user_id}`, {
            params: { year: props.year, month: props.month },
            headers: { Accept: 'application/json' },
        });
        setupData.value = data;
    } catch (err) {
        errorMessage.value = err.response?.data?.message || err.message || 'Failed to load salary bill details.';
    } finally {
        loading.value = false;
    }
};

watch(
    () => [props.show, props.employee?.user_id, props.year, props.month],
    ([isOpen]) => {
        if (isOpen && props.employee?.user_id) {
            fetchBreakdown();
        }
    },
    { immediate: true },
);

const handlePrint = () => {
    if (!printAreaRef.value) return;
    const printWin = window.open('', '_blank', 'width=900,height=760');
    if (!printWin) {
        window.print();
        return;
    }
    printWin.document.write(`<!DOCTYPE html>
<html>
<head>
<title>Salary Bill - ${props.employee?.employee_code || ''} (${periodCode.value})</title>
<style>
  body { font-family: Arial, Helvetica, sans-serif; color: #0f172a; margin: 20px; font-size: 12px; }
  .header { text-align: center; border-bottom: 2px solid #0f172a; padding-bottom: 10px; margin-bottom: 14px; }
  .header h1 { margin: 0; font-size: 17px; letter-spacing: 0.3px; }
  .header h2 { margin: 4px 0 0; font-size: 13px; font-weight: 600; color: #334155; }
  .meta { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
  .meta td { padding: 4px 6px; border: 1px solid #cbd5e1; font-size: 11.5px; }
  .meta td.lbl { background: #f8fafc; font-weight: 600; width: 18%; color: #334155; }
  table.bill { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
  table.bill th, table.bill td { border: 1px solid #cbd5e1; padding: 5px 7px; font-size: 11.5px; }
  table.bill th { background: #f1f5f9; text-align: left; font-weight: 700; }
  table.bill th.num, table.bill td.num { text-align: right; font-family: monospace; }
  table.bill tfoot td { background: #f8fafc; font-weight: 700; }
  .net-box { border: 1.5px solid #0f172a; background: #ecfdf5; padding: 10px 12px; margin-bottom: 16px; }
  .net-row { display: flex; justify-content: space-between; font-size: 14px; font-weight: 700; }
  .in-word { margin-top: 4px; font-size: 11.5px; font-style: italic; }
  .sigs { display: flex; justify-content: space-between; margin-top: 42px; text-align: center; font-size: 11px; }
  .sig-col { width: 28%; border-top: 1px solid #334155; padding-top: 5px; }
  @media print { body { margin: 8mm; } }
</style>
</head>
<body>${printAreaRef.value.innerHTML}</body>
</html>`);
    printWin.document.close();
    printWin.focus();
    setTimeout(() => {
        printWin.print();
        printWin.close();
    }, 250);
};
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="emit('close')">
        <div class="flex max-h-[90vh] flex-col">
            <!-- Modal Top Bar -->
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3.5">
                <div>
                    <h2 class="text-base font-bold text-slate-900">
                        Salary Bill / Pay Certificate Preview
                    </h2>
                    <p class="text-xs text-slate-500">
                        {{ periodTitle }} ({{ periodCode }}) · {{ employee?.employee_code }} — {{ employee?.name }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        :disabled="loading || !setupData"
                        @click="handlePrint"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-40"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                        </svg>
                        Print Bill
                    </button>
                    <SecondaryButton type="button" @click="emit('close')">Close</SecondaryButton>
                </div>
            </div>

            <!-- Scrollable Printable Body -->
            <div class="overflow-y-auto px-5 py-4">
                <div v-if="loading" class="py-12 text-center text-sm text-slate-500">
                    Loading salary bill breakdown...
                </div>

                <div
                    v-else-if="errorMessage"
                    class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-xs font-medium text-rose-800"
                >
                    {{ errorMessage }}
                </div>

                <div v-else-if="setupData" ref="printAreaRef" class="space-y-4 text-xs text-slate-800">
                    <!-- KAU Header -->
                    <div class="header border-b-2 border-slate-800 pb-3 text-center">
                        <h1 class="text-base font-extrabold uppercase tracking-wide text-slate-900">
                            Khulna Agricultural University, Khulna
                        </h1>
                        <h2 class="mt-0.5 text-xs font-semibold text-slate-600">
                            Monthly Salary Bill / Pay Certificate — {{ periodTitle }} ({{ periodCode }})
                        </h2>
                    </div>

                    <!-- Employee Details Grid -->
                    <table class="meta w-full border-collapse text-xs">
                        <tbody>
                            <tr>
                                <td class="lbl w-1/5 border border-slate-300 bg-slate-50 px-2.5 py-1.5 font-semibold text-slate-600">Employee Name</td>
                                <td class="w-3/10 border border-slate-300 px-2.5 py-1.5 font-semibold text-slate-900">{{ setupData.employee?.name || employee?.name }}</td>
                                <td class="lbl w-1/5 border border-slate-300 bg-slate-50 px-2.5 py-1.5 font-semibold text-slate-600">Service ID</td>
                                <td class="w-3/10 border border-slate-300 px-2.5 py-1.5 font-mono font-semibold text-slate-900">{{ setupData.employee?.code || employee?.employee_code }}</td>
                            </tr>
                            <tr>
                                <td class="lbl border border-slate-300 bg-slate-50 px-2.5 py-1.5 font-semibold text-slate-600">Designation</td>
                                <td class="border border-slate-300 px-2.5 py-1.5">{{ setupData.employee?.designation || employee?.designation_name || 'N/A' }}</td>
                                <td class="lbl border border-slate-300 bg-slate-50 px-2.5 py-1.5 font-semibold text-slate-600">Department</td>
                                <td class="border border-slate-300 px-2.5 py-1.5">{{ setupData.employee?.department || employee?.department_name || 'N/A' }}</td>
                            </tr>
                            <tr>
                                <td class="lbl border border-slate-300 bg-slate-50 px-2.5 py-1.5 font-semibold text-slate-600">Pay Scale &amp; Grade</td>
                                <td class="border border-slate-300 px-2.5 py-1.5">
                                    {{ setupData.pay_scale || employee?.pay_scale_name || 'N/A' }}
                                    <span v-if="setupData.grade || employee?.grade_name"> ({{ setupData.grade || employee?.grade_name }})</span>
                                </td>
                                <td class="lbl border border-slate-300 bg-slate-50 px-2.5 py-1.5 font-semibold text-slate-600">Basic Pay (BDT)</td>
                                <td class="border border-slate-300 px-2.5 py-1.5 font-mono font-bold text-slate-900">{{ formatMoney(basicPay) }}</td>
                            </tr>
                            <tr>
                                <td class="lbl border border-slate-300 bg-slate-50 px-2.5 py-1.5 font-semibold text-slate-600">Payroll Month</td>
                                <td class="border border-slate-300 px-2.5 py-1.5 font-mono">{{ periodCode }} ({{ periodTitle }})</td>
                                <td class="lbl border border-slate-300 bg-slate-50 px-2.5 py-1.5 font-semibold text-slate-600">Bank A/C No.</td>
                                <td class="border border-slate-300 px-2.5 py-1.5 font-mono">{{ employee?.bank_account_no || 'N/A' }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Allowances Table -->
                    <div>
                        <h3 class="mb-1.5 text-xs font-bold uppercase tracking-wider text-slate-700">Allowances</h3>
                        <table class="bill w-full border-collapse text-xs">
                            <thead>
                                <tr class="bg-slate-100 text-slate-700">
                                    <th class="w-12 border border-slate-300 px-2 py-1.5 text-center">Sl.No</th>
                                    <th class="border border-slate-300 px-2.5 py-1.5 text-left">Particulars</th>
                                    <th class="num w-28 border border-slate-300 px-2.5 py-1.5 text-right">Current</th>
                                    <th class="num w-28 border border-slate-300 px-2.5 py-1.5 text-right">Arrear</th>
                                    <th class="num w-28 border border-slate-300 px-2.5 py-1.5 text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(item, idx) in activeAllowances" :key="item.payroll_component_type_id">
                                    <td class="border border-slate-300 px-2 py-1 text-center">{{ idx + 1 }}</td>
                                    <td class="border border-slate-300 px-2.5 py-1">{{ item.name }}</td>
                                    <td class="num border border-slate-300 px-2.5 py-1 text-right font-mono">{{ formatMoney(item.current_amount) }}</td>
                                    <td class="num border border-slate-300 px-2.5 py-1 text-right font-mono">{{ formatMoney(item.arrear_amount) }}</td>
                                    <td class="num border border-slate-300 px-2.5 py-1 text-right font-mono font-semibold">{{ formatMoney(item.total_amount) }}</td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="bg-slate-50 font-bold text-slate-900">
                                    <td colspan="2" class="border border-slate-300 px-2.5 py-1.5 text-right">Gross Salary</td>
                                    <td class="num border border-slate-300 px-2.5 py-1.5 text-right font-mono">{{ formatMoney(setupData.regular_allowances) }}</td>
                                    <td class="num border border-slate-300 px-2.5 py-1.5 text-right font-mono">{{ formatMoney(setupData.arrear_allowances) }}</td>
                                    <td class="num border border-slate-300 px-2.5 py-1.5 text-right font-mono">{{ formatMoney(setupData.total_allowances) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- Deductions Table -->
                    <div>
                        <h3 class="mb-1.5 text-xs font-bold uppercase tracking-wider text-slate-700">Deductions</h3>
                        <table class="bill w-full border-collapse text-xs">
                            <thead>
                                <tr class="bg-slate-100 text-slate-700">
                                    <th class="w-12 border border-slate-300 px-2 py-1.5 text-center">Sl.No</th>
                                    <th class="border border-slate-300 px-2.5 py-1.5 text-left">Particulars</th>
                                    <th class="num w-28 border border-slate-300 px-2.5 py-1.5 text-right">Current</th>
                                    <th class="num w-28 border border-slate-300 px-2.5 py-1.5 text-right">Arrear</th>
                                    <th class="num w-28 border border-slate-300 px-2.5 py-1.5 text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(item, idx) in activeDeductions" :key="item.payroll_component_type_id">
                                    <td class="border border-slate-300 px-2 py-1 text-center">{{ idx + 1 }}</td>
                                    <td class="border border-slate-300 px-2.5 py-1">{{ item.name }}</td>
                                    <td class="num border border-slate-300 px-2.5 py-1 text-right font-mono">{{ formatMoney(item.current_amount) }}</td>
                                    <td class="num border border-slate-300 px-2.5 py-1 text-right font-mono">{{ formatMoney(item.arrear_amount) }}</td>
                                    <td class="num border border-slate-300 px-2.5 py-1 text-right font-mono font-semibold">{{ formatMoney(item.total_amount) }}</td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="bg-slate-50 font-bold text-slate-900">
                                    <td colspan="2" class="border border-slate-300 px-2.5 py-1.5 text-right">Total Deductions</td>
                                    <td class="num border border-slate-300 px-2.5 py-1.5 text-right font-mono">{{ formatMoney(setupData.regular_deductions) }}</td>
                                    <td class="num border border-slate-300 px-2.5 py-1.5 text-right font-mono">{{ formatMoney(setupData.arrear_deductions) }}</td>
                                    <td class="num border border-slate-300 px-2.5 py-1.5 text-right font-mono">{{ formatMoney(setupData.total_deductions) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- Net Payable Box -->
                    <div class="net-box rounded-lg border-2 border-emerald-700 bg-emerald-50/70 px-4 py-3">
                        <div class="net-row flex items-center justify-between text-sm font-extrabold text-emerald-950">
                            <span>Net Payable (BDT)</span>
                            <span class="font-mono text-base">৳{{ formatMoney(setupData.net_payable) }}</span>
                        </div>
                        <div class="in-word mt-1 text-xs italic text-slate-700">
                            <strong>In word:</strong> {{ amountInWords }}
                        </div>
                        <div v-if="employee?.bank_account_no" class="mt-1 text-[11px] text-slate-600">
                            <strong>Bank Account:</strong> {{ employee.bank_account_no }}
                            <span v-if="employee?.bank_address"> · {{ employee.bank_address }}</span>
                        </div>
                    </div>

                    <!-- Stamp & Signature Footer -->
                    <div class="sigs grid grid-cols-3 gap-6 pt-10 text-center text-[11px] text-slate-600">
                        <div class="sig-col border-t border-slate-400 pt-1.5 font-medium">
                            Prepared By<br />
                            <span class="text-[10px] text-slate-400">Accounts Section</span>
                        </div>
                        <div class="sig-col border-t border-slate-400 pt-1.5 font-medium">
                            Revenue Stamp &amp; Signature<br />
                            <span class="text-[10px] text-slate-400">Employee / Recipient</span>
                        </div>
                        <div class="sig-col border-t border-slate-400 pt-1.5 font-medium">
                            Accounts Officer / Treasurer<br />
                            <span class="text-[10px] text-slate-400">Khulna Agricultural University</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Modal>
</template>
