import * as XLSX from 'xlsx';

export const FONT_OPTIONS = [
    { value: 'SolaimanLipi', label: 'SolaimanLipi (Unicode)', fontFamily: 'SolaimanLipi', isBijoy: false },
    { value: 'SutonnyMJ', label: 'SutonnyMJ (Bijoy ANSI)', fontFamily: 'SutonnyMJ', isBijoy: true },
    { value: 'Nikosh', label: 'Nikosh (Unicode)', fontFamily: 'Nikosh', isBijoy: false },
    { value: 'Kalpurush', label: 'Kalpurush (Unicode)', fontFamily: 'Kalpurush', isBijoy: false },
];

export const BANGLA_MONTHS = [
    '',
    'জানুয়ারি',
    'ফেব্রুয়ারি',
    'মার্চ',
    'এপ্রিল',
    'মে',
    'জুন',
    'জুলাই',
    'আগস্ট',
    'সেপ্টেম্বর',
    'অক্টোবর',
    'নভেম্বর',
    'ডিসেম্বর',
];

const BANGLA_DIGIT_MAP = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

export function toBanglaDigits(value) {
    return String(value ?? '').replace(/\d/g, (d) => BANGLA_DIGIT_MAP[Number(d)]);
}

/**
 * Format numeric amount with exact 2-decimal precision, Bangladeshi/Indian comma grouping,
 * and Bangla digits (e.g. 5853836.5 -> "৫৮,৫৩,৮৩৬.৫০").
 */
export function formatBanglaMoney(amount) {
    const num = Number(amount || 0);
    const safe = Number.isFinite(num) ? Math.round(num * 100) / 100 : 0;
    const negative = safe < 0;
    const fixed = Math.abs(safe).toFixed(2);
    const [intPart, decPart] = fixed.split('.');

    let grouped = intPart;
    if (intPart.length > 3) {
        const last3 = intPart.slice(-3);
        const rest = intPart.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',');
        grouped = `${rest},${last3}`;
    }

    return toBanglaDigits(`${negative ? '-' : ''}${grouped}.${decPart}`);
}

const BANGLA_0_TO_99 = [
    'শূন্য', 'এক', 'দুই', 'তিন', 'চার', 'পাঁচ', 'ছয়', 'সাত', 'আট', 'নয়',
    'দশ', 'এগারো', 'বারো', 'তেরো', 'চৌদ্দ', 'পনেরো', 'ষোলো', 'সতেরো', 'আঠারো', 'উনিশ',
    'বিশ', 'একুশ', 'বাইশ', 'তেইশ', 'চব্বিশ', 'পঁচিশ', 'ছাব্বিশ', 'সাতাশ', 'আটাশ', 'ঊনত্রিশ',
    'ত্রিশ', 'একত্রিশ', 'বত্রিশ', 'তেত্রিশ', 'চৌত্রিশ', 'পঁয়ত্রিশ', 'ছত্রিশ', 'সাঁইত্রিশ', 'আটত্রিশ', 'ঊনচল্লিশ',
    'চল্লিশ', 'একচল্লিশ', 'বিয়াল্লিশ', 'তেতাল্লিশ', 'চুয়াল্লিশ', 'পঁয়তাল্লিশ', 'ছেচল্লিশ', 'সাতচল্লিশ', 'আটচল্লিশ', 'ঊনপঞ্চাশ',
    'পঞ্চাশ', 'একান্ন', 'বাহান্ন', 'তিপ্পান্ন', 'চুয়ান্ন', 'পঞ্চান্ন', 'ছাপ্পান্ন', 'সাতান্ন', 'আটান্ন', 'ঊনষাট',
    'ষাট', 'একষট্টি', 'বাষট্টি', 'তেষট্টি', 'চৌষট্টি', 'পঁয়ষট্টি', 'ছেষট্টি', 'সাতষট্টি', 'আটষট্টি', 'ঊনসত্তর',
    'সত্তর', 'একাত্তর', 'বাহাত্তর', 'তিয়াত্তর', 'চুয়াত্তর', 'পঁচাত্তর', 'ছিয়াত্তর', 'সাতাত্তর', 'আটাত্তর', 'ঊনআশি',
    'আশি', 'একাশি', 'বিরাশি', 'তিরাশি', 'চুরাশি', 'পঁচাশি', 'ছিয়াশি', 'সাতাশি', 'আটাশি', 'ঊননব্বই',
    'নব্বই', 'একানব্বই', 'বিরানব্বই', 'তিরানব্বই', 'চুরানব্বই', 'পঁচানব্বই', 'ছিয়ানব্বই', 'সাতানব্বই', 'আটানব্বই', 'নিরানব্বই',
];

function integerToBanglaWords(n) {
    if (n === 0) return BANGLA_0_TO_99[0];

    const parts = [];
    const crore = Math.floor(n / 10000000);
    const remAfterCrore = n % 10000000;
    const lakh = Math.floor(remAfterCrore / 100000);
    const remAfterLakh = remAfterCrore % 100000;
    const thousand = Math.floor(remAfterLakh / 1000);
    const remAfterThousand = remAfterLakh % 1000;
    const hundred = Math.floor(remAfterThousand / 100);
    const tens = remAfterThousand % 100;

    if (crore > 0) {
        parts.push(`${crore > 99 ? integerToBanglaWords(crore) : BANGLA_0_TO_99[crore]} কোটি`);
    }
    if (lakh > 0) {
        parts.push(`${BANGLA_0_TO_99[lakh]} লক্ষ`);
    }
    if (thousand > 0) {
        parts.push(`${BANGLA_0_TO_99[thousand]} হাজার`);
    }
    if (hundred > 0) {
        parts.push(`${BANGLA_0_TO_99[hundred]}শত`);
    }
    if (tens > 0) {
        parts.push(BANGLA_0_TO_99[tens]);
    }

    return parts.join(' ');
}

/**
 * Convert monetary amount to exact Bangla words (কথায়).
 * Example: 5853836.50 -> "আটান্ন লক্ষ তিপ্পান্ন হাজার আটশত ছত্রিশ টাকা পঞ্চাশ পয়সা মাত্র"
 */
export function amountToBanglaWords(amount) {
    const num = Number(amount || 0);
    if (!Number.isFinite(num)) return 'শূন্য টাকা মাত্র';

    const totalPaisa = Math.round(Math.abs(num) * 100);
    const taka = Math.floor(totalPaisa / 100);
    const paisa = totalPaisa % 100;

    if (taka === 0 && paisa === 0) {
        return 'শূন্য টাকা মাত্র';
    }

    const segments = [];
    if (taka > 0) {
        segments.push(`${integerToBanglaWords(taka)} টাকা`);
    }
    if (paisa > 0) {
        segments.push(`${BANGLA_0_TO_99[paisa]} পয়সা`);
    }

    return `${num < 0 ? 'ঋণাত্মক ' : ''}${segments.join(' ')} মাত্র`;
}

/**
 * Auto-detect official Bangla group label from active Category/Class filter name.
 */
