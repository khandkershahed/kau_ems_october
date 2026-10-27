import { defineStore } from 'pinia';
import Swal from 'sweetalert2';

export const useAdminStore = defineStore('admin', () => {
    // Dynamically target open dialog if present so confirmation/error alerts are not hidden behind top-layer modals
    const getTarget = () => {
        return document.querySelector('dialog[open]') || document.body;
    };

    const getSwal = () => {
        return Swal.mixin({
            target: getTarget(),
            customClass: {
                confirmButton: 'inline-flex items-center px-4 py-2 bg-green-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-500 focus:bg-green-500 active:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition ease-in-out duration-150',
                cancelButton: 'inline-flex items-center px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-200 uppercase tracking-widest shadow-sm hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150 mr-3',
                popup: 'rounded-xl bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 border border-gray-200 dark:border-gray-700',
                title: 'text-base font-bold text-gray-900 dark:text-white',
                htmlContainer: 'text-sm text-gray-600 dark:text-gray-300',
                container: '!z-[999999]',
            },
            buttonsStyling: false,
        });
    };

    const confirmAction = async (title = 'Are you sure?', text = "You won't be able to revert this!", options = {}) => {
        if (Swal.isVisible()) {
            Swal.close();
        }

        const result = await getSwal().fire({
            title: title,
            text: text,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Confirm',
            cancelButtonText: 'Cancel',
            reverseButtons: true,
            customClass: {
                confirmButton: 'inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-500 focus:bg-red-500 active:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150',
                cancelButton: 'inline-flex items-center px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-200 uppercase tracking-widest shadow-sm hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150 mr-3',
                popup: 'rounded-xl bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 border border-gray-200 dark:border-gray-700',
                title: 'text-base font-bold text-gray-900 dark:text-white',
                htmlContainer: 'text-sm text-gray-600 dark:text-gray-300',
                container: '!z-[999999]',
            },
            ...options,
        });

        return result.isConfirmed;
    };

    const showSuccess = (title = 'Success!', text = 'Operation completed successfully.', options = {}) => {
        // Dismiss any existing active alert
        if (Swal.isVisible()) {
            Swal.close();
        }

        // Target document.body directly so it is never trapped inside a closing <dialog>
        return Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'success',
            title: title,
            text: text,
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            target: document.body,
            customClass: {
                container: '!z-[999999]',
                popup: 'rounded-xl shadow-lg border border-gray-100 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100',
                title: 'text-sm font-semibold text-gray-900 dark:text-gray-100',
                htmlContainer: 'text-xs text-gray-600 dark:text-gray-300',
            },
            ...options,
        });
    };

    const showError = (title = 'Error!', text = 'An error occurred.', options = {}) => {
        if (Swal.isVisible()) {
            Swal.close();
        }

        return getSwal().fire({
            title: title,
            text: text,
            icon: 'error',
            confirmButtonText: 'OK',
            customClass: {
                confirmButton: 'inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-500 focus:bg-red-500 active:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150',
                popup: 'rounded-xl bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 border border-gray-200 dark:border-gray-700',
                title: 'text-base font-bold text-gray-900 dark:text-white',
                htmlContainer: 'text-sm text-gray-600 dark:text-gray-300',
                container: '!z-[999999]',
            },
            ...options,
        });
    };

    return {
        confirmAction,
        showSuccess,
        showError,
    };
});
