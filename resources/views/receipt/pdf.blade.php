<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 13px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 0; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        td { padding: 4px 10px 4px 0; }
    </style>
</head>
<body>
    <h1>Payment Receipt</h1>
    <p class="muted">{{ $payment->receipt_number }}</p>

    <table>
        <tr><td><strong>Student:</strong></td><td>{{ $student->fullName() }}</td></tr>
        <tr><td><strong>Student ID:</strong></td><td>{{ $student->student_id_number }}</td></tr>
        <tr><td><strong>Invoice:</strong></td><td>{{ $payment->invoice->invoice_number }}</td></tr>
        <tr><td><strong>Amount Paid:</strong></td><td>{{ number_format($payment->amount, 2) }} ETB</td></tr>
        <tr><td><strong>Method:</strong></td><td>{{ str_replace('_', ' ', $payment->method) }}</td></tr>
        <tr><td><strong>Reference:</strong></td><td>{{ $payment->reference_number ?? '—' }}</td></tr>
        <tr><td><strong>Verified On:</strong></td><td>{{ $payment->verified_at->format('M j, Y g:i A') }}</td></tr>
    </table>
</body>
</html>