export function detectBanglaGroupLabel(categoryName) {
    const raw = String(categoryName || '').trim();
    if (!raw) return 'সকল কর্মকর্তা ও কর্মচারীবৃন্দের';

    const lower = raw.toLowerCase();
    if (lower.includes('teacher') || lower.includes('faculty') || raw.includes('শিক্ষক')) {
        return 'শিক্ষকবৃন্দের';
    }
    if (
        (lower.includes('officer') || raw.includes('কর্মকর্তা'))
        && !lower.includes('staff')
        && !lower.includes('employee')
    ) {
        return 'কর্মকর্তাবৃন্দের';
    }
    if (
        (lower.includes('11') && lower.includes('16'))
        || raw.includes('১১-১৬')
        || raw.includes('১১–১৬')
        || lower.includes('class 3')
        || lower.includes('3rd class')
        || lower.includes('third class')
    ) {
        return 'বেতন গ্রেড : ১১-১৬ এর জনবলের';
    }
    if (
        (lower.includes('17') && lower.includes('20'))
        || raw.includes('১৭-২০')
        || raw.includes('১৭–২০')
        || lower.includes('class 4')
        || lower.includes('4th class')
        || lower.includes('fourth class')
    ) {
        return 'বেতন গ্রেড : ১৭-২০ এর জনবলের';
    }

    return 'সকল কর্মকর্তা ও কর্মচারীবৃন্দের';
}

const ALLOWANCE_CATALOG = [
    { key: 'basic', order: 1, labelBn: 'মূল বেতন', test: (l) => /^basic(\s+pay|\s+salary|\s+wage|\s+arrear)?$/.test(l) || l.includes('basic arrear') },
    { key: 'hra', order: 2, labelBn: 'বাড়ী ভাড়া ভাতা', test: (l) => /house\s*rent|\bhra\b/.test(l) },
    { key: 'medical', order: 3, labelBn: 'চিকিৎসা ভাতা', test: (l) => l.includes('medical') },
    { key: 'special_benefit', order: 4, labelBn: 'বিশেষ সুবিধা', test: (l) => l.includes('special benefit') },
    { key: 'education', order: 5, labelBn: 'শিক্ষা সহায়ক ভাতা', test: (l) => l.includes('education') },
    { key: 'charge_allowance', order: 6, labelBn: 'দায়িত্ব ভাতা', test: (l) => /charge\s*allowance|\bcharge\b/.test(l) },
    { key: 'mobile', order: 7, labelBn: 'মোবাইল ভাতা', test: (l) => l.includes('mobile') },
    { key: 'telephone', order: 8, labelBn: 'টেলিফোন ভাতা', test: (l) => /telephone|\btel\.?\b/.test(l) },
    { key: 'tiffin', order: 9, labelBn: 'টিফিন ভাতা', test: (l) => l.includes('tiffin') },
    { key: 'travel', order: 10, labelBn: 'যাতায়াত ভাতা', test: (l) => /travel|conveyance/.test(l) },
    { key: 'washing', order: 11, labelBn: 'ধোলাই ভাতা', test: (l) => /wash|dhobi/.test(l) },
    { key: 'entertainment', order: 12, labelBn: 'আপ্যায়ন ভাতা', test: (l) => /entertainment|sumptuary/.test(l) },
    { key: 'domestic_aid', order: 13, labelBn: 'গৃহকর্মী সহায়তা ভাতা', test: (l) => /domestic/.test(l) },
    { key: 'dearness', order: 14, labelBn: 'মহার্ঘ ভাতা', test: (l) => /dearness/.test(l) },
    { key: 'festival', order: 15, labelBn: 'উৎসব ভাতা', test: (l) => /festival|bonus/.test(l) },
];

const DEDUCTION_CATALOG = [
    { key: 'gpf', order: 1, labelBn: 'সাধারণ ভবিষ্য তহবিল (জিপিএফ)', test: (l) => /\bgpf\b|provident\s*fund/.test(l) },
    { key: 'income_tax', order: 2, labelBn: 'আয়কর', test: (l) => /income\s*tax|\btax\b/.test(l) },
    { key: 'house_rent_deduction', order: 3, labelBn: 'বাড়ী ভাড়া কর্তন', test: (l) => /house\s*rent|\bhra\b/.test(l) },
    { key: 'vehicle_deduction', order: 4, labelBn: 'যানবাহন / গাড়ী বাবদ কর্তন', test: (l) => /vehicle|transport/.test(l) },
    { key: 'utility_deduction', order: 5, labelBn: 'বিদ্যুৎ / পানি / গ্যাস বিল', test: (l) => /electric|water|gas|utility/.test(l) },
    { key: 'benevolent', order: 6, labelBn: 'কল্যাণ তহবিল', test: (l) => /benevolent|welfare/.test(l) },
    { key: 'group_insurance', order: 7, labelBn: 'যৌথ বীমা', test: (l) => /insurance/.test(l) },
    { key: 'loan_recovery', order: 8, labelBn: 'ঋণ কর্তন', test: (l) => /loan|advance/.test(l) },
    { key: 'special_benefit_recovery', order: 9, labelBn: 'বিশেষ সুবিধা সমন্বয় / কর্তন', test: (l) => l.includes('special benefit') },
    { key: 'basic_increment_adjustment', order: 10, labelBn: 'বেতন ইনক্রিমেন্ট সমন্বয়', test: (l) => l.includes('increment') },
    { key: 'revenue_stamp', order: 90, labelBn: 'রাজস্ব স্ট্যাম্প', test: (l) => /revenue\s*stamp|\bstamp\b/.test(l) },
];

function stripArrearSuffix(title) {
    return String(title || '')
        .replace(/\s*\(arrear\)\s*/gi, ' ')
        .replace(/\s+arrear$/gi, '')
        .trim();
}

function resolveComponentBucket(rawTitle, catalog) {
    const cleaned = stripArrearSuffix(rawTitle);
    const lower = cleaned.toLowerCase();
    for (const item of catalog) {
        if (item.test(lower)) {
            return { key: item.key, label: item.labelBn, order: item.order };
        }
    }
    const fallbackName = cleaned || String(rawTitle || '').trim() || 'অন্যান্য';
    return {
        key: `custom:${fallbackName.toLowerCase()}`,
        label: fallbackName,
        order: 50,
    };
}

function toCents(val) {
    const num = Number(val);
    return Number.isFinite(num) ? Math.round(num * 100) : 0;
}

/**
 * Aggregate component-wise totals across records using combined Total (Current + Arrear) ONLY.
 * Never produces separate Arrear rows or columns.
 */
