<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    item: {
        type: Object,
        required: true,
    },
    depth: {
        type: Number,
        default: 0,
    },
    isCollapsed: {
        type: Boolean,
        default: false,
    }
});

const isActive = (item) => {
    if (item.active) return true;
    if (item.children) {
        return item.children.some(child => isActive(child));
    }
    return false;
};

// Calculator belongs to Salary Estimations, but opens in a focused workspace.
// Start its parent accordion collapsed; expanding it reveals the highlighted link.
const isOpen = ref(isActive(props.item) && !(props.item.title === 'Salary & Bonus Management' && route().current('salary-calculator.*')));

const toggle = () => {
    if (props.isCollapsed) return;
    if (props.item.children) {
        isOpen.value = !isOpen.value;
    }
};
</script>

<template>
    <div>
        <!-- Menu Item with Children (Accordion) -->
        <button
            v-if="item.children"
            @click="toggle"
            class="flex w-full items-center justify-between rounded-md px-3 py-2 text-sm font-medium transition-colors hover:bg-green-50 hover:text-green-600 dark:hover:bg-gray-800 dark:hover:text-green-400 group relative"
            :class="[
                isOpen && !isCollapsed ? 'bg-green-50 text-green-700 dark:bg-gray-800 dark:text-green-400' : 'text-gray-600 dark:text-gray-300'
            ]"
            :style="{ paddingLeft: isCollapsed ? '0.75rem' : `${depth * 1.5 + 0.75}rem` }"
        >
            <div class="flex items-center overflow-hidden" :class="{ 'justify-center w-full': isCollapsed && depth === 0 }">
                <span v-if="item.icon" v-html="item.icon" class="mr-3" :class="{'mr-0': isCollapsed}"></span>
                <span v-if="!isCollapsed" class="truncate">{{ item.title }}</span>
            </div>
            <svg
                v-if="!isCollapsed"
                class="h-4 w-4 transform transition-transform duration-200 shrink-0"
                :class="{ 'rotate-180': isOpen }"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
            >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
            
            <!-- Tooltip for collapsed mode -->
            <div v-if="isCollapsed" class="absolute left-full top-1/2 -translate-y-1/2 ml-2 hidden group-hover:block z-50 px-2 py-1 bg-gray-800 text-white text-xs rounded shadow-lg whitespace-nowrap">
                {{ item.title }}
            </div>
        </button>

        <!-- Menu Item without Children (Link) -->
        <Link
            v-else
            :href="item.href || '#'"
            class="flex w-full items-center rounded-md px-3 py-2 text-sm font-medium transition-colors hover:bg-green-50 hover:text-green-600 dark:hover:bg-gray-800 dark:hover:text-green-400 group relative"
            :class="[
                item.active ? 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-400' : 'text-gray-600 dark:text-gray-300'
            ]"
            :style="{ paddingLeft: isCollapsed ? '0.75rem' : `${depth * 1.5 + 0.75}rem` }"
        >
            <div class="flex items-center overflow-hidden" :class="{ 'justify-center w-full': isCollapsed && depth === 0 }">
                <span v-if="item.icon" v-html="item.icon" class="mr-3" :class="{'mr-0': isCollapsed}"></span>
                <span v-if="!isCollapsed" class="truncate">{{ item.title }}</span>
            </div>
            
            <!-- Tooltip for collapsed mode -->
            <div v-if="isCollapsed" class="absolute left-full top-1/2 -translate-y-1/2 ml-2 hidden group-hover:block z-50 px-2 py-1 bg-gray-800 text-white text-xs rounded shadow-lg whitespace-nowrap">
                {{ item.title }}
            </div>
        </Link>

        <!-- Children -->
        <div v-if="item.children" v-show="isOpen && !isCollapsed" class="mt-1 space-y-1">
            <SidebarItem
                v-for="child in item.children"
                :key="child.title"
                :item="child"
                :depth="depth + 1"
                :isCollapsed="false" 
            />
        </div>
    </div>
</template>
