/** Read-only browser statement projection; never an authority for posting. */
const cents = value => {
    const n = Number(value);
    if (value === null || value === undefined || value === '' || !Number.isFinite(n) || n < 0) {
        throw new TypeError('Statement monetary value is invalid or missing.');
    }
    return Math.round(n * 100);
};

export function salaryStatementBreakdown(allowances, deductions, arrears) {
    const rows = { allowances: [], deductions: [] };
    const current = { gross: 0, deductions: 0 };
    const knownArrear = { gross: 0, deductions: 0 };
    const unknown = [];
    for (const [components, key, direction] of [
        [allowances, 'allowances', 'payable'],
        [deductions, 'deductions', 'deduction'],
    ]) {
        for (const component of components) {
            const value = cents(component.calculated_amount);
            const title = String(component.name || '').trim();
            if (!title) throw new TypeError('Component title missing.');
            rows[key].push({ title, direction, current: value / 100, arrear: 0,
                total: value / 100, source: component.component_source || component.gpf_calculation_source || 'employee_component', unknown: false });
            current[direction === 'payable' ? 'gross' : 'deductions'] += value;
        }
    }
    for (const item of arrears) {
        if (!['payable', 'deduction'].includes(item.direction)) throw new TypeError('Invalid arrear direction.');
        const title = String(item.title || item.name || '').trim();
        if (!title) throw new TypeError('Arrear title missing.');
        const raw = Object.prototype.hasOwnProperty.call(item, 'final_amount') ? item.final_amount : item.calculated_amount;
        const missing = raw === null || raw === undefined || raw === '';
        const amount = missing ? null : cents(raw);
        rows[item.direction === 'payable' ? 'allowances' : 'deductions'].push({
            title, direction: item.direction, current: 0,
            arrear: missing ? null : amount / 100,
            total: missing ? null : amount / 100,
            source: item.code || item.rule_id || 'manual_arrear', unknown: missing,
        });
        if (missing) unknown.push(title);
        else knownArrear[item.direction === 'payable' ? 'gross' : 'deductions'] += amount;
    }
    const provisional = unknown.length > 0;
    const currentNet = current.gross - current.deductions;
    const arrearNet = knownArrear.gross - knownArrear.deductions;
    const totals = {
        current: { gross: current.gross / 100, deductions: current.deductions / 100, net: currentNet / 100 },
        arrear: { gross: provisional ? null : knownArrear.gross / 100,
            deductions: provisional ? null : knownArrear.deductions / 100,
            net: provisional ? null : arrearNet / 100 },
        total: { gross: provisional ? null : (current.gross + knownArrear.gross) / 100,
            deductions: provisional ? null : (current.deductions + knownArrear.deductions) / 100,
            net: provisional ? null : (currentNet + arrearNet) / 100 },
        provisional_display: { gross: (current.gross + knownArrear.gross) / 100,
            deductions: (current.deductions + knownArrear.deductions) / 100,
            net: (currentNet + arrearNet) / 100 },
    };
    return { ...rows, summary: totals, provisional, unknown_arrears: unknown };
}