export function aggregateNoteSummaryComponents(records = []) {
    const allowanceBuckets = new Map();
    const deductionBuckets = new Map();

    const addBucket = (map, rawTitle, cents, catalog) => {
        if (cents === 0) return;
        const bucket = resolveComponentBucket(rawTitle, catalog);
        const existing = map.get(bucket.key);
        if (existing) {
            existing.cents += cents;
        } else {
            map.set(bucket.key, {
                key: bucket.key,
                label: bucket.label,
                order: bucket.order,
                cents,
                comment: '',
            });
        }
    };

    for (const record of records || []) {
        const allowances = Array.isArray(record.allowances) ? record.allowances : [];
        const deductions = Array.isArray(record.deductions) ? record.deductions : [];

        let recAllowCents = 0;
        for (const item of allowances) {
            const amt = item.total ?? ((Number(item.current || 0) + Number(item.arrear || 0)));
            const cents = toCents(amt);
            recAllowCents += cents;
            addBucket(allowanceBuckets, item.title || item.name || 'অন্যান্য ভাতা', cents, ALLOWANCE_CATALOG);
        }

        let recDedCents = 0;
        for (const item of deductions) {
            const amt = item.total ?? ((Number(item.current || 0) + Number(item.arrear || 0)));
            const cents = toCents(amt);
            recDedCents += cents;
            addBucket(deductionBuckets, item.title || item.name || 'অন্যান্য কর্তন', cents, DEDUCTION_CATALOG);
        }

        // For saved calculator snapshots where arrear_rows are stored separately from allowances/deductions,
        // fold them into the matching combined component buckets (never as separate arrear rows).
        const isSnapshotWithSeparateArrears =
            String(record.source || '').includes('CALCULATOR_SNAPSHOT') && Array.isArray(record.arrear_rows);
        if (isSnapshotWithSeparateArrears) {
            for (const ar of record.arrear_rows) {
                const amt = ar.final_amount ?? ar.amount ?? ar.total ?? 0;
                const cents = toCents(amt);
                if (cents === 0) continue;
                if (ar.direction === 'payable') {
                    const mappedTitle = ar.code === 'BASIC_ARREAR_GROSS' ? 'Basic Pay' : (ar.title || 'অন্যান্য ভাতা');
                    recAllowCents += cents;
                    addBucket(allowanceBuckets, mappedTitle, cents, ALLOWANCE_CATALOG);
                } else if (ar.direction === 'deduction') {
                    let mappedTitle = ar.title || 'অন্যান্য কর্তন';
                    if (ar.code === 'ADDITIONAL_GPF') mappedTitle = 'GPF';
                    else if (ar.code === 'HOUSE_RENT_RECOVERY') mappedTitle = 'House Rent Deduction';
                    else if (ar.code === 'SPECIAL_BENEFIT_RECOVERY') mappedTitle = 'Special Benefit Recovery';
                    else if (ar.code === 'BASIC_INCREMENT_ADJUSTMENT') mappedTitle = 'Basic Increment Adjustment';
                    recDedCents += cents;
                    addBucket(deductionBuckets, mappedTitle, cents, DEDUCTION_CATALOG);
                }
            }
        }

        // If a record had no itemized allowances but has a numeric basic/gross, preserve total integrity
        if (allowances.length === 0 && toCents(record.gross) !== 0 && recAllowCents === 0) {
            addBucket(allowanceBuckets, 'Basic Pay', toCents(record.gross), ALLOWANCE_CATALOG);
        }
        if (deductions.length === 0 && toCents(record.total_deductions) !== 0 && recDedCents === 0) {
            addBucket(deductionBuckets, 'অন্যান্য কর্তন', toCents(record.total_deductions), DEDUCTION_CATALOG);
        }
    }

    const allowanceRows = Array.from(allowanceBuckets.values())
        .filter((r) => r.cents !== 0)
        .sort((a, b) => a.order - b.order || b.cents - a.cents)
        .map((r, idx) => ({
            sl: idx + 1,
            key: r.key,
            label: r.label,
            amount: r.cents / 100,
            cents: r.cents,
            comment: '',
        }));

    const deductionRows = Array.from(deductionBuckets.values())
        .filter((r) => r.cents !== 0)
        .sort((a, b) => a.order - b.order || b.cents - a.cents)
        .map((r, idx) => ({
            sl: idx + 1,
            key: r.key,
            label: r.label,
            amount: r.cents / 100,
            cents: r.cents,
            comment: '',
        }));

    const grossCents = allowanceRows.reduce((sum, r) => sum + r.cents, 0);
    const totalDeductionCents = deductionRows.reduce((sum, r) => sum + r.cents, 0);
    const netPayableCents = grossCents - totalDeductionCents;

    return {
        employeeCount: (records || []).length,
        allowanceRows,
        deductionRows,
        grossTotal: grossCents / 100,
        deductionTotal: totalDeductionCents / 100,
        netPayable: netPayableCents / 100,
    };
}

// ============================================================================
// Unicode Bangla -> Bijoy ANSI (SutonnyMJ) Converter
// ============================================================================

const BIJOY_PRE_KARS = new Set(['ি', 'ে', 'ৈ']);
const BIJOY_CONSONANTS = new Set(
    'কখগঘঙচছজঝঞটঠডঢণতথদধনপফবভমযরলশষসহড়ঢ়য়'.split(''),
);

