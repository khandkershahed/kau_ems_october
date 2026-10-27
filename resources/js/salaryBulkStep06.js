/** View helpers only. The authoritative amounts and hashes come from Laravel. */
export const isReady = (row) => row?.status === 'ready' && /^[a-f0-9]{64}$/.test(row?.source_hash ?? '');

export function readyIds(rows) {
    return rows.filter(isReady).map(row => Number(row.user_id));
}

export function hashesForSelected(rows, selectedIds) {
    const selected = new Set(selectedIds.map(Number));
    const picked = rows.filter(row => selected.has(Number(row.user_id)));
    if (picked.length !== selected.size || picked.some(row => !isReady(row))) {
        throw new Error('Selected employees require a fresh, complete financial preview.');
    }
    return Object.fromEntries(picked.map(row => [String(row.user_id), row.source_hash]));
}

const safeCsvCell = (v) => {
    const text = String(v ?? '').replace(/^[\s]*([=+@-])/u, "'$&");
    return `"${text.replaceAll('"', '""')}"`;
};

export function previewCsv(rows, exceptionsOnly = false) {
    const columns = ['user_id','employee_code','name','grade','designation','status',
        'calculation_status','basic','regular_gross','regular_deductions','regular_net',
        'arrear_payable','arrear_deductions','arrear_net','net_payable','reason','source_hash'];
    const data = exceptionsOnly ? rows.filter(row => row.status !== 'ready') : rows;
    return [columns.map(safeCsvCell).join(','), ...data.map(row =>
        columns.map(key => safeCsvCell(row[key])).join(','))].join('\r\n');
}
