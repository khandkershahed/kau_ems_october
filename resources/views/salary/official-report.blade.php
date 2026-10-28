<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>KAU Official {{ $kind === 'arrear' ? 'Arrear Statement' : 'Salary Report' }} —
        {{ $year }}-{{ sprintf('%02d', $month) }}</title>
    <style>
        :root {
            color-scheme: light;
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f3f5f7;
            color: #162536;
            font-family: Arial, "Noto Sans Bengali", "Hind Siliguri", sans-serif;
            font-size: 11px;
        }

        body.embed .toolbar {
            display: none !important;
        }

        body.embed {
            background: #fff;
        }

        body.embed .pages {
            margin: 0;
        }

        body.embed .sheet {
            margin: 0;
            box-shadow: none;
        }

        .toolbar {
            background: #142a43;
            color: #fff;
            padding: 12px 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
        }

        .toolbar button,
        .toolbar a {
            background: #fff;
            color: #172c43;
            border: 0;
            padding: 9px 15px;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
        }

        .pages {
            margin: 22px auto;
        }

        .sheet {
            background: #fff;
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto 20px;
            padding: 11mm;
            display: flex;
            flex-direction: column;
            box-shadow: 0 3px 15px #0002;
            break-after: page;
            page-break-after: always;
        }

        .sheet:last-child {
            break-after: auto;
            page-break-after: auto;
        }

        body.bulk .sheet,
        body.arrear .sheet {
            width: 297mm;
            min-height: 210mm;
            padding: 9mm 10mm;
        }

        .header {
            display: grid;
            grid-template-columns: 52px 1fr 52px;
            border-bottom: 2px solid #21394f;
            padding-bottom: 9px;
            align-items: center;
            text-align: center;
        }

        .header img {
            width: 48px;
            height: 48px;
            object-fit: contain;
        }

        .header h1 {
            font-size: 17px;
            margin: 0 0 3px;
            font-weight: 700;
        }

        .header p {
            margin: 2px 0;
            font-size: 10px;
        }

        .header strong {
            font-size: 13px;
            display: block;
            margin-top: 5px;
        }

        .status {
            text-transform: uppercase;
            letter-spacing: .04em;
            font-size: 9px;
            color: #42566a;
        }

        .meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px 12px;
            margin: 11px 0;
        }

        .meta div {
            display: flex;
            gap: 7px;
        }

        .meta b {
            min-width: 105px;
        }

        .meta span {
            overflow-wrap: anywhere;
        }

        .heading {
            font-size: 11px;
            font-weight: 700;
            margin: 10px 0 4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        thead {
            display: table-header-group;
        }

        tr {
            break-inside: avoid;
            page-break-inside: avoid;
        }

        th,
        td {
            border: 1px solid #536678;
            padding: 5px 6px;
            vertical-align: middle;
            overflow-wrap: anywhere;
        }

        th {
            background: #eff2f6;
            color: #172a3f;
            font-weight: 700;
        }

        td.num,
        th.num {
            text-align: center;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }

        .total-row {
            background: #edf5ef;
            font-weight: 700;
        }

        .net-row {
            background: #e4ebf6;
            font-weight: 700;
        }

        .note {
            border: 1px solid #cfd5df;
            border-left: 3px solid #6b7f99;
            margin: 12px 0;
            padding: 8px;
            font-size: 10px;
            line-height: 1.55;
        }

        .note.warning {
            border-left-color: #b46c23;
            background: #fffcf4;
        }

        .signature {
            margin-top: auto;
            padding-top: 35px;
            display: flex;
            gap: 12px;
            justify-content: space-between;
            text-align: center;
        }

        .signature div {
            border-top: 1px solid #6b7886;
            width: 30%;
            padding-top: 5px;
            font-size: 10px;
        }

        .footer {
            border-top: 1px solid #d3dbe5;
            margin-top: 16px;
            padding-top: 7px;
            display: flex;
            justify-content: space-between;
            color: #536477;
            font-size: 9px;
        }

        .summary-table {
            font-size: 10px;
        }

        .summary-table th,
        .summary-table td {
            padding: 3px 4px;
            font-size: 9.4px;
            line-height: 1.12;
        }

        body.bulk.dense .summary-table th,
        body.bulk.dense .summary-table td {
            padding: 2px 2px;
            font-size: 6.4px;
            line-height: 1.08;
        }

        body.bulk .summary-table {
            table-layout: auto;
            font-size: 6.7px;
        }

        body.bulk .summary-table th,
        body.bulk .summary-table td {
            padding: 2px 2px;
            font-size: 6.7px;
            white-space: nowrap;
        }

        body.bulk .summary-table .person {
            white-space: normal;
            min-width: 62px;
            max-width: 98px;
        }

        body.bulk .header {
            padding-bottom: 4px;
        }

        body.bulk .header h1 {
            font-size: 13px;
        }

        body.bulk .signature {
            padding-top: 20px;
        }

        body.bulk .note {
            padding: 5px 7px;
            margin: 8px 0;
            font-size: 9px;
        }

        .breakdown-month {
            text-align: center;
        }

        .muted {
            color: #6b7787;
        }

        .small {
            font-size: 9px;
        }

        @media print {
            @page {
                size: A4 {{ in_array($kind, ['bulk', 'arrear']) ? 'landscape' : 'portrait' }};
                margin: 0;
            }

            body.bulk,
            body.arrear {
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }

            html,
            body {
                background: #fff !important;
                margin: 0;
                padding: 0;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .toolbar {
                display: none !important;
            }

            .pages {
                margin: 0;
            }

            .sheet {
                margin: 0;
                box-shadow: none;
                width: 210mm;
                min-height: 297mm;
                height: auto;
                padding: 11mm;
            }

            body.bulk .sheet,
            body.arrear .sheet {
                width: 297mm;
                min-height: 210mm;
                height: auto;
                padding: 9mm 10mm;
            }
        }
    </style>
</head>

<body class="{{ $kind }} {{ $kind === 'bulk' && $perPage > 15 ? 'dense' : '' }} {{ $embed ? 'embed' : '' }}">
    <div class="toolbar">
        <div><strong>KAU Official Reports</strong> · Read-only saved salary sources ·
            {{ sprintf('%02d', $month) }}/{{ $year }}</div>
        <div><button type="button" onclick="window.print()">Print / Save as PDF</button>
            @if ($pdfAvailable)
                <a href="{{ request()->fullUrlWithQuery(['format' => 'pdf']) }}">Download PDF</a>
            @endif
            <button type="button" onclick="window.close()">Close</button>
        </div>
    </div>
    <div class="pages">
        @foreach ($pages as $pageIndex => $pageRows)
            <section class="sheet">
                <header class="header">
                    <div>
                        @if ($logoData)
                            <img alt="KAU logo" src="{{ $logoData }}">
                        @endif
                    </div>
                    <div>
                        <h1>Khulna Agricultural University</h1>
                        <p>Temporary Office: 327, Jessore Road, North Mujgunni, Khalishpur, Khulna-9000</p>
                        <strong>
                            @if ($kind === 'bulk')
                                Horizontal Salary Bill (Current + Arrear)
                            @elseif($kind === 'arrear')
                                বকেয়া বেতনের হিসাব বিবরণী (Arrear Statement)
                            @else
                                Salary Bill
                            @endif
                        </strong>
                        <p>Salary period: {{ sprintf('%02d', $month) }}/{{ $year }}</p>
                        @if (in_array('DRAFT_REGULAR_ONLY_PREVIEW', array_column($pageRows, 'source'), true))
                            <p style="font-weight:bold;color:#b45309">DRAFT - NOT GENERATED (provisional preview rows are marked; no posted bills were substituted)</p>
                        @endif
                    </div>
                    <div></div>
                </header>
                @if ($kind === 'bulk')
                    {{-- <div class="heading">Salary Statement — Page {{ $pageIndex + 1 }} / {{ count($pages) }} · Up to {{ $perPage }} employees per page</div> --}}
                    @php
                        $component = static function ($r, $group, $words) {
                            $sum = 0.0;
                            foreach ($r[$group] ?? [] as $row) {
                                $name = strtolower((string) ($row['title'] ?? ''));
                                foreach ($words as $word) {
                                    if (str_contains($name, $word)) {
                                        $amount = $row['current'] ?? null;
                                        if ($amount === null) {
                                            return null;
                                        }
                                        $sum += (float) $amount;
                                        break;
                                    }
                                }
                            }
                            return $sum;
                        };
                        $num = static fn($amount) => $amount === null ? '—' : number_format((float) $amount, 2);
                        $columns = [
                            'basic',
                            'hra',
                            'medical',
                            'special',
                            'education',
                            'other',
                            'current_gross',
                            'gpf',
                            'tax',
                            'other_ded',
                            'current_deductions',
                            'basic_arrear_gross',
                            'basic_increment_adjustment',
                            'special_benefit_recovery',
                            'house_rent_recovery',
                            'additional_gpf',
                            'net',
                        ];
                        $totals = array_fill_keys($columns, 0.0);
                    @endphp
                    <table class="summary-table">
                        <thead>
                            <tr style="height:6mm; text-align: center;">
                                <th>SL</th>
                                <th>ID</th>
                                <th class="person">Name</th>
                                <th class="person">Designation</th>
                                <th class="num">Basic</th>
                                <th class="num">HRA</th>
                                <th class="num">MA</th>
                                <th class="num">S Benefit</th>
                                <th class="num">Edu</th>
                                <th class="num">Other A</th>
                                <th class="num">Reg Gross</th>
                                <th class="num">GPF</th>
                                <th class="num">Income T</th>
                                <th class="num">Other D</th>
                                <th class="num">Reg Ded</th>
                                <th class="num">Basic Arrear</th>
                                <th class="num">Basic Adj.</th>
                                <th class="num">S.B. Recovery</th>
                                <th class="num">HRA Recovery</th>
                                <th class="num">Addl. GPF</th>
                                <th class="num">Net</th>
                                <th>Signature</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pageRows as $i => $r)
                                @php
                                    $hra = $component($r, 'allowances', ['house rent']);
                                    $medical = $component($r, 'allowances', ['medical']);
                                    $special = $component($r, 'allowances', ['special benefit']);
                                    $education = $component($r, 'allowances', ['education']);
                                    $gpf = $component($r, 'deductions', ['provident fund', 'gpf']);
                                    $tax = $component($r, 'deductions', ['income tax']);
                                    $knownAdd = array_sum(
                                        array_filter([$r['basic'], $hra, $medical, $special, $education], 'is_numeric'),
                                    );
                                    $other =
                                        $r['current_gross'] === null ||
                                        in_array(null, [$r['basic'], $hra, $medical, $special, $education], true)
                                            ? null
                                            : $r['current_gross'] - $knownAdd;
                                    $otherDed =
                                        $r['current_deductions'] === null || $gpf === null || $tax === null
                                            ? null
                                            : $r['current_deductions'] - $gpf - $tax;
                                    // Unsaved regular-only previews have no verified arrear statement.
                                    // Do not turn unknown arrears into numeric zero.
                                    $arrearDefault = ($r['source'] ?? '') === 'DRAFT_REGULAR_ONLY_PREVIEW' ? null : 0.0;
                                    $arrearValues = [
                                        'basic_arrear_gross' => $arrearDefault,
                                        'basic_increment_adjustment' => $arrearDefault,
                                        'special_benefit_recovery' => $arrearDefault,
                                        'house_rent_recovery' => $arrearDefault,
                                        'additional_gpf' => $arrearDefault,
                                    ];
                                    foreach (($r['arrear_rows'] ?? []) as $arrearItem) {
                                        $code = strtolower((string) ($arrearItem['code'] ?? ''));
                                        if (array_key_exists($code, $arrearValues)) {
                                            $arrearValues[$code] = (float) ($arrearItem['final_amount'] ?? $arrearItem['amount'] ?? $arrearItem['total'] ?? 0);
                                        }
                                    }
                                    $vals = [
                                        'basic' => $r['basic'],
                                        'hra' => $hra,
                                        'medical' => $medical,
                                        'special' => $special,
                                        'education' => $education,
                                        'other' => $other,
                                        'current_gross' => $r['current_gross'],
                                        'gpf' => $gpf,
                                        'tax' => $tax,
                                        'other_ded' => $otherDed,
                                        'current_deductions' => $r['current_deductions'],
                                        'basic_arrear_gross' => $arrearValues['basic_arrear_gross'],
                                        'basic_increment_adjustment' => $arrearValues['basic_increment_adjustment'],
                                        'special_benefit_recovery' => $arrearValues['special_benefit_recovery'],
                                        'house_rent_recovery' => $arrearValues['house_rent_recovery'],
                                        'additional_gpf' => $arrearValues['additional_gpf'],
                                        'net' => $r['net'],
                                    ];
                                    foreach ($vals as $key => $value) {
                                        if (is_numeric($value)) {
                                            $totals[$key] += (float) $value;
                                        }
                                    }
                                @endphp
                                <tr style="height:6mm; text-align: center;">
                                    <td>{{ $pageIndex * $perPage + $i + 1 }}</td>
                                    <td>{{ $r['employee_code'] }}@if (($r['source'] ?? '') === 'DRAFT_REGULAR_ONLY_PREVIEW') <span style="font-weight:bold">DRAFT</span>@endif</td>
                                    <td class="person">{{ $r['name'] }}@if (($r['source'] ?? '') === 'DRAFT_REGULAR_ONLY_PREVIEW') <span style="font-weight:bold">(DRAFT)</span>@endif</td>
                                    <td class="person">{{ $r['designation'] }}</td>
                                    @foreach ($columns as $key)
                                        <td class="num" style="text-align: center;">{{ $num($vals[$key]) }}</td>
                                    @endforeach
                                    <td>
                                    </td>
                                </tr>
                            @endforeach
                            <tr class="total-row" style="height:6mm; text-align: center;">
                                <td colspan="4">PAGE TOTAL</td>
                                @foreach ($columns as $key)
                                    <td class="num">{{ $num($totals[$key]) }}</td>
                                @endforeach
                                <td>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    @if ($pageIndex === count($pages) - 1)
                        <div class="heading" style="text-align:right">Grand Net Total —
                            ৳{{ number_format($grandTotal, 2) }}</div>
                    @endif
                    {{-- <div class="signature"><div>Prepared by</div><div>Checked by</div><div>Accounts Officer</div></div> --}}
                @else
                    @php($r = $pageRows[0])
                    <div class="meta">
                        <div><b>Employee / Code</b><span>{{ $r['name'] }} / {{ $r['employee_code'] }}</span></div>
                        <div><b>Designation</b><span>{{ $r['designation'] }}</span></div>
                        <div><b>Grade / Pay Scale</b><span>{{ $r['grade'] }} · {{ $r['pay_scale'] }}</span></div>
                        <div><b>Department*</b><span>{{ $r['department'] }}</span></div>
                        <div><b>Bank Account</b><span>{{ $r['bank_account_no'] }}</span></div>
                        <div><b>Salary Bill ID</b><span>{{ $r['id'] }}</span></div>
                    </div>
                    @if ($kind === 'bill')
                        @foreach (['allowances' => 'Allowances / Additions', 'deductions' => 'Deductions'] as $group => $label)
                            <div class="heading">{{ $label }}</div>
                            <table>
                                <thead>
                                    <tr>
                                        <th style="width:30px">SL</th>
                                        <th>Particulars</th>
                                        <th class="num" style="width:94px">Current</th>
                                        <th class="num" style="width:94px">Arrear</th>
                                        <th class="num" style="width:94px">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($r[$group] as $i => $item)
                                        <tr>
                                            <td>{{ $i + 1 }}</td>
                                            <td>{{ $item['title'] }}</td>
                                            <td class="num">
                                                {{ $item['current'] === null ? 'UNKNOWN' : number_format($item['current'], 2) }}
                                            </td>
                                            <td class="num">
                                                {{ $item['arrear'] === null ? 'UNKNOWN' : number_format($item['arrear'], 2) }}
                                            </td>
                                            <td class="num">
                                                {{ $item['total'] === null ? 'UNKNOWN' : number_format($item['total'], 2) }}
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5">No saved {{ strtolower($label) }} lines</td>
                                        </tr>
                                    @endforelse
                                    <tr class="total-row">
                                        <td colspan="2">
                                            {{ $group === 'allowances' ? 'Gross Salary' : 'Total Deductions' }}</td>
                                        <td class="num">
                                            {{ $r[$group === 'allowances' ? 'current_gross' : 'current_deductions'] === null ? 'UNKNOWN' : number_format($r[$group === 'allowances' ? 'current_gross' : 'current_deductions'], 2) }}
                                        </td>
                                        <td class="num">
                                            {{ $r[$group === 'allowances' ? 'arrear_payable' : 'arrear_deductions'] === null ? 'UNKNOWN' : number_format($r[$group === 'allowances' ? 'arrear_payable' : 'arrear_deductions'], 2) }}
                                        </td>
                                        <td class="num">
                                            {{ number_format($r[$group === 'allowances' ? 'gross' : 'total_deductions'], 2) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        @endforeach
                        <div class="heading">Payment Summary</div>
                        <table>
                            <tbody>
                                <tr>
                                    <td>Regular Net Salary</td>
                                    <td class="num">
                                        {{ $r['current_net'] === null ? 'UNKNOWN' : number_format($r['current_net'], 2) }}
                                    </td>
                                </tr>
                                <tr>
                                    <td>Net Arrear</td>
                                    <td class="num">
                                        {{ $r['net_arrear'] === null ? 'UNKNOWN' : number_format($r['net_arrear'], 2) }}
                                    </td>
                                </tr>
                                <tr class="net-row">
                                    <td>Final Net Payable (Saved Posted Bill)</td>
                                    <td class="num">{{ number_format($r['net'], 2) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    @else
                        <div class="heading">July–September 2026: saved arrear components</div>
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:35px">SL</th>
                                    <th>Arrear / Recovery Item</th>
                                    <th style="width:90px">Jul 2026</th>
                                    <th style="width:90px">Aug 2026</th>
                                    <th style="width:90px">Sep 2026</th>
                                    <th class="num" style="width:105px">Posted Total</th>
                                    <th style="width:95px">Source</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($r['arrear_rows'] as $i => $item)
                                    <tr>
                                        <td>{{ $i + 1 }}</td>
                                        <td>{{ $item['title'] }}<div class="muted small">{{ $item['code'] }} ·
                                                {{ $item['direction'] }}</div>
                                        </td>
                                        @foreach (['2026-07', '2026-08', '2026-09'] as $period)
                                            <td class="breakdown-month">
                                                @if (is_array($item['month_amounts']) &&
                                                        array_key_exists($period, $item['month_amounts']) &&
                                                        is_numeric($item['month_amounts'][$period]))
                                                    {{ number_format((float) $item['month_amounts'][$period], 2) }}
                                                @else
                                                    NOT ALLOCATED
                                                @endif
                                            </td>
                                        @endforeach
                                        <td class="num">
                                            {{ $item['total'] === null ? 'UNKNOWN' : number_format($item['total'], 2) }}
                                        </td>
                                        <td class="small">{{ $item['source_state'] }} @if ($item['evidence'])
                                                <div class="muted">{{ $item['evidence'] }}</div>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7">No saved arrear component details for this posted salary
                                            bill.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                        <div class="heading">Arrear Reconciliation</div>
                        <table>
                            <tbody>
                                <tr>
                                    <td>Gross Arrear Payable</td>
                                    <td class="num">
                                        {{ $r['arrear_payable'] === null ? 'UNKNOWN' : number_format($r['arrear_payable'], 2) }}
                                    </td>
                                </tr>
                                <tr>
                                    <td>Arrear Deductions (including any saved Additional GPF)</td>
                                    <td class="num">
                                        {{ $r['arrear_deductions'] === null ? 'UNKNOWN' : number_format($r['arrear_deductions'], 2) }}
                                    </td>
                                </tr>
                                <tr class="net-row">
                                    <td>Net Arrear</td>
                                    <td class="num">
                                        {{ $r['net_arrear'] === null ? 'UNKNOWN' : number_format($r['net_arrear'], 2) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="note warning">The saved bill may contain only a three-month aggregate. Monthly 'NOT
                            ALLOCATED' values are not zero and must not be inferred by dividing by three. Revised/old
                            Basic, House Rent paid amounts and Special Benefit details are shown only when individually
                            recorded in the frozen source. This is a saved-bill statement, not a new entitlement
                            calculation.</div>
                    @endif
                    @if (!$r['details_complete'] || $r['remarks'])
                        <div class="note warning">
                            {{ $r['remarks'] ?: 'Some posted component source details are not fully verified.' }}</div>
                    @endif
                    <div class="note">Source: {{ $r['source'] }} · No historical salary or GPF records have been
                        recalculated. *Department is a current informational label; the posted Bill's frozen identity,
                        grade, designation and bank fields remain authoritative.</div>
                    {{-- <div class="signature">
                        <div>Prepared by</div>
                        <div>Accounts Officer</div>
                        <div>Signature of Employee</div>
                    </div> --}}
                @endif
                {{-- <footer class="footer"><span>Khulna Agricultural University · Official Salary Reporting · Saved Posted
                        Data</span><span>Page {{ $pageIndex + 1 }} / {{ count($pages) }} · Generated
                        {{ now()->format('d M Y') }}</span></footer> --}}
            </section>
        @endforeach
    </div>
</body>

</html>
