import { defineStore } from 'pinia';
import axios from 'axios';
import { useAdminStore } from '@/stores/admin';

export const useBonusGroupStore = defineStore('bonusGroup', {
    state: () => ({
        bonusGroups: {
            data: [],
            current_page: 1,
            last_page: 1,
            total: 0,
            per_page: 10,
            links: []
        },
        allBonusComponents: [],
        loading: false,
        loadingComponents: false,
        filters: {
            search: ''
        },
    }),
    actions: {
        async fetchBonusGroups(page = 1) {
            this.loading = true;
            try {
                const response = await axios.get(route('bonus-groups.index'), {
                    params: {
                        page: page,
                        ...this.filters
                    },
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                this.bonusGroups = response.data;
            } catch (error) {
                console.error("Error fetching bonus groups:", error);
                const adminStore = useAdminStore();
                adminStore.showError('Error', 'Failed to fetch bonus groups.');
            } finally {
                this.loading = false;
            }
        },

        async fetchAllBonusComponents() {
            this.loadingComponents = true;
            try {
                const response = await axios.get(route('bonus-components.index', { all: true }), {
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                this.allBonusComponents = response.data;
            } catch (error) {
                console.error("Error fetching all bonus components:", error);
            } finally {
                this.loadingComponents = false;
            }
        },

        setFilters(filters) {
            this.filters = { ...this.filters, ...filters };
            this.fetchBonusGroups(1);
        },

        resetFilters() {
            this.filters = {
                search: ''
            };
            this.fetchBonusGroups(1);
        },

        async createBonusGroup(data) {
            try {
                await axios.post(route('bonus-groups.store'), data);
                const adminStore = useAdminStore();
                adminStore.showSuccess('Created', 'Bonus group created successfully.');
                await this.fetchBonusGroups(this.bonusGroups.current_page);
                return true;
            } catch (error) {
                console.error("Error creating bonus group:", error);
                const adminStore = useAdminStore();
                const errorMsg = error.response?.data?.message || 'Failed to create bonus group.';
                adminStore.showError('Error', errorMsg);
                throw error;
            }
        },

        async updateBonusGroup(id, data) {
            try {
                await axios.put(route('bonus-groups.update', id), data);
                const adminStore = useAdminStore();
                adminStore.showSuccess('Updated', 'Bonus group updated successfully.');
                await this.fetchBonusGroups(this.bonusGroups.current_page);
                return true;
            } catch (error) {
                console.error("Error updating bonus group:", error);
                const adminStore = useAdminStore();
                const errorMsg = error.response?.data?.message || 'Failed to update bonus group.';
                adminStore.showError('Error', errorMsg);
                throw error;
            }
        },

        async deleteBonusGroup(id) {
            const adminStore = useAdminStore();
            const confirmed = await adminStore.confirmAction(
                'Delete Bonus Group?',
                'Are you sure you want to delete this bonus group? This action cannot be undone.'
            );

            if (confirmed) {
                try {
                    await axios.delete(route('bonus-groups.destroy', id));
                    adminStore.showSuccess('Deleted', 'Bonus group deleted successfully.');
                    await this.fetchBonusGroups(this.bonusGroups.current_page);
                    return true;
                } catch (error) {
                    console.error("Error deleting bonus group:", error);
                    const errorMsg = error.response?.data?.message || 'Failed to delete bonus group.';
                    adminStore.showError('Error', errorMsg);
                    throw error;
                }
            }
            return false;
        }
    }
});
