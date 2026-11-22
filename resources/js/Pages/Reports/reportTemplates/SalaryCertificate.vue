<script setup>
import { computed } from 'vue';

const props = defineProps({
    certificate: { type: Object, default: null },
});

const c = computed(() => props.certificate);
const money = (row, column) => {
    const paisa = c.value?.cells?.[row]?.[column];
    return paisa === undefined || paisa === null ? '—'
        : (Number(paisa) / 100).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
};
const payableRows = computed(() => c.value?.allowance_rows || []);
const deductionRows = computed(() => c.value?.deduction_rows || []);
const rowMoney = (row, column) => Number(row?.[column] || 0) / 100;
const showMoney = (row, column) => rowMoney(row, column).toLocaleString('en-US', {
    minimumFractionDigits: 2, maximumFractionDigits: 2,
});
</script>

<template>
    <article class="kau-salary-certificate" v-if="c">
        <!-- 1. Header Table (Bulletproof for PDF) -->
        <table style="width: 100%; border-collapse: collapse; border: none; margin: 0; padding: 0;">
            <tbody>
                <tr>
                    <td style="width: 22mm; border: none; padding: 0; vertical-align: middle;">
                        <img src="/logo.png" alt="KAU" class="certificate-logo" style="width: 12.5mm; height: 14.5mm; display: block; object-fit: contain;" />
                    </td>
                    <td style="border: none; padding: 0; text-align: center; vertical-align: middle;">
                        <div style="font-size: 14pt; font-weight: bold; line-height: 1.2;">Khulna Agricultural University</div>
                        <div style="font-size: 10pt; line-height: 1.2; margin-top: 2px;">
                            Temporary Office: 327, Jessore Road, North Mujgunni, Khalishpur, Khulna-9000
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- 2. Physical Spacer (Replaces CSS margins which html2canvas ignores) -->
        <div style="height: 6mm; font-size: 1pt; line-height: 6mm;">&nbsp;</div>

        <!-- 3. Metadata Table -->
        <table class="certificate-metadata">
            <tbody>
                <tr>
                    <td>
                        <div><b>Name:</b> {{ c.name }}</div>
                        <div><b>Designation:</b> {{ c.designation }}</div>
                        <div><b>Pay Scale:</b> {{ c.pay_scale }}</div>
                        <div><b>Approved Basic:</b> {{ c.approved_basic }}</div>
                    </td>
                    <td>
                        <div><b>Service ID:</b> {{ c.employee_code }}</div>
                        <div><b>Bank A/C:</b> {{ c.bank_account }}</div>
                        <div><b>Month:</b> {{ c.month }}</div>
                        <div><b>Fixation:</b> {{ c.fixation }}</div>
                    </td>
                </tr>
            </tbody>
        </table>

        <h2 class="certificate-title">Salary Certificate</h2>
        <div class="certificate-introduction">
            This is to certify that <b>{{ c.prefix }} {{ c.name }}</b> is serving as an <b>{{ c.designation }}</b> in the Department of {{ c.department }} at Khulna Agricultural University.
        </div>
        <p class="certificate-grade">
            Currently, {{ c.possessive }} salary is fixed under <b>{{ c.grade_range }}</b> of the Government National Pay Scale {{ c.pay_scale_year }}. The detailed salary statement, including perquisites and allowances, is presented below:
        </p>

        <!-- 4. Financial Table -->
        <table class="certificate-financial">
            <colgroup><col class="col-serial"/><col class="col-particulars"/><col class="col-amount"/><col class="col-amount"/><col class="col-amount"/></colgroup>
            <tbody>
                <tr class="section-title"><th colspan="5">Allowances / Payable</th></tr>
                <tr class="table-head"><th>Sl. No</th><th>Particulars</th><th>Current</th><th>Arrear</th><th>Total</th></tr>
                <tr v-for="r in payableRows" :key="`a-${r.sl}-${r.particulars}`">
                    <td>{{ r.sl }}</td><td>{{ r.particulars }}</td><td>{{ showMoney(r, 'current') }}</td><td>{{ showMoney(r, 'arrear') }}</td><td>{{ showMoney(r, 'total') }}</td>
                </tr>
                <tr class="total-row"><td></td><td>Gross Salary / Payable</td><td>{{ money(9, 'current') }}</td><td>{{ money(9, 'arrear') }}</td><td>{{ money(9, 'total') }}</td></tr>
                <tr class="section-title"><th colspan="5">Deductions / Recoveries</th></tr>
                <tr class="table-head"><th>Sl. No</th><th>Particulars</th><th>Current</th><th>Arrear</th><th>Total</th></tr>
                <tr v-for="r in deductionRows" :key="`d-${r.sl}-${r.particulars}`">
                    <td>{{ r.sl }}</td><td>{{ r.particulars }}</td><td>{{ showMoney(r, 'current') }}</td><td>{{ showMoney(r, 'arrear') }}</td><td>{{ showMoney(r, 'total') }}</td>
                </tr>
                <tr class="total-row"><td></td><td>Total Deductions</td><td>{{ money(18, 'current') }}</td><td>{{ money(18, 'arrear') }}</td><td>{{ money(18, 'total') }}</td></tr>
                <tr class="total-row"><td></td><td>Net Payable:</td><td>{{ money(19, 'current') }}</td><td>{{ money(19, 'arrear') }}</td><td>{{ money(19, 'total') }}</td></tr>
            </tbody>
        </table>
        <p class="certificate-words"><b>In words:</b> {{ c.in_words }}.</p>
        <p class="certificate-courtesy">I wish {{ c.object_pronoun }} continued success, good health, and prosperity in {{ c.possessive }} future endeavors.</p>

        <!-- 5. Signatures -->
        <footer class="certificate-signatures">
            <div></div>
            <div></div>
            <div></div>
        </footer>
    </article>
    <div v-else class="certificate-unavailable">Select an eligible employee to preview the Salary Certificate.</div>
