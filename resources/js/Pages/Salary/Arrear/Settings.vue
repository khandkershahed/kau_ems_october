<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import axios from 'axios';
import Swal from 'sweetalert2';

const props = defineProps({ rules: Array, componentTypes: Array, publications: Array });
const section = ref('arrear');
const current = ref((props.rules || []).map(r => ({ ...r, published:Number(r.published)===1, recurring:Number(r.recurring)===1, start_month:r.start_month?.slice(0,7)||'', end_month:r.end_month?.slice(0,7)||'', selected_months: JSON.parse(r.selected_months || '[]').join(','), employee_ids: JSON.parse(r.employee_ids||'[]').join(',') })));
const publications = ref((props.componentTypes||[]).map(t => {
    const p=(props.publications||[]).find(x=>Number(x.payroll_component_type_id)===Number(t.id));
    return { payroll_component_type_id:t.id, name:t.name, direction:p?.direction || (/provident|tax|deduction|stamp/i.test(t.name)?'deduction':'addition'),
      published:!!p?.published, default_calc_type:p?.default_calc_type||'fixed', default_amount:p?.default_amount ?? '', expected_revision:p?.revision || undefined };
}));
const saving = ref(false), message=ref(''), previewRows=ref([]);
const previewUser=ref(''), previewYear=ref(2026), previewMonth=ref(10), tabCount=computed(()=>current.value.length);
const arrearRuleDescription=code=>({
 BASIC_ARREAR_GROSS:'Pay Scale Basic difference for July–September',
 BASIC_INCREMENT_ADJUSTMENT:'Old 2015 increment adjustment (only once)',
 SPECIAL_BENEFIT_RECOVERY:'Previously received Special Benefit',
 HOUSE_RENT_RECOVERY:'House Rent entitlement/payment difference',
 ADDITIONAL_GPF:'Additional GPF difference against previous deductions'
}[code]||'Custom Fixed, Percentage or Advanced formula');
const sample = () => ({ title:'New arrear component', direction:'payable', mode:'fixed', amount:0, formula:'',
 start_month:'',end_month:'',selected_months:'', recurring:false, scope:'all', payroll_group_id:'', employee_ids:'',published:false, display_order:current.value.length+1 });
const addRule = () => current.value.push(sample());
const parseList = (text,numeric=false) => (text || '').split(',').map(v=>v.trim()).filter(Boolean).map(v=>numeric?Number(v):v);
const saveRule = async r => {
    saving.value=true; message.value='';
    try {
        const input = { ...r, expected_revision:r.revision, selected_months:parseList(r.selected_months),
            employee_ids:parseList(r.employee_ids,true),payroll_group_id:r.scope==='group'?Number(r.payroll_group_id):null,start_month:r.start_month||null,end_month:r.end_month||null,
            amount:r.amount===''?null:Number(r.amount), recurring:!!r.recurring, published:!!r.published,
            display_order:Number(r.display_order) };
        const response = await axios.post(route('salary-arrear-settings.rules.store'),input);
        r.id=Number(response.data.id);
        r.revision=Number(response.data.revision);
        r.published=response.data.published === true;
        r.recurring=response.data.rule?.recurring === true;
        message.value=response.data.message + (r.published ? ' Published and available in salary previews.' : ' Unpublished; not included in payable totals.');
    } catch(e) { message.value=e.response?.data?.message || JSON.stringify(e.response?.data?.errors||{}) || e.message; }
    finally { saving.value=false; }
};
const archive = async (r,i) => {
    if (!r.id) {current.value.splice(i,1);return;}
    if (!confirm('Archive this rule? Historical versions will remain.')) return;
    try {await axios.delete(route('salary-arrear-settings.rules.archive',{id:r.id}));current.value.splice(i,1);}catch(e){message.value=e.response?.data?.message||e.message;}
};
const savePublication = async p => {
    saving.value=true;
    try { const response=await axios.post(route('salary-arrear-settings.components.store'), {
        payroll_component_type_id:p.payroll_component_type_id, direction:p.direction, published:p.published,
        default_calc_type:p.default_calc_type, default_amount:p.default_amount===''?null:Number(p.default_amount),
        expected_revision:p.expected_revision
    }); p.expected_revision=Number(p.expected_revision||0)+1; message.value=response.data.message;
    }catch(e){message.value=e.response?.data?.message||e.message;}finally{saving.value=false;}
};
const preview = async r => {
    if (!previewUser.value) return Swal.fire({icon:'warning',text:'Enter an employee user ID first.'});
    saving.value=true;
    try { const id=r?.id||null; const response=await axios.get(route('salary-arrear-settings.rules.preview'),{
       params:{user_id:Number(previewUser.value),year:Number(previewYear.value),month:Number(previewMonth.value),rule_id:id}});
       previewRows.value=response.data.rows; message.value='Read-only formula preview: no employee record was modified.';
    } catch(e){ message.value=e.response?.data?.message||e.message;} finally{saving.value=false;}
};
</script>

