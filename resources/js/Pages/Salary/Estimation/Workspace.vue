<script setup>

import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
const props = defineProps({ rows:Object, counts:Object, runs:Array, year:Number, month:Number, filterOptions:Object });
const selected=ref([]), busy=ref(false), notice=ref(''), reason=ref(''), search=ref('');
const filters=ref({department_id:new URLSearchParams(window.location.search).get('department_id')||'',category_id:new URLSearchParams(window.location.search).get('category_id')||'',payroll_group_id:new URLSearchParams(window.location.search).get('payroll_group_id')||'',per_page:new URLSearchParams(window.location.search).get('per_page')||'25'});
const activeTab=ref(new URLSearchParams(window.location.search).get('status')==='CALCULATED'?'CALCULATED':'WORK_BLOCKED');
const pendingCodes=['1003','10046','10108','20029','20033','20038','20049','20057','20082','20083','30081','30098','30133','30254','40033','40140','40158','40274'];
const total=computed(()=>Number(props.counts?.WORK_BLOCKED||0)+Number(props.counts?.CALCULATED||0)+Number(props.counts?.READY_FOR_GENERATION||0)+Number(props.counts?.GENERATED||0));
const pageRows=computed(()=>props.rows?.data||[]);
const canSelect=(r)=>r.profile_count===1 && ['NOT_INITIALIZED','BLOCKED','CALCULATED'].includes(r.display_status);
const canApprove=(r)=>r.profile_count===1 && r.display_status==='CALCULATED' && Number(r.active_calculation_version_id)>0 && Number(r.revision)>0;
const approvedSelected=computed(()=>pageRows.value.filter(r=>selected.value.includes(r.user_id)&&canApprove(r)));
const allSelectedApproved=computed(()=>selected.value.length>0 && approvedSelected.value.length===selected.value.length);
const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.content||'';
async function post(url,payload){
 const res=await fetch(url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf()},body:JSON.stringify(payload)});
 const data=await res.json().catch(()=>({}));
 if(!res.ok)throw Error(data.message||JSON.stringify(data.errors||data)||`Request failed (${res.status})`);
 return data;
}
function refresh(){router.reload({only:['rows','counts','runs'],preserveScroll:true});}
function filterParams(status=activeTab.value){return {year:props.year,month:props.month,status,search:search.value,...filters.value};}
function applyFilter(s='WORK_BLOCKED'){activeTab.value=s;selected.value=[];router.get('/dashboard/salary/estimation',filterParams(s),{preserveState:true,replace:true});}
function findEmployees(){selected.value=[];router.get('/dashboard/salary/estimation',filterParams(),{preserveState:true,replace:true});}
function resetFilters(){search.value='';filters.value={department_id:'',category_id:'',payroll_group_id:'',per_page:'25'};findEmployees();}
function selectVisible(){selected.value=pageRows.value.filter(canSelect).map(r=>r.user_id);}
async function initialize(onlySelected=false){
 busy.value=true;notice.value='';
 try{
  const data=await post('/dashboard/salary/workflow/reconcile',{year:props.year,month:props.month,limit:1000,...(onlySelected?{user_ids:selected.value}:{})});
  notice.value=`Roster checked ${data.examined}: ${data.initialized} created, ${data.existing} already present, ${data.ambiguous} require evidence review, ${data.posted} pre-existing bills.`;
  selected.value=[];refresh();
 }catch(e){notice.value=e.message;}finally{busy.value=false;}
}
async function preview(mode){
 busy.value=true;notice.value='';
 try{
  let payload={year:props.year,month:props.month};
  if(mode==='pending')payload.employee_codes=pendingCodes;
  if(mode==='selected'){
   if(!selected.value.length)throw Error('Select employees first.');
   const missing=pageRows.value.filter(r=>selected.value.includes(r.user_id)&&r.display_status==='NOT_INITIALIZED');
   if(missing.length)throw Error('Initialize selected employee workflows first.');
   payload.user_ids=selected.value;
  }
  const first=await post('/dashboard/salary/estimation/runs',payload);
  let step;
  do{
   step=await post(`/dashboard/salary/estimation/runs/${first.run_id}/next`,{});
   notice.value=`Preview #${first.run_id}: ${step.run.examined} checked, ${step.run.estimated} estimated, ${step.run.blocked} blocked. No bills posted.`;
  }while(step.run.status==='running'&&step.processed>0);
  refresh();
 }catch(e){notice.value=e.message;}finally{busy.value=false;}
}
async function approve(action){
 busy.value=true;notice.value='';
 try{
  if(reason.value.trim().length<3||!selected.value.length)throw Error('Select employees and enter an audit reason.');
  const items=selected.value.map(id=>{const r=pageRows.value.find(e=>e.user_id===id);return {user_id:id,expected_revision:r.revision,calculation_version_id:r.active_calculation_version_id || r.latest_approved_calculation_version_id};});
  if(items.some(i=>!i.expected_revision))throw Error('Initialize selected workflows before approval.');
  if(action==='approve_for_generation' && !allSelectedApproved.value)throw Error('Select only CALCULATED employees with an active approved calculation.');
  if(items.length>50)throw Error('Approve up to 50 employees per batch.');
  const data=await post('/dashboard/salary/workflow/bulk-approve',{year:props.year,month:props.month,action,reason:reason.value,items});
  notice.value=`${data.succeeded} successful, ${data.failed} failed. ${(data.results||[]).filter(x=>!x.ok).map(x=>`${x.user_id}: ${x.error}`).join('; ')}`;
  refresh();
 }catch(e){notice.value=e.message;}finally{busy.value=false;}
}
function provisionalHorizontal(){
 const eligible=approvedSelected.value.map(r=>r.user_id);
 if(!eligible.length){notice.value='Select CALCULATED employees with approved saved versions.';return;}
 if(eligible.length>50){notice.value='Select up to 50 per report.';return;}
 const q=new URLSearchParams({year:String(props.year),month:String(props.month)});
 eligible.forEach(id=>q.append('user_ids[]',String(id)));
 window.open(`/dashboard/salary/estimation/horizontal-bill?${q.toString()}`,'_blank','noopener');
}
const selectedCodes=computed(()=>pageRows.value.filter(r=>selected.value.includes(r.user_id)).map(r=>r.employee_code));
const issues=computed(()=>pageRows.value.filter(r=>Number(r.profile_count)>1 || r.display_status==='NOT_INITIALIZED'));
const workBlockedCount=computed(()=>Number(props.counts?.WORK_BLOCKED||0));
async function saveCalculations(){
 if(!selectedCodes.value.length){notice.value='Select employees on this page.';return;}
 if(selectedCodes.value.length>50){notice.value='At most 50 selected employees per save batch.';return;}
 if(reason.value.trim().length<3){notice.value='Enter the correction/review reason.';return;}
 busy.value=true;notice.value='';
 try{
  const data=await post('/dashboard/salary/estimation/bulk-save-calculations',{year:props.year,month:props.month,employee_codes:selectedCodes.value,reason:reason.value});
  notice.value=`Saved ${data.succeeded}, failed ${data.failed}.\n`+(data.results||[]).map(r=>`${r.code}: ${r.ok?'Saved version '+r.version:r.error}`).join('\n');
  refresh();
 }catch(e){notice.value=e.message;}finally{busy.value=false;}
}