</template>

<style scoped>
.kau-salary-certificate {
    width: 210mm;
    height: 297mm;
    min-height: 297mm;
    max-height: 297mm;
    padding: 15mm 25.4mm 15mm 25.4mm;
    margin: 0 auto;
    box-sizing: border-box;
    /* REMOVED overflow: hidden, position: relative, and display: flex */
    background: #fff !important;
    color: #000 !important;
    font-family: 'Times New Roman', Times, serif;
    font-size: 12pt;
    text-align: left;
}

.certificate-logo {
    width: 12.5mm; height: 14.5mm; object-fit: contain; display: block;
}

.certificate-metadata {
    border: 1px dashed #ddd; width: 100%;
    margin: 0 0 6mm 0;
    font-size: 9pt; table-layout: fixed; border-collapse: collapse;
}
.certificate-metadata td { border: 1px dashed #ddd; width: 50%; padding: 2mm 2.5mm; vertical-align: top; line-height: 1.2; }
.certificate-metadata td:last-child { text-align: right; }
.certificate-title { font-size: 14pt; font-weight: bold; text-align: center; margin: 0 0 3.5mm 0; }
.certificate-introduction, .certificate-grade { font-size: 12pt; line-height: 1.2; text-align: justify; margin: 0; }
.certificate-grade { margin-top: 4mm; margin-bottom: 4mm; }
.certificate-financial { border-collapse: collapse; width: 100%; table-layout: fixed; font-size: 10pt; line-height: 1.35; }
.certificate-financial .col-serial { width: 9%; }
.certificate-financial .col-particulars { width: 31%; }
.certificate-financial .col-amount { width: 20%; }
.certificate-financial th, .certificate-financial td { border: 0.55pt solid #000; padding: 1mm 1mm; line-height: 1.35; vertical-align: middle; }
.certificate-financial th:nth-child(n+3), .certificate-financial td:nth-child(n+3) { text-align: right; }
.certificate-financial td:first-child { text-align: center; }
.certificate-financial .table-head th:first-child, .certificate-financial .table-head th:nth-child(2) { text-align: left; }
.certificate-financial .section-title th { text-align: left; font-size: 10.5pt; padding: 0.8mm 1mm; line-height: 1.35; }
.certificate-financial .total-row { font-weight: bold; }
.certificate-words { font-size: 10.5pt; margin: 1mm 0 0; }
.certificate-courtesy { font-size: 12pt; margin-top: 9mm; }
.certificate-signatures { margin-top: 15mm; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); width: 100%; height: 19mm; }
.certificate-signatures > div { border: 1px dashed #ddd; min-width: 0; }
.certificate-unavailable { padding: 2rem; text-align: center; color: #666; }
</style>
