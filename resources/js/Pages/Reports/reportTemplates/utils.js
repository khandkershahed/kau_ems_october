/**
 * Shared Report Utilities for Report Templates and Report Factory
 */

export const MONTH_NAMES = [
    '', 'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'
];

/**
 * Format numerical currency value into standard 2-decimal display (e.g. 25,000.00).
 */
export function formatMoney(val) {
    if (val === null || val === undefined || val === '') return '0.00';
    const num = typeof val === 'number' ? val : parseFloat(String(val).replace(/,/g, '').trim());
    if (isNaN(num)) return '0.00';
    return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/**
 * Convert numerical Taka value into words.
 * Example: 78000 -> "Seventy eight thousand Taka only."
 */
export function numberToWordsTaka(amount) {
    const num = Math.floor(Math.abs(Number(amount) || 0));
    if (num === 0) return 'Zero Taka only.';

    const a = [
        '', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
        'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'
    ];
    const b = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

    function inWords(n) {
        if (n < 20) return a[n];
        const digit = n % 10;
        return b[Math.floor(n / 10)] + (digit ? ' ' + a[digit] : '');
    }

    let words = '';
    const crore = Math.floor(num / 10000000);
    const lakh = Math.floor((num % 10000000) / 100000);
    const thousand = Math.floor((num % 100000) / 1000);
    const hundred = Math.floor((num % 1000) / 100);
    const rest = num % 100;

    if (crore > 0) words += inWords(crore) + ' crore ';
    if (lakh > 0) words += inWords(lakh) + ' lakh ';
    if (thousand > 0) words += inWords(thousand) + ' thousand ';
    if (hundred > 0) words += inWords(hundred) + ' hundred ';
    if (rest > 0) words += inWords(rest) + ' ';

    const formatted = words.trim();
    return (formatted.charAt(0).toUpperCase() + formatted.slice(1)) + ' Taka only.';
}

/**
 * Default University metadata
 */
export const UNIVERSITY_INFO = {
    name: 'Khulna Agricultural University',
    address: 'Temporary Office: 327, Jessore Road, North Mujgunni, Khalishpur, Khulna-9000',
    logo: '/logo.png',
    location: 'Khulna-9202, Bangladesh',
};
