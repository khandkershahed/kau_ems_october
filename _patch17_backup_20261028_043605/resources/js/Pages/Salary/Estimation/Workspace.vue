<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
const props = defineProps({ rows:Object, counts:Object, runs:Array, year:Number, month:Number });
const selected = ref([]);
const busy = ref(false);
const approvalReason = ref('');
const rosterBusy = ref(false);
const rosterMessage = ref('');
const rosterStats = ref(null);
async function initializeRoster() {
  if (rosterBusy.value) return;
  rosterBusy.value = true; rosterMessage.value = '';
  try {
    const response = await fetch('/dashboard/salary/workflow/reconcile', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
      body: JSON.stringify({ year: props.year, month: props.month, limit: 1000 })
    });
    const body = await response.json();
    if (!response.ok) throw new Error(body.message || JSON.stringify(body.errors || body));
    rosterStats.value = body;
    rosterMessage.value = `Roster: ${body.initialized} initialized, ${body.existing} existing, ${body.ambiguous} ambiguous, ${body.posted} posted.`;
    selected.value = [];
    router.reload({ only: ['rows','counts','runs'], preserveScroll: true });
  } catch (error) { rosterMessage.value = error.message || 'Roster initialization failed.'; }
  finally { rosterBusy.value = false; }
}

async function bulkApprove(action){
  if(!selected.value.length || approvalReason.value.trim().length<3){message.value='Select employees and enter an audit reason.';return;}
  busy.value=true;
  try {
    const items=selected.value.map(id=>{const row=props.rows.data.find(r=>r.user_id===id);return {user_id:id,expected_revision:row.revision,calculation_version_id:action==='approve_calculation'?row.latest_approved_calculation_version_id:row.active_calculation_version_id};});
    const res=await fetch('/dashboard/salary/workflow/bulk-approve',{method:'POST',headers:csrfHeaders(),body:JSON.stringify({year:props.year,month:props.month,action,reason:approvalReason.value,items})});
    const data=await res.json();if(!res.ok)throw new Error(data.message||JSON.stringify(data.errors));
    message.value=`Approval: ${data.succeeded} succeeded, ${data.failed} failed. ${data.results.filter(r=>!r.ok).map(r=>r.user_id+': '+r.error).join(' | ')}`;
    router.reload({only:['rows','counts']});
  }catch(e){message.value=e.message;}finally{busy.value=false;}
}

