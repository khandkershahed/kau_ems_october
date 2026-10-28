import { defineStore } from 'pinia';
import { ref, computed } from 'vue';

export const useNavigationStore = defineStore('navigation', () => {
    // Generic SVG Icons
    const icons = {
        dashboard: `<svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" /></svg>`,
        office: `<svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" /></svg>`,
        payroll: `<svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>`,
        salary: `<svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" /></svg>`,
        reports: `<svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>`,
        employee: `<svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>`,
        archive: `<svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" /></svg>`
    };

    // Canonical navigation menu structure
    const menuItems = computed(() => [
        {
            title: 'Dashboard',
            routeName: 'dashboard',
            icon: icons.dashboard,
            keywords: ['home', 'analytics', 'overview', 'kpi', 'metrics', 'stats'],
            description: 'Executive overview, workforce metrics & payroll expenditure'
        },
        {
            title: 'Office management',
            icon: icons.office,
            children: [
                {
                    title: 'Departments and office',
                    routeName: 'department-offices.index',
                    activePattern: 'department-offices.*',
                    category: 'Office management',
                    keywords: ['department', 'faculty', 'office', 'section', 'branch', 'administration', 'staff count'],
                    description: 'Manage faculties, departments and administrative offices'
                }
            ]
        },
        {
            title: 'Payroll management',
            icon: icons.payroll,
            children: [
                {
                    title: 'Payscales',
                    routeName: 'pay-scales.index',
                    activePattern: 'pay-scales.*',
                    category: 'Payroll management',
                    keywords: ['payscale', 'national pay scale', 'nps', 'scale', 'salary tier', 'structure'],
                    description: 'Define national and institutional pay scales'
                },
                {
                    title: 'Grades',
                    routeName: 'grades.index',
                    activePattern: 'grades.*',
                    category: 'Payroll management',
                    keywords: ['grade', 'salary grade', 'grade 1', 'grade 20', 'minimum salary', 'maximum salary'],
                    description: 'Configure Grade 1 through Grade 20 min/max salary limits'
                },
                {
                    title: 'Grade designations',
                    routeName: 'payroll-group-types.index',
                    activePattern: 'payroll-group-types.*',
                    category: 'Payroll management',
                    keywords: ['designation', 'position', 'job title', 'professor', 'registrar', 'officer', 'group type'],
                    description: 'Master list of academic and administrative designations'
                },
                {
                    title: 'Assign desig. to grades',
                    routeName: 'payroll-groups.index',
                    activePattern: 'payroll-groups.*',
                    category: 'Payroll management',
                    keywords: ['payroll group', 'mapping', 'assign grade', 'designation grade link'],
                    description: 'Link designations to pay grades and scales'
                },
                {
                    title: 'Payroll Components',
                    routeName: 'payroll-component-types.index',
                    activePattern: 'payroll-component-types.*',
                    category: 'Payroll management',
                    keywords: ['component type', 'allowance type', 'deduction type', 'basic pay', 'house rent', 'medical', 'tax'],
                    description: 'Master catalog of salary earnings and deductions'
                },
                {
                    title: 'Assign comp. to desig.',
                    routeName: 'payroll-components.index',
                    activePattern: 'payroll-components.*',
                    category: 'Payroll management',
                    keywords: ['component rules', 'allowances', 'deductions', 'fixed', 'percentage', 'taxable'],
                    description: 'Assign fixed and percentage component rules to designations'
                },
                {
                    title: 'Bonus components',
                    routeName: 'bonus-components.index',
                    activePattern: 'bonus-components.*',
                    category: 'Payroll management',
                    keywords: ['bonus component', 'festival bonus', 'boishakhi', 'eid', 'puja', 'from basic'],
                    description: 'Configure festival and annual bonus allowance rules'
                },
                {
                    title: 'Bonus setup',
                    routeName: 'bonus-groups.index',
                    activePattern: 'bonus-groups.*',
                    category: 'Payroll management',
                    keywords: ['bonus group', 'bonus package', 'festival group', 'bulk bonus setup'],
                    description: 'Package bonus components for staff group assignment'
                }
            ]
        },
        {
            title: 'Employee management',
            icon: icons.employee,
            children: [
                {
                    title: 'Group/Class',
                    routeName: 'user-categories.index',
                    activePattern: 'user-categories.*',
                    category: 'Employee management',
                    keywords: ['employee category', 'teacher', 'officer', 'staff', 'class 1', 'class 2', 'class 3', 'class 4'],
                    description: 'Manage employee categories and classifications'
                },
                {
                    title: 'Create Employee',
                    routeName: 'employees.create',
                    activePattern: 'employees.create',
                    category: 'Employee management',
                    keywords: ['add employee', 'new employee', 'register staff', 'onboard', 'nid', 'tin', 'bank account'],
                    description: 'Onboard new employee with personal, bank & payroll details'
                },
                {
                    title: 'Manage Employee',
                    routeName: 'employees.edit',
                    activePattern: 'employees.edit',
                    category: 'Employee management',
                    keywords: ['employee directory', 'edit staff', 'search employee', 'employee profile', 'update employee'],
                    description: 'Search, filter and update employee profile records'
                }
            ]
        },
        {
            title: 'Salary & Bonus Management',
            icon: icons.salary,
            children: [
                {
                    title: 'Salary fixation 2026',
                    routeName: 'salary-fixation.index',
                    activePattern: 'salary-fixation.*',
                    category: 'Salary & Bonus Management',
                    keywords: ['fixation', '2026 pay scale', 'new pay scale', 'salary adjustment', 'iBAS', 'fitment'],
                    description: 'Database-driven, read-only fixation previews and audit records'
                },
                {
                    title: 'Salary payroll preview',
                    routeName: 'salary-payroll-preview.index',
                    activePattern: 'salary-payroll-preview.*',
                    category: 'Salary & Bonus Management',
                    keywords: ['payroll preview', 'october 2026', 'draft salary', 'read-only salary', 'reconciliation'],
                    description: 'Read-only employee-wise monthly payroll draft, no bill generation'
                },
                // {
                //     title: 'Arrear & deduction preview',
                //     routeName: 'salary-arrears.index',
                //     activePattern: 'salary-arrears.*',
                //     category: 'Salary & Bonus Management',
                //     keywords: ['arrear', 'back pay', 'GPF adjustment', 'deduction', 'historical salary'],
                //     description: 'Evidence-based read-only arrear reconciliation with manual corrections'
                // },
                {
                    title: 'Arrear & component settings',
                    routeName: 'salary-arrear-settings.index',
                    activePattern: 'salary-arrear-settings.*',
                    category: 'Salary & Bonus Management',
                    keywords: ['arrear settings', 'payable', 'deduction', 'formula', 'published payroll components'],
                    description: 'Configure published arrear formulas and payroll components'
                },
                {
                    title: 'Salary estimations',
                    routeName: 'salary-estimation.index',
                    activePattern: 'salary-estimation.*',
                    category: 'Salary & Bonus Management',
                    keywords: ['salary setup', 'estimation', 'override', 'rule over', 'allowance customization', 'tailor salary'],
                    description: 'Personalize employee salary components & review estimations'
                },
                {
                    title: 'Bulk modify 18 employees',
                    routeName: 'salary-estimation.bulk-components',
                    activePattern: 'salary-estimation.bulk-components',
                    category: 'Salary & Bonus Management',
                    keywords: ['18 employees', 'bulk components', 'salary corrections'],
                    description: 'Edit selected component rules across multiple employees with audit tracking'
                },
                {
                    title: 'Bonus estimation',
                    routeName: 'bonus-setup.index',
                    activePattern: 'bonus-setup.*',
                    category: 'Salary & Bonus Management',
                    keywords: ['bonus setup', 'bonus estimation', 'festival estimation', 'assign bonus group'],
                    description: 'Assign bonus groups and preview calculated bonus figures'
                },
                {
                    title: 'Generate Salary',
                    routeName: 'salary-generates.index',
                    activePattern: 'salary-generates.*',
                    category: 'Salary & Bonus Management',
                    keywords: ['salary generate', 'monthly bill', 'payroll run', 'disburse', 'bulk generate', 'salary approval', 'review'],
                    description: 'Run monthly salary generation, review bills & email slips'
                },
                {
                    title: 'Generate Bonus',
                    routeName: 'bonus-generates.index',
                    activePattern: 'bonus-generates.*',
                    category: 'Salary & Bonus Management',
                    keywords: ['generate bonus', 'bonus bill', 'festival disbursement', 'eid bonus', 'puja bonus', 'boishakhi bill'],
                    description: 'Generate, audit and finalize festival bonus disbursements'
                },
                {
                    title: 'Provident Fund (GPF)',
                    routeName: 'gpf.index',
                    activePattern: 'gpf.*',
                    category: 'Salary & Bonus Management',
                    keywords: ['gpf', 'general provident fund', 'pf', 'provident fund', 'interest', 'ledger', 'settlement', 'pension'],
                    description: 'Track annual GPF contributions, interest accrual & settlements'
                }
            ]
        },
        {
            title: 'Reports',
            icon: icons.reports,
            children: [
                {
                    title: 'Blank reports',
                    routeName: 'reports.blank-forms.index',
                    activePattern: 'reports.blank-forms.*',
                    category: 'Reports',
                    keywords: ['blank forms', 'printable templates', 'official form', 'application form', 'joining form'],
                    description: 'Download standard university blank forms and applications'
                },
                {
                    title: 'Report factory',
                    routeName: 'reports.salary-reports.index',
                    activePattern: 'reports.salary-reports.*',
                    category: 'Reports',
                    keywords: ['reports', 'bank advice', 'salary bill sheet', 'salary summary', 'earn deduction', 'gpf statement', 'excel', 'pdf'],
                    description: 'Generate official Bank Advice, Salary Bills, GPF & Tax reports'
                }
            ]
        },
        {
            title: 'Archive Data',
            icon: icons.archive,
            children: [
                {
                    title: 'Import and Preview',
                    routeName: 'archive.import-preview',
                    activePattern: 'archive.*',
                    category: 'Archive',
                    keywords: ['archive', 'import and preview', 'import', 'preview', 'json import', 'previous salary', 'past salary', 'salary records', 'bulk write', 'historical reports'],
                    description: 'Import, preview and bulk write historical salary JSON files into salary records'
                }
            ]
        }
    ]);

    // Flatten all navigable items for search
    const searchableItems = computed(() => {
        const list = [];

        const traverse = (items, parentCategory = '') => {
            for (const item of items) {
                if (item.children && item.children.length > 0) {
                    traverse(item.children, item.title);
                } else if (item.routeName) {
                    list.push({
                        title: item.title,
                        routeName: item.routeName,
                        activePattern: item.activePattern || item.routeName,
                        category: item.category || parentCategory || 'Navigation',
                        keywords: item.keywords || [],
                        description: item.description || '',
                        icon: item.icon || icons.reports,
                    });
                }
            }
        };

        traverse(menuItems.value);
        return list;
    });

    // Reactive search state
    const searchQuery = ref('');
    const isSearchOpen = ref(false);
    const selectedIndex = ref(0);

    // Fuzzy matching score function
    const scoreItem = (item, query) => {
        if (!query) return 0;
        const q = query.toLowerCase().trim();
        const title = item.title.toLowerCase();
        const category = item.category.toLowerCase();
        const keywords = item.keywords.map(k => k.toLowerCase()).join(' ');
        const desc = item.description.toLowerCase();

        // 1. Exact match on title
        if (title === q) return 100;

        // 2. Starts with query in title
        if (title.startsWith(q)) return 85;

        // 3. Substring in title
        if (title.includes(q)) return 70;

        // 4. Keyword exact or startsWith
        if (item.keywords.some(k => k.toLowerCase() === q)) return 75;
        if (item.keywords.some(k => k.toLowerCase().startsWith(q))) return 65;

        // 5. Keyword contains query
        if (keywords.includes(q)) return 50;

        // 6. Category match
        if (category.includes(q)) return 40;

        // 7. Description contains query
        if (desc.includes(q)) return 30;

        // 8. Acronym / Sequential character match (e.g. "gpf", "ba" -> Bank Advice)
        let qIdx = 0;
        const target = `${title} ${keywords}`;
        for (let i = 0; i < target.length && qIdx < q.length; i++) {
            if (target[i] === q[qIdx]) {
                qIdx++;
            }
        }
        if (qIdx === q.length) return 20;

        return 0;
    };

    // Filtered and scored results
    const searchResults = computed(() => {
        const query = searchQuery.value.trim();
        if (!query) {
            // Return top 6 recommended quick navigation items if empty
            return searchableItems.value.slice(0, 6).map(item => ({
                ...item,
                href: safeRoute(item.routeName),
                score: 1,
            }));
        }

        const scored = [];
        for (const item of searchableItems.value) {
            const score = scoreItem(item, query);
            if (score > 0) {
                scored.push({
                    ...item,
                    href: safeRoute(item.routeName),
                    score,
                });
            }
        }

        scored.sort((a, b) => b.score - a.score);
        return scored.slice(0, 8);
    });

    // Helper for safe route resolution via Ziggy
    const safeRoute = (routeName, params = {}) => {
        try {
            if (typeof window !== 'undefined' && typeof window.route === 'function') {
                return window.route(routeName, params);
            }
            return `#${routeName}`;
        } catch (e) {
            return `#${routeName}`;
        }
    };

    const openSearch = () => {
        isSearchOpen.value = true;
        selectedIndex.value = 0;
    };

    const closeSearch = () => {
        isSearchOpen.value = false;
        searchQuery.value = '';
        selectedIndex.value = 0;
    };

    const nextResult = () => {
        if (searchResults.value.length === 0) return;
        selectedIndex.value = (selectedIndex.value + 1) % searchResults.value.length;
    };

    const prevResult = () => {
        if (searchResults.value.length === 0) return;
        selectedIndex.value = (selectedIndex.value - 1 + searchResults.value.length) % searchResults.value.length;
    };

    return {
        icons,
        menuItems,
        searchableItems,
        searchQuery,
        searchResults,
        isSearchOpen,
        selectedIndex,
        openSearch,
        closeSearch,
        nextResult,
        prevResult,
        safeRoute,
    };
});
