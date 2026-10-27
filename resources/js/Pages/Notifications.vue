<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { useNotificationStore } from '@/stores/notifications';

const notificationStore = useNotificationStore();
</script>

<template>
    <Head title="Notifications" />

    <AuthenticatedLayout>
        <div class="py-6">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800 transition-colors duration-300">
                    <div class="p-6 text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700">
                        
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-2xl font-bold">All Notifications</h2>
                            <button v-if="notificationStore.unreadCount > 0" @click="notificationStore.markAllAsRead" class="text-sm font-medium text-green-600 hover:text-green-500 dark:text-green-400 dark:hover:text-green-300 transition-colors">
                                Mark all as read
                            </button>
                        </div>
                        
                        <!-- Notifications List -->
                        <div class="space-y-4">
                            <div v-if="notificationStore.paginatedNotifications.length === 0" class="text-center py-12 text-gray-500 dark:text-gray-400">
                                You don't have any notifications.
                            </div>
                            
                            <div v-for="notification in notificationStore.paginatedNotifications" :key="notification.id" 
                                @click="notificationStore.markAsRead(notification.id)"
                                class="flex items-start p-4 rounded-lg border transition-colors cursor-pointer"
                                :class="[
                                    !notification.isRead 
                                        ? 'bg-green-50 dark:bg-gray-700/80 border-green-200 dark:border-gray-500' 
                                        : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50'
                                ]"
                            >
                                <div class="flex-shrink-0 mt-1">
                                    <!-- Dynamic Icon based on type -->
                                    <svg v-if="notification.type === 'success'" class="h-6 w-6" :class="!notification.isRead ? 'text-green-600 dark:text-green-400' : 'text-gray-400 dark:text-gray-500'" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <svg v-else class="h-6 w-6" :class="!notification.isRead ? 'text-blue-600 dark:text-blue-400' : 'text-gray-400 dark:text-gray-500'" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                                <div class="ml-3 w-full">
                                    <div class="flex items-center justify-between">
                                        <p class="text-sm font-medium dark:text-white" :class="!notification.isRead ? 'text-gray-900 font-bold' : 'text-gray-700'">{{ notification.title }}</p>
                                        <p class="text-xs" :class="!notification.isRead ? 'text-green-600 dark:text-green-400 font-medium' : 'text-gray-500 dark:text-gray-400'">{{ notification.time }}</p>
                                    </div>
                                    <p class="mt-1 text-sm dark:text-gray-300" :class="!notification.isRead ? 'text-gray-800' : 'text-gray-600'">{{ notification.message }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Pagination Controls -->
                        <div v-if="notificationStore.totalPages > 1" class="mt-8 flex items-center justify-between border-t border-gray-200 dark:border-gray-700 pt-6">
                            <div class="flex flex-1 justify-between sm:hidden">
                                <button @click="notificationStore.setPage(notificationStore.currentPage - 1)" :disabled="notificationStore.currentPage === 1" class="relative inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed dark:bg-gray-800 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">Previous</button>
                                <button @click="notificationStore.setPage(notificationStore.currentPage + 1)" :disabled="notificationStore.currentPage === notificationStore.totalPages" class="relative ml-3 inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed dark:bg-gray-800 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">Next</button>
                            </div>
                            <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm text-gray-700 dark:text-gray-300">
                                        Showing
                                        <span class="font-medium">{{ (notificationStore.currentPage - 1) * notificationStore.itemsPerPage + 1 }}</span>
                                        to
                                        <span class="font-medium">{{ Math.min(notificationStore.currentPage * notificationStore.itemsPerPage, notificationStore.notifications.length) }}</span>
                                        of
                                        <span class="font-medium">{{ notificationStore.notifications.length }}</span>
                                        results
                                    </p>
                                </div>
                                <div>
                                    <nav class="isolate inline-flex -space-x-px rounded-md shadow-sm" aria-label="Pagination">
                                        <button @click="notificationStore.setPage(notificationStore.currentPage - 1)" :disabled="notificationStore.currentPage === 1" class="relative inline-flex items-center rounded-l-md px-2 py-2 text-gray-400 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-20 focus:outline-offset-0 disabled:opacity-50 disabled:cursor-not-allowed dark:ring-gray-600 dark:hover:bg-gray-700">
                                            <span class="sr-only">Previous</span>
                                            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 01-.02 1.06L8.832 10l3.938 3.71a.75.75 0 11-1.04 1.08l-4.5-4.25a.75.75 0 010-1.08l4.5-4.25a.75.75 0 011.06.02z" clip-rule="evenodd" />
                                            </svg>
                                        </button>
                                        
                                        <button v-for="page in notificationStore.totalPages" :key="page" @click="notificationStore.setPage(page)" 
                                            class="relative inline-flex items-center px-4 py-2 text-sm font-semibold ring-1 ring-inset focus:z-20 focus:outline-offset-0"
                                            :class="page === notificationStore.currentPage ? 'z-10 bg-green-600 text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-600 border-none' : 'text-gray-900 dark:text-gray-100 ring-gray-300 dark:ring-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700'"
                                        >
                                            {{ page }}
                                        </button>

                                        <button @click="notificationStore.setPage(notificationStore.currentPage + 1)" :disabled="notificationStore.currentPage === notificationStore.totalPages" class="relative inline-flex items-center rounded-r-md px-2 py-2 text-gray-400 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-20 focus:outline-offset-0 disabled:opacity-50 disabled:cursor-not-allowed dark:ring-gray-600 dark:hover:bg-gray-700">
                                            <span class="sr-only">Next</span>
                                            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                                            </svg>
                                        </button>
                                    </nav>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
