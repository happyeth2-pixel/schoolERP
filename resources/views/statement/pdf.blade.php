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
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 6px 8px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #f3f4f6; font-size: 12px; text-transform: uppercase; color: #4b5563; }
        .num { text-align: right; }
        .totals { margin-top: 18px; width: 50%; margin-left: auto; }
        .totals td { border: none; padding: 3px 8px; }
        .totals .grand { font-size: 16px; font-weight: bold; border-top: 2px solid #1f2937; }
        .status { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: bold; }
        .status-paid { background: #d1fae5; color: #065f46; }
        .status-partially_paid { background: #fef3c7; color: #92400e; }
        .status-unpaid { background: #fee2e2; color: #991b1b; }
        .status-waived { background: #e5e7eb; color: #374151; }
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

    <h1>Payment Statement</h1>
    <p class="muted">Generated {{ $generatedAt }}</p>

    <table style="margin-top: 16px;">
        <tr><td style="border: none; padding: 2px 8px 2px 0;"><strong>Student:</strong></td><td style="border: none;">{{ $student->fullName() }}</td></tr>
        <tr><td style="border: none; padding: 2px 8px 2px 0;"><strong>Student ID:</strong></td><td style="border: none;">{{ $student->student_id_number }}</td></tr>
    </table>

    <h2 style="margin-top: 18px;">Billing periods</h2>
    <table>
        <thead>
            <tr>
                <th>Period</th>
                <th>Invoice</th>
                <th class="num">Billed</th>
                <th class="num">Paid</th>
                <th class="num">Left</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($periods as $p)
            <tr>
                <td>{{ $p['period'] }}<br><span class="muted">{{ $p['period_start'] }}</span></td>
                <td>{{ $p['number'] }}</td>
                <td class="num">{{ number_format($p['total'], 2) }}</td>
                <td class="num">{{ number_format($p['paid'], 2) }}</td>
                <td class="num">{{ number_format($p['balance'], 2) }}</td>
                <td><span class="status status-{{ $p['status'] }}">{{ ucwords(str_replace('_', ' ', $p['status'])) }}</span></td>
            </tr>
            @empty
            <tr><td colspan="6" class="muted">No bills yet.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2 style="margin-top: 18px;">Payments received</h2>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Invoice</th>
                <th>Method</th>
                <th>Receipt No.</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($payments as $p)
            <tr>
                <td>{{ $p['date'] }}</td>
                <td>{{ $p['invoice_number'] }}</td>
                <td>{{ ucfirst($p['method']) }}{{ $p['at_school'] ? ' (at school)' : '' }}</td>
                <td>{{ $p['receipt_number'] ?? '—' }}</td>
                <td class="num">{{ number_format($p['amount'], 2) }}</td>
            </tr>
            @empty
            <tr><td colspan="5" class="muted">No payments yet.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Total billed</td><td class="num">{{ number_format($summary['net'], 2) }} ETB</td></tr>
        <tr><td>Total waived</td><td class="num">{{ number_format($summary['waived'], 2) }} ETB</td></tr>
        <tr><td>Total left to pay</td><td class="num">{{ number_format($summary['outstanding'], 2) }} ETB</td></tr>
        <tr class="grand"><td>Total paid to date</td><td class="num">{{ number_format($summary['collected'], 2) }} ETB</td></tr>
    </table>
</body>
</html>