const BIJOY_CONJUNCT_MAP = {
    'ক্ষ্ম': '²',
    'ক্ষ': 'ÿ',
    'জ্ঞ': 'Á',
    'ঞ্জ': 'Ä',
    'ঞ্চ': 'Â',
    'ঙ্ক': '¼',
    'ঙ্গ': '½',
    'ক্ত': '³',
    'ক্র': 'µ',
    'ক্ল': 'K¬',
    'ক্ক': '°',
    'ক্ট': '±',
    'ক্স': '·',
    'ক্ব': 'K¡',
    'ক্ম': 'K¥',
    'গ্র': 'MÖ',
    'গ্ল': 'Mø',
    'গ্ধ': '»',
    'গ্ন': 'Mœ',
    'গ্ব': 'M¡',
    'গ্ম': 'M¥',
    'ঘ্ন': 'Nœ',
    'ঘ্র': 'Nª',
    'চ্চ': '”P',
    'চ্ছ': '”Q',
    'চ্ছ্ব': '”Q¡',
    'জ্জ': '¾',
    'জ্জ্ব': '¾¡',
    'জ্ঝ': 'À',
    'জ্ব': 'R¡',
    'জ্র': 'Rª',
    'ট্ট': 'Æ',
    'ট্ব': 'U¡',
    'ট্ম': 'U¥',
    'ট্র': 'Uª',
    'ড্ড': 'Ç',
    'ড্র': 'Wª',
    'ণ্ট': 'È',
    'ণ্ঠ': 'É',
    'ণ্ড': 'Ê',
    'ণ্ঢ': 'Ë',
    'ণ্ণ': 'Yœ',
    'ণ্ব': 'Y¡',
    'ণ্ম': 'Y¥',
    'ত্ত': 'Ë',
    'ত্ত্ব': 'Ë¡',
    'ত্থ': 'Ì',
    'ত্ন': 'Zœ',
    'ত্ম': 'Z¥',
    'ত্ব': 'Z¡',
    'ত্র': 'Î',
    'থ্ব': '_¡',
    'থ্র': '_ª',
    'দ্দ': 'Ï',
    'দ্ধ': '×',
    'দ্ব': 'Ø',
    'দ্ম': 'Ù',
    'দ্র': 'Ð',
    'দ্ভ': '™¢',
    'ধ্ন': 'aœ',
    'ধ্ব': 'a¡',
    'ধ্ম': 'a¥',
    'ধ্র': 'aª',
    'ন্ট': '›U',
    'ন্ঠ': '›V',
    'ন্ড': 'Û',
    'ন্ত': 'šÍ',
    'ন্ত্ব': 'šÍ¡',
    'ন্ত্র': 'š¿',
    'ন্থ': 'š’',
    'ন্দ': '›`',
    'ন্দ্ব': '›`¡',
    'ন্দ্র': '›`ª',
    'ন্ধ': 'Ü',
    'ন্ধ্র': 'Üª',
    'ন্ন': 'bœ',
    'ন্ব': 'b¡',
    'ন্ম': 'b¥',
    'ন্স': 'Ý',
    'প্ট': 'Þ',
    'প্ত': 'ß',
    'প্ন': 'cœ',
    'প্প': 'à',
    'প্ল': 'cø',
    'প্র': 'cÖ',
    'প্স': 'á',
    'ফ্ল': 'd¬',
    'ফ্র': 'd«',
    'ব্জ': 'â',
    'ব্দ': 'ã',
    'ব্ধ': 'ä',
    'ব্ব': 'eŸ',
    'ব্ল': 'eø',
    'ব্র': 'eª',
    'ভ্ল': 'f¬',
    'ভ্র': 'å',
    'ম্ন': 'gœ',
    'ম্প': '¤ú',
    'ম্প্র': '¤úÖ',
    'ম্ফ': '¤¢',
    'ম্ব': '¤^',
    'ম্ভ': '¤¢',
    'ম্ভ্র': '¤¢ª',
    'ম্ম': '¤§',
    'ম্ল': '¤ø',
    'ম্র': 'gª',
    'ল্ক': 'é',
    'ল্গ': 'ê',
    'ল্ট': 'ë',
    'ল্ড': 'ì',
    'ল্প': 'í',
    'ল্ফ': 'î',
    'ল্ব': 'j¡',
    'ল্ম': 'j¥',
    'ল্ল': 'jø',
    'শ্চ': 'ð',
    'শ্ছ': 'ñ',
    'শ্ন': 'kœ',
    'শ্ব': 'k¡',
    'শ্ম': 'k¥',
    'শ্ল': 'kø',
    'শ্র': 'kÖ',
    'ষ্ক': '®‹',
    'ষ্ট': 'ó',
    'ষ্ঠ': 'ô',
    'ষ্ণ': 'ò',
    'ষ্প': '®ú',
    'ষ্ফ': '®¢',
    'ষ্ম': '®§',
    'স্ক': '¯‹',
    'স্খ': 'ö',
    'স্ট': '÷',
    'স্ত': '¯Í',
    'স্ত্র': '¯¿',
    'স্থ': '¯’',
    'স্ন': 'mœ',
    'স্প': '¯ú',
    'স্ফ': 'ù',
    'স্ব': '¯^',
    'স্ম': '¯§',
    'স্ল': '¯ø',
    'স্র': 'mª',
    'হ্ণ': 'nè',
    'হ্ন': 'ý',
    'হ্ব': 'n¡',
    'হ্ম': 'þ',
    'হ্ল': 'n¬',
    'হ্র': 'nª',
    'রু': 'iæ',
    'রূ': 'iƒ',
    'গু': '¸',
    'শু': 'ï',
    'হু': 'û',
    'হৃ': 'ü',
    'ন্তু': 'š‘',
    'স্তু': '¯‘',
    'ত্রু': 'Îæ',
    'দ্রু': 'Ðæ',
};

const BIJOY_SINGLE_MAP = {
    'অ': 'A', 'আ': 'Av', 'ই': 'B', 'ঈ': 'C', 'উ': 'D', 'ঊ': 'E', 'ঋ': 'F',
    'এ': 'G', 'ঐ': 'H', 'ও': 'I', 'ঔ': 'J',
    'ক': 'K', 'খ': 'L', 'গ': 'M', 'ঘ': 'N', 'ঙ': 'O',
    'চ': 'P', 'ছ': 'Q', 'জ': 'R', 'ঝ': 'S', 'ঞ': 'T',
    'ট': 'U', 'ঠ': 'V', 'ড': 'W', 'ঢ': 'X', 'ণ': 'Y',
    'ত': 'Z', 'থ': '_', 'দ': '`', 'ধ': 'a', 'ন': 'b',
    'প': 'c', 'ফ': 'd', 'ব': 'e', 'ভ': 'f', 'ম': 'g',
    'য': 'h', 'র': 'i', 'ল': 'j', 'শ': 'k', 'ষ': 'l', 'স': 'm', 'হ': 'n',
    'ড়': 'o', 'ঢ়': 'p', 'য়': 'q', 'ৎ': 'r',
    'ং': 's', 'ঃ': 't', 'ঁ': 'u',
    'া': 'v', 'ি': 'w', 'ী': 'x', 'ু': 'y', 'ূ': '~', 'ৃ': '…',
    'ে': '‡', 'ৈ': 'ˆ', 'ৗ': 'Š',
    '্': '&',
    '০': '0', '১': '1', '২': '2', '৩': '3', '৪': '4',
    '৫': '5', '৬': '6', '৭': '7', '৮': '8', '৯': '9',
    '।': '|', '৳': '$', '–': '-', '—': '-',
};

const BIJOY_CONJUNCT_KEYS = Object.keys(BIJOY_CONJUNCT_MAP).sort((a, b) => b.length - a.length);

/**
 * Convert Unicode Bangla string into Bijoy ANSI encoding for SutonnyMJ font.
 */
export function unicodeToBijoy(input) {
    if (!input) return '';
    // Normalize composite Bangla characters & split two-part vowel signs (ো, ৌ)
    let text = String(input)
        .replace(/ব\u09BC/g, 'র')
        .replace(/ড\u09BC/g, 'ড়')
        .replace(/ঢ\u09BC/g, 'ঢ়')
        .replace(/য\u09BC/g, 'য়')
        .replace(/ো/g, 'ো')
        .replace(/ৌ/g, 'ৌ');

    // Step 1: Move reph (র্ + consonant cluster) to after the cluster as marker \uE001
    text = text.replace(
        /র্([ক-হড়-য়](?:্[ক-হড়-য়])*)/g,
        '$1\uE001',
    );

    // Step 2: Reorder pre-base vowel signs (ি, ে, ৈ) before their consonant cluster
    text = text.replace(
        /([ক-হড়-য়](?:্[ক-হড়-য়])*)(?:\uE001)?([িেৈ])/g,
        (match, cluster, kar) => {
            const hasReph = match.includes('\uE001');
            return kar + cluster + (hasReph ? '\uE001' : '');
        },
    );

    // Step 3: Convert ja-phala (্য) and ra-phala (্র) after consonants when not part of special conjuncts
    // Walk through string greedily matching longest conjuncts first
    let out = '';
    let i = 0;
    while (i < text.length) {
        const ch = text[i];

        if (ch === '\uE001') {
            out += '©';
            i += 1;
            continue;
        }

        // Handle ja-phala (্য) and ra-phala (্র)
        if (ch === '্' && text[i + 1] === 'য') {
            out += '¨';
            i += 2;
            continue;
        }
        if (ch === '্' && text[i + 1] === 'র') {
            out += 'ª';
            i += 2;
            continue;
        }

        // Try longest conjunct match
        let matched = false;
        for (const key of BIJOY_CONJUNCT_KEYS) {
            if (text.startsWith(key, i)) {
                out += BIJOY_CONJUNCT_MAP[key];
                i += key.length;
                matched = true;
                break;
            }
        }
        if (matched) continue;

        if (Object.prototype.hasOwnProperty.call(BIJOY_SINGLE_MAP, ch)) {
            out += BIJOY_SINGLE_MAP[ch];
        } else {
            out += ch;
        }
        i += 1;
    }

    return out;
}

