<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bonus Bill Review</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #f3f4f6;
            color: #1f2937;
            margin: 0;
            padding: 24px;
            line-height: 1.5;
        }
        .container {
            max-width: 680px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border: 1px solid #e5e7eb;
        }
        .header {
            background-color: #065f46;
            color: #ffffff;
            padding: 24px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        .header p {
            margin: 4px 0 0 0;
            font-size: 13px;
            color: #d1fae5;
        }
        .badge {
            display: inline-block;
            margin-top: 10px;
            padding: 4px 12px;
            background-color: #10b981;
            color: #ffffff;
            font-size: 11px;
            font-weight: 600;
            border-radius: 9999px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .content {
            padding: 24px;
        }
        .greeting {
            font-size: 15px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 12px;
        }
        .meta-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
            font-size: 13px;
        }
        .meta-table td {
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
        }
        .meta-table td.label {
            font-weight: 600;
            color: #4b5563;
            background-color: #f9fafb;
            width: 30%;
        }
        .section-title {
            font-size: 14px;
            font-weight: 700;
            color: #111827;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 20px 0 8px 0;
            border-bottom: 2px solid #065f46;
            padding-bottom: 4px;
        }
        .calc-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
            font-size: 13px;
        }
        .calc-table th {
            background-color: #f9fafb;
            color: #374151;
            font-weight: 600;
            text-align: left;
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
        }
        .calc-table td {
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
        }
        .calc-table tr:nth-child(even) {
            background-color: #fcfdfd;
        }
        .amount-col {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }
        .total-row td {
            font-weight: 700;
            background-color: #f3f4f6 !important;
            border-top: 2px solid #d1d5db;
        }
        .net-card {
            margin-top: 24px;
            background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
            border: 1px solid #a7f3d0;
            border-radius: 6px;
            padding: 16px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .net-title {
            font-size: 14px;
            font-weight: 700;
            color: #065f46;
            text-transform: uppercase;
        }
        .net-amount {
            font-size: 22px;
            font-weight: 800;
            color: #047857;
        }
        .footer {
            padding: 16px 24px;
            background-color: #f9fafb;
            border-top: 1px solid #e5e7eb;
            font-size: 11px;
            color: #6b7280;
            text-align: center;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>খুলনা কৃষি বিশ্ববিদ্যালয় (Khulna Agricultural University)</h1>
        <p>Payroll & Accounts Section • Monthly/Festival Bonus Bill Notification</p>
        <span class="badge">{{ $bonusBill->bonus_title ?: 'Bonus Bill' }} ({{ date('F', mktime(0,0,0, (int)$bonusBill->monthId, 1)) }} {{ $bonusBill->bonusGenerationYear }})</span>
    </div>

    <div class="content">
        <div class="greeting">
            Dear {{ $bonusBill->name }},
        </div>
        <p style="font-size: 13px; color: #4b5563; margin-bottom: 16px;">
            Your bonus bill for <strong>{{ $bonusBill->bonus_title ?: 'Festival Bonus' }}</strong> for the period <strong>{{ date('F', mktime(0,0,0, (int)$bonusBill->monthId, 1)) }} {{ $bonusBill->bonusGenerationYear }}</strong> has been prepared and generated.
        </p>

        <table class="meta-table">
            <tr>
                <td class="label">Employee Code</td>
                <td>{{ $bonusBill->employeeCode ?? 'N/A' }}</td>
                <td class="label">Pay Scale & Grade</td>
                <td>{{ $bonusBill->payScale ?? 'N/A' }} ({{ $bonusBill->grade ?? 'N/A' }})</td>
            </tr>
            <tr>
                <td class="label">Designation</td>
                <td>{{ $bonusBill->designation ?? 'N/A' }}</td>
                <td class="label">Religion</td>
                <td>{{ $bonusBill->religion ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td class="label">Bank Account</td>
                <td>{{ $bonusBill->bankAccountNo ?? 'N/A' }}</td>
                <td class="label">Bank & Branch</td>
                <td>{{ $bonusBill->bankAddress ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td class="label">Bill Generation Date</td>
                <td>{{ $bonusBill->bonusGenerationDate?->format('F d, Y') ?? 'N/A' }}</td>
                <td class="label">Review Status</td>
                <td style="text-transform: uppercase; font-weight: 600; color: #065f46;">{{ $bonusBill->reviewStatus }}</td>
            </tr>
        </table>

        <!-- Allowances -->
        <div class="section-title">Bonus Allowances</div>
        <table class="calc-table">
            <thead>
                <tr>
                    <th style="width: 60%;">Component</th>
                    <th class="amount-col" style="width: 40%;">Amount (৳)</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($bonusBill->bonusAllowanceCalcObject ?? [] as $allowance)
                    <tr>
                        <td>{{ $allowance['name'] ?? ($allowance['display_name'] ?? 'Bonus Allowance') }}</td>
                        <td class="amount-col">৳ {{ number_format($allowance['calculated_amount'] ?? $allowance['amount'] ?? 0, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="2" style="text-align: center; color: #9ca3af;">No bonus allowance components.</td>
                    </tr>
                @endforelse
                <tr class="total-row">
                    <td>Total Bonus Allowance</td>
                    <td class="amount-col">৳ {{ number_format($bonusBill->totalAllowance, 2) }}</td>
                </tr>
            </tbody>
        </table>

        @if (!empty($bonusBill->bonusDeductionCalcObject) && count($bonusBill->bonusDeductionCalcObject) > 0)
        <!-- Deductions -->
        <div class="section-title">Bonus Deductions / Tax</div>
        <table class="calc-table">
            <thead>
                <tr>
                    <th style="width: 60%;">Component</th>
                    <th class="amount-col" style="width: 40%;">Amount (৳)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($bonusBill->bonusDeductionCalcObject as $deduction)
                    <tr>
                        <td>{{ $deduction['name'] ?? ($deduction['display_name'] ?? 'Bonus Deduction') }}</td>
                        <td class="amount-col">৳ {{ number_format($deduction['calculated_amount'] ?? $deduction['amount'] ?? 0, 2) }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td>Total Bonus Deduction</td>
                    <td class="amount-col">৳ {{ number_format($bonusBill->totalDeduction, 2) }}</td>
                </tr>
            </tbody>
        </table>
        @endif

        <div class="net-card">
            <span class="net-title">Net Payable Bonus:</span>
            <span class="net-amount">৳ {{ number_format($bonusBill->netBonus, 2) }}</span>
        </div>
    </div>

    <div class="footer">
        <p>This is an automated administrative notification from Khulna Agricultural University EMS.</p>
        <p>© {{ date('Y') }} Khulna Agricultural University. All rights reserved.</p>
    </div>
</div>
</body>
</html>
