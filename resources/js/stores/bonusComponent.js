import { defineStore } from 'pinia';
import axios from 'axios';
import { useAdminStore } from '@/stores/admin';

export const useBonusComponentStore = defineStore('bonusComponent', {
    state: () => ({
        bonusComponents: {
            data: [],
            current_page: 1,
            last_page: 1,
            total: 0,
            per_page: 10,
            links: []
        },
        loading: false,
        filters: {
            search: '',
            componentType: '',
            calcType: '',
            fromBasic: '',
            isTaxable: ''
        },
    }),
    actions: {
        async fetchBonusComponents(page = 1) {
            this.loading = true;
            try {
                const response = await axios.get(route('bonus-components.index'), {
                    params: {
                        page: page,
                        ...this.filters
                    },
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                this.bonusComponents = response.data;
            } catch (error) {
                console.error("Error fetching bonus components:", error);
                const adminStore = useAdminStore();
                adminStore.showError('Error', 'Failed to fetch bonus components.');
            } finally {
                this.loading = false;
            }
        },

        setFilters(filters) {
            this.filters = { ...this.filters, ...filters };
            this.fetchBonusComponents(1);
        },

        resetFilters() {
            this.filters = {
                search: '',
                componentType: '',
                calcType: '',
                fromBasic: '',
                isTaxable: ''
            };
            this.fetchBonusComponents(1);
        },

        async createBonusComponent(data) {
            try {
                await axios.post(route('bonus-components.store'), data);
                const adminStore = useAdminStore();
                adminStore.showSuccess('Created', 'Bonus component created successfully.');
                await this.fetchBonusComponents(this.bonusComponents.current_page);
                return true;
            } catch (error) {
                console.error("Error creating bonus component:", error);
                const adminStore = useAdminStore();
                const errorMsg = error.response?.data?.message || 'Failed to create bonus component.';
                adminStore.showError('Error', errorMsg);
                throw error;
            }
        },

        async updateBonusComponent(id, data) {
            try {
                await axios.put(route('bonus-components.update', id), data);
                const adminStore = useAdminStore();
                adminStore.showSuccess('Updated', 'Bonus component updated successfully.');
                await this.fetchBonusComponents(this.bonusComponents.current_page);
                return true;
            } catch (error) {
                console.error("Error updating bonus component:", error);
                const adminStore = useAdminStore();
                const errorMsg = error.response?.data?.message || 'Failed to update bonus component.';
                adminStore.showError('Error', errorMsg);
                throw error;
            }
        },

        async deleteBonusComponent(id) {
            const adminStore = useAdminStore();
            const confirmed = await adminStore.confirmAction(
                'Delete Bonus Component?',
                'Are you sure you want to delete this bonus component? This action cannot be undone.'
            );

            if (confirmed) {
                try {
                    await axios.delete(route('bonus-components.destroy', id));
                    adminStore.showSuccess('Deleted', 'Bonus component deleted successfully.');
                    await this.fetchBonusComponents(this.bonusComponents.current_page);
                    return true;
                } catch (error) {
                    console.error("Error deleting bonus component:", error);
                    const errorMsg = error.response?.data?.message || 'Failed to delete bonus component.';
                    adminStore.showError('Error', errorMsg);
                    throw error;
                }
            }
            return false;
        }
    }
});
