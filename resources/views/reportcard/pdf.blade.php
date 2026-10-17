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
        th, td { border: 1px solid #d1d5db; padding: 6px 10px; text-align: left; }
        th { background: #f3f4f6; }
        .summary { margin-top: 16px; }
        .summary td { border: none; padding: 2px 10px 2px 0; }
    </style>
</head>
<body>
    @isset($school)
    <div class="letterhead">
        @if ($school->logoDataUri())
        <div class="letterhead-logo"><img src="{{ $school->logoDataUri() }}" alt=""></div>
        @endif
        <div class="letterhead-name">
            <h2>{{ $school->name }}</h2>
        </div>
    </div>
    @endisset

    <h1>Student Report Card</h1>
    <p class="muted">{{ $academic_year ?? '' }}</p>

    @foreach (($weight_warnings ?? []) as $w)
        <p style="color: #92400e; background: #fffbeb; padding: 6px 10px; font-size: 12px;">{{ $w }}</p>
    @endforeach

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
                    <td>
                        {{ $s['subject'] }}
                        @if ($s['assessments_missing'] > 0)
                            <br><span class="muted" style="font-size: 11px;">
                                {{ ($missing_mark_policy ?? 'exclude') === 'zero'
                                    ? $s['assessments_missing'].' of '.$s['assessments_published'].' exams had no score - counted as 0'
                                    : 'Based on '.$s['assessments_counted'].' of '.$s['assessments_published'].' exams ('.$s['assessments_missing'].' had no score)' }}
                            </span>
                        @endif
                    </td>
                    <td>{{ $s['average'] ?? '—' }}</td>
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
        <tr><td><strong>Class Rank:</strong></td><td>{{ $rank ? "{$rank} / {$class_size}" : 'Not ranked' }}</td></tr>
    </table>

    @if (! empty($rank_basis))
        <div class="muted" style="font-size: 11px; margin-top: 12px;">
            @if (! $rank && ! empty($rank_reasons))
                <p><strong>Why there is no class rank:</strong></p>
                <ul>@foreach ($rank_reasons as $r)<li>{{ $r }}</li>@endforeach</ul>
            @endif
            <p><strong>How the class rank is calculated (rules v{{ $rank_basis['rules_version'] }}):</strong></p>
            <ul>
                <li>Class: {{ $rank_basis['class'] }}</li>
                <li>Subjects required: {{ count($rank_basis['required_subjects']) ? implode(', ', $rank_basis['required_subjects']) : 'none published yet' }}</li>
                <li>Who is ranked: {{ $rank_basis['eligibility'] }}</li>
                <li>Order: {{ $rank_basis['ordering'] }}</li>
                <li>This class: {{ $rank_basis['ranked_count'] }} ranked, {{ $rank_basis['not_ranked_count'] }} not ranked, {{ $rank_basis['class_size'] }} in total.</li>
            </ul>
        </div>
    @endif

    @if (! empty($policy_note))
        <p class="muted" style="font-size: 11px; margin-top: 12px;">Only published results are included. Missing scores: {{ $policy_note }}</p>
    @endif
</body>
</html>
