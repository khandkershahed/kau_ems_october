<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
</script>

<template>
    <Head title="Employee History" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Employee History</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-2 lg:px-4 space-y-6">
                <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                    <section class="max-w-xl">
                        <header>
                            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Employee History Content</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                This page is currently blank.
                            </p>
                        </header>
                    </section>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