const message = ref('');
const status = ref('');
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
const csrfHeaders = () => ({'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf()});
const pendingCodes = new Set(['1003','10046','10108','20029','20033','20038','20049','20057','20082','20083','30081','30098','30133','30254','40033','40140','40158','40274']);
function selectPendingOnPage(){ selected.value=props.rows.data.filter(r=>pendingCodes.has(String(r.employee_code)) && r.workflow_status!=='GENERATED').map(r=>r.user_id); }
const total = computed(() => Object.values(props.counts || {}).reduce((s,n)=>s+Number(n),0));
function filter(value){ router.get('/dashboard/salary/estimation',{year:props.year,month:props.month,...(value?{status:value}:{})}); }
async function run(selectedOnly, pending18=false){
  busy.value=true; message.value='';
  try {
    const payload={year:props.year,month:props.month,...(pending18?{employee_codes:[...pendingCodes]}:(selectedOnly?{user_ids:selected.value}: {}))};
    if(selectedOnly && !selected.value.length) throw new Error('Select employees first.');
    const start=await fetch('/dashboard/salary/estimation/runs',{method:'POST',headers:csrfHeaders(),body:JSON.stringify(payload)});
    const initial=await start.json();if(!start.ok)throw new Error(initial.message||JSON.stringify(initial.errors));
    let current;
    do {
      const res=await fetch(`/dashboard/salary/estimation/runs/${initial.run_id}/next`,{method:'POST',headers:csrfHeaders});
      current=await res.json();if(!res.ok)throw new Error(current.message||'Batch failed.');
      message.value=`Run #${initial.run_id}: ${current.run.examined} examined, ${current.run.estimated} estimated, ${current.run.blocked} blocked`;
    } while(current.run.status==='running' && current.processed>0);
    router.reload({only:['rows','counts','runs']});
  }catch(e){message.value=e.message;}finally{busy.value=false;}
}
</script>
<template>
  <main class="p-6 space-y-5">
    <h1 class="text-2xl font-semibold">Salary Estimation / Calculation</h1>
    <a class="inline-block rounded bg-emerald-700 px-4 py-2 text-white" :href="`/dashboard/salary/estimation/bulk-components?year=${year}&month=${month}`">Bulk modify 18 employees →</a>
    <p class="text-sm text-gray-600">{{ year }}-{{ String(month).padStart(2,'0') }} · {{ total }} reconciled employees. Preview only — does not approve or post salary.</p>
    <section class="rounded-lg border border-amber-200 bg-amber-50 p-4 space-y-2" aria-label="Roster recovery">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div><h2 class="font-semibold text-amber-950">Employee roster</h2><p class="text-sm text-amber-900">{{ total ? 'Refresh roster to include any newly eligible employees.' : 'No employee-period workflow records found. Initialize October roster before calculation.' }}</p></div>
        <button type="button" class="rounded bg-emerald-700 px-4 py-2 font-medium text-white disabled:opacity-50" :disabled="rosterBusy || busy" @click="initializeRoster">{{ rosterBusy ? 'Initializing…' : (total ? 'Reconcile / Refresh Roster' : 'Initialize October Roster') }}</button>
      </div>
      <p v-if="rosterMessage" role="status" class="text-sm text-amber-950">{{ rosterMessage }}</p>
      <p v-if="rosterStats?.ambiguous" class="text-sm text-red-700">{{ rosterStats.ambiguous }} ambiguous profile/bill records require manual evidence-based review. No amounts were changed.</p>
    </section>
    <div class="flex gap-2 flex-wrap">
      <button v-for="s in ['', 'BLOCKED','CALCULATED','READY_FOR_GENERATION','GENERATED']" :key="s" @click="filter(s)" class="border rounded px-3 py-2 text-sm">{{ s||'All' }} {{ s ? (counts[s]||0) : total }}</button>
    </div>
    <div class="flex gap-3 flex-wrap">
      <button class="border border-emerald-700 rounded px-4 py-2" :disabled="busy || rosterBusy || !total" @click="run(false,true)">Calculate pending 18 (preview, all pages)</button>
      <button class="bg-indigo-700 text-white rounded px-4 py-2 disabled:opacity-40" :disabled="busy || rosterBusy || !total" @click="run(false)">Calculate All (preview)</button>
      <button class="border rounded px-4 py-2 disabled:opacity-40" :disabled="busy || !selected.length" @click="run(true)">Calculate Selected (preview)</button>
    </div>
    <div class="flex flex-wrap gap-2 items-center"><input v-model="approvalReason" class="border rounded px-3 py-2" placeholder="Mandatory audit reason" aria-label="Approval reason"/><button class="border rounded px-3 py-2" :disabled="busy||!selected.length" @click="bulkApprove('approve_calculation')">Approve Calculation (selected)</button><button class="border rounded px-3 py-2" :disabled="busy||!selected.length" @click="bulkApprove('approve_for_generation')">Approve for Generation (selected)</button><button class="border rounded px-3 py-2" :disabled="busy||!selected.length" @click="bulkApprove('return_for_revision')">Return for Revision</button></div>
    <p aria-live="polite" class="text-sm">{{ message }}</p>
    <div v-if="!total" class="rounded border border-dashed p-5 text-sm text-gray-600">The October roster is empty. Use Initialize October Roster above. This does not approve or post salaries.</div>
    <div v-else class="overflow-x-auto border rounded">
      <table class="w-full text-sm"><thead><tr class="bg-gray-100"><th class="p-2">Select</th><th>Employee Code</th><th>User ID</th><th>Employee</th><th>Status</th><th>Revision</th><th>Actions</th></tr></thead>
        <tbody><tr v-for="r in rows.data" :key="r.user_id" class="border-t"><td class="p-2 text-center"><input type="checkbox" :value="r.user_id" v-model="selected" :disabled="r.workflow_status==='GENERATED'"></td><td class="p-2">{{r.employee_code}}</td><td class="p-2">{{r.user_id}}</td><td class="p-2">{{r.name}}</td><td class="p-2">{{r.workflow_status}}</td><td class="p-2">{{r.revision}}</td><td class="p-2"><a class="underline text-indigo-700" :href="`/dashboard/salary/calculator/${r.user_id}?year=${year}&month=${month}`">Edit calculation</a> · <a class="underline text-indigo-700" :href="`/dashboard/salary/fixation/${r.user_id}/workspace?year=${year}&month=${month}`">Components</a> · <a target="_blank" rel="noopener" class="underline" :href="`/dashboard/salary/provisional/${r.user_id}?year=${year}&month=${month}`">Provisional Bill</a></td></tr></tbody>
      </table>
    </div>
    <div class="flex gap-4"><a v-for="p in rows.links" :key="p.label" v-html="p.label" :href="p.url || undefined" class="underline text-sm"></a></div>
    <section><h2 class="font-semibold">Recent estimation runs</h2><p v-for="r in runs" :key="r.id" class="text-sm">#{{r.id}} · {{r.status}} · {{r.examined}} examined · {{r.estimated}} estimated · {{r.blocked}} blocked</p></section>
  </main>
</template>
