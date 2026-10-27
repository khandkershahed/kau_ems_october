<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    modelValue: {
        type: [Number, String],
        default: '',
    },
    employees: {
        type: Array,
        default: () => [],
    },
    placeholder: {
        type: String,
        default: '-- Search and Select Employee --',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    required: {
        type: Boolean,
        default: false,
    },
    error: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['update:modelValue', 'change']);

const isOpen = ref(false);
const searchQuery = ref('');
const highlightedIndex = ref(-1);
const searchInputRef = ref(null);
const containerRef = ref(null);
const listRef = ref(null);

// Find currently selected employee object
const selectedEmployee = computed(() => {
    if (!props.modelValue && props.modelValue !== 0) return null;
    return props.employees.find(e => String(e.id) === String(props.modelValue)) || null;
});

// Filter employees based on search query - DO NOT show all employees by default
const filteredEmployees = computed(() => {
    const q = String(searchQuery.value ?? '').trim().toLowerCase();
    if (!q) return [];

    const tokens = q.split(/\s+/).filter(Boolean);

    return props.employees.filter(emp => {
        if (!emp) return false;
        const name = String(emp.name ?? '').toLowerCase();
        const code = String(emp.employee_code ?? '').toLowerCase();
        const idStr = String(emp.id ?? '').toLowerCase();
        const email = String(emp.email ?? '').toLowerCase();
        const dept = String(emp.department ?? '').toLowerCase();
        const desig = String(emp.designation ?? '').toLowerCase();

        const haystack = `${name} ${code} ${idStr} ${email} ${dept} ${desig}`;

        return tokens.every(token => haystack.includes(token));
    });
});

// Automatically highlight first search result when query changes
watch(searchQuery, () => {
    highlightedIndex.value = filteredEmployees.value.length > 0 ? 0 : -1;
});


// Generate initials for avatar
const getInitials = (name) => {
    if (!name) return 'EMP';
    const str = String(name).trim();
    if (!str) return 'EMP';
    const parts = str.split(/\s+/);
    if (parts.length === 1) return parts[0].substring(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
};

// Open dropdown
const openDropdown = () => {
    if (props.disabled) return;
    isOpen.value = true;
    searchQuery.value = '';
    highlightedIndex.value = -1;

    nextTick(() => {
        if (searchInputRef.value) {
            searchInputRef.value.focus();
        }
    });
};

// Close dropdown
const closeDropdown = () => {
    isOpen.value = false;
    searchQuery.value = '';
    highlightedIndex.value = -1;
};

// Toggle dropdown
const toggleDropdown = () => {
    if (isOpen.value) {
        closeDropdown();
    } else {
        openDropdown();
    }
};

// Select employee
const selectEmployee = (emp) => {
    emit('update:modelValue', emp ? emp.id : '');
    emit('change', emp);
    closeDropdown();
};

// Clear selection
const clearSelection = (event) => {
    event.stopPropagation();
    emit('update:modelValue', '');
    emit('change', null);
};

// Keyboard navigation
const onKeyDown = (e) => {
    if (!isOpen.value) {
        if (e.key === 'Enter' || e.key === 'ArrowDown' || e.key === ' ') {
            e.preventDefault();
            openDropdown();
        }
        return;
    }

    if (e.key === 'Escape') {
        e.preventDefault();
        closeDropdown();
    } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (filteredEmployees.value.length === 0) return;
        highlightedIndex.value = (highlightedIndex.value + 1) % filteredEmployees.value.length;
        scrollToHighlighted();
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (filteredEmployees.value.length === 0) return;
        highlightedIndex.value = (highlightedIndex.value - 1 + filteredEmployees.value.length) % filteredEmployees.value.length;
        scrollToHighlighted();
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (highlightedIndex.value >= 0 && highlightedIndex.value < filteredEmployees.value.length) {
            selectEmployee(filteredEmployees.value[highlightedIndex.value]);
        }
    }
};

// Auto-scroll inside dropdown list to keep highlighted item visible
const scrollToHighlighted = () => {
    nextTick(() => {
        if (!listRef.value) return;
        const items = listRef.value.querySelectorAll('[data-employee-item]');
        if (items[highlightedIndex.value]) {
            items[highlightedIndex.value].scrollIntoView({ block: 'nearest' });
        }
    });
};

