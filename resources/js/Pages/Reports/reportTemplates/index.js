import FixationStatement from './FixationStatement.vue';
import SalaryBill from './SalaryBill.vue';
import BankAdvice from './BankAdvice.vue';
import SalarySummary from './SalarySummary.vue';
import EarnDeduction from './EarnDeduction.vue';
import GpfStatement from './GpfStatement.vue';
import BonusReport from './BonusReport.vue';
import BonusBankAdvice from './BonusBankAdvice.vue';

export {
    SalaryBill,
    BankAdvice,
    SalarySummary,
    EarnDeduction,
    GpfStatement,
    BonusReport,
    BonusBankAdvice,
    FixationStatement,
};

/**
 * Resolver to return the Vue template component for a given report type.
 */
export function getReportTemplate(reportType) {
    switch (reportType) {
        case 'Bonus Bank Advice':
            return BonusBankAdvice;
        case 'Bonus Bill':
        case 'Bonus report':
        case 'Bonus Report':
        case 'Bonus repot':
            return BonusReport;
        case 'Salary Bill':
        case 'Arrear & Deduction Statement':
            return SalaryBill;
        case 'Salary Fixation Report':
            return FixationStatement;
        case 'Provisional Bank Advice':
        case 'Bank Advice':
            return BankAdvice;
        case 'Salary Summary':
            return SalarySummary;
        case 'Earn Deduction':
            return EarnDeduction;
        case 'GPF Personal Statement':
        case 'GPF Financial Personal Balance':
        case 'GPF Financial Statement':
        case 'GPF Summary':
            return GpfStatement;
        default:
            return SalarySummary;
    }
}