export function encodeForSelectedFont(text, fontValue) {
    const opt = FONT_OPTIONS.find((f) => f.value === fontValue);
    if (opt?.isBijoy) {
        return unicodeToBijoy(text);
    }
    return String(text ?? '');
}

// ============================================================================
// Lightweight Zero-Dependency OOXML ZIP Builder (STORE + CRC32)
// Used for genuine styled .xlsx and .docx exports without heavy dependencies.
// ============================================================================

const CRC32_TABLE = (() => {
    const table = new Uint32Array(256);
    for (let i = 0; i < 256; i++) {
        let c = i;
        for (let k = 0; k < 8; k++) {
            c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
        }
        table[i] = c >>> 0;
    }
    return table;
})();

function crc32(bytes) {
    let crc = 0xFFFFFFFF;
    for (let i = 0; i < bytes.length; i++) {
        crc = CRC32_TABLE[(crc ^ bytes[i]) & 0xFF] ^ (crc >>> 8);
    }
    return (crc ^ 0xFFFFFFFF) >>> 0;
}

function createZipUint8Array(entries) {
    const encoder = new TextEncoder();
    const localParts = [];
    const centralParts = [];
    let offset = 0;

    for (const entry of entries) {
        const nameBytes = encoder.encode(entry.name);
        const dataBytes = typeof entry.content === 'string'
            ? encoder.encode(entry.content)
            : entry.content;
        const crc = crc32(dataBytes);

        // Local file header (30 bytes + name)
        const localHeader = new Uint8Array(30 + nameBytes.length);
        const lv = new DataView(localHeader.buffer);
        lv.setUint32(0, 0x04034B50, true); // signature
        lv.setUint16(4, 20, true); // version needed
        lv.setUint16(6, 0x0800, true); // UTF-8 flag
        lv.setUint16(8, 0, true); // method: STORE
        lv.setUint16(10, 0, true); // mod time
        lv.setUint16(12, 0x5421, true); // mod date
        lv.setUint32(14, crc, true);
        lv.setUint32(18, dataBytes.length, true);
        lv.setUint32(22, dataBytes.length, true);
        lv.setUint16(26, nameBytes.length, true);
        lv.setUint16(28, 0, true);
        localHeader.set(nameBytes, 30);

        localParts.push(localHeader, dataBytes);

        // Central directory header (46 bytes + name)
        const centralHeader = new Uint8Array(46 + nameBytes.length);
        const cv = new DataView(centralHeader.buffer);
        cv.setUint32(0, 0x02014B50, true); // signature
        cv.setUint16(4, 20, true); // version made by
        cv.setUint16(6, 20, true); // version needed
        cv.setUint16(8, 0x0800, true); // UTF-8 flag
        cv.setUint16(10, 0, true); // method: STORE
        cv.setUint16(12, 0, true);
        cv.setUint16(14, 0x5421, true);
        cv.setUint32(16, crc, true);
        cv.setUint32(20, dataBytes.length, true);
        cv.setUint32(24, dataBytes.length, true);
        cv.setUint16(28, nameBytes.length, true);
        cv.setUint16(30, 0, true);
        cv.setUint16(32, 0, true);
        cv.setUint16(34, 0, true);
        cv.setUint16(36, 0, true);
        cv.setUint32(38, 0, true);
        cv.setUint32(42, offset, true);
        centralHeader.set(nameBytes, 46);

        centralParts.push(centralHeader);
        offset += localHeader.length + dataBytes.length;
    }

    const centralSize = centralParts.reduce((s, b) => s + b.length, 0);
    const eocd = new Uint8Array(22);
    const ev = new DataView(eocd.buffer);
    ev.setUint32(0, 0x06054B50, true);
    ev.setUint16(4, 0, true);
    ev.setUint16(6, 0, true);
    ev.setUint16(8, entries.length, true);
    ev.setUint16(10, entries.length, true);
    ev.setUint32(12, centralSize, true);
    ev.setUint32(16, offset, true);
    ev.setUint16(20, 0, true);

    const totalSize = offset + centralSize + eocd.length;
    const output = new Uint8Array(totalSize);
    let cursor = 0;
    for (const part of localParts) {
        output.set(part, cursor);
        cursor += part.length;
    }
    for (const part of centralParts) {
        output.set(part, cursor);
        cursor += part.length;
    }
    output.set(eocd, cursor);
    return output;
}

function escapeXml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&apos;');
}

function triggerBlobDownload(uint8Array, mimeType, filename) {
    const blob = new Blob([uint8Array], { type: mimeType });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    setTimeout(() => URL.revokeObjectURL(url), 1500);
}

// ============================================================================
// Styled Excel (.xlsx) Builder & Downloader
// - Uses XLSX.utils cell encoding + styled OOXML SpreadsheetML so ONLY the two
//   summary tables have borders, all narrative paragraphs have NO borders and
//   justified alignment, and the user-selected font is applied throughout.
// ============================================================================

