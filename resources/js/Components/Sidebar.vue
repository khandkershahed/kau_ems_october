<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import SidebarItem from '@/Components/SidebarItem.vue';
import { useNavigationStore } from '@/stores/navigation';

defineProps({
    isOpen: {
        type: Boolean,
        required: true,
    },
    isCollapsed: {
        type: Boolean,
        default: false,
    }
});

defineEmits(['close']);

const navigationStore = useNavigationStore();

// Prepare menu items with runtime Ziggy route resolutions
const menuItems = computed(() => {
    return navigationStore.menuItems.map(item => {
        const hasChildren = item.children && item.children.length > 0;
        return {
            ...item,
            href: item.routeName ? route(item.routeName) : null,
            active: item.activePattern 
                ? route().current(item.activePattern) 
                : (item.routeName ? route().current(item.routeName) : false),
            children: hasChildren ? item.children.map(child => ({
                ...child,
                href: child.routeName ? route(child.routeName) : null,
                active: child.routeName === 'salary-setup.index' && route().current('salary-calculator.*')
                    ? true
                    : (child.activePattern
                        ? route().current(child.activePattern)
                        : (child.routeName ? route().current(child.routeName) : false)),
            })) : null
        };
    });
});
</script>

<template>
    <!-- Off-canvas overlay for mobile -->
    <div 
        v-if="isOpen" 
        class="fixed inset-0 z-40 bg-gray-900/50 backdrop-blur-sm lg:hidden transition-opacity"
        @click="$emit('close')"
    ></div>

    <!-- Sidebar Container -->
    <aside
        :class="[
            isOpen ? 'translate-x-0' : '-translate-x-full',
            isCollapsed ? 'lg:w-20' : 'lg:w-72',
            'fixed inset-y-0 left-0 z-50 w-72 transform overflow-y-auto overflow-x-hidden bg-white transition-all duration-300 ease-in-out lg:static lg:translate-x-0 lg:h-screen dark:bg-gray-900 border-r border-gray-200 dark:border-gray-800 shadow-lg lg:shadow-none flex flex-col'
        ]"
    >
        <!-- Logo Area -->
        <div class="flex h-16 shrink-0 items-center justify-center border-b border-gray-200 dark:border-gray-800 px-4">
            <Link href="/" class="flex items-center justify-center">
                <ApplicationLogo class="h-10 rounded-sm fill-current text-green-600" />
            </Link>
        </div>

        <!-- Navigation Menu -->
        <nav class="flex-1 space-y-1 px-3 py-4 overflow-y-auto">
            <SidebarItem
                v-for="item in menuItems"
                :key="item.title"
                :item="item"
                :isCollapsed="isCollapsed"
            />
        </nav>
    </aside>
</template>
