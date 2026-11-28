<script setup>
import { computed, ref, watch } from 'vue';
import {
    FONT_OPTIONS,
    BANGLA_MONTHS,
    toBanglaDigits,
    formatBanglaMoney,
    amountToBanglaWords,
    detectBanglaGroupLabel,
    aggregateNoteSummaryComponents,
    downloadSalaryNoteSummaryExcel,
    downloadSalaryNoteSummaryWord,
} from '@/utils/salaryNoteSummaryExport';

const props = defineProps({
    show: { type: Boolean, default: false },
    records: { type: Array, default: () => [] },
    year: { type: Number, required: true },
    month: { type: Number, required: true },
    categoryName: { type: String, default: '' },
    selectionScopeLabel: { type: String, default: '' },
});

const emit = defineEmits(['close']);

const selectedFont = ref('SolaimanLipi');
const subject1 = ref('');
const introLine = ref('');
const allowanceRows = ref([]);
const deductionRows = ref([]);
const grossTotal = ref(0);
const deductionTotal = ref(0);
const netPayable = ref(0);
const netPayableLine = ref('');
const postTableParagraph = ref('');
const bankAdviceSubject = ref('');
const bankName = ref('সোনালী ব্যাংক পিএলসি');
const bankBranch = ref('খুলনা কৃষি বিশ্ববিদ্যালয় শাখা, খুলনা');
const bankAccountNo = ref('২৭০৮৬০২০০০৫৭৭');
const bankAdviceParagraph = ref('');
const extraParagraphs = ref([]);
const signatures = ref([
    'সহকারী হিসাবরক্ষণ অফিসার',
    'সহকারী পরিচালক',
    'পরিচালক (অর্থ ও হিসাব)',
    'ট্রেজারার মহোদয়',
    'উপাচার্য মহোদয়',
]);

const activeGroupLabel = computed(() => detectBanglaGroupLabel(props.categoryName));
const banglaMonth = computed(() => BANGLA_MONTHS[Number(props.month)] || String(props.month));
const banglaYear = computed(() => toBanglaDigits(props.year));
const employeeCountBn = computed(() => toBanglaDigits(props.records?.length || 0));

const buildDefaultBankAdviceParagraph = () => {
    const netBn = formatBanglaMoney(netPayable.value);
    const netWords = amountToBanglaWords(netPayable.value);
    return `উপরে অনুমোদিত ${banglaMonth.value}' ${banglaYear.value} মাসের বেতন-ভাতাদি বাবদ নীট পরিশোধযোগ্য ${netBn} (কথায়: ${netWords}) টাকা অত্র বিশ্ববিদ্যালয়ের ${bankName.value}, ${bankBranch.value}-এ পরিচালিত হিসাব নম্বর ${bankAccountNo.value} হতে সংশ্লিষ্ট ${activeGroupLabel.value} নিজ নিজ ব্যাংক হিসাবে স্থানান্তরের প্রয়োজনীয় ব্যাংক অ্যাডভাইস জারির জন্য সদয় অনুমোদন কামনা করছি।`;
};

