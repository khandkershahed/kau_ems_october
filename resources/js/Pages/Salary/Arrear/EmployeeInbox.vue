<script setup>
import { ref } from 'vue';
import axios from 'axios';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
const props = defineProps({ snapshots:{type:Array,default:()=>[]} });
const records = ref(props.snapshots);
const notes = ref({});
const error = ref('');
const message = ref('');
async function respond(snapshot, decision) {
    error.value = ''; message.value = '';
    try {
        const res = await axios.post(route('salary-arrears.review', {snapshotId:snapshot.id}), {action:decision,note:notes.value[snapshot.id]||''});
        snapshot.status = res.data.status;
        message.value = 'Your response was recorded for HR. No salary was paid.';
    } catch (e) { error.value = e.response?.data?.message || e.message; }
}
const money = x => x===null ? '—' : Number(x).toLocaleString('en-BD',{minimumFractionDigits:2,maximumFractionDigits:2});
</script>
<template>
    <Head title="My Arrear Review" />
    <AuthenticatedLayout><div class="max-w-4xl mx-auto p-6 space-y-4"><h1 class="text-2xl font-semibold">My Arrear Statements</h1><p class="text-sm text-amber-700">These are provisional retrospective calculations. Confirmation does not create a salary payment or GPF transaction.</p><p v-if="error" class="text-red-700">{{ error }}</p><p v-if="message" class="text-green-700">{{ message }}</p><div v-for="s in records" :key="s.id" class="bg-white border rounded-lg p-4 space-y-3"><h2 class="font-semibold">{{ s.period_name }} — Version {{ s.version }}</h2><p>Status: {{ s.status }} · Proposed net: BDT {{ money(s.net_arrear) }}</p><div v-if="s.status==='sent'" class="space-y-2"><textarea v-model="notes[s.id]" rows="2" class="border rounded w-full" placeholder="Comments or disputed information"></textarea><div class="flex gap-2"><button class="rounded bg-emerald-700 text-white px-4 py-2" @click="respond(s,'confirm')">Confirm as reviewed</button><button class="rounded bg-red-700 text-white px-4 py-2" @click="respond(s,'dispute')">Dispute</button></div></div></div><p v-if="!records.length">No arrear review statements have been sent to you.</p></div></AuthenticatedLayout>
</template>
