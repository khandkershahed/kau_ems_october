<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Provisional Horizontal Bill</title><style>
@page{size:A3 landscape;margin:12mm}body{font:11px Arial,sans-serif;color:#14243a}h1{font-size:19px}table{border-collapse:collapse;width:100%;margin:12px 0}th,td{border:1px solid #94a3b8;padding:5px;text-align:right}th:first-child,td:first-child{text-align:left}th{background:#e2e8f0}.warning{border:2px solid #b45309;padding:10px;background:#fffbeb}.meta{font-size:10px;color:#475569}.action{margin:12px 0}@media print{.action{display:none}} </style></head><body>
<h1>Khulna Agricultural University — Provisional Horizontal Salary Bill</h1>
<div class="warning"><b>PROVISIONAL — NOT GENERATED</b> · {{ sprintf('%04d-%02d', $year, $month) }} · Read-only, saved approved snapshots · NOT a bank advice/payment authorization</div>
<p class="meta">Printed {{ now()->toDateTimeString() }} · Selected reviewed snapshots: {{count($rows)}} · Exclusions: {{count($errors)}}</p>
@if($errors)<div class="warning"><b>Excluded employees:</b><ul>@foreach($errors as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<table><thead><tr><th>Employee / version / hash</th><th>Basic</th><th>Additions</th><th>Deductions</th><th>GPF</th><th>Regular net</th>@foreach($arrearCodes as $code)<th>{{str_replace('_',' ', $code)}}</th>@endforeach<th>Evidence completeness</th></tr></thead><tbody>
@foreach($rows as $r)<tr><td>{{ $r['name'] }} (#{{ $r['user_id'] }})<div class="meta">V{{ $r['version'] }} #{{ $r['version_id'] }} · {{ $r['status'] }} · {{ $r['hash'] }}</div></td>
@foreach(['basic','total_allowance','total_deduction','gpf_deduction','net_salary'] as $k)<td>{{ $r['regular'][$k]===null?'NOT RECORDED':number_format($r['regular'][$k],2) }}</td>@endforeach
@foreach($arrearCodes as $k)<td>{{ $r['arrear'][$k]===null?'NOT RECORDED':number_format($r['arrear'][$k],2) }}</td>@endforeach
<td>{{count($r['missing'])?implode(', ',$r['missing']):'Recorded fields present'}}</td></tr>@endforeach
<tr><th>Totals (only when complete)</th>@foreach(['basic','total_allowance','total_deduction','gpf_deduction','net_salary'] as $k)<th>{{ $totals[$k]===null?'INCOMPLETE':number_format($totals[$k],2) }}</th>@endforeach
@foreach($arrearCodes as $k)<th>{{ $totals[$k]===null?'INCOMPLETE':number_format($totals[$k],2) }}</th>@endforeach<th>Never treat NOT RECORDED as zero</th></tr></tbody></table>
<p class="meta">Financial columns come exclusively from persisted calculation versions. A missing arrear line is NOT evidence of zero entitlement. No payroll bill or GPF ledger writes are performed.</p>
<button class="action" onclick="window.print()">Print / Save PDF</button></body></html>