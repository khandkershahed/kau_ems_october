<script setup>
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { Head, Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import axios from "axios";
import Swal from "sweetalert2";
import { salaryProvisionalTotals } from "@/utils/salaryProvisionalTotals";
import { salaryStatementBreakdown } from "@/utils/salaryStatementBreakdown";
import { salaryDisplayAmount } from "@/utils/salaryDisplayAmount";

const props = defineProps({
    employee: { type: Object, required: true },
    period: { type: Object, required: true },
    effective: { type: Object, required: true },
    financial: { type: Object, default: null },
    baseline: { type: Object, default: null },
    additions: { type: Array, default: () => [] },
    deductions: { type: Array, default: () => [] },
    gpfOverride: { type: Object, default: null },
    saved: { type: Object, default: null },
    error: { type: String, default: null },
    componentOverrides: { type: Array, default: () => [] },
    componentOptions: { type: Object, default: () => ({ addition: [], deduction: [] }) },
    componentOverrideHash: { type: String, default: "" },
    arrearRows: { type: Array, default: () => [] },
    unpublishedArrearTemplates: { type: Array, default: () => [] },
    verifiedBank: { type: String, default: null },
    bankWarnings: { type: Array, default: () => [] },
});
const clone = (v) => JSON.parse(JSON.stringify(v));
const isBasic = (r) =>
    /\bbasic(?:\s+pay|\s+salary)?\b/i.test(String(r?.name || ""));
const isGpf = (r) =>
    /gpf|provident/i.test(
        String(r?.name || "") + " " + String(r?.component_type || ""),
    );
const normalize = (r) => ({
    ...r,
    calcType: r.calcType || "fixed",
    amount: Number(r.amount || 0),
});
const initialAdds = (props.additions || []).map(normalize);
const initialDeds = (props.deductions || [])
    .filter((r) => !isGpf(r))
    .map(normalize);
const additions = ref(clone(initialAdds)),
    deductions = ref(clone(initialDeds));
const configuredGpf = (props.deductions || []).find(isGpf);
// The server's actual resolved GPF rule is the initial UI source. In particular,
// legacy FIXED subscriptions must not be silently displayed as Default 10%.
const serverGpfRule = props.financial?.gpf_rule || null;
const initialHasGpf = props.gpfOverride?.type !== "disabled" &&
    (Boolean(configuredGpf) || serverGpfRule?.type !== "disabled");
const hasGpf = ref(initialHasGpf);
const initialGpfMode = !hasGpf.value
    ? "default"
    : props.gpfOverride?.type ||
      (serverGpfRule?.source === "default_10_percent_half_up"
          ? "default"
          : serverGpfRule?.type) ||
      configuredGpf?.calcType ||
      "default";
const initialGpfRate = Number(
    props.gpfOverride?.amount ??
        serverGpfRule?.amount ??
        configuredGpf?.amount ??
        10,
);
const gpfMode = ref(initialGpfMode);
const gpfAmount = ref(initialGpfRate);
const initialArrear = (props.arrearRows || []).map((r) => ({
    ...r,
    final_amount: r.final_amount ?? r.calculated_amount ?? null,
    months: Array.isArray(r.months) ? r.months : [],
    reason: r.reason || "",
    evidence_reference: r.evidence_reference || "",
    mode: r.mode || "fixed",
    formula: r.formula || "",
    error: r.error || null,
}));
const arrears = ref(clone(initialArrear));
const reason = ref(props.saved?.reason || ""),
    evidenceReference = ref(props.saved?.evidence_reference || "");
const version = ref(Number(props.saved?.version || 0)),
    status = ref(props.saved?.status || "unsaved");
const saving = ref(false),
    error = ref(props.error || ""),
    recalculating = ref(false),
    printVariant = ref("salary");
const money = salaryDisplayAmount;
const sourceAmount = (n) =>
    n === null || n === undefined ? "0.00 (unverified)" : money(n);
const arrearRuleDescription = (code) =>
    ({
        BASIC_ARREAR_GROSS:
            "New phased Basic minus June 2015 Basic, added for each eligible month",
        BASIC_INCREMENT_ADJUSTMENT:
            "Old-scale increment adjustment; deduct once, not again from paid Basic",
        SPECIAL_BENEFIT_RECOVERY:
            "Special Benefit actually received in July–September",
        HOUSE_RENT_RECOVERY:
            "House Rent entitlement and previous receipt reconciliation; signed difference",
        ADDITIONAL_GPF:
            "Additional applicable GPF minus verified earlier contribution",
    })[code] || "Employee-specific or custom arrear adjustment";
const monthName = computed(() =>
    new Date(props.period.year, props.period.month - 1, 1).toLocaleString(
        "en",
        { month: "long" },
    ),
);
const basicRows = computed(() => additions.value.filter(isBasic));
const basic = computed(() => Number(basicRows.value[0]?.amount || 0));
const halfUp = (n) => Math.floor(n + 0.5 + 1e-9);
const amountOf = (r) =>
    Math.round(
        (r.calcType === "percentage"
            ? (Number(r.percentage_base_amount ?? basic.value) *
                  Number(r.amount || 0)) /
              100
            : Number(r.amount || 0)) * 100,
    ) / 100;
const gpf = computed(() =>
    hasGpf.value
        ? halfUp(
              gpfMode.value === "fixed"
                  ? Number(gpfAmount.value)
                  : (Number(
                        configuredGpf?.percentage_base_amount ?? basic.value,
                    ) *
                        Number(
                            gpfMode.value === "default" ? 10 : gpfAmount.value,
                        )) /
                        100,
          )
        : 0,
);
const gross = computed(
    () =>
        Math.round(
            additions.value.reduce((sum, r) => sum + amountOf(r), 0) * 100,
        ) / 100,
);
const normalDeduction = computed(
    () =>
        Math.round(
            (deductions.value.reduce((sum, r) => sum + amountOf(r), 0) +
                gpf.value) *
                100,
        ) / 100,
);
const regularNet = computed(
    () => Math.round((gross.value - normalDeduction.value) * 100) / 100,
);
const arrearUnknown = computed(() =>
    arrears.value.filter(
        (r) =>
            r.final_amount === null ||
            r.final_amount === "" ||
            r.final_amount === undefined,
    ),
);
const arrearPayable = computed(
    () =>
        Math.round(
            arrears.value
                .filter(
                    (r) => r.direction === "payable" && r.final_amount !== null,
                )
                .reduce((sum, r) => sum + Number(r.final_amount || 0), 0) * 100,
        ) / 100,
);
const arrearDeduction = computed(
    () =>
        Math.round(
            arrears.value
                .filter(
                    (r) =>
                        r.direction === "deduction" && r.final_amount !== null,
                )
                .reduce((sum, r) => sum + Number(r.final_amount || 0), 0) * 100,
        ) / 100,
);
const arrearNet = computed(() =>
    arrearUnknown.value.length
        ? null
        : Math.round((arrearPayable.value - arrearDeduction.value) * 100) / 100,
);
// Display-only, *provisional* totals. Missing lines contribute no verified amount
// (displayed as 0.00), but remain null in the source snapshot and block posting.
// Never serialize these provisional totals as certified salary/payment amounts.
const displayTotals = computed(() =>
    salaryProvisionalTotals(gross.value, normalDeduction.value, arrears.value),
);
const grossTotal = computed(() => displayTotals.value.gross);
const deductionTotal = computed(() => displayTotals.value.deductions);
const netTotal = computed(() => displayTotals.value.net);
const financialIsProvisional = computed(() => displayTotals.value.provisional);
const statement = computed(() =>
    salaryStatementBreakdown(
        additions.value.map((r) => ({ ...r, calculated_amount: amountOf(r) })),
        [
            ...deductions.value.map((r) => ({
                ...r,
                calculated_amount: amountOf(r),
            })),
            ...(hasGpf.value
                ? [
                      {
                          name: "General Provident Fund (GPF)",
                          calculated_amount: gpf.value,
                          gpf_calculation_source:
                              serverGpfRule?.source || "manual_workspace",
                      },
                  ]
                : []),
        ],
        arrears.value,
    ),
);
const statementSummary = computed(() => statement.value.summary);
const validBasic = computed(
    () =>
        basicRows.value.length === 1 &&
        basic.value > 0 &&
        basicRows.value[0].calcType === "fixed" &&
        Number.isFinite(basic.value),
);
const validation = computed(() => {
    if (!validBasic.value)
        return "Exactly one positive fixed Basic Pay component is required.";
    if (
        [...additions.value, ...deductions.value].some(
            (r) =>
                !String(r.name || "").trim() ||
                !["fixed", "percentage"].includes(r.calcType) ||
                !Number.isFinite(amountOf(r)) ||
                Number(r.amount) < 0,
        )
    )
        return "A regular salary component has an invalid title, amount or type.";
    if (
        !Number.isFinite(gpf.value) ||
        gpf.value < 0 ||
        normalDeduction.value > gross.value
    )
        return "Check GPF and regular deductions against Gross.";
    if (
        arrears.value.some(
            (r) =>
                !String(r.title || "").trim() ||
                !["payable", "deduction"].includes(r.direction) ||
                !["fixed", "percentage", "formula"].includes(r.mode),
        )
    )
        return "Arrear component title, direction or mode is invalid.";
    if (
        arrears.value.some(
            (r) =>
                r.final_amount !== null &&
                (!Number.isFinite(Number(r.final_amount)) ||
                    Number(r.final_amount) < 0),
        )
    )
        return "An arrear final amount is invalid.";
    return "";
});
const snapshot = computed(() =>
    JSON.stringify({
        adds: additions.value,
        deds: deductions.value,
        gpf: hasGpf.value
            ? { mode: gpfMode.value, amount: gpfAmount.value }
            : "disabled",
        arrear: arrears.value,
        reason: reason.value,
        evidence: evidenceReference.value,
    }),
);
const baselineSnapshot = ref(snapshot.value),
    dirty = computed(() => snapshot.value !== baselineSnapshot.value);
const beforeUnload = (e) => {
    if (dirty.value) {
        e.preventDefault();
        e.returnValue = "";
    }
};
if (typeof window !== "undefined")
    window.addEventListener("beforeunload", beforeUnload);
onBeforeUnmount(() => {
    if (typeof window !== "undefined")
        window.removeEventListener("beforeunload", beforeUnload);
});
const reset = () => {
    additions.value = clone(initialAdds);
    deductions.value = clone(initialDeds);
    arrears.value = clone(initialArrear);
    hasGpf.value = initialHasGpf;
    gpfMode.value = initialGpfMode;
    gpfAmount.value = initialGpfRate;
    reason.value = props.saved?.reason || "";
    evidenceReference.value = props.saved?.evidence_reference || "";
    error.value = "";
};
const trashSvg = "M3 6h18M8 6V4h8v2m3 0-1 14H6L5 6m4 4v7m6-7v7";
const removeRegular = (dir, index) => {
    let list = dir === "addition" ? additions : deductions;
    if (dir === "addition" && isBasic(list.value[index])) return;
    list.value.splice(index, 1);
};
const newComponentSelection = ref({ addition: '', deduction: '' });
const quickAddComponent = (direction) => {
    const selected = Number(newComponentSelection.value[direction]);
    const option = (props.componentOptions?.[direction] || []).find((item) => Number(item.id) === selected);
    if (!option) return;
    const list = direction === 'addition' ? additions : deductions;
    const key = (item) => Number(item.payroll_component_type_id ?? item.payroll_component_type?.id ?? 0);
    if (list.value.some((item) => key(item) === selected ||
        String(item.name || '').trim().toLowerCase() === String(option.source_name || option.name).trim().toLowerCase())) {
        newComponentSelection.value[direction] = '';
        return;
    }
    list.value.push({
        name: option.source_name || option.name,
        payroll_component_type_id: option.id,
        calcType: option.calcType || 'fixed',
        amount: Number(option.amount ?? 0),
    });
    newComponentSelection.value[direction] = '';
};
const resetRegularRow = (direction, index) => {
    const list = direction === 'addition' ? additions : deductions;
    const original = (direction === 'addition' ? initialAdds : initialDeds).find((item) =>
        Number(item.payroll_component_type_id ?? item.payroll_component_type?.id ?? 0) > 0 &&
        Number(item.payroll_component_type_id ?? item.payroll_component_type?.id) ===
        Number(list.value[index]?.payroll_component_type_id ?? list.value[index]?.payroll_component_type?.id)) ||
        (direction === 'addition' ? initialAdds : initialDeds).find((item) =>
            item.name === list.value[index]?.name);
    if (original) list.value[index] = clone(original);
};
const addArrear = (dir) =>
    arrears.value.push({
        rule_id: null,
        title: "",
        direction: dir,
        mode: "fixed",
        formula: "",
        amount: 0,
        calculated_amount: null,
        // No evidence was entered yet: zero is never an assumed verified value.
        final_amount: null,
        months: [],
        reason: "",
        evidence_reference: "",
        error: null,
    });
const removeArrear = (i) => arrears.value.splice(i, 1);
const overridden = (r) =>
    r.calculated_amount === null ||
    r.calculated_amount === undefined ||
    Math.abs(Number(r.final_amount || 0) - Number(r.calculated_amount || 0)) >
        0.009;
const recalculateArrear = async () => {
    recalculating.value = true;
    error.value = "";
    try {
        const rows = arrears.value.map((r) => ({
            code: r.code || null,
            title: r.title,
            direction: r.direction,
            mode: r.mode,
            formula: r.formula || null,
            amount:
                r.mode === "fixed"
                    ? Number(r.final_amount || 0)
                    : Number(r.amount || 0),
            selected_months: r.months || [],
            start_month: null,
            end_month: null,
        }));
        const response = await axios.post(
            route("salary-calculator.arrear-preview", {
                user: props.employee.id,
            }),
            { year: props.period.year, month: props.period.month, rows },
        );
        if (!Array.isArray(response.data?.rows) || response.data.rows.length !== arrears.value.length ||
            response.data.rows.some((next, i) => String(next.code || '') !== String(arrears.value[i]?.code || ''))) {
            throw new Error('Arrear response did not match current component identities. No amounts were updated.');
        }
        response.data.rows.forEach((next, i) => {
            const was = arrears.value[i];
            const previous = was.calculated_amount;
            const custom =
                was.final_amount !== null &&
                was.final_amount !== "" &&
                (previous === null ||
                    Math.abs(Number(was.final_amount) - Number(previous)) >
                        0.009);
            was.calculated_amount = next.calculated_amount;
            was.error = next.error;
            was.month_sources = next.month_sources;
            was.direction = next.direction;
            was.estimated_amount = next.estimated_amount ?? null;
            was.payment_reference_status =
                next.payment_reference_status ?? null;
            was.old_scale_reference = next.old_scale_reference ?? null;
            was.missing_paid_periods = next.missing_paid_periods || [];
            was.baseline_warning = next.baseline_warning || null;
            was.old_scale_stage_reference =
                next.old_scale_stage_reference || null;
            if (!custom) was.final_amount = next.calculated_amount;
        });
    } catch (e) {
        error.value =
            e.response?.data?.message || e.message ||
            "Unable to recalculate arrear formula.";
    } finally {
        recalculating.value = false;
    }
};
const save = async (selectedStatus) => {
    // Draft is an editable workspace. Financial validation applies to approval/posting.
    if (selectedStatus === "approved" && validation.value) {
        error.value = validation.value;
        return;
    }
    if (selectedStatus === "approved" && arrearUnknown.value.length) {
        error.value =
            "Some arrear amounts remain unverified. Supply documented values or unpublish the rule before approving.";
        return;
    }
    saving.value = true;
    error.value = "";
    try {
        const payload = {
            year: props.period.year,
            month: props.period.month,
            status: selectedStatus,
            expected_version: version.value,
            component_override_hash: props.componentOverrideHash,
            additions: additions.value.map((r) => ({
                ...r,
                amount: Number(r.amount),
            })),
            deductions: deductions.value.map((r) => ({
                ...r,
                amount: Number(r.amount),
            })),
            gpf_override: hasGpf.value
                ? configuredGpf?.component_source ===
                      "employee_month_override" || gpfMode.value === "default"
                    ? null
                    : { type: gpfMode.value, amount: Number(gpfAmount.value) }
                : { type: "disabled", amount: 0 },
            arrear_rows: arrears.value.map((r) => ({
                rule_id: r.rule_id,
                code: r.code || null,
                title: r.title,
                direction: r.direction,
                mode: r.mode,
                formula: r.formula || null,
                calculated_amount:
                    r.calculated_amount === null
                        ? null
                        : Number(r.calculated_amount),
                final_amount:
                    r.final_amount === null ? null : Number(r.final_amount),
                reason: r.reason || null,
                evidence_reference: r.evidence_reference || null,
                months: r.months || [],
                error: r.error || null,
            })),
            reason: reason.value,
            evidence_reference: evidenceReference.value,
        };
        const response = await axios.post(
            route("salary-calculator.versions.store", {
                user: props.employee.id,
            }),
            payload,
        );
        version.value = Number(response.data.version);
        status.value = response.data.status;
        baselineSnapshot.value = snapshot.value;
        Swal.fire({
            icon: "success",
            title: "Calculation saved",
            text: response.data.message,
            timer: 2400,
        });
    } catch (e) {
        error.value =
            e.response?.data?.message ||
            Object.values(e.response?.data?.errors || {})
                .flat()
                .join(" · ") ||
            "Unable to save calculation.";
    } finally {
        saving.value = false;
    }
};
const regularOnlyLoading = ref(false);
const regularOnlyResult = ref(null);
const showRegularOnly = async () => {
    regularOnlyLoading.value = true;
    regularOnlyResult.value = null;
    error.value = '';
    try {
        const response = await axios.get(route('salary-calculator.regular-only-preview', {
            user: props.employee.id,
        }), { params: { year: props.period.year, month: props.period.month } });
        regularOnlyResult.value = response.data;
    } catch (e) {
        error.value = e.response?.data?.message || 'Unable to load server regular-only preview.';
    } finally {
        regularOnlyLoading.value = false;
    }
};
const posting = ref(false);
const generateBill = async () => {
    if (validation.value || arrearUnknown.value.length) {
        error.value =
            validation.value ||
            "Arrear amounts are unverified; provide verified values before posting.";
        return;
    }
    if (dirty.value) {
        error.value =
            "Save Draft first so the immutable server source matches this screen.";
        return;
    }
    const answer = await Swal.fire({
        title: "Generate Salary Bill?",
        text: "The server recalculates current fixation, published arrears, bank account and GPF. An existing bill will never be overwritten.",
        icon: "warning",
        showCancelButton: true,
        confirmButtonText: "Generate Bill",
    });
    if (!answer.isConfirmed) return;
    posting.value = true;
    error.value = "";
    try {
        const response = await axios.post(
            route("salary-calculator.generate-bill", {
                user: props.employee.id,
            }),
            {
                year: props.period.year,
                month: props.period.month,
                expected_version: version.value,
            },
        );
        status.value =
            response.data.result === "generated"
                ? "bill_generated"
                : status.value;
        Swal.fire({
            icon: "success",
            title:
                response.data.result === "generated"
                    ? "Salary Bill Generated"
                    : "Existing Bill Preserved",
            text: `Bill #${response.data.bill_id}, Net ৳${money(response.data.net_salary)}`,
        });
    } catch (e) {
        error.value =
            e.response?.data?.message ||
            Object.values(e.response?.data?.errors || {})
                .flat()
                .join(" · ") ||
            "Salary posting failed.";
    } finally {
        posting.value = false;
    }
};
const csvDownload = () => {
    const quote = (v) =>
        '"' +
        String(
            v === null || v === undefined ? "0.00 (unverified)" : v,
        ).replaceAll('"', '""') +
        '"';
    const rows = [
        [
            "Financial status",
            financialIsProvisional.value
                ? "PROVISIONAL ONLY - UNVERIFIED ARREARS EXCLUDED, NOT PAYABLE"
                : "SOURCE COMPLETE - STILL NOT PROOF OF PAYMENT",
        ],
        [
            "Unverified arrear item count",
            statement.value.unknown_arrears.length,
        ],
        ["Employee", props.employee.name],
        ["Code", props.employee.code],
        ["Month", `${monthName.value} ${props.period.year}`],
        ["Scale", props.effective.pay_scale],
        ["Type", "Title", "Current", "Arrear", "Total", "Source Status"],
        ...statement.value.allowances.map((r) => [
            "Addition",
            r.title,
            r.current,
            r.arrear,
            r.total,
            r.unknown ? "MISSING_EVIDENCE" : "SOURCE_PRESENT",
        ]),
        ...statement.value.deductions.map((r) => [
            "Deduction",
            r.title,
            r.current,
            r.arrear,
            r.total,
            r.unknown ? "MISSING_EVIDENCE" : "SOURCE_PRESENT",
        ]),
        [
            "Gross",
            statementSummary.value.current.gross,
            statementSummary.value.arrear.gross,
            statementSummary.value.total.gross,
            financialIsProvisional.value ? "PROVISIONAL" : "SOURCE_PRESENT",
        ],
        [
            "Deductions",
            statementSummary.value.current.deductions,
            statementSummary.value.arrear.deductions,
            statementSummary.value.total.deductions,
            financialIsProvisional.value ? "PROVISIONAL" : "SOURCE_PRESENT",
        ],
        [
            "Net",
            statementSummary.value.current.net,
            statementSummary.value.arrear.net,
            statementSummary.value.total.net,
            financialIsProvisional.value ? "PROVISIONAL" : "SOURCE_PRESENT",
        ],
    ];
    const blob = new Blob(
        ["\ufeff" + rows.map((r) => r.map(quote).join(",")).join("\r\n")],
        { type: "text/csv;charset=utf-8" },
    );
    const url = URL.createObjectURL(blob),
        a = document.createElement("a");
    a.href = url;
    a.download = `KAU_Salary_${props.employee.code}_${props.period.year}_${props.period.month}.csv`;
    a.click();
    URL.revokeObjectURL(url);
};
const wordUnder1000 = (n) => {
    const one = [
        "zero",
        "one",
        "two",
        "three",
        "four",
        "five",
        "six",
        "seven",
        "eight",
        "nine",
        "ten",
        "eleven",
        "twelve",
        "thirteen",
        "fourteen",
        "fifteen",
        "sixteen",
        "seventeen",
        "eighteen",
        "nineteen",
    ];
    const tens = [
        "",
        "",
        "twenty",
        "thirty",
        "forty",
        "fifty",
        "sixty",
        "seventy",
        "eighty",
        "ninety",
    ];
    const bits = [];
    if (n >= 100) {
        bits.push(one[Math.floor(n / 100)] + " hundred");
        n %= 100;
    }
    if (n >= 20) {
        bits.push(tens[Math.floor(n / 10)] + (n % 10 ? " " + one[n % 10] : ""));
    } else if (n > 0) bits.push(one[n]);
    return bits.join(" ");
};
const amountWords = computed(() => {
    if (financialIsProvisional.value)
        return "Provisional estimate — missing arrear sources";
    const n = Math.floor(netTotal.value);
    if (!n) return "Zero Taka only";
    const levels = [
        [10000000, "crore"],
        [100000, "lakh"],
        [1000, "thousand"],
        [1, ""],
    ];
    let remain = n,
        out = [];
    for (const [unit, label] of levels) {
        const part = Math.floor(remain / unit);
        if (part) {
            out.push(wordUnder1000(part) + (label ? " " + label : ""));
            remain %= unit;
        }
    }
    return "Taka " + out.join(" ") + " only";
});
const printAs = (variant) => {
    if (validation.value) {
        error.value = validation.value;
        return;
    }
    printVariant.value = variant;
    setTimeout(() => window.print(), 50);
};
// Step 03: replaces one regular employee component for the selected month only.
const componentEditor = ref(null),
    overrideSaving = ref(false);
const componentSearch = ref("");
const filteredMasterOptions = computed(() => {
    const direction = componentEditor.value?.direction;
    const options = props.componentOptions?.[direction] || [];
    const q = componentSearch.value.trim().toLowerCase();
    return options.filter((option) =>
        !q || String(option.name).toLowerCase().includes(q) ||
        String(option.id) === q ||
        Number(option.id) === Number(componentEditor.value?.payroll_component_type_id),
    );
});
const selectOverrideMaster = (event) => {
    const id = Number(event.target.value);
    if (!id || !componentEditor.value) return;
    const direction = componentEditor.value.direction;
    const master = (props.componentOptions?.[direction] || []).find((r) => Number(r.id) === id);
    if (!master) return;
    const row = (direction === 'addition' ? additions.value : deductions.value)
        .find((r) => Number(r.payroll_component_type_id ?? r.payroll_component_type?.id) === id ||
            (!Number(r.payroll_component_type_id ?? r.payroll_component_type?.id) &&
                String(r.name).trim().toLowerCase() === String(master.source_name).trim().toLowerCase()));
    if (!row) {
        error.value = 'Selected component is not effective for this employee and direction.';
        return;
    }
    editComponent({ ...row, name: master.name, payroll_component_type_id: id }, direction, false);
};
const overrideKey = (r, dir) => {
    const id = Number(
        r.payroll_component_type_id ?? r.payroll_component_type?.id ?? 0,
    );
    return id > 0
        ? `type:${id}`
        : `legacy:${dir}:${r.name.trim().toLowerCase()}`;
};
const matchingOverride = (r, dir) => {
    const id = Number(
        r.payroll_component_type_id ?? r.payroll_component_type?.id ?? 0,
    );
    return props.componentOverrides.find(
        (o) =>
            o.direction === dir &&
            ((id > 0 && Number(o.payroll_component_type_id) === id) ||
                (id === 0 &&
                    o.component_title.trim().toLowerCase() ===
                        r.name.trim().toLowerCase())),
    );
};
const editComponent = (r, dir, custom = false) => {
    if (isBasic(r)) return;
    componentSearch.value = '';
    // New regular rows must select an existing configured component, not invent a name.
    if (!String(r?.name || '').trim()) custom = false;
    custom =
        custom || String(r?.override_component_key || "").startsWith("custom:");
    const old = custom ? null : matchingOverride(r, dir);
    const rowId = Number(r.payroll_component_type_id ?? r.payroll_component_type?.id ?? 0);
    const option = (props.componentOptions?.[dir] || []).find((x) =>
        Number(x.id) === rowId || (rowId === 0 &&
            String(x.source_name).trim().toLowerCase() === String(r.name).trim().toLowerCase()));
    // Keep existing legacy override identity until reconciled, never auto-upgrade.
    const masterId = old && !old.payroll_component_type_id ? null : (rowId || option?.id || null);
    componentEditor.value = {
        direction: dir,
        component_name: masterId ? (option?.name || r.name || '') : (r.name || ''),
        payroll_component_type_id: custom ? null : masterId,
        custom,
        mode: old?.mode || "manual",
        calc_type: old?.calc_type || r.calcType || "fixed",
        value: Number(old?.value ?? r.amount ?? 0),
        basis:
            old?.basis ||
            (r.percentage_base_amount
                ? "legacy_basic"
                : r.calcType === "percentage"
                  ? "payable_basic"
                  : "none"),
        legacy_basic_reference:
            old?.legacy_basic_reference ?? r.percentage_base_amount ?? null,
        reason: old?.reason || "",
        evidence_reference: old?.evidence_reference || "",
        expected_revision: Number(old?.revision || 0),
        // Show the live effective rate, not a possibly stale legacy default_amount.
        // Once an override has been applied, do not infer its pre-override rate.
        configured_calc_type: r.component_source === 'employee_month_override' ? null : r.calcType ?? null,
        configured_value: r.component_source === 'employee_month_override' ? null : r.amount ?? null,
    };
};
const savedDisabled = computed(() =>
    props.componentOverrides.filter((o) => o.mode === "disabled"),
);
const openDisabled = (o) => {
    componentEditor.value = {
        direction: o.direction,
        component_name: o.component_title,
        payroll_component_type_id: o.payroll_component_type_id,
        custom: String(o.component_key).startsWith("custom:"),
        mode: "automatic",
        calc_type: "fixed",
        value: 0,
        basis: "none",
        legacy_basic_reference: null,
        reason: o.reason || "",
        evidence_reference: o.evidence_reference || "",
        expected_revision: Number(o.revision || 0),
    };
};
const saveComponentOverride = async () => {
    if (!componentEditor.value || overrideSaving.value) return;
    overrideSaving.value = true;
    error.value = "";
    try {
        const v = componentEditor.value;
        const response = await axios.post(
            route("salary-calculator.component-override", {
                user: props.employee.id,
            }),
            {
                ...v,
                year: props.period.year,
                month: props.period.month,
                custom: !!v.custom,
                value: v.mode === "manual" ? Number(v.value) : null,
                calc_type: v.mode === "manual" ? v.calc_type : null,
                basis:
                    v.mode === "manual"
                        ? v.calc_type === "fixed"
                            ? "none"
                            : v.basis
                        : null,
                legacy_basic_reference:
                    v.mode === "manual" && v.basis === "legacy_basic"
                        ? Number(v.legacy_basic_reference)
                        : null,
            },
        );
        componentEditor.value = null;
        await Swal.fire({
            icon: "success",
            title: "Component override saved",
            text: response.data.message,
        });
        // Fetch authoritative server projection; never let stale saved snapshots mask the new override.
        // The quick editor does not navigate away from the current workspace.
        // The saved override will be reflected after the next authoritative page load.

    } catch (e) {
        error.value =
            e.response?.data?.message ||
            Object.values(e.response?.data?.errors || {})
                .flat()
                .join(" · ") ||
            "Unable to save override.";
    } finally {
        overrideSaving.value = false;
    }
};
</script>

<template>
    <Head title="Employee Salary Calculation Workspace" />
    <AuthenticatedLayout>
        <div
            class="max-w-screen-2xl mx-auto px-4 py-5 space-y-4 text-slate-800"
            id="calculator-screen"
        >
            <header
                class="rounded-xl border bg-white p-5 flex justify-between flex-wrap gap-2 items-start"
            >
                <div>
                    <h1 class="text-2xl font-bold">
                        Employee Salary Calculation Workspace
                    </h1>
                    <p class="text-sm text-slate-500">
                        {{ employee.name }} · #{{ employee.code }} ·
                        {{ monthName }} {{ period.year }} · v{{
                            version || "new"
                        }}
                    </p>
                </div>
                <div class="flex gap-2 text-xs">
                    <span
                        class="rounded-full px-3 py-1 bg-slate-100 font-bold uppercase"
                        >{{ status }}</span
                    ><span v-if="dirty" class="text-amber-600 font-semibold"
                        >Unsaved Changes</span
                    ><Link :href="route('salary-setup.index')" class="underline"
                        >Back to Salary Estimations</Link
                    >
                </div>
            </header>
            <div
                class="sticky top-12 z-20 rounded-xl border bg-white/95 shadow-sm p-3 flex flex-wrap gap-2 items-center"
            >
                <button
                    :disabled="saving"
                    @click="save('draft')"
                    class="px-4 py-2 rounded bg-emerald-700 text-white disabled:opacity-40"
                >
                    Save Draft
                </button>
                <span v-if="regularOnlyResult" role="status" class="text-xs px-2 py-1 rounded border border-teal-300 bg-teal-50 text-teal-950">
                    REGULAR ONLY / READ-ONLY · {{ regularOnlyResult.status }} ·
                    Gross ৳{{ regularOnlyResult.regular_gross === null ? '0.00 (unverified)' : money(regularOnlyResult.regular_gross) }} ·
                    Deduction ৳{{ regularOnlyResult.regular_deductions === null ? '0.00 (unverified)' : money(regularOnlyResult.regular_deductions) }} ·
                    Net ৳{{ regularOnlyResult.regular_net === null ? '0.00 (unverified)' : money(regularOnlyResult.regular_net) }}
                    <span v-if="regularOnlyResult.blocking_reasons?.length"> · {{ regularOnlyResult.blocking_reasons.join('; ') }}</span>
                    · No bill, arrear or GPF posted.
                </span>
                <button
                    :disabled="saving || !!validation || !!arrearUnknown.length"
                    @click="save('approved')"
                    class="px-4 py-2 rounded bg-blue-700 text-white disabled:opacity-40"
                >
                    Approve Now
                </button>
                <button
                    :disabled="
                        saving ||
                        posting ||
                        !!validation ||
                        !!arrearUnknown.length ||
                        dirty
                    "
                    @click="generateBill"
                    class="px-4 py-2 rounded bg-indigo-800 text-white disabled:opacity-40"
                    title="Posts server-verified salary and GPF once"
                >
                    {{ posting ? "Generating..." : "Generate Bill" }}
                </button>
                <button type="button" :disabled="regularOnlyLoading" @click="showRegularOnly"
                    class="px-4 py-2 rounded border border-teal-600 text-teal-800 disabled:opacity-40">
                    {{ regularOnlyLoading ? 'Checking regular salary…' : 'Preview Regular Only' }}
                </button>
                <button
                    @click="csvDownload"
                    :disabled="!!validation"
                    class="px-4 py-2 rounded border disabled:opacity-40"
                >
                    Download Excel/CSV
                </button>
                <button
                    @click="printAs('salary')"
                    class="px-4 py-2 rounded border"
                >
                    Salary Bill-style PDF
                </button>
                <button
                    @click="printAs('arrear')"
                    class="px-4 py-2 rounded border"
                >
                    Arrear Statement PDF
                </button>
                <button
                    @click="reset"
                    :disabled="!dirty"
                    class="px-4 py-2 rounded border disabled:opacity-40"
                >
                    Discard Changes
                </button>
                <Link
                    :href="route('salary-arrear-settings.index')"
                    class="px-4 py-2 rounded border text-emerald-700"
                    >Payroll & Arrear Settings</Link
                >
            </div>
            <div
                v-if="error || validation"
                class="rounded-lg border border-rose-300 p-3 bg-rose-50 text-sm text-rose-800"
            >
                {{ error || validation }}
            </div>
            <section class="grid sm:grid-cols-2 lg:grid-cols-5 gap-3">
                <div
                    v-for="r in [
                        ['Pay Scale', effective.pay_scale],
                        ['Grade', effective.grade],
                        ['Approved / Existing Basic', money(effective.basic)],
                        [
                            'Effective Date',
                            effective.effective_date || 'Legacy',
                        ],
                        [
                            'Fixation Reference',
                            effective.fixation_id
                                ? '#' + effective.fixation_id
                                : '2015',
                        ],
                    ]"
                    :key="r[0]"
                    class="bg-white border rounded-lg p-3"
                >
                    <p class="text-xs text-slate-500">{{ r[0] }}</p>
                    <p class="font-semibold">{{ r[1] }}</p>
                </div>
            </section>
            <section class="grid lg:grid-cols-2 gap-4" aria-label="Quick salary components editor">
                <div v-for="direction in ['addition', 'deduction']" :key="direction"
                    class="rounded-xl border bg-white overflow-hidden"
                    :class="direction === 'addition' ? 'border-emerald-300' : 'border-rose-300'">
                    <div class="flex items-center justify-between px-4 py-3 font-bold"
                        :class="direction === 'addition' ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-800'">
                        <h2>{{ direction === 'addition' ? '+ Configured Allowances / Additions' : '− Configured Deductions' }}</h2>
                        <span>৳{{ money(direction === 'addition' ? gross : normalDeduction) }}</span>
                    </div>
                    <div class="space-y-2 p-3">
                        <div v-for="(row, index) in (direction === 'addition' ? additions : deductions)" :key="index"
                            class="rounded-lg border border-slate-200 p-3 space-y-2">
                            <input v-model="row.name" type="text" maxlength="160"
                                :readonly="direction === 'addition' && isBasic(row)"
                                aria-label="Component title"
                                class="w-full rounded-lg border-slate-300 text-sm font-medium" placeholder="Component title" />
                            <div class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] gap-2 items-center">
                                <select v-model="row.calcType" :disabled="direction === 'addition' && isBasic(row)"
                                    aria-label="Calculation type" class="w-full rounded-lg border-slate-300 text-sm">
                                    <option value="fixed">Fixed</option><option value="percentage">Percentage (%)</option>
                                </select>
                                <input v-model.number="row.amount" type="number" step="0.01" min="0"
                                    aria-label="Component value" class="w-full rounded-lg border-slate-300 text-right text-sm" />
                                <p v-if="direction === 'addition' && isBasic(row) && Math.abs(Number(row.amount) - Number(effective.basic || 0)) > 0.009"
                                    class="col-span-3 text-xs text-amber-700">
                                    HR Basic override: approved fixation Basic ৳{{ money(Number(effective.basic || 0)) }}. Enter revision reason and evidence reference below before approval.
                                </p>
                                <div class="flex gap-1 items-center">
                                    <button type="button" @click="resetRegularRow(direction, index)"
                                        :disabled="direction === 'addition' && isBasic(row)" title="Reset to initial value"
                                        class="rounded border border-slate-200 px-2 py-2 text-slate-600 disabled:opacity-30" aria-label="Reset component">↺</button>
                                    <button type="button" @click="removeRegular(direction, index)"
                                        :disabled="direction === 'addition' && isBasic(row)" title="Delete component"
                                        class="rounded border border-rose-200 px-2 py-2 text-rose-700 disabled:opacity-30" aria-label="Delete component">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path :d="trashSvg" /></svg>
                                    </button>
                                </div>
                            </div>
                            <div class="text-xs text-right" :class="direction === 'addition' ? 'text-emerald-700' : 'text-rose-700'">
                                Calculated: ৳{{ money(amountOf(row)) }}
                            </div>
                        </div>
                        <div v-if="direction === 'deduction' && hasGpf" class="rounded-lg border border-rose-200 bg-rose-50 p-3 space-y-2">
                            <input type="text" value="General Provident Fund (GPF)" readonly class="w-full rounded border-slate-300 text-sm bg-white" />
                            <div class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] gap-2 items-center">
                                <select v-model="gpfMode" aria-label="GPF calculation type" class="w-full rounded border-slate-300 text-sm">
                                    <option value="default">Default 10%</option><option value="percentage">Percentage (%)</option><option value="fixed">Fixed</option>
                                </select>
                                <input v-model.number="gpfAmount" :disabled="gpfMode === 'default'" type="number" min="0" step="0.01"
                                    aria-label="GPF value" class="w-full rounded border-slate-300 text-right text-sm" />
                                <button type="button" @click="hasGpf = false" title="Remove GPF" aria-label="Remove GPF"
                                    class="rounded border border-rose-200 px-2 py-2 text-rose-700">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path :d="trashSvg" /></svg>
                                </button>
                            </div>
                            <div class="text-xs text-right text-rose-700">Calculated: ৳{{ money(gpf) }}</div>
                        </div>
                        <button v-if="direction === 'deduction' && !hasGpf" type="button" @click="hasGpf = true"
                            class="text-sm underline text-rose-800">+ Restore GPF</button>
                        <div class="flex flex-wrap items-center gap-2 pt-2 border-t">
                            <select v-model="newComponentSelection[direction]" :aria-label="'Add ' + direction + ' component'"
                                class="flex-1 min-w-40 rounded-lg border-slate-300 text-sm">
                                <option value="">Select saved component…</option>
                                <option v-for="option in (componentOptions?.[direction] || [])" :key="option.id"
                                    :value="String(option.id)">{{ option.name }}</option>
                            </select>
                            <button type="button" @click="quickAddComponent(direction)" :disabled="!newComponentSelection[direction]"
                                class="rounded-lg px-4 py-2 text-sm font-medium text-white disabled:opacity-40"
                                :class="direction === 'addition' ? 'bg-emerald-700' : 'bg-rose-700'">+ Add</button>
                        </div>
                    </div>
                </div>
            </section>
            <section class="rounded-xl border bg-white p-4 space-y-3">
                <div class="flex justify-between items-center flex-wrap gap-2">
                    <div>
                        <h2 class="text-lg font-bold">
                            Arrear Payable & Deductions
                        </h2>
                    </div>
                    <button
                        @click="recalculateArrear"
                        :disabled="recalculating"
                        class="border rounded px-3 py-2"
                    >
                        Refresh automatic arrear
                    </button>
                </div>
                <div class="grid lg:grid-cols-2 gap-4">
                    <div
                        v-for="dir in ['payable', 'deduction']"
                        :key="dir"
                        :class="
                            dir === 'payable'
                                ? 'border-emerald-200'
                                : 'border-rose-200'
                        "
                        class="border rounded-lg overflow-hidden"
                    >
                        <h3
                            :class="
                                dir === 'payable'
                                    ? 'text-emerald-800 bg-emerald-50'
                                    : 'text-rose-800 bg-rose-50'
                            "
                            class="p-3 font-semibold"
                        >
                            {{
                                dir === "payable"
                                    ? "+ Arrear Payable"
                                    : "− Arrear Deductions"
                            }}
                            · ৳{{
                                money(
                                    dir === "payable"
                                        ? arrearPayable
                                        : arrearDeduction,
                                )
                            }}
                        </h3>
                        <div v-for="(r, i) in arrears" :key="i">
                            <div
                                v-if="r.direction === dir"
                                class="border-t p-3 space-y-2 text-xs"
                            >
                                <input v-model="r.title" type="text" maxlength="160"
                                    class="w-full rounded-lg border-slate-300 text-sm" placeholder="Arrear component title" />
                                <div class="flex items-center gap-2">
                                    <input v-model.number="r.final_amount" type="number" min="0" step="0.01"
                                        aria-label="Arrear amount" class="flex-1 min-w-0 rounded-lg border-slate-300 text-sm text-right"
                                        placeholder="Amount (BDT)" />
                                    <div v-if="overridden(r)" class="w-full grid grid-cols-1 md:grid-cols-2 gap-2">
                                        <input v-model="r.reason" type="text" maxlength="2000" class="rounded-lg border-slate-300 text-sm"
                                            aria-label="Arrear manual override reason" placeholder="Arrear override reason" />
                                        <input v-model="r.evidence_reference" type="text" maxlength="500" class="rounded-lg border-slate-300 text-sm"
                                            aria-label="Arrear evidence reference" placeholder="Evidence reference / HR authorization" />
                                    </div>
                                    <button type="button" @click="removeArrear(i)" title="Delete arrear component"
                                        aria-label="Delete arrear component" class="rounded border border-rose-200 px-3 py-2 text-rose-700">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path :d="trashSvg" /></svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <button
                            @click="addArrear(dir)"
                            class="m-3 px-3 py-2 rounded text-sm"
                            :class="
                                dir === 'payable'
                                    ? 'bg-emerald-50 text-emerald-800'
                                    : 'bg-rose-50 text-rose-800'
                            "
                        >
                            + Add Arrear
                            {{ dir === "payable" ? "Payable" : "Deduction" }}
                        </button>
                    </div>
                </div>
            </section>
            <section
                class="rounded-xl border bg-white p-4 space-y-2"
                aria-label="Unified salary statement preview"
            >
                <div class="flex justify-between items-center flex-wrap gap-2">
                    <h2 class="font-semibold">
                        Current / Arrear / Total — Unified Salary Preview
                    </h2>
                    <span class="text-xs text-slate-500"
                        >Same breakdown used by CSV and printable preview</span
                    >
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm border-collapse">
                        <thead class="bg-slate-100">
                            <tr>
                                <th class="border p-2 text-left">Component</th>
                                <th class="border p-2 text-right">Current</th>
                                <th class="border p-2 text-right">Arrear</th>
                                <th class="border p-2 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <th
                                    colspan="4"
                                    class="border p-2 text-left bg-emerald-50"
                                >
                                    Additions / Allowances
                                </th>
                            </tr>
                            <tr
                                v-for="(row, i) in statement.allowances"
                                :key="'stmt-a-' + i"
                            >
                                <td class="border p-2">{{ row.title }}</td>
                                <td class="border p-2 text-right">
                                    {{ money(row.current) }}
                                </td>
                                <td
                                    class="border p-2 text-right"
                                    :class="
                                        row.unknown
                                            ? 'text-amber-700 font-semibold'
                                            : ''
                                    "
                                >
                                    {{ money(row.arrear) }}
                                </td>
                                <td class="border p-2 text-right">
                                    {{ money(row.total) }}
                                </td>
                            </tr>
                            <tr class="bg-emerald-50 font-semibold">
                                <td class="border p-2">Gross Salary</td>
                                <td class="border p-2 text-right">
                                    {{ money(statementSummary.current.gross) }}
                                </td>
                                <td class="border p-2 text-right">
                                    {{ money(statementSummary.arrear.gross) }}
                                </td>
                                <td class="border p-2 text-right">
                                    {{ money(statementSummary.total.gross) }}
                                </td>
                            </tr>
                            <tr>
                                <th
                                    colspan="4"
                                    class="border p-2 text-left bg-rose-50"
                                >
                                    Deductions / Recoveries
                                </th>
                            </tr>
                            <tr
                                v-for="(row, i) in statement.deductions"
                                :key="'stmt-d-' + i"
                            >
                                <td class="border p-2">{{ row.title }}</td>
                                <td class="border p-2 text-right">
                                    {{ money(row.current) }}
                                </td>
                                <td
                                    class="border p-2 text-right"
                                    :class="
                                        row.unknown
                                            ? 'text-amber-700 font-semibold'
                                            : ''
                                    "
                                >
                                    {{ money(row.arrear) }}
                                </td>
                                <td class="border p-2 text-right">
                                    {{ money(row.total) }}
                                </td>
                            </tr>
                            <tr class="bg-rose-50 font-semibold">
                                <td class="border p-2">Total Deductions</td>
                                <td class="border p-2 text-right">
                                    {{
                                        money(
                                            statementSummary.current.deductions,
                                        )
                                    }}
                                </td>
                                <td class="border p-2 text-right">
                                    {{
                                        money(
                                            statementSummary.arrear.deductions,
                                        )
                                    }}
                                </td>
                                <td class="border p-2 text-right">
                                    {{
                                        money(statementSummary.total.deductions)
                                    }}
                                </td>
                            </tr>
                            <tr class="bg-slate-100 font-bold">
                                <td class="border p-2">Net Salary / Payable</td>
                                <td class="border p-2 text-right">
                                    {{ money(statementSummary.current.net) }}
                                </td>
                                <td class="border p-2 text-right">
                                    {{ money(statementSummary.arrear.net) }}
                                </td>
                                <td class="border p-2 text-right">
                                    {{ money(statementSummary.total.net) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p
                    v-if="statement.provisional"
                    class="text-xs text-amber-800 font-semibold"
                >
                    Unverified arrear evidence:
                    {{ statement.unknown_arrears.join(", ") }}. Provisional
                    display totals are not a payable balance.
                </p>
            </section>
            <div
                v-if="financialIsProvisional"
                class="rounded border border-sky-200 bg-sky-50 text-sky-900 p-3 text-sm"
                role="alert"
            >
                <strong>PROVISIONAL DISPLAY ONLY — not payable:</strong> Regular
                Gross ৳{{ money(gross) }}, Regular Deductions ৳{{
                    money(normalDeduction)
                }}, Regular Net ৳{{ money(regularNet) }}.
                {{ arrearUnknown.length }} unverified arrear item(s) are
                displayed with a provisional contribution of ৳0.00 in the
                summary, not treated as confirmed zero payments. Provide
                documented final amounts (including a documented zero where
                genuinely applicable) or unpublish inapplicable rules before
                approval/bill generation.
            </div>
            <section class="grid lg:grid-cols-4 gap-3">
                <div
                    v-for="r in [
                        ['Basic', basic],
                        ['Gross (Current + Arrear)', grossTotal],
                        ['Total Deductions', deductionTotal],
                        ['Net Salary / Payable', netTotal],
                    ]"
                    :key="r[0]"
                    class="bg-white border rounded-xl p-4"
                >
                    <p class="text-xs text-slate-500">{{ r[0] }}</p>
                    <p class="text-xl font-bold">৳{{ money(r[1]) }}</p>
                    <p
                        v-if="financialIsProvisional && r[0] !== 'Basic'"
                        class="text-amber-700 text-xs font-semibold"
                    >
                        Provisional · unverified arrears excluded
                    </p>
                </div>
            </section>
            <div
                v-if="arrearUnknown.length"
                class="bg-amber-50 border border-amber-200 p-3 rounded text-sm"
            >
                {{ arrearUnknown.length }} arrear amount(s) lack verified
                payment sources. Their summary contributions appear as ৳0.00
                <strong>for display only</strong>; the official payable amount
                remains unverified and bill generation stays blocked.
            </div>
            <section
                class="rounded-xl bg-white border p-4 grid md:grid-cols-2 gap-3"
            >
                <label class="text-xs"
                    >Calculation revision reason<textarea
                        v-model="reason"
                        rows="2"
                        class="w-full rounded border-slate-300 block mt-1"
                        placeholder="Describe the revision"
                    /></label
                ><label class="text-xs"
                    >Evidence Reference<input
                        v-model="evidenceReference"
                        class="w-full rounded border-slate-300 block mt-1"
                        placeholder="HR memo/order"
                /></label>
                <p class="md:col-span-2 text-xs text-slate-500">
                    Save Draft / Approve stores a calculation version only. No
                    Salary Bill, arrear payment, GPF ledger or Bank Advice
                    posting.
                </p>
            </section>
        </div>
    </AuthenticatedLayout>
    <!-- Print-only A4 report modeled on the supplied KAU Salary Bill PDF: institutional header and Current / Arrear / Total columns. -->
    <div id="kau-print-area">
        <div class="report-title">
            <img src="/logo.png" alt="KAU logo" /><b
                >Khulna Agricultural University</b
            >
            <p>
                Temporary Office: 327, Jessore Road, North Mujgunni, Khalishpur,
                Khulna-9000
            </p>
            <b>{{
                printVariant === "arrear"
                    ? "Arrear & Deduction Statement"
                    : "Salary Bill (Calculation Preview)"
            }}</b>
        </div>
        <div class="report-meta">
            <div>Name : {{ employee.name }}</div>
            <div>Service ID : {{ employee.code }}</div>
            <div>
                Pay Scale : {{ effective.pay_scale }} ({{ effective.grade }})
            </div>
            <div>
                Month : {{ String(props.period.month).padStart(2, "0") }}-{{
                    props.period.year
                }}
            </div>
            <div>Approved Basic : {{ money(basic) }}</div>
            <div>Fixation : {{ effective.fixation_id || "Legacy" }}</div>
        </div>
        <table class="report-table">
            <thead>
                <tr>
                    <th colspan="5">Allowances / Payable</th>
                </tr>
                <tr>
                    <th>Sl.No</th>
                    <th>Particulars</th>
                    <th>Current</th>
                    <th>Arrear</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(r, i) in statement.allowances" :key="'pa' + i">
                    <td>{{ i + 1 }}</td>
                    <td>{{ r.title }}</td>
                    <td>{{ money(r.current) }}</td>
                    <td>{{ money(r.arrear) }}</td>
                    <td>{{ money(r.total) }}</td>
                </tr>
                <tr class="report-total">
                    <td></td>
                    <td>Gross Salary / Payable</td>
                    <td>{{ money(statementSummary.current.gross) }}</td>
                    <td>{{ money(statementSummary.arrear.gross) }}</td>
                    <td>{{ money(statementSummary.total.gross) }}</td>
                </tr>
                <tr>
                    <th colspan="5">Deductions / Recoveries</th>
                </tr>
                <tr>
                    <th>Sl.No</th>
                    <th>Particulars</th>
                    <th>Current</th>
                    <th>Arrear</th>
                    <th>Total</th>
                </tr>
                <tr v-for="(r, i) in statement.deductions" :key="'pd' + i">
                    <td>{{ i + 1 }}</td>
                    <td>{{ r.title }}</td>
                    <td>{{ money(r.current) }}</td>
                    <td>{{ money(r.arrear) }}</td>
                    <td>{{ money(r.total) }}</td>
                </tr>
                <tr class="report-total">
                    <td></td>
                    <td>Total Deductions</td>
                    <td>{{ money(statementSummary.current.deductions) }}</td>
                    <td>{{ money(statementSummary.arrear.deductions) }}</td>
                    <td>{{ money(statementSummary.total.deductions) }}</td>
                </tr>
                <tr class="report-total">
                    <td></td>
                    <td>Net Payable</td>
                    <td>{{ money(statementSummary.current.net) }}</td>
                    <td>{{ money(statementSummary.arrear.net) }}</td>
                    <td>{{ money(statementSummary.total.net) }}</td>
                </tr>
            </tbody>
        </table>
        <p v-if="arrearUnknown.length" class="report-warning">
            PROVISIONAL ESTIMATE ONLY. {{ arrearUnknown.length }} unverified
            arrear item(s) excluded from displayed total. Not an approved Salary
            Bill or payment authorization.
        </p>
        <p class="report-note">In word: {{ amountWords }}.</p>
        <p class="report-note">
            Calculation preview · Version {{ version || "unsaved" }} · Not proof
            of bank payment or HR disbursement.
        </p>
        <div class="report-signatures">
            <span>Bank A/C: {{ verifiedBank || "To be verified" }}</span
            ><span>STAMP</span><span>Signature of Employee</span>
        </div>
    </div>
</template>

<style>
#kau-print-area {
    display: none;
}
@media print {
    @page {
        size: A4 portrait;
        margin: 12mm;
    }
    body * {
        visibility: hidden !important;
    }
    #kau-print-area,
    #kau-print-area * {
        visibility: visible !important;
    }
    #kau-print-area {
        display: block !important;
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        background: white;
        color: black;
        font:
            10pt Arial,
            sans-serif;
    }
    .report-title {
        text-align: center;
        line-height: 1.35;
        margin-bottom: 15px;
        position: relative;
        min-height: 48px;
    }
    .report-title img {
        position: absolute;
        left: 5px;
        top: 0;
        width: 55px;
        height: 55px;
        object-fit: contain;
    }
    .report-title > b:first-child {
        font-size: 14pt;
    }
    .report-title p {
        font-size: 9pt;
    }
    .report-meta {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 3px;
        margin-bottom: 12px;
        font-size: 9pt;
    }
    .report-meta div:nth-child(2n) {
        text-align: right;
    }
    .report-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 9pt;
    }
    .report-table th,
    .report-table td {
        border: 1px solid #333;
        padding: 5px 6px;
    }
    .report-table th {
        background: #f6f6f6;
        text-align: left;
    }
    .report-table td:nth-child(n + 3) {
        text-align: right;
    }
    .report-table td:first-child {
        width: 35px;
    }
    .report-table tr {
        break-inside: avoid;
        page-break-inside: avoid;
    }
    .report-total {
        font-weight: 700;
        background: #f5f5f5;
    }
    .report-note {
        margin-top: 12px;
        font-size: 9pt;
    }
    .report-warning {
        color: #a00;
        font-weight: bold;
    }
    .report-signatures {
        display: flex;
        justify-content: space-between;
        margin-top: 46px;
        padding-bottom: 6px;
        font-size: 9pt;
        gap: 15px;
    }
}
</style>