const initializeForm = () => {
    const agg = aggregateNoteSummaryComponents(props.records || []);
    allowanceRows.value = agg.allowanceRows.map((r) => ({ ...r }));
    deductionRows.value = agg.deductionRows.map((r) => ({ ...r }));
    grossTotal.value = agg.grossTotal;
    deductionTotal.value = agg.deductionTotal;
    netPayable.value = agg.netPayable;

    const grp = activeGroupLabel.value;
    const mBn = banglaMonth.value;
    const yBn = banglaYear.value;
    const cntBn = toBanglaDigits(agg.employeeCount);
    const grossBn = formatBanglaMoney(agg.grossTotal);
    const dedBn = formatBanglaMoney(agg.deductionTotal);
    const netBn = formatBanglaMoney(agg.netPayable);
    const netWords = amountToBanglaWords(agg.netPayable);

    subject1.value = `বিষয়: খুকৃবি এর ${grp} ${mBn}' ${yBn} মাসের বেতন ভাতাদি বাবদ আর্থিক দাবী পরিশোধের অনুমোদন।`;
    introLine.value = `অত্র বিশ্ববিদ্যালয়ের রাজস্ব খাতের আওতায় কর্মরত ${grp} (${cntBn} জন) ${mBn}' ${yBn} মাসের মোট বেতন ভাতাদি ও কর্তনযোগ্য টাকার পরিমাণ নিম্নরূপ:`;
    netPayableLine.value = `নীট পরিশোধযোগ্য টাকার পরিমাণ: ${netBn} টাকা (কথায়: ${netWords})।`;
    postTableParagraph.value = `এমতাবস্থায়, অত্র বিশ্ববিদ্যালয়ের ${grp} (${cntBn} জন) ${mBn}' ${yBn} মাসের বেতন-ভাতাদি বাবদ মোট ${grossBn} টাকা হতে বিভিন্ন খাতে সর্বমোট ${dedBn} টাকা কর্তনপূর্বক নীট পরিশোধযোগ্য ${netBn} (কথায়: ${netWords}) টাকা পরিশোধের প্রশাসনিক ও আর্থিক অনুমোদন এবং সংশ্লিষ্ট ব্যাংক হিসাবে অর্থ স্থানান্তরের জন্য নথিটি সদয় অনুমোদনের নিমিত্তে উপস্থাপন করা হলো।`;

    bankAdviceSubject.value = `বিষয়: খুকৃবি এর ${grp} ${mBn}' ${yBn} মাসের বেতন-ভাতাদি বাবদ ব্যাংক অ্যাডভাইস অনুমোদন প্রসঙ্গে।`;
    bankName.value = 'সোনালী ব্যাংক পিএলসি';
    bankBranch.value = 'খুলনা কৃষি বিশ্ববিদ্যালয় শাখা, খুলনা';
    bankAccountNo.value = '২৭০৮৬০২০০০৫৭৭';
    bankAdviceParagraph.value = buildDefaultBankAdviceParagraph();

    // Start with NO default (০৩) or (০৪) blocks; user adds dynamically via "+ Add Paragraph"
    extraParagraphs.value = [];

    signatures.value = [
        'সহকারী হিসাবরক্ষণ অফিসার',
        'সহকারী পরিচালক',
        'পরিচালক (অর্থ ও হিসাব)',
        'ট্রেজারার মহোদয়',
        'উপাচার্য মহোদয়',
    ];
};

watch(
    () => [props.show, props.records, props.year, props.month, props.categoryName],
    ([visible]) => {
        if (visible) {
            initializeForm();
        }
    },
    { immediate: true },
);

const syncBankDetailsToParagraph = () => {
    bankAdviceParagraph.value = buildDefaultBankAdviceParagraph();
};

let nextParaId = 1;
const addExtraParagraph = () => {
    extraParagraphs.value.push({
        id: nextParaId++,
        text: '',
    });
};

const removeExtraParagraph = (id) => {
    extraParagraphs.value = extraParagraphs.value.filter((p) => p.id !== id);
};

const addSignature = () => {
    signatures.value.push('');
};

const removeSignature = (index) => {
    signatures.value.splice(index, 1);
};

const buildExportPayload = () => ({
    font: selectedFont.value,
    subject1: subject1.value,
    introLine: introLine.value,
    allowanceRows: allowanceRows.value,
    deductionRows: deductionRows.value,
    grossTotal: grossTotal.value,
    deductionTotal: deductionTotal.value,
    netPayable: netPayable.value,
    netPayableLine: netPayableLine.value,
    postTableParagraph: postTableParagraph.value,
    bankAdviceSubject: bankAdviceSubject.value,
    bankAdviceParagraph: bankAdviceParagraph.value,
    extraParagraphs: extraParagraphs.value,
    signatures: signatures.value,
});

const fileSlug = computed(() => {
    const mStr = String(props.month).padStart(2, '0');
    return `KAU_Note_Salary_Summary_${props.year}_${mStr}`;
});

const handleDownloadExcel = () => {
    downloadSalaryNoteSummaryExcel(buildExportPayload(), `${fileSlug.value}.xlsx`);
};

