<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 13px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 0; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #d1d5db; padding: 6px 10px; text-align: left; }
        th { background: #f3f4f6; }
        .summary { margin-top: 16px; }
        .summary td { border: none; padding: 2px 10px 2px 0; }
    </style>
</head>
<body>
    <h1>Student Report Card</h1>
    <p class="muted">{{ $academic_year ?? '' }}</p>

    <table class="summary">
        <tr><td><strong>Student:</strong></td><td>{{ $student['name'] }}</td></tr>
        <tr><td><strong>Student ID:</strong></td><td>{{ $student['student_id_number'] }}</td></tr>
        <tr><td><strong>Section:</strong></td><td>{{ $section ?? 'Unassigned' }}</td></tr>
    </table>

    <table>
        <thead>
            <tr><th>Subject</th><th>Average</th><th>Grade</th></tr>
        </thead>
        <tbody>
            @forelse ($subjects as $s)
                <tr>
                    <td>{{ $s['subject'] }}</td>
                    <td>{{ $s['average'] }}</td>
                    <td>{{ $s['letter'] ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="3">No published results yet.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="summary">
        <tr><td><strong>Overall Average:</strong></td><td>{{ $overall_average ?? '—' }}</td></tr>
        <tr><td><strong>Overall Grade:</strong></td><td>{{ $overall_letter ?? '—' }}</td></tr>
        <tr><td><strong>Class Rank:</strong></td><td>{{ $rank ? "{$rank} / {$class_size}" : '—' }}</td></tr>
    </table>
</body>
</html>
