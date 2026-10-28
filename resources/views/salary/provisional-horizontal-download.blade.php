<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>KAU Provisional Horizontal Bill — {{ sprintf('%04d-%02d', $year, $month) }}</title>
<style>
@page { size: A3 landscape; margin: 9mm; }
* { box-sizing: border-box; }
body { margin: 12px; font: 9px Arial, sans-serif; color: #17263a; }
h1 { font-size: 17px; margin: 0 0 5px; text-align: center; }
h2 { font-size: 11px; margin: 8px 0; text-align: center; }
.meta { text-align: center; margin: 4px 0 10px; }
.warning { border: 1px solid #bb7424; padding: 7px; margin: 7px 0; background: #fff9ed; }
.report-actions { margin: 9px 0; text-align: right; }
.report-actions button { padding: 8px 14px; background: #394ab9; color: white; border: 0; border-radius: 5px; cursor: pointer; }
.table-wrap { width: 100%; }
table { border-collapse: collapse; width: 100%; table-layout: fixed; font-size: 8px; }
th, td { border: 1px solid #6c7e99; padding: 4px 2px; text-align: right; vertical-align: middle; overflow-wrap: anywhere; }
th { background: #dfe7f1; text-align: center; }
th:nth-child(1), td:nth-child(1) { width: 2.8%; text-align: center; }
th:nth-child(2), td:nth-child(2) { width: 4.4%; text-align: center; }
th:nth-child(3), td:nth-child(3) { width: 8.5%; text-align: left; }
th:nth-child(4), td:nth-child(4) { width: 7%; text-align: left; }
th:last-child, td:last-child { width: 4.5%; }
small { font-size: 7px; display: block; color: #526176; }
tfoot th { font-weight: bold; background: #edf4ee; }
.missing { color: #a23c19; font-size: 7px; }
.warning ul { margin: 5px 0; padding-left: 20px; max-height: 120px; overflow-y: auto; }
.note { margin-top: 12px; font-size: 9px; }
@media print {
    body { margin: 0; }
    .report-actions { display: none; }
    .warning { background: white; }
    thead { display: table-header-group; }
    tfoot { display: table-row-group; }
    tr { break-inside: avoid; page-break-inside: avoid; }
}
</style>
</head>
<body>
<h1>Khulna Agricultural University</h1>
<h2>Provisional Horizontal Salary Bill — {{ sprintf('%02d/%04d', $month, $year) }}</h2>
<div class="meta"><b>PROVISIONAL — NOT GENERATED — NOT FOR BANK PAYMENT</b> · {{ count($rows) }} saved snapshots in report / {{ $requested }} requested · {{ count($errors) }} exclusions · Printed {{ now()->format('Y-m-d H:i') }}</div>
<div class="report-actions"><button type="button" onclick="window.print()">Print / Save as PDF</button></div>
@if($errors)
<div class="warning"><strong>Not included (no financial figures invented):</strong>
<ul>@foreach($errors as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
@if(count($rows))
<div class="table-wrap"><table>
<thead><tr>
<th>SL</th><th>ID</th><th>Name</th><th>Designation</th>
<th>Basic</th><th>HRA</th><th>MA</th><th>S Benefit</th><th>Edu</th><th>Other A</th>
<th>Reg Gross</th><th>GPF</th><th>Income T</th><th>Other D</th><th>Reg Ded</th>
<th>Basic Arrear</th><th>Basic Adj.</th><th>S.B. Recovery</th><th>HRA Recovery</th><th>Addl. GPF</th><th>Net</th><th>Signature</th>
</tr></thead>
<tbody>
@foreach($rows as $index => $row)
<tr>
<td>{{ $index+1 }}</td>
<td>{{ $row['employee_code'] }}<small>{{ strtoupper($row['version_status']) }}</small></td>
<td>{{ $row['name'] }}<small>Saved v{{ $row['version'] }} · {{ $row['workflow_status'] }}</small></td>
<td>{{ $row['designation'] }}</td>
@foreach($columns as $key)
@php($amount = $row['financial'][$key] ?? null)
<td class="{{ $amount === null ? 'missing' : '' }}">{{ $amount === null ? 'N/R' : number_format($amount, 2) }}</td>
@endforeach
<td></td>
</tr>
@endforeach
</tbody>
<tfoot><tr><th colspan="4">GRAND TOTAL — {{ count($rows) }} saved snapshots</th>
@foreach($columns as $key)<th>{{ $totals[$key] === null ? 'INCOMPLETE' : number_format($totals[$key], 2) }}</th>@endforeach
<th></th></tr></tfoot>
</table></div>
@endif
<div class="note">N/R = Not recorded in the saved snapshot. An absent arrear component is <b>not</b> assumed to be zero; its total is marked INCOMPLETE. Draft versions are clearly marked and are not approved salaries. All amounts come from saved calculation snapshots; this read-only report does not save, approve, calculate, generate, or post a salary bill.</div>
</body></html>
