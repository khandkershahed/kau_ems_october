<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
const props = defineProps({ rows:Object, counts:Object, runs:Array, year:Number, month:Number });
const selected=ref([]), busy=ref(false), notice=ref(''), reason=ref(''), search=ref('');
const statuses=['','NOT_INITIALIZED','BLOCKED','CALCULATED','READY_FOR_GENERATION','GENERATED'];
const pendingCodes=['1003','10046','10108','20029','20033','20038','20049','20057','20082','20083','30081','30098','30133','30254','40033','40140','40158','40274'];
const total=computed(()=>Object.values(props.counts||{}).reduce((a,n)=>a+Number(n),0));
const pageRows=computed(()=>props.rows?.data||[]);
const canSelect=(r)=>r.profile_count===1 && r.display_status!=='GENERATED';
const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.content||'';
async function post(url,payload){
 const res=await fetch(url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf()},body:JSON.stringify(payload)});
 const data=await res.json().catch(()=>({}));
 if(!res.ok)throw Error(data.message||JSON.stringify(data.errors||data)||`Request failed (${res.status})`);
 return data;
}
function refresh(){router.reload({only:['rows','counts','runs'],preserveScroll:true});}
function applyFilter(s=''){router.get('/dashboard/salary/estimation',{year:props.year,month:props.month,...(s?{status:s}:{})}, {preserveState:false});}
function findEmployees(){router.get('/dashboard/salary/estimation',{year:props.year,month:props.month,search:search.value},{preserveState:false});}
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
  const items=selected.value.map(id=>{const r=pageRows.value.find(e=>e.user_id===id);return {user_id:id,expected_revision:r.revision,calculation_version_id:action==='approve_calculation'?r.latest_approved_calculation_version_id:r.active_calculation_version_id};});
  if(items.some(i=>!i.expected_revision))throw Error('Initialize selected workflows before approval.');
  const data=await post('/dashboard/salary/workflow/bulk-approve',{year:props.year,month:props.month,action,reason:reason.value,items});
  notice.value=`${data.succeeded} successful, ${data.failed} failed. ${(data.results||[]).filter(x=>!x.ok).map(x=>`${x.user_id}: ${x.error}`).join('; ')}`;
  refresh();
 }catch(e){notice.value=e.message;}finally{busy.value=false;}
}
</script>
<template>
  <main class="mx-auto max-w-7xl space-y-5 p-4 md:p-6 text-slate-800">
    <header class="flex flex-wrap items-center justify-between gap-3">
      <div><div class="text-xs font-semibold uppercase tracking-wider text-indigo-600">Payroll · {{year}} / {{String(month).padStart(2,'0')}}</div>
        <h1 class="text-2xl font-bold">Salary Estimation</h1><p class="text-sm text-slate-500">Employee roster, calculation previews & approvals. No posting from this page.</p></div>
      <a :href="`/dashboard/salary/estimation/bulk-components?year=${year}&month=${month}`" class="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-semibold text-indigo-700">Edit employee components →</a>
    </header>
    <section class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
      <button v-for="s in statuses" :key="s" @click="applyFilter(s)" class="rounded-xl border bg-white p-3 text-left shadow-sm hover:border-indigo-400"><div class="text-xs text-slate-500">{{s?s.replaceAll('_',' '):'All employees'}}</div><div class="mt-1 text-2xl font-bold">{{s?Number(counts[s]||0):total}}</div></button>
    </section>
    <div v-if="Number(counts.NOT_INITIALIZED||0)>0" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
      <div><div class="font-semibold text-amber-900">{{counts.NOT_INITIALIZED}} employee workflows not initialized</div><p class="text-xs text-amber-800">Employees are visible below. Initialize workflows to enable calculation. This does not change salary amounts.</p></div>
      <button :disabled="busy" @click="initialize()" class="rounded-lg bg-amber-800 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{{busy?'Working…':'Initialize roster'}}</button>
    </div>
    <div class="rounded-xl border bg-white p-4 shadow-sm space-y-3">
      <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="font-semibold">Employee actions</h2><span class="text-xs text-slate-500">{{selected.length}} selected on this page</span></div>
      <div class="flex flex-wrap gap-2">
        <button @click="selectVisible" class="rounded-lg border px-3 py-2 text-sm">Select page</button>
        <button @click="selected=[]" class="rounded-lg border px-3 py-2 text-sm">Clear</button>
        <button :disabled="busy||!selected.length" @click="initialize(true)" class="rounded-lg border px-3 py-2 text-sm disabled:opacity-40">Initialize selected</button>
        <button :disabled="busy" @click="preview('pending')" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-40">Preview pending 18</button>
        <button :disabled="busy||!selected.length" @click="preview('selected')" class="rounded-lg border border-indigo-400 px-3 py-2 text-sm text-indigo-700 disabled:opacity-40">Preview selected</button>
      </div>
      <details class="rounded-lg border p-3"><summary class="cursor-pointer text-sm font-semibold">Calculation approval options</summary>
        <div class="mt-3 flex flex-wrap items-center gap-2"><input v-model="reason" placeholder="Reason for approval / revision" class="min-w-[240px] flex-1 rounded-lg border px-3 py-2 text-sm"/>
          <button :disabled="busy||!selected.length" @click="approve('approve_calculation')" class="rounded-lg border px-3 py-2 text-sm disabled:opacity-40">Approve calculation</button>
          <button :disabled="busy||!selected.length" @click="approve('approve_for_generation')" class="rounded-lg border px-3 py-2 text-sm disabled:opacity-40">Approve for generation</button>
          <button :disabled="busy||!selected.length" @click="approve('return_for_revision')" class="rounded-lg border px-3 py-2 text-sm disabled:opacity-40">Return for revision</button>
        </div><p class="mt-2 text-xs text-slate-500">Approvals require a verified saved calculation snapshot. A preview alone is not an approval.</p></details>
      <p v-if="notice" role="status" class="rounded-lg bg-slate-50 p-3 text-sm whitespace-pre-wrap">{{notice}}</p>
    </div>
    <section class="overflow-hidden rounded-xl border bg-white shadow-sm">
      <div class="flex flex-wrap items-center gap-2 border-b p-3"><h2 class="mr-auto font-semibold">Employees</h2>
        <input v-model="search" @keyup.enter="findEmployees" placeholder="Search name / employee code" class="rounded-lg border px-3 py-2 text-sm"/><button @click="findEmployees" class="rounded-lg border px-3 py-2 text-sm">Search</button></div>
      <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="p-3">Select</th><th class="p-3">Employee</th><th class="p-3">Status</th><th class="p-3">Version</th><th class="p-3">Actions</th></tr></thead>
        <tbody><tr v-for="r in pageRows" :key="r.user_id" class="border-t hover:bg-slate-50"><td class="p-3"><input v-model="selected" :value="r.user_id" :disabled="!canSelect(r)" type="checkbox"/></td>
          <td class="p-3"><div class="font-semibold">{{r.employee_code}} · {{r.name}}</div><div class="text-xs text-slate-400">User #{{r.user_id}} <span v-if="r.profile_count>1" class="text-rose-600">· Duplicate profiles</span></div></td>
          <td class="p-3"><span class="rounded-full bg-slate-100 px-2 py-1 text-xs">{{r.display_status.replaceAll('_',' ')}}</span></td><td class="p-3">{{r.latest_approved_calculation_version_id||'—'}}</td>
          <td class="p-3 whitespace-nowrap"><a class="text-indigo-700 underline" :href="`/dashboard/salary/calculator/${r.user_id}?year=${year}&month=${month}`">Calculator</a><span class="mx-2 text-slate-300">|</span><a class="text-indigo-700 underline" :href="`/dashboard/salary/fixation/${r.user_id}/workspace?year=${year}&month=${month}`">Components</a></td></tr>
          <tr v-if="!pageRows.length"><td colspan="5" class="p-8 text-center text-slate-500">No employees match this filter. Check employee profiles and selected period.</td></tr>
        </tbody></table></div>
      <div class="flex flex-wrap gap-2 border-t p-3"><a v-for="(link,i) in (rows?.links||[])" :key="i" :href="link.url||undefined" class="rounded border px-2 py-1 text-xs" :class="{'bg-indigo-50 font-bold':link.active}" v-html="link.label"/></div>
    </section>
    <details class="rounded-xl border bg-white p-4"><summary class="cursor-pointer font-semibold">Recent preview runs</summary><p v-if="!(runs||[]).length" class="mt-2 text-sm text-slate-500">No previews yet.</p><div v-for="r in runs" :key="r.id" class="border-b py-2 text-sm">#{{r.id}} · {{r.status}} · {{r.examined}} checked · {{r.estimated}} estimated · {{r.blocked}} blocked</div></details>
  </main>
</template>
