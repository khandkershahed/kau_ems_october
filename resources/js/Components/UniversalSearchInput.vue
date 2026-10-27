<script setup>
import { computed, ref, watch } from 'vue';
import { useEmployeeSearchStore } from '@/stores/employeeSearch';

const props = defineProps({
    modelValue: {
        type: String,
        default: ''
    },
    totalMatches: {
        type: Number,
        default: 0
    },
    loading: {
        type: Boolean,
        default: false
    },
    label: {
        type: String,
        default: 'Employee Search'
    },
    placeholder: {
        type: String,
        default: 'Name, email, code, phone, NID...'
    },
    showLabel: {
        type: Boolean,
        default: true
    },
    showCount: {
        type: Boolean,
        default: true
    },
    inputId: {
        type: String,
        default: 'filter_search'
    }
});

const emit = defineEmits(['update:modelValue', 'search']);

const searchStore = useEmployeeSearchStore();
const inputRef = ref(null);
const showHelp = ref(false);

// Sync prop modelValue into the store when changed externally (e.g. Reset)
watch(() => props.modelValue, (newVal) => {
    if (newVal !== searchStore.query) {
        searchStore.syncFrom(newVal);
    }
}, { immediate: true });

// Sync store query changes back to parent modelValue
watch(() => searchStore.query, (newVal) => {
    emit('update:modelValue', newVal);
});

const onInput = (e) => {
    searchStore.query = e.target.value;
    emit('update:modelValue', searchStore.query);
};

// When user submits with Enter key
const onKeydownEnter = (e) => {
    e.preventDefault();
    searchStore.applySearch();
    emit('search');
};

// Intercept paste to cleanly parse Excel/column data, newlines, tabs, commas, or semicolons
const onPaste = (e) => {
    const pasted = e.clipboardData?.getData('text');
    if (pasted && (
        pasted.includes('\n') || 
        pasted.includes('\r') || 
        pasted.includes('\t') || 
        pasted.includes(',') || 
        pasted.includes(';') ||
        /^\d+(\s+\d+)+$/.test(pasted.trim())
    )) {
        e.preventDefault();
        const input = inputRef.value;
        const isAllSelected = !input || !searchStore.query || (input.selectionStart === 0 && input.selectionEnd === input.value.length);
        searchStore.handlePaste(pasted, { replace: isAllSelected });
        emit('update:modelValue', searchStore.query);
        emit('search');
    }
};

// Remove a single term chip
const removeChip = (index) => {
    searchStore.removeTerm(index);
    emit('update:modelValue', searchStore.query);
    emit('search');
    inputRef.value?.focus();
};

// Clear all terms
const clearAll = () => {
    searchStore.clearSearch();
    emit('update:modelValue', '');
    emit('search');
    inputRef.value?.focus();
};

// Helper badge label
const modeBadge = computed(() => {
    if (searchStore.termCount > 1) {
        return {
            text: `Multi-User (${searchStore.termCount})`,
            class: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300 border-emerald-300 dark:border-emerald-700/60'
        };
    }
    if (searchStore.termCount === 1) {
        return {
            text: 'Single Term',
            class: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300 border-slate-200 dark:border-slate-700'
        };
    }
    return null;
});
</script>