export function buildSalaryNoteSummaryExcelBytes(doc) {
    const fontOpt = FONT_OPTIONS.find((f) => f.value === doc.font) || FONT_OPTIONS[0];
    const fontName = fontOpt.fontFamily;
    const tx = (s) => encodeForSelectedFont(s, fontOpt.value);

    // Style indices in xl/styles.xml:
    // 0: default (no border, left, wrap)
    // 1: bold subject / title (no border, left/justify, bold, wrap)
    // 2: justified narrative paragraph (no border, justify, top, wrap)
    // 3: table header (thin border, center, bold, light gray fill, wrap)
    // 4: table cell center (thin border, center, wrap)
    // 5: table cell left (thin border, left, wrap)
    // 6: table cell right (thin border, right, wrap)
    // 7: table total left bold (thin border, left, bold, light fill, wrap)
    // 8: table total right bold (thin border, right, bold, light fill, wrap)
    // 9: table total center bold (thin border, center, bold, light fill, wrap)
    // 10: net payable highlight (no border, bold, justify, wrap)
    // 11: signature cell (no border, center, bold, wrap)

    const rows = []; // { cells: [{ col, value, style }], height?: number, merge?: [c1, c2] }

    const estimateRowHeight = (text, charsPerLine = 82, lineHeight = 18) => {
        const str = String(text || '');
        if (!str.trim()) return 14;
        const lines = str.split('\n').reduce((acc, part) => {
            return acc + Math.max(1, Math.ceil(part.length / charsPerLine));
        }, 0);
        return Math.max(22, lines * lineHeight + 6);
    };

    const pushMergedLine = (text, style, height) => {
        const val = tx(text);
        rows.push({
            cells: [{ col: 0, value: val, style }],
            merge: [0, 3],
            height: height ?? estimateRowHeight(text),
        });
    };

    const pushBlankRow = (height = 12) => {
        rows.push({ cells: [], height });
    };

    // Subject 1 (NO (০১) prefix, NO border)
    if (String(doc.subject1 || '').trim()) {
        pushMergedLine(doc.subject1.trim(), 1, estimateRowHeight(doc.subject1, 75, 20));
    }

    // Intro line (NO border, justified)
    if (String(doc.introLine || '').trim()) {
        pushBlankRow(8);
        pushMergedLine(doc.introLine.trim(), 2, estimateRowHeight(doc.introLine, 82, 18));
    }

    pushBlankRow(8);

    // Table 1: Allowances (WITH borders)
    rows.push({
        cells: [
            { col: 0, value: tx('ক্রমিক'), style: 3 },
            { col: 1, value: tx('খাতভিত্তিক বেতন-ভাতাদির বিবরণ'), style: 3 },
            { col: 2, value: tx('টাকার পরিমাণ'), style: 3 },
            { col: 3, value: tx('মন্তব্য'), style: 3 },
        ],
        height: 24,
    });

    (doc.allowanceRows || []).forEach((row, idx) => {
        rows.push({
            cells: [
                { col: 0, value: tx(toBanglaDigits(idx + 1)), style: 4 },
                { col: 1, value: tx(row.label), style: 5 },
                { col: 2, value: tx(formatBanglaMoney(row.amount)), style: 6 },
                { col: 3, value: tx(row.comment || ''), style: 5 },
            ],
            height: 21,
        });
    });

    rows.push({
        cells: [
            { col: 0, value: '', style: 9 },
            { col: 1, value: tx('মোট বেতন-ভাতাদি'), style: 7 },
            { col: 2, value: tx(formatBanglaMoney(doc.grossTotal)), style: 8 },
            { col: 3, value: '', style: 9 },
        ],
        height: 23,
    });

    pushBlankRow(12);

    // Table 2: Deductions (WITH borders)
    rows.push({
        cells: [
            { col: 0, value: tx('ক্রমিক'), style: 3 },
            { col: 1, value: tx('বিভিন্ন তহবিলে কর্তনাদির বিবরণ'), style: 3 },
            { col: 2, value: tx('টাকার পরিমাণ'), style: 3 },
            { col: 3, value: tx('মন্তব্য'), style: 3 },
        ],
        height: 24,
    });

    (doc.deductionRows || []).forEach((row, idx) => {
        rows.push({
            cells: [
                { col: 0, value: tx(toBanglaDigits(idx + 1)), style: 4 },
                { col: 1, value: tx(row.label), style: 5 },
                { col: 2, value: tx(formatBanglaMoney(row.amount)), style: 6 },
                { col: 3, value: tx(row.comment || ''), style: 5 },
            ],
            height: 21,
        });
    });

    rows.push({
        cells: [
            { col: 0, value: '', style: 9 },
            { col: 1, value: tx('সর্বমোট কর্তনাদি'), style: 7 },
            { col: 2, value: tx(formatBanglaMoney(doc.deductionTotal)), style: 8 },
            { col: 3, value: '', style: 9 },
        ],
        height: 23,
    });

    rows.push({
        cells: [
            { col: 0, value: '', style: 9 },
            { col: 1, value: tx('নীট পরিশোধযোগ্য'), style: 7 },
            { col: 2, value: tx(formatBanglaMoney(doc.netPayable)), style: 8 },
            { col: 3, value: '', style: 9 },
        ],
        height: 24,
    });

    pushBlankRow(10);

    // Net Payable Line (অংকে ও কথায়) — NO border, justified
    if (String(doc.netPayableLine || '').trim()) {
        pushMergedLine(doc.netPayableLine.trim(), 10, estimateRowHeight(doc.netPayableLine, 80, 19));
    }

    // Post-Table Approval Paragraph — NO border, justified
    if (String(doc.postTableParagraph || '').trim()) {
        pushBlankRow(10);
        pushMergedLine(doc.postTableParagraph.trim(), 2, estimateRowHeight(doc.postTableParagraph, 82, 18));
    }

    // Bank Advice Subject (NO (০২) prefix, NO border, justified)
    if (String(doc.bankAdviceSubject || '').trim()) {
        pushBlankRow(14);
        pushMergedLine(doc.bankAdviceSubject.trim(), 1, estimateRowHeight(doc.bankAdviceSubject, 78, 19));
    }

    // Bank Advice Paragraph — NO border, justified
    if (String(doc.bankAdviceParagraph || '').trim()) {
        pushBlankRow(8);
        pushMergedLine(doc.bankAdviceParagraph.trim(), 2, estimateRowHeight(doc.bankAdviceParagraph, 82, 18));
    }

    // Dynamic Extra Paragraphs — NO numbering prefixes, NO borders, justified
    for (const para of doc.extraParagraphs || []) {
        const text = String(para?.text ?? para ?? '').trim();
        if (!text) continue;
        pushBlankRow(10);
        pushMergedLine(text, 2, estimateRowHeight(text, 82, 18));
    }

    // Signature Block — NO borders
    const sigs = (doc.signatures || []).map((s) => String(s || '').trim()).filter(Boolean);
    if (sigs.length > 0) {
        pushBlankRow(28);
        // Place signatures in clean 2-per-row or 3-per-row layout across columns 0..3
        for (let idx = 0; idx < sigs.length; idx += 2) {
            const left = sigs[idx];
            const right = sigs[idx + 1];
            if (right) {
                rows.push({
                    cells: [
                        { col: 0, value: tx(left), style: 11 },
                        { col: 2, value: tx(right), style: 11 },
                    ],
                    customMerges: [[0, 1], [2, 3]],
                    height: 24,
                });
            } else {
                rows.push({
                    cells: [{ col: 0, value: tx(left), style: 11 }],
                    merge: [0, 3],
                    height: 24,
                });
            }
            if (idx + 2 < sigs.length) {
                pushBlankRow(26);
            }
        }
    }

    // Build sheet XML using XLSX.utils.encode_cell for exact coordinates
    const merges = [];
    const sheetRowsXml = rows.map((rowObj, rIdx) => {
        const rowNum = rIdx + 1;
        if (rowObj.merge) {
            const [c1, c2] = rowObj.merge;
            merges.push(`${XLSX.utils.encode_cell({ r: rIdx, c: c1 })}:${XLSX.utils.encode_cell({ r: rIdx, c: c2 })}`);
        }
        if (rowObj.customMerges) {
            for (const [c1, c2] of rowObj.customMerges) {
                merges.push(`${XLSX.utils.encode_cell({ r: rIdx, c: c1 })}:${XLSX.utils.encode_cell({ r: rIdx, c: c2 })}`);
            }
        }
        const cellsXml = (rowObj.cells || []).map((c) => {
            const ref = XLSX.utils.encode_cell({ r: rIdx, c: c.col });
            return `<c r="${ref}" t="inlineStr" s="${c.style}"><is><t xml:space="preserve">${escapeXml(c.value)}</t></is></c>`;
        }).join('');
        const htAttr = rowObj.height ? ` ht="${rowObj.height}" customHeight="1"` : '';
        return `<row r="${rowNum}"${htAttr}>${cellsXml}</row>`;
    }).join('');

    const mergeXml = merges.length
        ? `<mergeCells count="${merges.length}">${merges.map((ref) => `<mergeCell ref="${ref}"/>`).join('')}</mergeCells>`
        : '';

    const stylesXml = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="3">
    <font><sz val="12"/><name val="${escapeXml(fontName)}"/></font>
    <font><b/><sz val="12"/><name val="${escapeXml(fontName)}"/></font>
    <font><b/><sz val="13"/><name val="${escapeXml(fontName)}"/></font>
  </fonts>
  <fills count="3">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/><bgColor indexed="64"/></patternFill></fill>
  </fills>
  <borders count="2">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border>
      <left style="thin"><color rgb="FF334155"/></left>
      <right style="thin"><color rgb="FF334155"/></right>
      <top style="thin"><color rgb="FF334155"/></top>
      <bottom style="thin"><color rgb="FF334155"/></bottom>
      <diagonal/>
    </border>
  </borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="12">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="left" vertical="top" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="justify" vertical="top" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="justify" vertical="top" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="justify" vertical="top" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
  </cellXfs>
  <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>`;

    const sheetXml = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetViews><sheetView workbookViewId="0" showGridLines="0"/></sheetViews>
  <sheetFormatPr defaultRowHeight="20"/>
  <cols>
    <col min="1" max="1" width="10" customWidth="1"/>
    <col min="2" max="2" width="46" customWidth="1"/>
    <col min="3" max="3" width="22" customWidth="1"/>
    <col min="4" max="4" width="24" customWidth="1"/>
  </cols>
  <sheetData>${sheetRowsXml}</sheetData>
  ${mergeXml}
  <pageMargins left="0.6" right="0.6" top="0.7" bottom="0.7" header="0.3" footer="0.3"/>
  <pageSetup paperSize="9" orientation="portrait" fitToWidth="1" fitToHeight="0"/>
</worksheet>`;

    const contentTypesXml = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>`;

    const rootRelsXml = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>`;

    const workbookXml = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets><sheet name="Note &amp; Summary" sheetId="1" r:id="rId1"/></sheets>
</workbook>`;

    const workbookRelsXml = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>`;

    return createZipUint8Array([
        { name: '[Content_Types].xml', content: contentTypesXml },
        { name: '_rels/.rels', content: rootRelsXml },
        { name: 'xl/workbook.xml', content: workbookXml },
        { name: 'xl/_rels/workbook.xml.rels', content: workbookRelsXml },
        { name: 'xl/styles.xml', content: stylesXml },
        { name: 'xl/worksheets/sheet1.xml', content: sheetXml },
    ]);
}

