<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
const props = defineProps({ fixation: { type: Object, required: true } });
const decision = ref('confirm');
const note = ref('');
const busy = ref(false);
const money = n => '৳' + Number(n).toLocaleString('en-BD', {minimumFractionDigits:2,maximumFractionDigits:2});
function respond(){
  if (busy.value || props.fixation.status !== 'sent') return;
  busy.value = true;
  router.post(route('salary-fixation.employee-respond', props.fixation.id), {decision:decision.value,note:note.value}, {onFinish:()=>busy.value=false});
}
</script>
<template>
  <Head title="My 2026 Salary Fixation Review" />
  <AuthenticatedLayout>
    <main class="max-w-3xl mx-auto p-6 space-y-5 text-slate-900 dark:text-white">
      <h1 class="text-2xl font-bold">My 2026 Salary Fixation</h1>
      <p class="text-sm">This is your personal saved fixation statement. Status: <strong>{{fixation.status}}</strong>.</p>
      <p><a :href="route('salary-fixation.employee-print', fixation.id)" target="_blank" rel="noopener" class="inline-block rounded-lg px-4 py-2 bg-emerald-700 text-white">Open official fixation form · Print / Save as PDF</a></p>
      <div class="rounded-xl border bg-white dark:bg-gray-800 p-5 space-y-3">
        <p>Grade: {{fixation.grade_number}} · Old basic: {{money(fixation.old_basic)}}</p>
        <p>Fitted 2026 grade basic: {{money(fixation.calculation.fitment_basic)}}</p>
        <p>July 2026 payable basic: {{money(fixation.calculation.phase_2026_07)}}</p>
        <p>January 2027 payable basic: {{money(fixation.calculation.phase_2027_01)}}</p>
        <p>July 2027 payable basic: {{money(fixation.calculation.phase_2027_07)}}</p>
      </div>
      <form v-if="fixation.status === 'sent'" @submit.prevent="respond" class="space-y-3 border rounded-xl p-5">
        <label class="block"><input type="radio" v-model="decision" value="confirm" /> I have checked and confirm my fixation</label>
        <label class="block"><input type="radio" v-model="decision" value="dispute" /> I disagree and request HR correction</label>
        <textarea v-model="note" class="w-full rounded-lg text-slate-900" rows="3" placeholder="Notes or correction details (required if disputed)" :required="decision==='dispute'" />
        <button :disabled="busy || (decision==='dispute' && !note.trim())" class="bg-emerald-700 text-white px-5 py-2 rounded-lg disabled:opacity-40">Submit response</button>
      </form>
      <div v-else class="text-sm text-slate-600 dark:text-slate-300">Your response has been recorded or this statement is no longer awaiting employee review.</div>
    </main>
  </AuthenticatedLayout>
</template>