const handleDownloadWord = () => {
    downloadSalaryNoteSummaryWord(buildExportPayload(), `${fileSlug.value}.docx`);
};
</script>

<template>
    <Teleport to="body">
        <div
            v-if="show"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-3 backdrop-blur-xs sm:p-5"
            role="dialog"
            aria-modal="true"
            aria-labelledby="note-summary-modal-title"
        >
            <div class="flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl">
                <!-- Modal Header -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-5 py-3.5">
                    <div>
                        <h2 id="note-summary-modal-title" class="text-base font-bold text-slate-900">
                            Note &amp; Salary Summary (Excel &amp; Word)
                        </h2>
                        <p class="text-xs text-slate-600">
                            {{ banglaMonth }}' {{ banglaYear }} · {{ activeGroupLabel }} ({{ employeeCountBn }} জন)
                            <span v-if="selectionScopeLabel" class="ml-1 text-indigo-700">· {{ selectionScopeLabel }}</span>
                        </p>
                    </div>

                    <div class="flex items-center gap-3">
                        <div class="flex items-center gap-1.5">
                            <label for="note-font-select" class="text-xs font-semibold text-slate-700">Font:</label>
                            <select
                                id="note-font-select"
                                v-model="selectedFont"
                                class="rounded-lg border-slate-300 bg-white py-1 pl-2.5 pr-7 text-xs font-medium text-slate-800 focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option v-for="f in FONT_OPTIONS" :key="f.value" :value="f.value">
                                    {{ f.label }}
                                </option>
                            </select>
                        </div>

                        <button
                            type="button"
                            @click="emit('close')"
                            class="rounded-lg border border-slate-300 bg-white p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-800"
                            aria-label="Close modal"
                        >
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Modal Body -->
                <div class="flex-1 space-y-5 overflow-y-auto px-5 py-4 text-sm text-slate-800">
                    <!-- Quick Summary Strip -->
                    <div class="grid grid-cols-1 gap-2.5 rounded-lg border border-slate-200 bg-slate-50 p-3 sm:grid-cols-3">
                        <div>
                            <div class="text-[11px] font-semibold text-slate-500">মোট বেতন-ভাতাদি (Gross)</div>
                            <div class="text-base font-bold text-slate-900">৳ {{ formatBanglaMoney(grossTotal) }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] font-semibold text-slate-500">সর্বমোট কর্তনাদি (Deductions)</div>
                            <div class="text-base font-bold text-rose-700">৳ {{ formatBanglaMoney(deductionTotal) }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] font-semibold text-emerald-700">নীট পরিশোধযোগ্য (Net Payable)</div>
                            <div class="text-base font-extrabold text-emerald-900">৳ {{ formatBanglaMoney(netPayable) }}</div>
                        </div>
                    </div>

                    <!-- Subject 1 (No (০১) prefix) -->
                    <div class="space-y-1.5">
                        <label class="block text-xs font-semibold text-slate-700">বিষয় (নোট শিরোনাম)</label>
                        <input
                            v-model="subject1"
                            type="text"
                            class="w-full rounded-lg border-slate-300 text-sm font-semibold text-slate-900 focus:border-indigo-500 focus:ring-indigo-500"
                        />
                    </div>

                    <!-- Intro Line -->
                    <div class="space-y-1.5">
                        <label class="block text-xs font-semibold text-slate-700">ভূমিকা অনুচ্ছেদ</label>
                        <textarea
                            v-model="introLine"
                            rows="2"
                            class="w-full rounded-lg border-slate-300 text-sm text-slate-800 focus:border-indigo-500 focus:ring-indigo-500"
                            style="text-align: justify;"
                        />
                    </div>

                    <!-- Table 1: Allowances (Bordered) -->
                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between">
                            <h3 class="text-xs font-bold uppercase tracking-wide text-slate-700">
                                খাতভিত্তিক বেতন-ভাতাদির বিবরণ (Current + Arrear সম্মিলিত মোট)
                            </h3>
                            <span class="text-[11px] text-slate-500">{{ toBanglaDigits(allowanceRows.length) }} টি খাত</span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full border-collapse border border-slate-400 text-xs">
                                <thead>
                                    <tr class="bg-slate-100 text-slate-900">
                                        <th class="w-14 border border-slate-400 px-2 py-1.5 text-center">ক্রমিক</th>
                                        <th class="border border-slate-400 px-2.5 py-1.5 text-left">খাতভিত্তিক বেতন-ভাতাদির বিবরণ</th>
                                        <th class="w-40 border border-slate-400 px-2.5 py-1.5 text-right">টাকার পরিমাণ</th>
                                        <th class="w-56 border border-slate-400 px-2.5 py-1.5 text-left">মন্তব্য</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(row, idx) in allowanceRows" :key="row.key">
                                        <td class="border border-slate-400 px-2 py-1 text-center">{{ toBanglaDigits(idx + 1) }}</td>
                                        <td class="border border-slate-400 px-2 py-1">
                                            <input
                                                v-model="row.label"
                                                type="text"
                                                class="w-full border-0 bg-transparent p-0 text-xs text-slate-900 focus:ring-0"
                                            />
                                        </td>
                                        <td class="border border-slate-400 px-2.5 py-1 text-right font-semibold tabular-nums">
                                            {{ formatBanglaMoney(row.amount) }}
                                        </td>
                                        <td class="border border-slate-400 px-2 py-1">
                                            <input
                                                v-model="row.comment"
                                                type="text"
                                                placeholder="মন্তব্য লিখুন..."
                                                class="w-full border-0 bg-transparent p-0 text-xs text-slate-700 placeholder:text-slate-400 focus:ring-0"
                                            />
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="bg-slate-50 font-bold text-slate-900">
                                        <td class="border border-slate-400 px-2 py-1.5 text-center"></td>
                                        <td class="border border-slate-400 px-2.5 py-1.5 text-left">মোট বেতন-ভাতাদি</td>
                                        <td class="border border-slate-400 px-2.5 py-1.5 text-right tabular-nums">
                                            {{ formatBanglaMoney(grossTotal) }}
                                        </td>
                                        <td class="border border-slate-400 px-2 py-1.5"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <!-- Table 2: Deductions (Bordered) -->
                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between">
                            <h3 class="text-xs font-bold uppercase tracking-wide text-slate-700">
                                বিভিন্ন তহবিলে কর্তনাদির বিবরণ (Current + Arrear সম্মিলিত মোট)
                            </h3>
                            <span class="text-[11px] text-slate-500">{{ toBanglaDigits(deductionRows.length) }} টি খাত</span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full border-collapse border border-slate-400 text-xs">
                                <thead>
                                    <tr class="bg-slate-100 text-slate-900">
                                        <th class="w-14 border border-slate-400 px-2 py-1.5 text-center">ক্রমিক</th>
                                        <th class="border border-slate-400 px-2.5 py-1.5 text-left">বিভিন্ন তহবিলে কর্তনাদির বিবরণ</th>
                                        <th class="w-40 border border-slate-400 px-2.5 py-1.5 text-right">টাকার পরিমাণ</th>
                                        <th class="w-56 border border-slate-400 px-2.5 py-1.5 text-left">মন্তব্য</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(row, idx) in deductionRows" :key="row.key">
                                        <td class="border border-slate-400 px-2 py-1 text-center">{{ toBanglaDigits(idx + 1) }}</td>
                                        <td class="border border-slate-400 px-2 py-1">
                                            <input
                                                v-model="row.label"
                                                type="text"
                                                class="w-full border-0 bg-transparent p-0 text-xs text-slate-900 focus:ring-0"
                                            />
                                        </td>
                                        <td class="border border-slate-400 px-2.5 py-1 text-right font-semibold tabular-nums">
                                            {{ formatBanglaMoney(row.amount) }}
                                        </td>
                                        <td class="border border-slate-400 px-2 py-1">
                                            <input
                                                v-model="row.comment"
                                                type="text"
                                                placeholder="মন্তব্য লিখুন..."
                                                class="w-full border-0 bg-transparent p-0 text-xs text-slate-700 placeholder:text-slate-400 focus:ring-0"
                                            />
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="bg-slate-50 font-bold text-slate-900">
                                        <td class="border border-slate-400 px-2 py-1.5 text-center"></td>
                                        <td class="border border-slate-400 px-2.5 py-1.5 text-left">সর্বমোট কর্তনাদি</td>
                                        <td class="border border-slate-400 px-2.5 py-1.5 text-right tabular-nums">
                                            {{ formatBanglaMoney(deductionTotal) }}
                                        </td>
                                        <td class="border border-slate-400 px-2 py-1.5"></td>
                                    </tr>
                                    <tr class="bg-emerald-50/70 font-extrabold text-emerald-950">
                                        <td class="border border-slate-400 px-2 py-1.5 text-center"></td>
                                        <td class="border border-slate-400 px-2.5 py-1.5 text-left">নীট পরিশোধযোগ্য</td>
                                        <td class="border border-slate-400 px-2.5 py-1.5 text-right tabular-nums">
                                            {{ formatBanglaMoney(netPayable) }}
                                        </td>
                                        <td class="border border-slate-400 px-2 py-1.5"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <!-- Net Payable Line (অংকে ও কথায়) -->
                    <div class="space-y-1.5">
                        <label class="block text-xs font-semibold text-slate-700">নীট পরিশোধযোগ্য (অংকে ও কথায়)</label>
                        <input
                            v-model="netPayableLine"
                            type="text"
                            class="w-full rounded-lg border-slate-300 text-sm font-semibold text-slate-900 focus:border-indigo-500 focus:ring-indigo-500"
                        />
                    </div>

                    <!-- Post-Table Approval Paragraph (No border, justified) -->
                    <div class="space-y-1.5">
                        <label class="block text-xs font-semibold text-slate-700">
                            অনুমোদন ও অর্থ স্থানান্তর অনুচ্ছেদ (বর্ডারবিহীন, Justified)
                        </label>
                        <textarea
                            v-model="postTableParagraph"
                            rows="3"
                            class="w-full rounded-lg border-slate-300 text-sm text-slate-800 focus:border-indigo-500 focus:ring-indigo-500"
                            style="text-align: justify;"
                        />
                    </div>

                    <!-- Bank Advice Subject & Paragraph (No (০২) prefix, No border, justified) -->
                    <div class="space-y-3 rounded-lg bg-slate-50/80 p-3.5">
                        <div class="text-xs font-bold text-slate-700">ব্যাংক অ্যাডভাইস অংশ (বর্ডারবিহীন, Justified)</div>

                        <div class="space-y-1">
                            <label class="block text-[11px] font-semibold text-slate-600">ব্যাংক অ্যাডভাইস বিষয়</label>
                            <input
                                v-model="bankAdviceSubject"
                                type="text"
                                class="w-full rounded-lg border-slate-300 bg-white text-sm font-semibold text-slate-900 focus:border-indigo-500 focus:ring-indigo-500"
                            />
                        </div>

                        <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-3">
                            <div>
                                <label class="block text-[11px] font-semibold text-slate-600">ব্যাংকের নাম</label>
                                <input
                                    v-model="bankName"
                                    @input="syncBankDetailsToParagraph"
                                    type="text"
                                    class="mt-0.5 w-full rounded-lg border-slate-300 bg-white text-xs text-slate-800 focus:border-indigo-500 focus:ring-indigo-500"
                                />
                            </div>
                            <div>
                                <label class="block text-[11px] font-semibold text-slate-600">শাখা</label>
                                <input
                                    v-model="bankBranch"
                                    @input="syncBankDetailsToParagraph"
                                    type="text"
                                    class="mt-0.5 w-full rounded-lg border-slate-300 bg-white text-xs text-slate-800 focus:border-indigo-500 focus:ring-indigo-500"
                                />
                            </div>
                            <div>
                                <label class="block text-[11px] font-semibold text-slate-600">হিসাব নম্বর</label>
                                <input
                                    v-model="bankAccountNo"
                                    @input="syncBankDetailsToParagraph"
                                    type="text"
                                    class="mt-0.5 w-full rounded-lg border-slate-300 bg-white text-xs font-semibold text-slate-800 focus:border-indigo-500 focus:ring-indigo-500"
                                />
                            </div>
                        </div>

                        <div class="space-y-1">
                            <label class="block text-[11px] font-semibold text-slate-600">ব্যাংক অ্যাডভাইস অনুচ্ছেদ</label>
                            <textarea
                                v-model="bankAdviceParagraph"
                                rows="3"
                                class="w-full rounded-lg border-slate-300 bg-white text-sm text-slate-800 focus:border-indigo-500 focus:ring-indigo-500"
                                style="text-align: justify;"
                            />
                        </div>
                    </div>

                    <!-- Dynamic Extra Paragraphs (+ Add Paragraph / Remove) -->
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between">
                            <label class="text-xs font-bold text-slate-700">
                                অতিরিক্ত অনুচ্ছেদসমূহ (ঐচ্ছিক — নম্বর/বর্ডারবিহীন, Justified)
                            </label>
                            <button
                                type="button"
                                @click="addExtraParagraph"
                                class="inline-flex items-center gap-1 rounded-lg border border-indigo-300 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-100"
                            >
                                + Add Paragraph
                            </button>
                        </div>

                        <div
                            v-for="(para, idx) in extraParagraphs"
                            :key="para.id"
                            class="space-y-1 rounded-lg bg-slate-50 p-2.5"
                        >
                            <div class="flex items-center justify-between">
                                <span class="text-[11px] font-semibold text-slate-500">
                                    অতিরিক্ত অনুচ্ছেদ {{ toBanglaDigits(idx + 1) }} (এক্সপোর্টে কোনো নম্বর প্রিফিক্স যুক্ত হবে না)
                                </span>
                                <button
                                    type="button"
                                    @click="removeExtraParagraph(para.id)"
                                    class="text-xs font-semibold text-rose-600 hover:text-rose-800"
                                >
                                    Remove
                                </button>
                            </div>
                            <textarea
                                v-model="para.text"
                                rows="2"
                                placeholder="অতিরিক্ত অনুচ্ছেদের বিবরণ লিখুন..."
                                class="w-full rounded-lg border-slate-300 bg-white text-sm text-slate-800 focus:border-indigo-500 focus:ring-indigo-500"
                                style="text-align: justify;"
                            />
                        </div>
                    </div>

                    <!-- Signature Block -->
                    <div class="space-y-2">
                        <div class="flex items-center justify-between">
                            <label class="text-xs font-bold text-slate-700">স্বাক্ষরকারী পদবিসমূহ (Signature Block)</label>
                            <button
                                type="button"
                                @click="addSignature"
                                class="rounded-md border border-slate-300 bg-white px-2 py-0.5 text-[11px] font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                + Add Designation
                            </button>
                        </div>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            <div v-for="(sig, idx) in signatures" :key="idx" class="flex items-center gap-1.5">
                                <input
                                    v-model="signatures[idx]"
                                    type="text"
                                    class="w-full rounded-lg border-slate-300 text-xs text-slate-800 focus:border-indigo-500 focus:ring-indigo-500"
                                />
                                <button
                                    v-if="signatures.length > 1"
                                    type="button"
                                    @click="removeSignature(idx)"
                                    class="rounded-md p-1 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                                    title="Remove designation"
                                >
                                    ✕
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal Footer (Excel & Word ONLY — NO PDF) -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 px-5 py-3">
                    <div class="text-[11px] text-slate-500">
                        Selected Font: <strong class="text-slate-800">{{ selectedFont }}</strong>
                        <span v-if="selectedFont === 'SutonnyMJ'" class="ml-1 text-amber-700">(Auto Unicode → Bijoy ANSI conversion)</span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            @click="emit('close')"
                            class="rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100"
                        >
                            Close
                        </button>
                        <button
                            type="button"
                            @click="handleDownloadExcel"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-700"
                        >
                            Download Excel (.xlsx)
                        </button>
                        <button
                            type="button"
                            @click="handleDownloadWord"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700"
                        >
                            Download Word (.docx)
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
