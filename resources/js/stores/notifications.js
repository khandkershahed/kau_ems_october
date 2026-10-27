import { defineStore } from 'pinia';

// Dummy data generator
const generateDummyNotifications = () => {
    const notifications = [];
    for (let i = 1; i <= 24; i++) {
        notifications.push({
            id: i,
            title: i % 3 === 0 ? 'Salary generation completed' : (i % 2 === 0 ? 'New employee added to Payroll group' : 'Leave request approved'),
            message: i % 3 === 0 
                ? `The monthly salary generation process for the previous month has completed successfully. (Batch #${i})`
                : (i % 2 === 0 
                    ? `John Doe was successfully added to the 'Senior Management' payroll group. (ID: ${1000+i})`
                    : `Your annual leave request for next week has been approved by your manager.`),
            time: i === 1 ? '5 minutes ago' : (i === 2 ? '2 hours ago' : (i < 5 ? 'Yesterday' : `${i} days ago`)),
            isRead: i > 3, // First 3 are unread
            type: i % 3 === 0 ? 'info' : 'success'
        });
    }
    return notifications;
};

export const useNotificationStore = defineStore('notifications', {
    state: () => ({
        notifications: generateDummyNotifications(),
        currentPage: 1,
        itemsPerPage: 10,
    }),
    getters: {
        unreadCount: (state) => state.notifications.filter(n => !n.isRead).length,
        paginatedNotifications: (state) => {
            const start = (state.currentPage - 1) * state.itemsPerPage;
            const end = start + state.itemsPerPage;
            return state.notifications.slice(start, end);
        },
        totalPages: (state) => Math.ceil(state.notifications.length / state.itemsPerPage),
        latestNotifications: (state) => state.notifications.slice(0, 4) // Show top 4 in dropdown
    },
    actions: {
        setPage(page) {
            if (page >= 1 && page <= this.totalPages) {
                this.currentPage = page;
            }
        },
        markAllAsRead() {
            this.notifications.forEach(n => n.isRead = true);
        },
        markAsRead(id) {
            const notification = this.notifications.find(n => n.id === id);
            if (notification) notification.isRead = true;
        }
    }
});
