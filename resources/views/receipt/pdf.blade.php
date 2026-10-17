<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans', 'Noto Sans Ethiopic', sans-serif; font-size: 13px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 0; }
        h2 { font-size: 16px; margin: 0; }
        .muted { color: #6b7280; }
        .letterhead { display: table; width: 100%; margin-bottom: 12px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px; }
        .letterhead-logo { display: table-cell; width: 56px; vertical-align: middle; }
        .letterhead-logo img { max-width: 48px; max-height: 48px; }
        .letterhead-name { display: table-cell; vertical-align: middle; padding-left: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        td { padding: 4px 10px 4px 0; }
    </style>
</head>
<body>
    <div class="letterhead">
        @if ($student->school->logoDataUri())
        <div class="letterhead-logo"><img src="{{ $student->school->logoDataUri() }}" alt=""></div>
        @endif
        <div class="letterhead-name">
            <h2>{{ $student->school->name }}</h2>
        </div>
    </div>

    <h1>Payment Receipt</h1>
    <p class="muted">{{ $payment->receipt_number }}</p>

    <table>
        <tr><td><strong>Student:</strong></td><td>{{ $student->fullName() }}</td></tr>
        <tr><td><strong>Student ID:</strong></td><td>{{ $student->student_id_number }}</td></tr>
        <tr><td><strong>Invoice:</strong></td><td>{{ $payment->invoice->invoice_number }}</td></tr>
        <tr><td><strong>Amount Paid:</strong></td><td>{{ number_format($payment->amount, 2) }} ETB</td></tr>
        <tr><td><strong>Method:</strong></td><td>{{ str_replace('_', ' ', $payment->method) }}</td></tr>
        <tr><td><strong>Reference:</strong></td><td>{{ $payment->reference_number ?? '—' }}</td></tr>
        @if ($payment->paid_by_name)
        <tr><td><strong>Paid By:</strong></td><td>{{ $payment->paid_by_name }}</td></tr>
        @endif
        @if ($payment->isCounter())
        <tr><td><strong>Received:</strong></td><td>At the school office{{ $payment->verifiedBy ? ' by '.$payment->verifiedBy->name : '' }}</td></tr>
        @endif
        <tr><td><strong>Verified On:</strong></td><td>{{ \App\Support\SchoolCalendar::dateTime($payment->verified_at, $student->school) }}</td></tr>
    </table>
</body>
</html>
