<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({ rows: Object, counts: Object, year: Number, month: Number, status: String });
const selected = ref([]);
const busy = ref(false);
const message = ref('');
const currentRun = ref(null);
const total = computed(() => Number(props.counts?.READY_FOR_GENERATION || 0));
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
const headers = () => ({ 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() });

function showStatus(status) {
  router.get('/dashboard/salary/generation-workspace', { year: props.year, month: props.month, status });
}
async function start(selectedOnly) {
  if (selectedOnly && selected.value.length === 0) { message.value = 'Select at least one READY employee.'; return; }
  busy.value = true;
  try {
    const request = { year: props.year, month: props.month, ...(selectedOnly ? { user_ids: selected.value } : {}) };
    const response = await fetch('/dashboard/salary/generation-workspace/runs', { method: 'POST', headers: headers(), body: JSON.stringify(request) });
    const result = await response.json();
    if (!response.ok) throw new Error(result.message || JSON.stringify(result.errors || result));
    currentRun.value = result;
    message.value = `Run ${result.run_token}: queued ${result.total} approved employees. Click Continue Posting.`;
  } catch (e) { message.value = e.message; }
  finally { busy.value = false; }
}
async function continuePosting() {
  if (!currentRun.value || busy.value) return;
  busy.value = true;
  try {
    const response = await fetch(currentRun.value.next_url, { method: 'POST', headers: headers() });
    const result = await response.json();
    if (!response.ok) throw new Error(result.message || 'Posting failed');
    message.value = `${result.processed} processed this request; ${result.remaining} pending. ${JSON.stringify(result.counts || {})}`;
    if (result.remaining === 0) router.reload({ only: ['rows', 'counts'] });
  } catch (e) { message.value = e.message; }
  finally { busy.value = false; }
}
</script>
<template>
  <main class="p-6 space-y-5">
    <h1 class="text-2xl font-semibold">Generate Salary</h1>
    <p class="text-sm">{{ year }}-{{ String(month).padStart(2,'0') }} · {{ total }} ready for generation</p>
    <p class="text-sm text-amber-800">Only READY_FOR_GENERATION bills can be posted. Existing generated bills are read-only.</p>
    <div class="flex gap-3 flex-wrap">
      <button class="border rounded px-3 py-2" @click="showStatus('READY_FOR_GENERATION')">Ready ({{ counts?.READY_FOR_GENERATION || 0 }})</button>
      <button class="border rounded px-3 py-2" @click="showStatus('GENERATED')">Generated ({{ counts?.GENERATED || 0 }})</button>
    </div>
    <div v-if="status === 'READY_FOR_GENERATION'" class="flex gap-3 flex-wrap">
      <button :disabled="busy || !selected.length" class="border rounded px-4 py-2 disabled:opacity-40" @click="start(true)">Prepare Selected</button>
      <button :disabled="busy || total === 0" class="border rounded px-4 py-2 disabled:opacity-40" @click="start(false)">Prepare Next 50 Ready</button>
      <button :disabled="busy || !currentRun" class="bg-indigo-700 text-white rounded px-4 py-2 disabled:opacity-40" @click="continuePosting">Continue Posting (up to 25)</button>
    </div>
    <p class="text-sm" role="status" aria-live="polite">{{ message }}</p>
    <p v-if="currentRun" class="text-xs">Resume run: {{ currentRun.run_token }}</p>
    <div class="overflow-x-auto border rounded">
      <table class="min-w-full text-sm"><thead><tr><th>Select</th><th>User ID</th><th>Employee</th><th>Status</th><th>Bill ID</th><th>Posting error</th></tr></thead>
        <tbody><tr v-for="row in rows.data" :key="row.user_id" class="border-t">
          <td class="p-2"><input v-if="row.workflow_status === 'READY_FOR_GENERATION'" type="checkbox" :value="row.user_id" v-model="selected"></td>
          <td class="p-2">{{ row.user_id }}</td><td class="p-2">{{ row.name }}</td>
          <td class="p-2">{{ row.workflow_status }}</td><td class="p-2">{{ row.posted_salary_generate_id || '—' }}</td>
          <td class="p-2">{{ row.posting_error || '—' }}</td>
        </tr></tbody></table>
    </div>
    <nav class="flex gap-3 flex-wrap"><a v-for="link in rows.links" :key="link.label" :href="link.url || undefined" v-html="link.label" class="underline text-xs"></a></nav>
  </main>
</template>