// Handle clicks outside component
const handleClickOutside = (e) => {
    if (containerRef.value && !containerRef.value.contains(e.target)) {
        closeDropdown();
    }
};

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
});
</script>

<template>
    <div ref="containerRef" class="relative w-full" @keydown="onKeyDown">
        <!-- Trigger Button / Box -->
        <div
            tabindex="0"
            @click="toggleDropdown"
            :class="[
                'w-full flex items-center justify-between px-3 py-2 text-xs rounded-md border transition-all cursor-pointer select-none outline-none',
                error
                    ? 'border-red-500 ring-1 ring-red-500'
                    : isOpen
                    ? 'border-emerald-500 ring-2 ring-emerald-500/20'
                    : 'border-gray-300 dark:border-gray-700 hover:border-gray-400 dark:hover:border-gray-600',
                disabled ? 'bg-gray-100 dark:bg-gray-800 cursor-not-allowed opacity-60' : 'bg-white dark:bg-gray-900',
            ]"
        >
            <!-- Selected State -->
            <div v-if="selectedEmployee" class="flex items-center gap-2.5 min-w-0 flex-1">
                <div class="w-7 h-7 rounded-full bg-emerald-100 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 font-bold flex items-center justify-center text-[10px] shrink-0 border border-emerald-200 dark:border-emerald-800">
                    {{ getInitials(selectedEmployee.name) }}
                </div>
                <div class="min-w-0 flex-1 truncate">
                    <div class="flex items-center gap-1.5 truncate">
                        <span class="font-semibold text-gray-900 dark:text-gray-100 truncate">{{ selectedEmployee.name }}</span>
                        <span class="font-mono text-[10px] px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 shrink-0 font-medium">
                            ID: {{ selectedEmployee.employee_code }}
                        </span>
                    </div>
                    <div class="text-[11px] text-gray-500 dark:text-gray-400 truncate mt-0.5">
                        {{ selectedEmployee.designation }} • {{ selectedEmployee.department }}
                    </div>
                </div>
            </div>

            <!-- Empty / Placeholder State -->
            <div v-else class="flex items-center gap-2 text-gray-400 dark:text-gray-500 flex-1 truncate">
                <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <span class="truncate">{{ placeholder }}</span>
            </div>

            <!-- Right Controls: Clear and Caret -->
            <div class="flex items-center gap-1 shrink-0 ml-2">
                <button
                    v-if="selectedEmployee && !disabled"
                    type="button"
                    @click="clearSelection"
                    title="Clear selection"
                    class="p-1 rounded-full text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800"
                >
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>

                <svg
                    :class="['w-4 h-4 text-gray-400 transition-transform duration-200', isOpen ? 'rotate-180 text-emerald-600' : '']"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </div>
        </div>

        <!-- Dropdown Menu / Popup -->
        <div
            v-if="isOpen"
            class="absolute z-50 mt-1 w-full bg-white dark:bg-gray-900 rounded-lg shadow-xl border border-gray-200 dark:border-gray-700 overflow-hidden animate-in fade-in zoom-in-95 duration-100"
        >
            <!-- Search Input Header -->
            <div class="p-2 border-b border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-800/50">
                <div class="relative">
                    <svg class="w-4 h-4 text-gray-400 absolute left-2.5 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    <input
                        ref="searchInputRef"
                        v-model="searchQuery"
                        type="text"
                        placeholder="Type employee name, ID (code), email, designation, or dept..."
                        class="w-full pl-8 pr-7 py-1.5 text-xs rounded-md border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:border-emerald-500 focus:ring-emerald-500 shadow-sm"
                        @keydown.stop="onKeyDown"
                    />
                    <button
                        v-if="searchQuery"
                        type="button"
                        @click="searchQuery = ''"
                        class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Scrollable Employee List -->
            <div ref="listRef" class="max-h-60 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-800/60">
                <!-- State 1: When user has not typed anything yet (!searchQuery.trim()) -->
                <div
                    v-if="!searchQuery.trim()"
                    class="py-8 px-4 text-center text-xs text-gray-500 dark:text-gray-400"
                >
                    <div class="w-10 h-10 rounded-full bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400 flex items-center justify-center mx-auto mb-2.5 border border-emerald-100 dark:border-emerald-900/40">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                    <p class="font-semibold text-gray-800 dark:text-gray-200">Search for an Employee</p>
                    <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1 max-w-xs mx-auto">
                        Type name, ID / employee code, email, designation, or department to find an employee.
                    </p>
                    <div v-if="selectedEmployee" class="mt-3 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-emerald-50 dark:bg-emerald-950/40 text-[11px] text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                        <svg class="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        <span>Currently selected: <strong class="font-semibold">{{ selectedEmployee.name }}</strong> (ID: {{ selectedEmployee.employee_code }})</span>
                    </div>
                </div>

                <!-- State 2: User has typed something, but no matching records found -->
                <div
                    v-else-if="filteredEmployees.length === 0"
                    class="py-8 px-4 text-center text-xs text-gray-500 dark:text-gray-400"
                >
                    <div class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-400 flex items-center justify-center mx-auto mb-2.5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <p class="font-semibold text-gray-800 dark:text-gray-200">No employees found</p>
                    <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">
                        No match for "<span class="font-medium text-gray-700 dark:text-gray-300">{{ searchQuery }}</span>"
                    </p>
                    <p class="text-[10px] text-gray-400 mt-1">Try searching by ID, designation, department, or email</p>
                </div>

                <!-- State 3: Filtered list of matching employees -->
                <div
                    v-else
                    v-for="(emp, index) in filteredEmployees"
                    :key="emp.id"
                    data-employee-item
                    @click="selectEmployee(emp)"
                    @mouseenter="highlightedIndex = index"
                    :class="[
                        'px-3 py-2.5 flex items-center justify-between gap-2.5 cursor-pointer transition-colors text-xs',
                        highlightedIndex === index
                            ? 'bg-emerald-50/80 dark:bg-emerald-950/40'
                            : String(emp.id) === String(modelValue)
                            ? 'bg-gray-50 dark:bg-gray-800/60 font-medium'
                            : 'hover:bg-gray-50 dark:hover:bg-gray-800/40',
                    ]"
                >
                    <!-- Employee Avatar & Information -->
                    <div class="flex items-center gap-2.5 min-w-0 flex-1">
                        <div
                            :class="[
                                'w-7 h-7 rounded-full font-bold flex items-center justify-center text-[10px] shrink-0 border',
                                String(emp.id) === String(modelValue)
                                    ? 'bg-emerald-600 text-white border-emerald-600'
                                    : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-200 dark:border-gray-700'
                            ]"
                        >
                            {{ getInitials(emp.name) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <span class="font-semibold text-gray-900 dark:text-gray-100">{{ emp.name }}</span>
                                <span class="font-mono text-[10px] px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 font-medium">
                                    ID: {{ emp.employee_code }}
                                </span>
                            </div>
                            <div class="text-[11px] text-gray-500 dark:text-gray-400 truncate mt-0.5 flex items-center gap-1.5">
                                <span>{{ emp.designation }}</span>
                                <span v-if="emp.department && emp.department !== 'N/A'">•</span>
                                <span v-if="emp.department && emp.department !== 'N/A'">{{ emp.department }}</span>
                            </div>
                            <div v-if="emp.email" class="text-[10px] text-gray-400 dark:text-gray-500 truncate mt-0.5">
                                {{ emp.email }}
                            </div>
                        </div>
                    </div>

                    <!-- Selected Indicator -->
                    <div v-if="String(emp.id) === String(modelValue)" class="text-emerald-600 dark:text-emerald-400 shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Footer Stats -->
            <div class="px-3 py-1.5 bg-gray-50 dark:bg-gray-800/70 border-t border-gray-100 dark:border-gray-800 flex items-center justify-between text-[10px] text-gray-400">
                <span v-if="!searchQuery.trim()">Type above to search from {{ employees.length }} employees</span>
                <span v-else>Found {{ filteredEmployees.length }} matching employee{{ filteredEmployees.length === 1 ? '' : 's' }}</span>
                <span class="hidden sm:inline">Use ↑↓ keys and Enter to select</span>
            </div>
        </div>
    </div>
</template>