// All pages are selected by the SERVER cursor, not by current-page checkboxes.
const operationSize=ref('100');
const bulkSaveStatus=ref('draft');
const operationBusy=ref(false);
const operationLog=ref([]);
const operationSummary=ref({checked:0,ok:0,failed:0});
function exportOperationLog(){
 const cells=[['employee_code','user_id','status','result','message'],...operationLog.value.map(x=>[x.code,x.user_id,x.status,x.ok?'OK':'FAILED',x.message||''])];
 const csv=cells.map(a=>a.map(v=>'"'+String(v??'').replaceAll('"','""')+'"').join(',')).join('\r\n');
 const blob=new Blob(['\ufeff'+csv],{type:'text/csv;charset=utf-8'});
 const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;a.download=`KAU_calculation_audit_${props.year}_${props.month}.csv`;a.click();URL.revokeObjectURL(url);
}
async function calculateAll(){
 if(operationBusy.value||reason.value.trim().length<3){notice.value='Enter an HR audit reason (at least 3 characters).';return;}
 if(!window.confirm(bulkSaveStatus.value==='draft'
 ? 'Save PROVISIONAL DRAFT salary snapshots? Unverified arrear amounts remain unresolved. NO bills will be generated.'
 : 'Save APPROVED snapshots only for employees passing all financial checks? NO bills will be generated.'))return;
 operationBusy.value=true;operationLog.value=[];operationSummary.value={checked:0,ok:0,failed:0};
 let cursor=0;const max=operationSize.value==='all'?Number.MAX_SAFE_INTEGER:Number(operationSize.value);
 try{
  while(operationSummary.value.checked<max){
   const data=await post('/dashboard/salary/estimation/bulk-operator/batch',{year:props.year,month:props.month,cursor,batch_size:Math.min(10,max-operationSummary.value.checked),reason:reason.value,action:bulkSaveStatus.value});
   cursor=data.cursor;
   operationLog.value.push(...data.results);operationSummary.value.checked+=data.examined;
   operationSummary.value.ok+=data.succeeded;operationSummary.value.failed+=data.failed;
   notice.value=`Bulk calculation: ${operationSummary.value.checked} checked, ${operationSummary.value.ok} saved/preserved, ${operationSummary.value.failed} blocked. No salary posting.`;
   if(!data.has_more||data.examined===0)break;
  }
 }catch(e){notice.value=`Stopped after ${operationSummary.value.checked} checks: ${e.message}. Export the CSV; rerun will retain approved snapshots.`;}
 finally{operationBusy.value=false;refresh();}
}
</script>
<template>
<Head title="My 2026 Salary Fixation Review" />
  <AuthenticatedLayout>
  <main class="mx-auto max-w-9xl space-y-5 p-4 text-slate-800 md:p-6">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div><div class="text-xs font-semibold uppercase tracking-wide text-indigo-600">October {{year}} · Salary estimation</div>
        <h1 class="text-2xl font-bold">Salary Estimation / Calculation</h1>
        <p class="mt-1 text-sm text-slate-500">Master Plan §5 · Blocked and Calculated worklists · No salary posting</p></div>
      <a :href="`/dashboard/salary/estimation/bulk-components?year=${year}&month=${month}`" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Bulk Modify / Spreadsheet Grid</a>
    </header>
    <section class="grid grid-cols-2 gap-3 sm:grid-cols-4">
      <div v-for="(label,key) in { WORK_BLOCKED:'Needs attention', CALCULATED:'Calculated', READY_FOR_GENERATION:'Ready', GENERATED:'Generated' }" :key="key" class="rounded-xl border bg-white p-3 shadow-sm">
        <p class="text-xs text-slate-500">{{label}}</p><p class="text-2xl font-bold">{{Number(counts[key]||0)}}</p>
      </div>
    </section>
    <section class="rounded-xl border-2 border-indigo-200 bg-white p-4 space-y-3">
      <h2 class="text-lg font-bold">1. Bulk Calculate + Save Snapshots (No Salary Generation)</h2>
      <p class="text-sm text-slate-600">All-pages server selection. Runs in 10-employee requests, preserving existing approved snapshots. Draft mode can save unresolved historical arrears with their errors, without declaring them approved. Approval mode remains financial-review gated.</p>
      <div class="flex flex-wrap gap-3 items-center">
        <label class="text-sm font-semibold">Process <select v-model="operationSize" class="rounded border px-3 py-2"><option value="100">100</option><option value="500">500</option><option value="1000">1000</option><option value="all">All eligible</option></select></label>
        <label class="text-sm font-semibold">Save as <select v-model="bulkSaveStatus" class="rounded border px-3 py-2"><option value="draft">Provisional Draft (recommended)</option><option value="approved">Approved (verified only)</option></select></label>
        <input v-model="reason" class="min-w-64 flex-1 rounded border px-3 py-2 text-sm" placeholder="HR audit reason / reference (required)" />
        <button :disabled="operationBusy||reason.trim().length<3" @click="calculateAll" class="rounded bg-indigo-700 px-4 py-2 font-semibold text-white disabled:opacity-40">{{operationBusy?'Processing…':bulkSaveStatus==='draft'?'Calculate + Save Draft':'Calculate + Save Approved'}}</button>
        <button :disabled="!operationLog.length" @click="exportOperationLog" class="rounded border px-3 py-2 disabled:opacity-40">Export results CSV</button>
      </div>
      <p class="text-sm">Checked {{operationSummary.checked}} · Saved/preserved {{operationSummary.ok}} · Blocked {{operationSummary.failed}}</p>
      <details v-if="operationLog.length"><summary class="cursor-pointer text-sm underline">Show per-employee results</summary><div class="max-h-48 overflow-auto text-xs"><p v-for="(r,i) in operationLog" :key="i" :class="r.ok?'text-emerald-700':'text-rose-700'">{{r.code}} · {{r.status}} · {{r.message||''}}</p></div></details>
      <p class="text-xs text-amber-800">A successful save is not a verified financial audit. Review provisional Horizontal Bill before promoting to Ready for Generation.</p>
    </section>
    <section v-if="Number(counts.NOT_INITIALIZED||0)>0" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3">
      <div><strong>{{counts.NOT_INITIALIZED}} employees need roster initialization</strong><p class="text-xs text-amber-800">Safe one-time setup; existing bills and GPF records stay unchanged.</p></div>
      <button :disabled="busy" @click="initialize()" class="rounded-lg bg-amber-800 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40">Initialize eligible workflows</button>
    </section>
    <section class="rounded-xl border bg-white shadow-sm">
      <div class="flex flex-wrap gap-2 border-b p-3">
        <button @click="applyFilter('WORK_BLOCKED')" class="rounded-lg px-4 py-2 font-semibold" :class="activeTab==='WORK_BLOCKED'?'bg-indigo-600 text-white':'bg-slate-100'">Blocked Employee List ({{workBlockedCount}})</button>
        <button @click="applyFilter('CALCULATED')" class="rounded-lg px-4 py-2 font-semibold" :class="activeTab==='CALCULATED'?'bg-indigo-600 text-white':'bg-slate-100'">Calculated Employee List ({{Number(counts.CALCULATED||0)}})</button>
      </div>
      <div class="space-y-3 p-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div class="flex flex-wrap items-end gap-2 rounded-lg border bg-slate-50 p-3 text-sm">
            <label class="space-y-1"><span class="block text-xs text-slate-600">Department / Office</span><select v-model="filters.department_id" class="rounded border px-2 py-2"><option value="">All Departments</option><option v-for="o in (filterOptions?.departments||[])" :key="o.id" :value="String(o.id)">{{o.name}}</option></select></label>
            <label class="space-y-1"><span class="block text-xs text-slate-600">Staff Category</span><select v-model="filters.category_id" class="rounded border px-2 py-2"><option value="">All Categories</option><option v-for="o in (filterOptions?.categories||[])" :key="o.id" :value="String(o.id)">{{o.name}}</option></select></label>
            <label class="space-y-1"><span class="block text-xs text-slate-600">Payroll Group</span><select v-model="filters.payroll_group_id" class="rounded border px-2 py-2"><option value="">All Payroll Groups</option><option v-for="o in (filterOptions?.payroll_groups||[])" :key="o.id" :value="String(o.id)">{{o.group_name}}</option></select></label>
            <label class="space-y-1"><span class="block text-xs text-slate-600">Rows / Page</span><select v-model="filters.per_page" class="rounded border px-2 py-2"><option value="25">25</option><option value="50">50</option><option value="100">100</option><option value="200">200</option></select></label>
            <button @click="findEmployees" class="rounded bg-indigo-600 px-3 py-2 text-white">Apply Filters</button>
            <button @click="resetFilters" class="rounded border bg-white px-3 py-2">Reset</button>
          </div>
          <div class="flex gap-2"><input v-model="search" @keyup.enter="findEmployees" placeholder="Name / employee code" class="w-52 rounded-lg border px-3 py-2 text-sm"/><button @click="findEmployees" class="rounded-lg border px-3 py-2 text-sm">Search</button></div>
          <span class="text-sm text-slate-500">{{selected.length}} selected on current page</span>
        </div>
        <div class="flex flex-wrap gap-2">
          <button @click="selectVisible" class="rounded-lg border px-3 py-2 text-sm">Select page</button>
          <button @click="selected=[]" class="rounded-lg border px-3 py-2 text-sm">Clear</button>
          <button :disabled="busy||!selected.length" @click="initialize(true)" class="rounded-lg border px-3 py-2 text-sm disabled:opacity-40">Initialize selected</button>
          <button :disabled="busy" @click="preview('pending')" class="rounded-lg border px-3 py-2 text-sm disabled:opacity-40">Preview pending 18</button>
          <button :disabled="busy||!selected.length" @click="preview('selected')" class="rounded-lg border px-3 py-2 text-sm disabled:opacity-40">Preview selected</button>
        </div>
        <section class="rounded-lg border border-indigo-200 bg-indigo-50 p-3 space-y-2">
           <h3 class="font-semibold">HR Review & Bulk Approval — no salary posting</h3>
           <p class="text-xs">Select CALCULATED employees. First print the approved-snapshot Horizontal Bill, review with HR, then approve for generation (up to 50/page). Errors are reported by employee.</p>
           <input v-model="reason" class="w-full rounded border p-2 text-sm" placeholder="Required HR decision / approval reference" />
           <div class="flex flex-wrap gap-2">
             <button :disabled="busy||!approvedSelected.length" @click="provisionalHorizontal" class="rounded bg-white px-3 py-2 text-sm border disabled:opacity-40">Print Provisional Horizontal Bill ({{approvedSelected.length}})</button>
             <button :disabled="busy||!allSelectedApproved||reason.trim().length<3" @click="approve('approve_for_generation')" class="rounded bg-indigo-700 px-3 py-2 text-sm text-white disabled:opacity-40">Bulk Approve for Generation ({{approvedSelected.length}})</button>
           </div>
           <p class="text-xs text-amber-900">This moves CALCULATED → READY only. No salary bill, bank payment, GPF ledger or arrear disbursement is created.</p>
        </section>
        <details class="rounded-lg border p-3"><summary class="cursor-pointer font-semibold">Save calculations & approval actions</summary>
          <p class="mt-2 text-xs text-slate-500">Save uses existing audited calculator versioning; approval is separate. Failed employees remain visible. Never posts a bill.</p>
          <div class="mt-3 flex flex-wrap gap-2"><input v-model="reason" placeholder="Correction / approval reason" class="min-w-56 flex-1 rounded-lg border px-3 py-2 text-sm"/>
            <button :disabled="busy||!selected.length" @click="saveCalculations" class="rounded-lg border border-indigo-400 px-3 py-2 text-sm text-indigo-700 disabled:opacity-40">Save selected calculations</button>
            <button :disabled="busy||!selected.length" @click="approve('approve_calculation')" class="rounded-lg border px-3 py-2 text-sm disabled:opacity-40">Resolve & Approve Calculation</button>
            <button :disabled="busy||!selected.length" @click="approve('approve_for_generation')" class="rounded-lg border px-3 py-2 text-sm disabled:opacity-40">Approve for Generation</button>
            <button :disabled="busy||!selected.length" @click="approve('return_for_revision')" class="rounded-lg border px-3 py-2 text-sm disabled:opacity-40">Return for Revision</button>
          </div>
        </details>
        <p v-if="notice" role="status" class="whitespace-pre-wrap rounded-lg bg-slate-50 p-3 text-sm">{{notice}}</p>
      </div>
      <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="p-3">Select</th><th class="p-3">Employee</th><th class="p-3">Workflow</th><th class="p-3">Approved version</th><th class="p-3">Actions</th></tr></thead><tbody>
        <tr v-for="r in pageRows" :key="r.user_id" class="border-t"><td class="p-3"><input v-model="selected" type="checkbox" :value="r.user_id" :disabled="!canSelect(r)"/></td>
          <td class="p-3"><strong>{{r.employee_code}} · {{r.name}}</strong><p v-if="r.profile_count>1" class="text-xs text-rose-600">Duplicate profile; review required</p></td>
          <td class="p-3">{{r.display_status.replaceAll('_',' ')}}</td><td class="p-3">{{r.latest_approved_calculation_version_id||'—'}}</td>
          <td class="whitespace-nowrap p-3"><a class="text-indigo-700 underline" :href="`/dashboard/salary/calculator/${r.user_id}?year=${year}&month=${month}`">Calculator</a><span class="mx-2 text-slate-300">|</span><a class="text-indigo-700 underline" :href="`/dashboard/salary/fixation/${r.user_id}/workspace?year=${year}&month=${month}`">Edit components</a><template v-if="r.display_status==='CALCULATED' && r.active_calculation_version_id"><span class="mx-2 text-slate-300">|</span><a class="text-indigo-700 underline" target="_blank" rel="noopener" :href="`/dashboard/salary/provisional/${r.user_id}?year=${year}&month=${month}&version_id=${r.active_calculation_version_id}`">Provisional bill</a></template></td></tr>
        <tr v-if="!pageRows.length"><td colspan="5" class="p-8 text-center text-slate-500">No employees in this worklist. Review the period and filters.</td></tr>
      </tbody></table></div>
      <nav class="flex flex-wrap gap-2 border-t p-3"><a v-for="(link,i) in (rows?.links||[])" :key="i" :href="link.url||undefined" class="rounded border px-2 py-1 text-xs" :class="{'bg-indigo-50 font-bold':link.active}" v-html="link.label"/></nav>
    </section>
    <details class="rounded-xl border bg-white p-4"><summary class="cursor-pointer font-semibold">Run history / exact failure counts</summary><div v-for="r in runs" :key="r.id" class="border-b py-2 text-sm">Run #{{r.id}} · {{r.status}} · {{r.examined}} checked · {{r.estimated}} estimated · {{r.blocked}} blocked</div><p v-if="!(runs||[]).length" class="text-sm text-slate-500">No preview runs</p></details>
  </main>
    </AuthenticatedLayout>
</template>
