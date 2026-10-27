/** UI-only preview helper. UNKNOWN financial source ≠ paid zero.
 * UNKNOWN arrear rows are excluded from display totals and must remain null in
 * calculation snapshots. Backend posting is responsible for strict validation.
 */
const cents = value => {
    const n = Number(value);
    if (!Number.isFinite(n)) throw new TypeError('Invalid financial amount');
    return Math.round(n * 100);
};
export function salaryProvisionalTotals(regularGross, regularDeductions, arrearRows) {
    let payable = 0, deductions = 0, unknown = 0;
    for (const row of arrearRows) {
        const value = row.final_amount;
        if (value === null || value === undefined || value === '') {
            unknown++;
            continue;
        }
        const amount = cents(value);
        if (amount < 0) throw new TypeError('Negative arrear line');
        if (row.direction === 'payable') payable += amount;
        else if (row.direction === 'deduction') deductions += amount;
        else throw new TypeError('Invalid arrear direction');
    }
    const currentGross = cents(regularGross), currentDeductions = cents(regularDeductions);
    return {
        payable: payable / 100,
        arrearDeductions: deductions / 100,
        gross: (currentGross + payable) / 100,
        deductions: (currentDeductions + deductions) / 100,
        net: (currentGross + payable - currentDeductions - deductions) / 100,
        provisional: unknown > 0,
        unknownCount: unknown,
    };
}