<template>
<Head title="Salary Component & Arrear Settings" />
<AuthenticatedLayout>
 <main class="max-w-screen-2xl mx-auto p-5 space-y-5 text-slate-800">
  <header class="rounded-xl border bg-white p-5 flex flex-wrap justify-between items-start gap-2">
   <div><h1 class="text-2xl font-bold">Payroll Component & Arrear Settings</h1><p class="text-sm text-slate-500">Publish rules once. Individual salary calculator can override the result without changing these rules.</p></div>
   <Link :href="route('salary-setup.index')" class="text-sm underline">Back to Salary Estimations</Link>
  </header>
  <div class="flex gap-2"><button @click="section='arrear'" :class="section==='arrear'?'bg-emerald-800 text-white':'bg-white'" class="rounded-lg border px-4 py-2">Arrear Rules ({{tabCount}})</button><button @click="section='component'" :class="section==='component'?'bg-emerald-800 text-white':'bg-white'" class="rounded-lg border px-4 py-2">Regular Payroll Components</button></div>
  <div v-if="message" class="rounded-lg p-3 bg-sky-50 border text-sm break-words">{{message}}</div>
  <template v-if="section==='arrear'">
   <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm">{{current.filter(r=>r.published).length}} published / {{current.filter(r=>!r.published).length}} unpublished rules. Basic arrear can use pay-scale entitlement, but recovery/GPF amounts require evidence or explicit manual confirmation. For October 2026, the five standard components are calculated by the structured pay-scale engine; saved spreadsheet formulas remain visible for audit and other/custom periods. Changes to the historical formulas do not change structured October calculation.</div>
   <section class="bg-white border rounded-xl p-4 space-y-3">
    <h2 class="font-semibold">Preview Calculation Sources (read-only)</h2>
    <div class="flex gap-2 flex-wrap"><label class="text-xs">Employee user ID<input v-model="previewUser" type="number" class="block rounded border-slate-300 w-36" placeholder="User ID"/></label><label class="text-xs">Year<input v-model.number="previewYear" type="number" class="block rounded border-slate-300 w-28"/></label><label class="text-xs">Month<input v-model.number="previewMonth" type="number" min="1" max="12" class="block rounded border-slate-300 w-24"/></label><button @click="preview(null)" class="bg-emerald-700 text-white rounded px-4 self-end py-2">Preview Published Rules</button></div>
    <div v-for="r in previewRows" :key="r.rule_id" class="border-b py-2 text-sm"><b>{{r.title}}</b> — {{r.calculated_amount===null?'UNKNOWN':Number(r.calculated_amount).toFixed(2)}}<p v-if="r.error" class="text-rose-700">{{r.error}}</p><details><summary>Calculation sources</summary><pre class="text-xs overflow-auto">{{JSON.stringify(r.month_sources,null,2)}}</pre></details></div>
   </section>
   <div class="flex justify-between"><h2 class="font-semibold">Global Arrear Template</h2><button class="rounded bg-emerald-700 px-4 py-2 text-white" @click="addRule">+ Add Arrear Component</button></div>
   <section v-for="(r,i) in current" :key="r.id || 'draft'+i" class="rounded-xl border bg-white p-4 space-y-3">
    <div class="grid md:grid-cols-4 gap-3"><label class="text-xs md:col-span-2">Title<input v-model="r.title" class="block w-full rounded border-slate-300"/></label><label class="text-xs">Direction<select v-model="r.direction" class="block w-full rounded border-slate-300"><option value="payable">+ Payable</option><option value="deduction">− Deduction</option></select></label><label class="text-xs">Calculation<select v-model="r.mode" class="block w-full rounded border-slate-300"><option value="fixed">Fixed amount</option><option value="percentage">Percentage of formula/basis</option><option value="formula">Formula</option></select></label></div>
    <div class="grid md:grid-cols-4 gap-3"><label class="text-xs">Amount / %<input v-model="r.amount" type="number" min="0" step="0.01" class="block w-full rounded border-slate-300"/></label><div class="text-xs md:col-span-3 rounded border border-slate-200 p-2 bg-slate-50"><strong>Calculation method:</strong> {{ arrearRuleDescription(r.code) }}<details class="mt-1"><summary class="cursor-pointer">Advanced: edit legacy formula (changes require audited review)</summary><input v-model="r.formula" class="block w-full rounded border-slate-300 font-mono text-sm mt-2" placeholder="SUM_MONTHS(REVISED_BASIC - JUNE_BASIC)"/></details></div></div>
    <div class="grid md:grid-cols-4 gap-3"><label class="text-xs">Start YYYY-MM<input type="month" v-model="r.start_month" class="block w-full rounded border-slate-300"/></label><label class="text-xs">End YYYY-MM<input type="month" v-model="r.end_month" class="block w-full rounded border-slate-300"/></label><label class="text-xs md:col-span-2">Selected months (comma-separated; overrides range)<input v-model="r.selected_months" class="block w-full rounded border-slate-300" placeholder="2026-07,2026-08,2026-09"/></label></div>
    <div class="grid md:grid-cols-4 gap-3"><label class="text-xs">Scope<select v-model="r.scope" class="block w-full rounded border-slate-300"><option value="all">All employees</option><option value="specific">Specific employee IDs</option><option value="group">Payroll group</option><option value="period">Period-specific (all employees)</option></select></label><label v-if="r.scope==='group'" class="text-xs">Payroll Group ID<input v-model.number="r.payroll_group_id" type="number" min="1" class="block w-full rounded border-slate-300"/></label><label class="text-xs">Specific user IDs<input v-model="r.employee_ids" :disabled="r.scope!=='specific'" class="block w-full rounded border-slate-300" placeholder="58, 60"/></label><label class="text-xs">Display order<input v-model.number="r.display_order" type="number" class="block w-full rounded border-slate-300"/></label><label class="text-xs flex gap-2 items-end pb-2"><input type="checkbox" v-model="r.recurring"/>Recurring</label></div>
    <div class="flex items-center flex-wrap gap-2"><label class="text-sm font-semibold"><input type="checkbox" v-model="r.published"/> Published</label><button :disabled="saving" @click="saveRule(r)" class="rounded bg-slate-800 text-white px-4 py-2 disabled:opacity-50">Save Rule</button><button :disabled="saving" @click="preview(r)" class="rounded border px-3 py-2">Preview Saved Rule</button><button @click="archive(r,i)" class="rounded border text-rose-700 px-3 py-2">Archive</button><span :class="r.published?'text-emerald-700':'text-amber-800'" class="text-xs font-semibold">{{r.published?'PUBLISHED':'UNPUBLISHED'}} · Version {{r.revision||'new'}} · Saved bills unchanged</span></div>
   </section>
  </template>
  <template v-else>
   <div class="bg-sky-50 border p-3 rounded text-sm">Regular salary rows come from existing employee Payroll Components and any explicitly published defaults. This page changes future projection only; it does not overwrite employee setup JSON.</div>
   <section class="bg-white border rounded-xl overflow-x-auto"><table class="w-full text-sm"><thead class="bg-slate-100"><tr><th class="p-3 text-left">Payroll Component</th><th class="p-3">Direction</th><th class="p-3">Calculation</th><th class="p-3">Default (optional)</th><th class="p-3">Publish</th><th class="p-3">Action</th></tr></thead><tbody><tr v-for="p in publications" :key="p.payroll_component_type_id" class="border-t"><td class="p-3">{{p.name}}</td><td class="p-2"><select v-model="p.direction" class="rounded text-xs"><option value="addition">+ Addition</option><option value="deduction">− Deduction</option></select></td><td class="p-2"><select v-model="p.default_calc_type" class="rounded text-xs"><option value="fixed">Fixed</option><option value="percentage">% Basic</option></select></td><td class="p-2"><input v-model="p.default_amount" type="number" min="0" step="0.01" class="w-28 rounded text-right"/></td><td class="p-2 text-center"><input type="checkbox" v-model="p.published"/></td><td class="p-2"><button :disabled="saving" @click="savePublication(p)" class="bg-slate-800 text-white px-3 py-2 rounded disabled:opacity-40">Save</button></td></tr></tbody></table></section>
  </template>
 </main>
</AuthenticatedLayout>
</template>
