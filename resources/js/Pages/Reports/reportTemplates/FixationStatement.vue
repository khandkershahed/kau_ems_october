<script setup>
import { computed } from 'vue';

// Render the original 6-column, landscape A4 statement structure and KAU heading.
// Financial data is read only. Provisional calculated values remain visibly unapproved.
const props = defineProps({
    data: { type: Array, default: () => [] },
    startIndex: { type: Number, default: 0 },
    rowsPerPage: { type: Number, default: 15 },
    pageNumber: { type: Number, default: 1 },
    totalPages: { type: Number, default: 1 },
    allData: { type: Array, default: () => [] },
    organizationLogo: { type: String, default: '/image/logo.png' },
    organizationName: { type: String, default: 'Khulna Agricultural University' },
    organizationAddress: { type: String, default: 'Temporary Office: 327, Jessore Road, North Mujgunni, Khalishpur, Khulna-9000' },
});
const fmtInt = v => v === undefined || v === null || v === '' || !Number.isFinite(Number(v)) ? '—' : Number(v).toLocaleString('en-BD', {maximumFractionDigits: 0});
const fmt = v => v === undefined || v === null || v === '' || !Number.isFinite(Number(v)) ? '—'
    : Number(v).toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2});
const pct = v => v == null ? '—' : `${Math.round(Number(v) * 100)}%`;
const stage = (fix, date) => fix?.stages?.find(x => String(x.effective_date).slice(0,10) === date)?.basic ?? null;
const numeric = v => v !== undefined && v !== null && Number.isFinite(Number(v));
const activeCount = computed(() => Math.max(1, Math.min(15, Number(props.rowsPerPage) || 15)));
const rowMinMm = computed(() => activeCount.value <= 10 ? 12.3 : activeCount.value <= 15 ? 9.0 : Math.max(6.55, 129 / activeCount.value));
const hasCalculable = row => {
    const f = row.fixation_report;
    return !!f && numeric(f.old_basic) && numeric(f.grade) && numeric(f.increment_2026)
        && ['2026-07-01','2027-01-01','2027-07-01'].every(date => numeric(stage(f,date)));
};
const eligible = row => hasCalculable(row) && row.fixation_report.status === 'approved' && row.fixation_report.ready !== false;
const line1 = f => `গ্রেড ${String(f?.grade ?? '—').padStart(2,'0')} · ২০১৫ স্কেল: ${fmtInt(f?.old_scale_min)}-${fmtInt(f?.old_scale_max)} → ২০২৬ স্কেল: ${fmtInt(f?.new_scale_min)}-${fmtInt(f?.new_scale_max)}`;
const beforeFirstIncrement = f => numeric(stage(f,'2026-07-01')) && numeric(f?.increment_2026)
    ? Number(stage(f,'2026-07-01')) - Number(f.increment_2026) : null;
const phaseIncrementRate = f => numeric(f?.rate_2026) && numeric(f?.rate_2027)
    ? Math.round((Number(f.rate_2027)-Number(f.rate_2026))*100) : null;
const line2 = f => `৩০/০৬/২৬ মূল: ${fmt(f?.old_basic)} ⇒ ০১/০৭/২৬: ${fmt(beforeFirstIncrement(f))} + ইনক্রি. ${fmt(f?.increment_2026)} = ${fmt(stage(f,'2026-07-01'))} (${pct(f?.rate_2026)})`;
const line3 = f => `০১/০১/২৭: ${fmt(stage(f,'2027-01-01'))} (${phaseIncrementRate(f) ?? '—'}% অবশিষ্ট) ⇒ ০১/০৭/২৭: ${fmt(stage(f,'2027-07-01'))}`;
</script>

