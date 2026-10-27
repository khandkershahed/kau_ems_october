<script setup>
import { computed } from 'vue';
const props=defineProps({data:{type:Array,default:()=>[]},organizationName:{type:String,default:'Khulna Agricultural University'},organizationLogo:{type:String,default:'/logo.png'},organizationAddress:{type:String,default:'Temporary Office: 327, Jessore Road, North Mujgunni, Khalishpur, Khulna-9000'}});
const record=computed(()=>props.data?.[0]||null);
const money=v=>v===null||v===undefined?'—':Number(v).toLocaleString('en-BD',{minimumFractionDigits:2,maximumFractionDigits:2});
</script>
<template>
<div class="a4-sheet bg-white mx-auto p-10 min-h-[297mm] border text-slate-900 text-xs print:border-0 print:shadow-none" style="break-after:page">
 <header class="relative text-center border-b pb-5"><img :src="organizationLogo" alt="University logo" class="absolute left-0 h-14 w-14 object-contain"/><h1 class="text-lg font-bold">{{organizationName}}</h1><p>{{organizationAddress}}</p><p class="font-bold mt-2">Approved Salary Fixation — Summary</p></header>
 <div v-if="record" class="mt-5 space-y-4">
  <div class="flex justify-between"><span>Employee: <b>{{record.name}}</b></span><span>Code: {{record.employee_code}}</span></div>
  <div v-if="record.fixation_report">
   <div class="mb-4">Fixation #{{record.fixation_report.id}} · Grade {{record.fixation_report.grade}} · Approved</div>
   <table class="w-full border-collapse border border-slate-700"><thead><tr><th class="border p-3 text-left">Effective Period</th><th class="border p-3 text-right">Basic (Taka)</th></tr></thead><tbody>
    <tr><td class="border p-3">30 June 2026 historical Basic</td><td class="border p-3 text-right">{{money(record.fixation_report.old_basic)}}</td></tr>
    <tr v-for="s in record.fixation_report.stages" :key="s.effective_date"><td class="border p-3">{{s.effective_date}}</td><td class="border p-3 text-right">{{money(s.basic)}}</td></tr>
   </tbody></table>
   <p class="mt-3 print:hidden">The full original signed-format Fixation PDF, not this summary, is available at <a class="text-blue-700 underline" :href="record.fixation_report.original_pdf_url" target="_blank" rel="noopener">Open original fixation form</a>.</p>
  </div>
  <p v-else class="p-5 border">No approved fixation for this employee. Historical 2015 pay remains unchanged.</p>
 </div>
 <div class="grid grid-cols-4 gap-4 mt-32 text-center"><div v-for="label in ['Employee Signature','Accounts Officer','Assistant Director (Finance)','Director (Finance)']" :key="label" class="border-t pt-2">{{label}}</div></div>
</div>
</template>
