<!doctype html>
<html><body>
<p>Dear {{ $fixation->employee->name }},</p>
<p>Your individual 2026 salary fixation statement is available for your review in the university employee portal.</p>
<p><a href="{{ route('salary-fixation.employee-review', $fixation->id) }}">View your fixation and submit your response</a></p>
<p>After signing in, please check the grade, historical basic pay and effective-dated amounts. Choose Confirm if accurate, or Dispute and explain any discrepancy. HR must separately approve the result.</p>
<p>This is a confidential payroll record. Please do not forward the link.</p>
<p>Khulna Agricultural University — HR &amp; Accounts</p>
</body></html>