<template>
  <section style="font-family: Arial, Helvetica, sans-serif;" class="a4-sheet kau-fixation-statement bg-white mx-auto" :class="{'kau-bulk-provisional': !data.every(eligible)}" :style="{'--kau-row-mm': rowMinMm + 'mm'}">
    <header class="kau-bulk-heading">
      <img :src="organizationLogo" alt="KAU" class="kau-bulk-logo" />
      <div class="kau-bulk-address"><strong style="padding-bottom: 4px;">খুলনা কৃষি বিশ্ববিদ্যালয়, খুলনা</strong><span>{{organizationAddress}}</span></div>
    </header>
    <h1 style="padding-bottom: 5px;">Pay Fixation Statement of KAU Employees (Teachers, Officers, Class III and Class IV Employees)</h1>
    <p class="kau-bulk-caption" style="padding-bottom: 6px;">খুলনা কৃষি বিশ্ববিদ্যালয়, খুলনা · এস.আর.ও নং ৩৪৮-আইন/২০২৬ (১৭/০৯/২০২৬) অনুযায়ী জাতীয় বেতন স্কেল ২০২৬ ভিত্তিক বেতন নির্ধারণ · পৃষ্ঠা: {{pageNumber}}/{{totalPages}} · মোট কর্মকর্তা/কর্মচারী: {{allData.length || data.length}} জন</p>

    <table class="kau-fixation-grid" aria-label="Pay Fixation Statement">
      <colgroup><col style="width:3%"/><col style="width:8%"/><col style="width:13.2%"/><col style="width:13.5%"/><col style="width:14.6%"/><col style="width:40%"/></colgroup>
      <thead><tr>
        <th>SL<br/><small>ক্রমিক</small></th>
        <th>Employee ID<br/><small>কর্মকর্তা/কর্মচারী আইডি</small></th>
        <th style="font-size: 11px;">Employee Name<br/><small>নাম</small></th>
        <th style="font-size: 11px;">Designation<br/><small>পদবি</small></th>
        <th style="font-size: 11px;">Department<br/><small>বিভাগ/শাখা</small></th>
        <th style="font-size: 11px;">Pay Fixation at a Glance<br/><small>সংক্ষেপে বেতন নির্ধারণ</small></th>
      </tr></thead>
      <tbody>
        <tr style="min-height: 15mm;" v-for="(row,i) in data" :key="row.user_id ?? i">
          <td style="padding-bottom: 3px;" class="kau-bulk-index">{{startIndex + i + 1}}</td>
          <td style="padding-bottom: 3px;" class="kau-bulk-code">{{row.employee_code || '—'}}</td>
          <td style="padding-bottom: 3px;" class="kau-bulk-name">{{row.name || '—'}}</td>
          <td style="padding-bottom:3px; font-size: 11px;">{{row.designation || '—'}}</td>
          <td style="padding-bottom:3px; font-size: 11px;">{{row.department || '—'}}</td>
          <td style="padding-bottom: 3px;" class="kau-bulk-glance">
            <template v-if="hasCalculable(row)">
              <!-- <div>{{line1(row.fixation_report)}}</div> -->
              <p class="glance-line">{{line2(row.fixation_report)}}</p>
              <p class="glance-line">{{line3(row.fixation_report)}}</p>
            </template>
            <div v-else class="kau-bulk-absent">Missing verified calculation — values unavailable</div>
          </td>
        </tr>
      </tbody>
    </table>

    <p class="kau-bulk-verification">উপরোক্ত বেতন নির্ধারণের সকল বিবরণী সমূহ পরীক্ষা করা হয়েছে ও কমিটির সদস্যবৃন্দের সামগ্রিক স্বাক্ষরের মাধ্যমে অনুমোদনের জন্য সুপারিশ করা হলো।</p>
    <!-- <p v-if="!data.every(eligible)" class="kau-bulk-preview-warning">PROVISIONAL — NOT APPROVED FOR SIGNATURE / PAYMENT</p> -->

    <footer class="kau-bulk-signatures">
      <div><div class="kau-sign-line"></div>
      <b>জি এম আনিসুর রহমান</b>
      <span>পরিচালক (অর্থ ও হিসাব) চুক্তিভিত্তিক, অর্থ ও হিসাব শাখা, খুলনা কৃষি বিশ্ববিদ্যালয়</span>
      <strong>আহ্বায়ক</strong></div>
      <div><div class="kau-sign-line"></div>
      <b>মোঃ শামসুল ইসলাম</b>
      <span>উপ-কোষাধ্যক্ষ, হিসাব শাখা, বাংলাদেশ কৃষি বিশ্ববিদ্যালয় (বাকৃবি), ময়মনসিংহ</span>
      <strong>সদস্য</strong></div>
      <div><div class="kau-sign-line"></div>
      <b>মোঃ রেজাউল ইসলাম</b>
      <span>রেজিস্ট্রার (ভারপ্রাপ্ত), রেজিস্ট্রার কার্যালয়, খুকৃবি, খুলনা ও সদস্য, সংশ্লিষ্ট কমিটি</span>
      <strong>সদস্য</strong></div>
      <div><div class="kau-sign-line"></div>
      <b>মোঃ জহুরুল ইসলাম</b>
      <span>সহকারী অধ্যাপক, এগ্রিকালচার ইকোনমিক্স বিভাগ, খুকৃবি, খুলনা</span>
      <strong>সদস্য</strong></div>
      <div><div class="kau-sign-line"></div>
      <b>মুরাদ বিল্লাহ</b>
      <span>সহকারী পরিচালক (হিসাব), অর্থ ও হিসাব শাখা, খুকৃবি, খুলনা</span>
      <strong>সদস্য-সচিব</strong></div>
    </footer>
  </section>
