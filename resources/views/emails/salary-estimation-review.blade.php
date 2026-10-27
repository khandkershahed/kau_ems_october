<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salary Structure Review</title>
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
            background-color: #e6fff8;
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
            background-color: #10e210;
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
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
        }
        .info-item {
            color: #4b5563;
        }
        .info-item strong {
            color: #111827;
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
            background-color: #fffbeb;
            border-left: 4px solid #f59e0b;
            padding: 12px;
            border-radius: 4px;
            font-size: 12px;
            color: #92400e;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- University Header -->
        <div class="header">
            <h1>Khulna Agricultural University</h1>
            <p>Temporary Office: 327, Jessore Road, North Mujgunni, Khalishpur, Khulna-9000</p>
            <div class="badge">Estimated Salary Structure Review</div>
        </div>

        <div class="content">
            <div class="greeting">Dear {{ $user->name }},</div>
            <p class="intro-text">
                Please review your currently configured monthly salary estimation and component breakdown below.
                If any allowance, deduction, or personal detail requires correction, please contact the Accounts and Finance Section.
            </p>

            <!-- Employee Details Box -->
            <div class="employee-info-box">
                <table style="border: none;">
                    <tr>
                        <td style="border: none; padding: 3px 0;"><strong>Employee Code:</strong> {{ $breakdown['employee_code'] ?? 'N/A' }}</td>
                        <td style="border: none; padding: 3px 0;"><strong>Designation:</strong> {{ $breakdown['designation'] ?? 'N/A' }}</td>
                    </tr>
                    <tr>
                        <td style="border: none; padding: 3px 0;"><strong>Department:</strong> {{ $breakdown['department'] ?? 'N/A' }}</td>
                        <td style="border: none; padding: 3px 0;"><strong>Category:</strong> {{ $breakdown['user_category'] ?? 'N/A' }}</td>
                    </tr>
                    <tr>
                        <td style="border: none; padding: 3px 0;"><strong>Pay Scale:</strong> {{ $breakdown['pay_scale'] ?? 'N/A' }}</td>
                        <td style="border: none; padding: 3px 0;"><strong>Grade:</strong> {{ $breakdown['grade'] ?? 'N/A' }}</td>
                    </tr>
                </table>
            </div>

            <!-- Allowances (Additions) -->
            <div class="section-title" style="color: #065f46;">
                <span>Earnings & Allowances</span>
                <span class="font-mono">৳{{ number_format($breakdown['total_allowance'] ?? 0, 2) }}</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 60px;">SL</th>
                            <th>Particulars / Allowance Name</th>
                            <th class="text-right" style="width: 140px;">Amount (৳)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($breakdown['allowances'] ?? [] as $idx => $item)
                        <tr>
                            <td>{{ $idx + 1 }}</td>
                            <td>
                                {{ $item['name'] ?? 'Allowance' }}
                                @if(strtolower($item['calcType'] ?? '') === 'percentage')
                                    <span style="font-size: 11px; color: #6b7280;">({{ $item['amount'] }}% of Basic)</span>
                                @endif
                            </td>
                            <td class="text-right font-mono">{{ number_format((float) ($item['calculated_amount'] ?? $item['amount'] ?? 0), 2) }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="3" style="text-align: center; color: #9ca3af;">No allowances configured.</td>
                        </tr>
                        @endforelse
                        <tr class="total-row">
                            <td colspan="2" style="text-align: right;">Total Allowances:</td>
                            <td class="text-right font-mono" style="color: #065f46;">৳{{ number_format($breakdown['total_allowance'] ?? 0, 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Deductions (Subtractions) -->
            <div class="section-title" style="color: #b91c1c;">
                <span>Deductions</span>
                <span class="font-mono">৳{{ number_format($breakdown['total_deduction'] ?? 0, 2) }}</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 60px;">SL</th>
                            <th>Particulars / Deduction Name</th>
                            <th class="text-right" style="width: 140px;">Amount (৳)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($breakdown['deductions'] ?? [] as $idx => $item)
                        <tr>
                            <td>{{ $idx + 1 }}</td>
                            <td>
                                {{ $item['name'] ?? 'Deduction' }}
                                @if(strtolower($item['calcType'] ?? '') === 'percentage')
                                    <span style="font-size: 11px; color: #6b7280;">({{ $item['amount'] }}% of Basic)</span>
                                @endif
                            </td>
                            <td class="text-right font-mono">{{ number_format((float) ($item['calculated_amount'] ?? $item['amount'] ?? 0), 2) }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="3" style="text-align: center; color: #9ca3af;">No deductions configured.</td>
                        </tr>
                        @endforelse
                        <tr class="total-row">
                            <td colspan="2" style="text-align: right;">Total Deductions:</td>
                            <td class="text-right font-mono" style="color: #b91c1c;">৳{{ number_format($breakdown['total_deduction'] ?? 0, 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Net Salary Summary Box -->
            <div class="summary-box">
                <div class="summary-row">
                    <span>Gross Estimated Allowances:</span>
                    <strong class="font-mono">৳{{ number_format($breakdown['total_allowance'] ?? 0, 2) }}</strong>
                </div>
                <div class="summary-row">
                    <span>Total Configured Deductions:</span>
                    <strong class="font-mono" style="color: #b91c1c;">- ৳{{ number_format($breakdown['total_deduction'] ?? 0, 2) }}</strong>
                </div>
                <div class="summary-net">
                    <span>Estimated Net Monthly Salary:</span>
                    <span class="font-mono">৳{{ number_format($breakdown['net_salary'] ?? 0, 2) }}</span>
                </div>
            </div>

            <div class="alert-box">
                <strong>Notice:</strong> This is an informational estimation of your monthly salary structure based on the current payroll setup. Actual billed amounts may adjust for specific monthly allowances, arrears, or ad-hoc deductions.
            </div>
        </div>

        <div class="footer">
            <p style="margin: 0;">This is an automated notification from the <strong>Khulna Agricultural University Employee Management System</strong>.</p>
            <p style="margin: 4px 0 0 0;">Please do not reply directly to this email. For inquiries, contact accounts@kau.edu.bd.</p>
        </div>
    </div>
</body>
</html>
