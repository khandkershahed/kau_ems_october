<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Provisional Horizontal Bill</title>
<style>@page{size:A4 landscape;margin:12mm}body{font:12px Arial,sans-serif;color:#152238}h1{font-size:19px;margin:0 0 6px}table{width:100%;border-collapse:collapse;margin-top:12px}th,td{border:1px solid #9da6b1;padding:6px;text-align:left}th{background:#edf2f7}.alert{border:2px solid #a16207;background:#fffbeb;padding:10px;margin:12px 0;font-weight:bold}.meta{margin:6px 0}.muted{color:#526070;font-size:11px}.action{margin:16px 0}@media print{.action{display:none}.alert{background:white}}</style></head><body>
<h1>Khulna Agricultural University — Provisional Horizontal Salary Bill</h1>
<div class="alert">PROVISIONAL — NOT GENERATED · Review copy only · NOT A PAYMENT ADVICE</div>
<p class="meta"><strong>Employee:</strong> {{ $employee->name }} (ID {{ $employee->id }}) &nbsp; <strong>Period:</strong> {{ sprintf('%04d-%02d',$year,$month) }} &nbsp; <strong>Status:</strong> {{ $workflow->workflow_status }}</p>
<p class="muted">Approved calculation version #{{ $version->id }} / v{{ $version->version }} · Snapshot SHA256: {{ $version->snapshot_hash }} · Snapshot created: {{ $version->created_at }} · Printed: {{ now()->toDateTimeString() }}</p>
<table><thead><tr><th>Regular component (saved)</th><th>Direction</th><th>Rule</th><th>Configured rate/value</th></tr></thead><tbody>
@foreach($additions as $item)<tr><td>{{ $item['name']??'Addition' }}</td><td>Addition</td><td>{{ $item['calcType']??'—' }}</td><td>{{ $item['amount']??'—' }}</td></tr>@endforeach
@foreach($deductions as $item)<tr><td>{{ $item['name']??'Deduction' }}</td><td>Deduction</td><td>{{ $item['calcType']??'—' }}</td><td>{{ $item['amount']??'—' }}</td></tr>@endforeach
</tbody></table>
<table><thead><tr><th>Saved financial result</th><th>Amount (BDT)</th></tr></thead><tbody>
@foreach(['basic'=>'Basic','total_allowance'=>'Gross / total additions','total_deduction'=>'Regular deductions','gpf_deduction'=>'GPF','net_salary'=>'Regular net salary'] as $key=>$label)
<tr><td>{{ $label }}</td><td>{{ array_key_exists($key,$regular) && is_numeric($regular[$key]) ? number_format((float)$regular[$key],2) : 'Not recorded in snapshot' }}</td></tr>@endforeach
</tbody></table>
<h3>Arrear — saved approved snapshot only</h3>
<table><thead><tr><th>Component</th><th>Direction</th><th>Pay/arrear months</th><th>Final amount (BDT)</th><th>Evidence / issue</th></tr></thead><tbody>
@forelse(($arrear['rows']??[]) as $line)<tr><td>{{ $line['code']??$line['title']??'Unnamed' }}</td><td>{{ $line['direction']??'—' }}</td><td>{{ is_array($line['months']??null) ? implode(', ',$line['months']) : '—' }}</td><td>{{ isset($line['final_amount']) && is_numeric($line['final_amount']) ? number_format((float)$line['final_amount'],2) : 'NOT VERIFIED' }}</td><td>{{ $line['evidence_reference']??$line['error']??'—' }}</td></tr>
@empty<tr><td colspan="5">No arrear lines in the approved snapshot. This does not establish a zero arrear entitlement.</td></tr>@endforelse</tbody></table>
<p class="muted">All figures are displayed from persisted salary_calculation_versions. This report does not recalculate salary, post a bill, credit GPF, or confirm payment. Do not treat missing source amounts as zero.</p>
<button class="action" onclick="window.print()">Print / Save as PDF</button></body></html>
