/* Database bridge for the unmodified supplied fixation calculator.
   Server always calculates the audited figures; this document is a read-only visual preview. */
window.addEventListener('DOMContentLoaded', () => {
    const d = window.KAU_DB_FIXATION || {};
    // Reporting is independent of payment approval. Never leave the demo employee or
    // example money visible if audited employee data is unavailable.
    if (!d.calculation || !d.grade || !Number.isFinite(Number(d.basic)) || Number(d.basic) <= 0) {
        const known = {of_01:d.name, of_02:d.designation, of_03:d.department,
            of_04:d.employee_code, of_05:d.nid, of_06:d.phone, of_07:d.dob,
            of_08:d.joining_date};
        for (const node of document.querySelectorAll('#officialFormContainer [id^="of_"]')) {
            if (Object.prototype.hasOwnProperty.call(known, node.id)) {
                node.textContent = ': ' + (known[node.id] || '—');
            } else {
                node.textContent = '—';
            }
        }
        for (const id of ['ver_scale_2026','ver_basic_19','ver_basic_23','ver_basic_23_repeat','ver_basic_29']) {
            const node = document.getElementById(id);
            if (node) node.textContent = '—';
        }
        const join = document.getElementById('disp_top_join');
        if (join) join.textContent = d.joining_date || '—';
        const mark = document.createElement('div');
        mark.className = 'kau-provisional-mark';
        mark.style.cssText = 'font-size:10px;text-align:center;font-weight:bold;color:#9b1c1c';
        mark.textContent = 'PROVISIONAL — MISSING FINANCIAL SOURCE VALUES; NOT VALID FOR PAYMENT';
        document.getElementById('officialFormContainer')?.appendChild(mark);
        return;
    }

    // Fix two transcription typos in the supplied HTML display strings without touching its logic.
    if (typeof PAY_SCALES !== 'undefined') {
        PAY_SCALES[10].scale2026Str = '৩২০০০-৭৭৩০০/-';
        PAY_SCALES[20].scale2026Str = '২০০০০-৪৮৪০০/-';
    }

    const put = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.value = value ?? '';
    };
    const textValue = (value) => value === null || value === undefined ? '' : String(value);
    const fmtDate = (str) => {
        if (!str || !/^\d{4}-\d\d-\d\d/.test(str)) return '';
        return str.slice(8, 10) + '/' + str.slice(5, 7) + '/' + str.slice(0, 4);
    };
    const gradeEl = document.getElementById('gradeSelect');
    if (gradeEl) gradeEl.value = textValue(d.grade);
    populateStagesForCurrentGrade(d.basic);
    put('input_basic_old', d.basic);
    put('input_pp', d.personal_pay);
    put('input_hr_old', d.house_rent_old ?? 0);
    put('input_hr_new', 0); // No 2028 area category or future rent in database: never invent.
    put('input_med', d.medical ?? 0);
    put('input_deduction', d.deductions ?? 0);
    put('input_inst_name', 'খুলনা কৃষি বিশ্ববিদ্যালয়, খুলনা।');
    put('input_emp_name', d.name);
    put('input_emp_designation', d.designation);
    put('input_emp_dept', d.department);
    put('input_emp_pay_id', d.employee_code);
    put('input_emp_nid', d.nid);
    put('input_emp_mobile', d.phone);
    put('input_emp_dob', d.dob);
    put('input_emp_join_date', fmtDate(d.joining_date));
    put('input_emp_first_post', ''); // Not reliably stored as a first-post field.
    put('input_emp_curr_post_date', ''); // Promotion effective dates not stored.
    put('input_emp_top_join', fmtDate(d.joining_date));
    window.KAUFIX_HOLD_INC = Boolean(d.hold_increment);
    recalculate();

    // Server-computed figures override the legacy browser demonstration. The independent
    // 2026 and 2027 increment flags must not be silently merged into one boolean.
    const applySavedFigures = () => {
        const c = d.calculation || {};
        const putMoney = (id, amount) => {
            const node = document.getElementById(id);
            if (node && amount !== undefined && amount !== null)
                node.textContent = Number(amount).toLocaleString('en-US', {minimumFractionDigits:2,maximumFractionDigits:2});
        };
        const printed = {
            of_17: c.fitment_basic, of_19: c.fitment_basic,
            of_22: c.increment_2026, of_23: c.phase_2026_07,
            of_27: c.phase_2027_01,
            of_28: c.phase_2027_07_before_increment,
            of_29: c.phase_2027_07,
            ver_basic_19: c.fitment_basic,
            ver_basic_23: c.phase_2026_07,
            ver_basic_23_repeat: c.phase_2026_07,
            ver_basic_29: c.phase_2027_07,
        };
        Object.entries(printed).forEach(([id, value]) => putMoney(id, value));
        if (d.approved_print) {
            const label = document.getElementById('kau-approval-status');
            if (label) label.textContent = 'HR APPROVED PAY FIXATION';
        }
    };
    window.KAUFIX_APPLY_SERVER_CALC = applySavedFigures;
    applySavedFigures();
    window.addEventListener('beforeprint', applySavedFigures);

    // Source fields must never be changed into unrecorded alternative salary inputs.
    document.querySelectorAll('#calculatorForm input').forEach(el => {
        el.readOnly = true;
        el.title = 'Automatically populated from employee records; edit employee salary setup at source.';
        el.classList.add('cursor-not-allowed');
    });
    document.querySelectorAll('#calculatorForm select').forEach(el => {
        el.disabled = true;
        el.title = 'Automatically selected from university records.';
    });
    document.querySelectorAll('button[onclick="resetToExample()"]')
        .forEach(el => { el.style.display = 'none'; });

    const notice = document.createElement('section');
    notice.className = 'no-print max-w-7xl mx-auto w-full mt-3 mx-auto px-4 sm:px-6 lg:px-8';
    const content = document.createElement('div');
    content.className = 'bg-amber-50 text-amber-950 border border-amber-300 rounded-xl px-4 py-3 shadow-sm';
    const heading = document.createElement('div');
    heading.className = 'text-sm font-bold';
    heading.textContent = 'Database-linked fixation preview — NOT iBAS++ approval';
    const intro = document.createElement('p');
    intro.className = 'text-xs mt-1';
    intro.textContent = 'Employee ID: ' + textValue(d.employee_code) +
        ' | Salary source: ' + textValue(d.source_type) +
        ' | Baseline period: ' + textValue(d.baseline_period || '2026-06') +
        ' | Formula estimates only. Do not use the projected gross/net amounts or 2028 housing estimate as final.';
    content.append(heading, intro);
    const pdfButton = document.createElement('button');
    pdfButton.type = 'button';
    pdfButton.setAttribute('data-kau-download-pdf', '');
    pdfButton.className = 'no-print inline-block mt-3 mr-3 rounded-lg bg-emerald-700 px-4 py-2 text-white text-sm font-bold';
    pdfButton.textContent = 'Download one-page PDF';
    content.appendChild(pdfButton);
    if (!d.ready || !d.approved_print) {
        pdfButton.textContent = 'Download provisional one-page PDF';
        const mark = document.createElement('div');
        mark.className = 'kau-provisional-mark';
        mark.style.cssText = 'font-size:10px;text-align:center;font-weight:bold;color:#9b1c1c';
        mark.textContent = 'PROVISIONAL — NOT APPROVED FOR PAYMENT OR SIGNATURE';
        document.getElementById('officialFormContainer')?.appendChild(mark);
    }
    if (!d.ready) {
        const block = document.createElement('p');
        block.className = 'mt-2 font-bold text-red-800 text-sm';
        block.textContent = 'NOT VALIDATED — unresolved blockers: ' + (d.blockers || []).join(' | ');
        content.appendChild(block);
    }
    if ((d.warnings || []).length) {
        const list = document.createElement('ul');
        list.className = 'text-xs list-disc pl-5 mt-2 space-y-1';
        for (const message of d.warnings) {
            const li = document.createElement('li');
            li.textContent = message;
            list.appendChild(li);
        }
        content.appendChild(list);
    }
    // Keep approval and evidence in the underlying immutable record, not as a
    // large extra heading on the official printout. Historical baseline
    // substitutions remain visible in the official form if they apply.
    if (d.baseline_period && d.baseline_period !== '2026-06') {
        const baselineNotice = document.createElement('div');
        baselineNotice.className = 'border border-amber-700 p-1 font-bold text-center text-amber-800 text-xs';
        baselineNotice.textContent = 'HISTORICAL BASELINE OVERRIDE: ' + d.baseline_period
            + ' (HR evidence: ' + textValue(d.manual_evidence || 'NOT PROVIDED') + ')';
        document.getElementById('officialFormContainer')?.prepend(baselineNotice);
    }

    const back = document.createElement('a');
    back.href = '/dashboard/salary/fixation';
    back.textContent = '← Back to salary fixation';
    back.className = 'inline-block mt-3 text-xs underline font-bold';
    content.appendChild(back);
    notice.appendChild(content);
    document.getElementById('mainContentWrapper')?.before(notice);

    // A source-of-truth calculation must agree with the browser demonstration.
    const discrepancies = [];
    for (const [id, expected] of [
        ['of_17', d.calculation.fitment_basic],
        ['of_23', d.calculation.phase_2026_07],
        ['of_27', d.calculation.phase_2027_01],
        ['of_29', d.calculation.phase_2027_07],
    ]) {
        const value = document.getElementById(id)?.textContent ?? '';
        const actual = Number(value.replace(/,/g, ''));
        if (Math.abs(actual - Number(expected)) > 0.001) discrepancies.push(id);
    }
    if (discrepancies.length) {
        const issue = document.createElement('p');
        issue.className = 'text-xs font-bold mt-2 text-red-700';
        issue.textContent = 'Calculation mismatch detected: ' + discrepancies.join(', ') + '. Do not use this estimate.';
        content.appendChild(issue);
        const mark = document.querySelector('#officialFormContainer .kau-provisional-mark');
        if (mark) mark.textContent = 'PROVISIONAL — CALCULATION REQUIRES REVIEW; NOT VALID FOR PAYMENT';
    }
});
