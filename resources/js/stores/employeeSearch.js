import { defineStore } from 'pinia';
import { useEmployeeStore } from '@/stores/employee';

export const useEmployeeSearchStore = defineStore('employeeSearch', {
    state: () => ({
        query: '',
        history: [],
    }),

    getters: {
        /**
         * Parsed list of individual search terms from comma/newline separated query
         * @returns {string[]}
         */
        terms: (state) => {
            if (!state.query || typeof state.query !== 'string') return [];
            const trimmed = state.query.trim();
            if (/^\d+(\s+\d+)+$/.test(trimmed)) {
                return trimmed.split(/\s+/).filter(t => t.length > 0);
            }
            return trimmed
                .split(/[\t\n\r,;]+/)
                .map(t => t.trim())
                .filter(t => t.length > 0);
        },

        /**
         * Whether the search contains multiple terms (multi-user search)
         * @returns {boolean}
         */
        isMultiUser: (state) => {
            return state.terms.length > 1;
        },

        /**
         * Total number of search tokens currently entered
         * @returns {number}
         */
        termCount: (state) => {
            return state.terms.length;
        },

        /**
         * Clean normalized query string with standard comma separation
         * @returns {string}
         */
        normalizedQuery: (state) => {
            return state.terms.join(', ');
        }
    },

    actions: {
        /**
         * Set the search query and optionally apply to employeeStore
         * @param {string} newQuery
         * @param {boolean} applyImmediately
         */
        setQuery(newQuery, applyImmediately = false) {
            this.query = typeof newQuery === 'string' ? newQuery : '';
            if (applyImmediately) {
                this.applySearch();
            }
        },

        /**
         * Add a new single or comma-separated term to the search
         * @param {string} term
         */
        addTerm(term) {
            if (!term) return;
            const newTerms = term
                .split(/[,\n\r]+/)
                .map(t => t.trim())
                .filter(t => t.length > 0);

            if (newTerms.length === 0) return;

            const existing = [...this.terms];
            newTerms.forEach(t => {
                if (!existing.includes(t)) {
                    existing.push(t);
                }
            });

            this.query = existing.join(', ');
            this.applySearch();
        },

        /**
         * Remove a specific term by index from the comma-separated list
         * @param {number} index
         */
        removeTerm(index) {
            const current = [...this.terms];
            if (index >= 0 && index < current.length) {
                current.splice(index, 1);
                this.query = current.join(', ');
                this.applySearch();
            }
        },

        /**
         * Clear the entire search input
         */
        clearSearch() {
            this.query = '';
            this.applySearch();
        },

        /**
         * Handle paste event: parses newlines, tabs, commas, and semicolons into a clean comma-separated string
         * @param {string} pastedText
         * @param {Object} options
         */
        handlePaste(pastedText, options = { replace: true }) {
            if (!pastedText) return;
            const trimmed = pastedText.trim();
            let extracted = [];
            if (/^\d+(\s+\d+)+$/.test(trimmed)) {
                extracted = trimmed.split(/\s+/).filter(t => t.length > 0);
            } else {
                extracted = trimmed
                    .split(/[\t\n\r,;]+/)
                    .map(t => t.trim())
                    .filter(t => t.length > 0);
            }

            if (extracted.length > 0) {
                if (options.replace) {
                    this.query = Array.from(new Set(extracted)).join(', ');
                } else {
                    const current = [...this.terms];
                    extracted.forEach(t => {
                        if (!current.includes(t)) {
                            current.push(t);
                        }
                    });
                    this.query = current.join(', ');
                }
                this.applySearch();
            }
        },

        /**
         * Sync store query with external filter state (e.g. on page mount or reset)
         * @param {string} externalValue
         */
        syncFrom(externalValue) {
            this.query = typeof externalValue === 'string' ? externalValue : '';
        },

        /**
         * Record current search query into history
         * @param {Object} options
         */
        applySearch(options = {}) {
            const cleanQuery = this.query.trim();

            // Save to recent search history if non-empty
            if (cleanQuery && !this.history.includes(cleanQuery)) {
                this.history.unshift(cleanQuery);
                if (this.history.length > 10) {
                    this.history.pop();
                }
            }

            if (options.applyToEmployeeStore) {
                const employeeStore = useEmployeeStore();
                employeeStore.setFilters({ search: cleanQuery });
            }
        }
    }
});
