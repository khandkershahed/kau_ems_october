/** Presentation only: never coerce missing financial evidence inside saved calculations. */
export const salaryDisplayAmount = value => value === null || value === undefined || value === ''
    ? '0.00'
    : Number(value || 0).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