<template>
    <div class="space-y-1.5">
        <!-- Label and Mode Badge Row -->
        <div v-if="showLabel" class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <label :for="inputId" class="block text-xs font-semibold text-slate-700 dark:text-slate-300">
                    {{ label }}
                </label>
                <button
                    type="button"
                    @click="showHelp = !showHelp"
                    class="text-slate-400 hover:text-emerald-600 dark:hover:text-emerald-400 transition cursor-pointer"
                    title="Click for multi-search instructions"
                >
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </button>
            </div>

            <!-- Dynamic Multi-User / Single Badge -->
            <span
                v-if="modeBadge"
                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold border transition-all"
                :class="modeBadge.class"
            >
                <span v-if="searchStore.isMultiUser" class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                {{ modeBadge.text }}
            </span>
        </div>

        <!-- Tooltip Help Box (Toggleable) -->
        <div
            v-if="showHelp"
            class="p-2.5 rounded-lg bg-emerald-50/80 dark:bg-slate-800 border border-emerald-200 dark:border-slate-700 text-[11px] text-slate-700 dark:text-slate-300 space-y-1 shadow-sm"
        >
            <div class="flex items-center justify-between font-semibold text-emerald-800 dark:text-emerald-400">
                <span>Multi-User Search Tips:</span>
                <button type="button" @click="showHelp = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">✕</button>
            </div>
            <p class="leading-relaxed text-slate-600 dark:text-slate-300">
                You can search single or multiple employees simultaneously by separating names, employee codes, emails, phones, or NIDs with commas.
            </p>
            <p class="font-mono text-[10px] text-emerald-700 dark:text-emerald-300 bg-white/80 dark:bg-slate-900/80 p-1.5 rounded border border-emerald-100 dark:border-slate-800">
                Example: 10128, Alom, emp40140@kau.ac.bd
            </p>
        </div>

        <!-- Input Box -->
        <div class="relative">
            <!-- Search Icon -->
            <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400 dark:text-slate-500">
                <svg v-if="!loading" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <svg v-else class="w-4 h-4 animate-spin text-emerald-500" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                </svg>
            </span>

            <!-- Main Input Field -->
            <input
                :id="inputId"
                ref="inputRef"
                type="text"
                :value="searchStore.query"
                @input="onInput"
                @keydown.enter="onKeydownEnter"
                @paste="onPaste"
                :placeholder="placeholder"
                class="w-full pl-9 pr-16 text-sm rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-900 text-slate-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 focus:border-emerald-500 focus:ring-emerald-500 transition-colors shadow-2xs py-2"
                autocomplete="off"
            />

            <!-- Clear & Action Buttons on Right -->
            <div class="absolute inset-y-0 right-0 pr-2 flex items-center gap-1">
                <button
                    v-if="searchStore.query"
                    type="button"
                    @click="clearAll"
                    class="p-1 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 rounded-md hover:bg-slate-100 dark:hover:bg-slate-800 transition cursor-pointer"
                    title="Clear search"
                >
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>

        <!-- Comma-Separated Multi-User Token Chips -->
        <div v-if="searchStore.isMultiUser" class="flex flex-wrap items-center gap-1.5 pt-1 animate-fadeIn">
            <span
                v-for="(term, idx) in searchStore.terms"
                :key="idx"
                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-mono font-medium bg-emerald-50 text-emerald-800 dark:bg-slate-800 dark:text-emerald-300 border border-emerald-200 dark:border-slate-700 shadow-2xs"
            >
                <span class="max-w-[140px] truncate" :title="term">{{ term }}</span>
                <button
                    type="button"
                    @click="removeChip(idx)"
                    class="text-emerald-600 hover:text-emerald-900 dark:text-emerald-400 dark:hover:text-emerald-200 p-0.5 rounded hover:bg-emerald-200/50 dark:hover:bg-slate-700 transition cursor-pointer"
                    title="Remove this search item"
                >
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </span>

            <button
                type="button"
                @click="clearAll"
                class="text-[11px] font-medium text-slate-400 hover:text-rose-500 dark:hover:text-rose-400 ml-1 transition cursor-pointer"
            >
                Clear all
            </button>
        </div>

        <!-- Matching Count Indicator -->
        <div v-if="showCount" class="flex items-center justify-between text-[11px] text-slate-500 dark:text-slate-400 pt-0.5">
            <div class="flex items-center gap-1.5">
                <span v-if="loading" class="inline-block w-2 h-2 rounded-full bg-emerald-500 animate-ping"></span>
                <span>
                    Found <strong class="text-slate-800 dark:text-slate-200 font-semibold">{{ totalMatches }}</strong> matching employee{{ totalMatches === 1 ? '' : 's' }}
                </span>
            </div>
            <span v-if="searchStore.isMultiUser" class="text-emerald-600 dark:text-emerald-400 text-[10px] font-medium">
                Matching ANY of {{ searchStore.termCount }} terms
            </span>
        </div>
    </div>
</template>

<style scoped>
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-2px); }
    to { opacity: 1; transform: translateY(0); }
}
.animate-fadeIn {
    animation: fadeIn 0.2s ease-out;
}
</style>