export function downloadSalaryNoteSummaryExcel(doc, filename) {
    const bytes = buildSalaryNoteSummaryExcelBytes(doc);
    triggerBlobDownload(
        bytes,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        filename,
    );
}

// ============================================================================
// Native Word (.docx) OOXML Builder & Downloader
// - ONLY the two summary tables (Allowances & Deductions) have borders.
// - All narrative paragraphs have NO borders and justified alignment (w:jc="both").
// - Applies user-selected font across ASCII, HAnsi, and Complex Script (CS).
// ============================================================================

export function buildSalaryNoteSummaryWordBytes(doc) {
    const fontOpt = FONT_OPTIONS.find((f) => f.value === doc.font) || FONT_OPTIONS[0];
    const fontName = fontOpt.fontFamily;
    const tx = (s) => encodeForSelectedFont(s, fontOpt.value);
    const rFontsXml = `<w:rFonts w:ascii="${escapeXml(fontName)}" w:hAnsi="${escapeXml(fontName)}" w:cs="${escapeXml(fontName)}"/>`;

    const makeParagraph = (text, { bold = false, align = 'both', size = 24, spaceBefore = 0, spaceAfter = 120 } = {}) => {
        const lines = String(text ?? '').split('\n');
        const runsXml = lines.map((line, i) => {
            const br = i > 0 ? '<w:br/>' : '';
            return `<w:r><w:rPr>${rFontsXml}${bold ? '<w:b/><w:bCs/>' : ''}<w:sz w:val="${size}"/><w:szCs w:val="${size}"/></w:rPr>${br}<w:t xml:space="preserve">${escapeXml(tx(line))}</w:t></w:r>`;
        }).join('');

        return `<w:p><w:pPr><w:jc w:val="${align}"/><w:spacing w:before="${spaceBefore}" w:after="${spaceAfter}" w:line="276" w:lineRule="auto"/></w:pPr>${runsXml}</w:p>`;
    };

    const makeTableCell = (text, { widthDxa, align = 'left', bold = false, shading = null, size = 22 } = {}) => {
        const shdXml = shading ? `<w:shd w:val="clear" w:color="auto" w:fill="${shading}"/>` : '';
        return `<w:tc>
          <w:tcPr>
            <w:tcW w:w="${widthDxa}" w:type="dxa"/>
            ${shdXml}
            <w:vAlign w:val="center"/>
          </w:tcPr>
          <w:p>
            <w:pPr><w:jc w:val="${align}"/><w:spacing w:before="40" w:after="40"/></w:pPr>
            <w:r>
              <w:rPr>${rFontsXml}${bold ? '<w:b/><w:bCs/>' : ''}<w:sz w:val="${size}"/><w:szCs w:val="${size}"/></w:rPr>
              <w:t xml:space="preserve">${escapeXml(tx(text))}</w:t>
            </w:r>
          </w:p>
        </w:tc>`;
    };

    const makeBorderedTable = (headers, bodyRows, footerRows) => {
        // Total A4 usable width ~ 9638 dxa (with 0.8in left/right margins)
        const widths = [900, 4438, 2200, 2100];
        const headerRowXml = `<w:tr><w:trPr><w:tblHeader/></w:trPr>${headers.map((h, i) => makeTableCell(h, { widthDxa: widths[i], align: 'center', bold: true, shading: 'F1F5F9' })).join('')}</w:tr>`;
        const bodyXml = bodyRows.map((row, idx) => `<w:tr>${[
            makeTableCell(toBanglaDigits(idx + 1), { widthDxa: widths[0], align: 'center' }),
            makeTableCell(row.label, { widthDxa: widths[1], align: 'left' }),
            makeTableCell(formatBanglaMoney(row.amount), { widthDxa: widths[2], align: 'right' }),
            makeTableCell(row.comment || '', { widthDxa: widths[3], align: 'left' }),
        ].join('')}</w:tr>`).join('');
        const footerXml = footerRows.map((fr) => `<w:tr>${[
            makeTableCell('', { widthDxa: widths[0], align: 'center', bold: true, shading: 'F8FAFC' }),
            makeTableCell(fr.label, { widthDxa: widths[1], align: 'left', bold: true, shading: 'F8FAFC' }),
            makeTableCell(formatBanglaMoney(fr.amount), { widthDxa: widths[2], align: 'right', bold: true, shading: 'F8FAFC' }),
            makeTableCell('', { widthDxa: widths[3], align: 'left', bold: true, shading: 'F8FAFC' }),
        ].join('')}</w:tr>`).join('');

        return `<w:tbl>
          <w:tblPr>
            <w:tblW w:w="9638" w:type="dxa"/>
            <w:tblBorders>
              <w:top w:val="single" w:sz="4" w:space="0" w:color="334155"/>
              <w:left w:val="single" w:sz="4" w:space="0" w:color="334155"/>
              <w:bottom w:val="single" w:sz="4" w:space="0" w:color="334155"/>
              <w:right w:val="single" w:sz="4" w:space="0" w:color="334155"/>
              <w:insideH w:val="single" w:sz="4" w:space="0" w:color="334155"/>
              <w:insideV w:val="single" w:sz="4" w:space="0" w:color="334155"/>
            </w:tblBorders>
            <w:tblCellMar>
              <w:top w:w="60" w:type="dxa"/>
              <w:left w:w="100" w:type="dxa"/>
              <w:bottom w:w="60" w:type="dxa"/>
              <w:right w:w="100" w:type="dxa"/>
            </w:tblCellMar>
          </w:tblPr>
          <w:tblGrid>${widths.map((w) => `<w:gridCol w:w="${w}"/>`).join('')}</w:tblGrid>
          ${headerRowXml}
          ${bodyXml}
          ${footerXml}
        </w:tbl>`;
    };

    const bodyParts = [];

    // Subject 1 (NO (০১) prefix, NO border)
    if (String(doc.subject1 || '').trim()) {
        bodyParts.push(makeParagraph(doc.subject1.trim(), { bold: true, align: 'both', size: 26, spaceAfter: 140 }));
    }

    // Intro Line (NO border, justified)
    if (String(doc.introLine || '').trim()) {
        bodyParts.push(makeParagraph(doc.introLine.trim(), { bold: false, align: 'both', size: 24, spaceAfter: 120 }));
    }

    // Table 1: Allowances (bordered)
    bodyParts.push(makeBorderedTable(
        ['ক্রমিক', 'খাতভিত্তিক বেতন-ভাতাদির বিবরণ', 'টাকার পরিমাণ', 'মন্তব্য'],
        doc.allowanceRows || [],
        [{ label: 'মোট বেতন-ভাতাদি', amount: doc.grossTotal }],
    ));

    // Spacer
    bodyParts.push('<w:p><w:pPr><w:spacing w:before="80" w:after="80"/></w:pPr></w:p>');

    // Table 2: Deductions (bordered)
    bodyParts.push(makeBorderedTable(
        ['ক্রমিক', 'বিভিন্ন তহবিলে কর্তনাদির বিবরণ', 'টাকার পরিমাণ', 'মন্তব্য'],
        doc.deductionRows || [],
        [
            { label: 'সর্বমোট কর্তনাদি', amount: doc.deductionTotal },
            { label: 'নীট পরিশোধযোগ্য', amount: doc.netPayable },
        ],
    ));

    // Net Payable Line (অংকে ও কথায়) — NO border, justified
    if (String(doc.netPayableLine || '').trim()) {
        bodyParts.push(makeParagraph(doc.netPayableLine.trim(), { bold: true, align: 'both', size: 24, spaceBefore: 140, spaceAfter: 140 }));
    }

    // Post-Table Paragraph — NO border, justified
    if (String(doc.postTableParagraph || '').trim()) {
        bodyParts.push(makeParagraph(doc.postTableParagraph.trim(), { bold: false, align: 'both', size: 24, spaceBefore: 60, spaceAfter: 180 }));
    }

    // Bank Advice Subject — NO (০২) prefix, NO border, justified
    if (String(doc.bankAdviceSubject || '').trim()) {
        bodyParts.push(makeParagraph(doc.bankAdviceSubject.trim(), { bold: true, align: 'both', size: 25, spaceBefore: 120, spaceAfter: 100 }));
    }

    // Bank Advice Paragraph — NO border, justified
    if (String(doc.bankAdviceParagraph || '').trim()) {
        bodyParts.push(makeParagraph(doc.bankAdviceParagraph.trim(), { bold: false, align: 'both', size: 24, spaceBefore: 40, spaceAfter: 160 }));
    }

    // Dynamic Extra Paragraphs — NO numbering prefixes, NO borders, justified
    for (const para of doc.extraParagraphs || []) {
        const text = String(para?.text ?? para ?? '').trim();
        if (!text) continue;
        bodyParts.push(makeParagraph(text, { bold: false, align: 'both', size: 24, spaceBefore: 60, spaceAfter: 160 }));
    }

    // Signature Block — borderless table
    const sigs = (doc.signatures || []).map((s) => String(s || '').trim()).filter(Boolean);
    if (sigs.length > 0) {
        const sigRows = [];
        for (let idx = 0; idx < sigs.length; idx += 2) {
            const left = sigs[idx];
            const right = sigs[idx + 1] || '';
            sigRows.push(`<w:tr>
              <w:tc><w:tcPr><w:tcW w:w="4819" w:type="dxa"/></w:tcPr>${makeParagraph(left, { bold: true, align: 'center', size: 22, spaceBefore: 480, spaceAfter: 80 })}</w:tc>
              <w:tc><w:tcPr><w:tcW w:w="4819" w:type="dxa"/></w:tcPr>${right ? makeParagraph(right, { bold: true, align: 'center', size: 22, spaceBefore: 480, spaceAfter: 80 }) : '<w:p/>'}</w:tc>
            </w:tr>`);
        }

        bodyParts.push(`<w:tbl>
          <w:tblPr>
            <w:tblW w:w="9638" w:type="dxa"/>
            <w:tblBorders>
              <w:top w:val="none" w:sz="0" w:space="0" w:color="auto"/>
              <w:left w:val="none" w:sz="0" w:space="0" w:color="auto"/>
              <w:bottom w:val="none" w:sz="0" w:space="0" w:color="auto"/>
              <w:right w:val="none" w:sz="0" w:space="0" w:color="auto"/>
              <w:insideH w:val="none" w:sz="0" w:space="0" w:color="auto"/>
              <w:insideV w:val="none" w:sz="0" w:space="0" w:color="auto"/>
            </w:tblBorders>
          </w:tblPr>
          <w:tblGrid><w:gridCol w:w="4819"/><w:gridCol w:w="4819"/></w:tblGrid>
          ${sigRows.join('')}
        </w:tbl>`);
    }

    const documentXml = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>
    ${bodyParts.join('\n')}
    <w:sectPr>
      <w:pgSz w:w="11906" w:h="16838"/>
      <w:pgMar w:top="1080" w:right="1134" w:bottom="1080" w:left="1134" w:header="720" w:footer="720" w:gutter="0"/>
    </w:sectPr>
  </w:body>
</w:document>`;

    const stylesXml = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:docDefaults>
    <w:rPrDefault>
      <w:rPr>
        ${rFontsXml}
        <w:sz w:val="24"/>
        <w:szCs w:val="24"/>
      </w:rPr>
    </w:rPrDefault>
  </w:docDefaults>
</w:styles>`;

    const contentTypesXml = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
</Types>`;

    const rootRelsXml = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>`;

    const docRelsXml = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>`;

    return createZipUint8Array([
        { name: '[Content_Types].xml', content: contentTypesXml },
        { name: '_rels/.rels', content: rootRelsXml },
        { name: 'word/document.xml', content: documentXml },
        { name: 'word/styles.xml', content: stylesXml },
        { name: 'word/_rels/document.xml.rels', content: docRelsXml },
    ]);
}

export function downloadSalaryNoteSummaryWord(doc, filename) {
    const bytes = buildSalaryNoteSummaryWordBytes(doc);
    triggerBlobDownload(
        bytes,
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        filename,
    );
}