</template>

<style>
.kau-fixation-statement { width:297mm!important; min-width:297mm; height:210mm!important; min-height:210mm!important; max-height:210mm!important; padding:6mm 7.5mm 5.2mm!important; color:#101010!important; background:white!important; position:relative; overflow:hidden; box-sizing:border-box; font-family:Georgia,'Noto Serif Bengali','Noto Sans Bengali',serif; box-shadow:none; border:none; }
.kau-bulk-heading {display:flex;align-items:center;justify-content:center;height:14mm;gap:7mm;position:relative;border:1px solid #111}
.kau-bulk-logo {width:12mm;height:12mm;object-fit:contain;}
.kau-bulk-address {display:flex;flex-direction:column;text-align:center;line-height:1.18}
.kau-bulk-form-label{font-size:7.7px;line-height:1.05}.kau-bulk-address strong {font-size:12px}.kau-bulk-address span {font-family:Arial,sans-serif;font-size:7.5px;}
.kau-fixation-statement h1{text-align:center;font-family:Georgia,serif;font-size:14.5px;font-weight:700;margin:1.1mm 0 0;line-height:1.2}
.kau-bulk-caption {text-align:center;font-size:6.6px;margin:0 0 2mm;white-space:nowrap;}

/* TABLE STYLES */
.kau-fixation-grid {width:100%;border-collapse:collapse;table-layout:fixed;border:1px solid #111;font-size:10px;}
.kau-fixation-grid th {border:1px solid #111;padding:3px;vertical-align:middle;overflow-wrap:break-word;line-height:1.11;color:#111;text-align:center;background:#e9eef4;font-family:Georgia,serif;font-weight:700;font-size:9px;height:12mm;}
.kau-fixation-grid td {border:1px solid #111;padding:4px 3px;vertical-align:top !important;overflow-wrap:break-word;line-height:1.11;color:#111;}
.kau-fixation-grid th small{font-size:7.2px}
.kau-fixation-grid tbody tr {height:var(--kau-row-mm);break-inside:avoid;page-break-inside:avoid}
.kau-bulk-index {text-align:center;font-family:Georgia,serif}
.kau-bulk-code {text-align:center;font-weight:bold;font-size:12px}

/* PAY FIXATION COLUMN STYLES */
.kau-bulk-name {font-weight:bold;font-size:11px}
.kau-bulk-glance {font-size:8.8px!important;line-height:1.18!important; font-family: "Noto Sans Bengali", "Noto Serif Bengali", Georgia, serif!important;}
.kau-bulk-glance .glance-line { font-family: Arial, Helvetica, sans-serif; margin: 0; padding-bottom: 3px; line-height: 1.2; }
.kau-bulk-glance .glance-line:last-child { padding-bottom: 0; }
.kau-bulk-absent {color:#9b2c2c;font-weight:bold}

.kau-bulk-verification{font-size:10px;margin-top:3mm}
.kau-bulk-signatures{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:4mm;text-align:center;position:absolute;bottom:5mm;left:7.5mm;right:7.5mm;font-size:10px;line-height:1.32}
.kau-bulk-signatures>div{display:flex;flex-direction:column;align-items:stretch;min-width:0}
.kau-sign-line{border-top:1px solid #111;margin-bottom:1.4mm;}
.kau-bulk-signatures b{font-size:10px}.kau-bulk-signatures span{font-size:9px}.kau-bulk-signatures strong{font-size:9px}
.kau-bulk-preview-warning {position:absolute;bottom:29mm;left:8mm;right:8mm;text-align:center;color:#9b2c2c;font-weight:bold;font-size:10px;pointer-events:none;}
@page kau-fixation-landscape {size:A4 landscape;margin:0}
@media print { .kau-fixation-statement{page:kau-fixation-landscape!important;break-after:page;page-break-after:always;print-color-adjust:exact;-webkit-print-color-adjust:exact;} }
</style>
