<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salary Bill Review</title>
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
            background-color: #f1fdfa;
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
            font-size: 12px;
            color: #d1fae5;
        }
        .badge {
            display: inline-block;
            margin-top: 10px;
            padding: 4px 12px;
            background-color: #0cd337;
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
            margin-bottom: 8px;
        }
        .intro-text {
            font-size: 13px;
            color: #4b5563;
            margin-bottom: 20px;
        }
        .employee-info-box {
            background-color: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 14px;
            margin-bottom: 20px;
            font-size: 12px;
        }
        .section-title {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 20px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .table-wrap {
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 16px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        th {
            background-color: #f9fafb;
            padding: 8px 12px;
            text-align: left;
            font-weight: 600;
            color: #4b5563;
            border-bottom: 1px solid #e5e7eb;
        }
        td {
            padding: 8px 12px;
            border-bottom: 1px solid #f3f4f6;
            color: #1f2937;
        }
        tr:last-child td {
            border-bottom: none;
        }
        .text-right {
            text-align: right;
        }
        .font-mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }
        .total-row {
            background-color: #f9fafb;
            font-weight: 700;
        }
        .summary-box {
            background-color: #ecfdf5;
            border: 1px solid #a7f3d0;
            border-radius: 6px;
            padding: 16px;
            margin-top: 24px;
            text-align: right;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            margin-bottom: 6px;
            color: #374151;
        }
        .summary-net {
            display: flex;
            justify-content: space-between;
            font-size: 16px;
            font-weight: 700;
            color: #065f46;
            padding-top: 8px;
            border-top: 1px solid #a7f3d0;
            margin-top: 8px;
        }
        .footer {
            background-color: #f9fafb;
            border-top: 1px solid #e5e7eb;
            padding: 16px 24px;
            text-align: center;
            font-size: 11px;
            color: #6b7280;
        }
        .alert-box {
            background-color: #eff6ff;
            border-left: 4px solid #3b82f6;
            padding: 12px;
            border-radius: 4px;
            font-size: 12px;
            color: #1e40af;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    @php
        $monthName = date('F', mktime(0, 0, 0, (int) $salaryGenerate->monthId, 10));
        $allowances = is_array($salaryGenerate->salaryAllowanceCalcObject) 
            ? $salaryGenerate->salaryAllowanceCalcObject 
            : (json_decode($salaryGenerate->salaryAllowanceCalcObject, true) ?: []);
        $deductions = is_array($salaryGenerate->salaryDeductionCalcObject) 
            ? $salaryGenerate->salaryDeductionCalcObject 
            : (json_decode($salaryGenerate->salaryDeductionCalcObject, true) ?: []);
    @endphp

    <div class="container">
        <!-- University Header -->
        <div class="header">
            <h1>Khulna Agricultural University</h1>
            <p>Temporary Office: 327, Jessore Road, North Mujgunni, Khalishpur, Khulna-9000</p>
            <div class="badge">Salary Bill Review &bull; {{ $monthName }} {{ $salaryGenerate->salaryGenerationYear }}</div>
        </div>

        <div class="content">
            <div class="greeting">Dear {{ $salaryGenerate->name }},</div>
            <p class="intro-text">
                Your monthly salary bill for <strong>{{ $monthName }} {{ $salaryGenerate->salaryGenerationYear }}</strong> has been prepared and is currently submitted for review.
                Please examine the itemized breakdown below.
            </p>

            <!-- Employee Details Box -->
            <div class="employee-info-box">
                <table style="border: none;">
                    <tr>
                        <td style="border: none; padding: 3px 0;"><strong>Employee Code:</strong> {{ $salaryGenerate->employeeCode }}</td>
                        <td style="border: none; padding: 3px 0;"><strong>Designation:</strong> {{ $salaryGenerate->designation }}</td>
                    </tr>
                    <tr>
                        <td style="border: none; padding: 3px 0;"><strong>Pay Scale:</strong> {{ $salaryGenerate->payScale }}</td>
                        <td style="border: none; padding: 3px 0;"><strong>Grade:</strong> {{ $salaryGenerate->grade }}</td>
                    </tr>
                    <tr>
                        <td style="border: none; padding: 3px 0;"><strong>Bank Account:</strong> {{ $salaryGenerate->bankAccountNo }}</td>
                        <td style="border: none; padding: 3px 0;"><strong>Branch:</strong> {{ $salaryGenerate->bankAddress }}</td>
                    </tr>
                    @if($salaryGenerate->salaryGenerationDate)
                    <tr>
                        <td colspan="2" style="border: none; padding: 3px 0;"><strong>Bill Generation Date:</strong> {{ $salaryGenerate->salaryGenerationDate }}</td>
                    </tr>
                    @endif
                </table>
            </div>

            <!-- Allowances Table -->
            <div class="section-title" style="color: #065f46;">
                <span>Allowances & Earnings</span>
                <span class="font-mono">৳{{ number_format($salaryGenerate->totalAllowance, 2) }}</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 50px;">SL</th>
                            <th>Particulars</th>
                            <th class="text-right" style="width: 100px;">Current (৳)</th>
                            <th class="text-right" style="width: 90px;">Arrear (৳)</th>
                            <th class="text-right" style="width: 110px;">Total (৳)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($allowances as $idx => $item)
                        <tr>
                            <td>{{ $idx + 1 }}</td>
                            <td>{{ $item['name'] ?? 'Allowance' }}</td>
                            <td class="text-right font-mono">{{ number_format((float) ($item['amount'] ?? 0), 2) }}</td>
                            <td class="text-right font-mono">{{ number_format((float) ($item['arrear'] ?? 0), 2) }}</td>
                            <td class="text-right font-mono font-semibold">{{ number_format((float) (($item['amount'] ?? 0) + ($item['arrear'] ?? 0)), 2) }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" style="text-align: center; color: #9ca3af;">No allowances recorded.</td>
                        </tr>
                        @endforelse
                        <tr class="total-row">
                            <td colspan="4" style="text-align: right;">Gross Allowances:</td>
                            <td class="text-right font-mono" style="color: #065f46;">৳{{ number_format($salaryGenerate->totalAllowance, 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Deductions Table -->
            <div class="section-title" style="color: #b91c1c;">
                <span>Deductions</span>
                <span class="font-mono">৳{{ number_format($salaryGenerate->totalDeduction, 2) }}</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 50px;">SL</th>
                            <th>Particulars</th>
                            <th class="text-right" style="width: 110px;">Amount (৳)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($deductions as $idx => $item)
                        <tr>
                            <td>{{ $idx + 1 }}</td>
                            <td>{{ $item['name'] ?? 'Deduction' }}</td>
                            <td class="text-right font-mono">{{ number_format((float) ($item['amount'] ?? 0), 2) }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="3" style="text-align: center; color: #9ca3af;">No deductions recorded.</td>
                        </tr>
                        @endforelse
                        <tr class="total-row">
                            <td colspan="2" style="text-align: right;">Total Deductions:</td>
                            <td class="text-right font-mono" style="color: #b91c1c;">৳{{ number_format($salaryGenerate->totalDeduction, 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Net Salary Summary Box -->
            <div class="summary-box">
                <div class="summary-row">
                    <span>Gross Allowances:</span>
                    <strong class="font-mono">৳{{ number_format($salaryGenerate->totalAllowance, 2) }}</strong>
                </div>
                <div class="summary-row">
                    <span>Total Deductions:</span>
                    <strong class="font-mono" style="color: #b91c1c;">- ৳{{ number_format($salaryGenerate->totalDeduction, 2) }}</strong>
                </div>
                @if($salaryGenerate->taxDeduction > 0)
                <div class="summary-row" style="font-size: 11px; color: #6b7280;">
                    <span>&bull; Income Tax:</span>
                    <span class="font-mono">৳{{ number_format($salaryGenerate->taxDeduction, 2) }}</span>
                </div>
                @endif
                @if($salaryGenerate->gpfDeduction > 0)
                <div class="summary-row" style="font-size: 11px; color: #6b7280;">
                    <span>&bull; GPF Deduction:</span>
                    <span class="font-mono">৳{{ number_format($salaryGenerate->gpfDeduction, 2) }}</span>
                </div>
                @endif
                <div class="summary-net">
                    <span>Net Payable Amount:</span>
                    <span class="font-mono">৳{{ number_format($salaryGenerate->salaryTotal, 2) }}</span>
                </div>
            </div>

            <div class="alert-box">
                <strong>Review Status:</strong> This bill is currently marked under review. If any discrepancies or changes are required before final disbursement, please notify the Accounts and Finance Section immediately.
            </div>
        </div>

        <div class="footer">
            <p style="margin: 0;">This is an automated notification from the <strong>Khulna Agricultural University Employee Management System</strong>.</p>
            <p style="margin: 4px 0 0 0;">Please do not reply directly to this email. For inquiries, contact accounts@kau.edu.bd.</p>
        </div>
    </div>
</body>
</html>
