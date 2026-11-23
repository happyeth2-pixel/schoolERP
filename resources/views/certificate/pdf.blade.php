<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        body { font-family: 'DejaVu Sans', 'Noto Sans Ethiopic', sans-serif; color: #16241d; margin: 0; }
        .sheet { page-break-after: always; }
        .sheet:last-child { page-break-after: auto; }
        .frame {
            margin: 18px;
            border: 3px solid #c9a24b;
            padding: 10px;
        }
        .frame-inner {
            border: 1px solid #c9a24b;
            padding: 36px 54px;
            text-align: center;
            min-height: 470px;
        }
        .crest { width: 72px; height: 72px; border-radius: 50%; border: 2px solid #c9a24b; }
        .kicker { letter-spacing: 4px; font-size: 11px; color: #7d621d; text-transform: uppercase; margin-top: 14px; }
        .title { font-size: 30px; font-weight: bold; color: #0e3b2e; margin: 6px 0 2px; }
        .subtitle { font-size: 13px; color: #52412a; margin-bottom: 18px; }
        .presented { font-size: 12px; color: #6b7280; margin-top: 8px; }
        .name { font-size: 34px; font-weight: bold; color: #0e3b2e; margin: 10px 0; border-bottom: 2px solid #c9a24b; display: inline-block; padding: 0 24px 6px; }
        .rank-badge { margin: 18px auto 6px; width: 92px; height: 92px; border-radius: 50%; background: #c9a24b; color: #ffffff; font-size: 16px; font-weight: bold; text-align: center; line-height: 92px; }
        .rank-label { font-size: 15px; font-weight: bold; color: #7d621d; margin-top: 4px; }
        .detail { font-size: 13px; color: #16241d; margin-top: 14px; }
        .muted { color: #6b7280; font-size: 11px; }
        .sign-row { display: table; width: 100%; margin-top: 46px; }
        .sign-cell { display: table-cell; width: 50%; text-align: center; }
        .sign-line { border-top: 1px solid #16241d; width: 70%; margin: 0 auto 6px; padding-top: 6px; font-size: 11px; }
    </style>
</head>
<body>
    @foreach ($students as $s)
    <div class="sheet">
        <div class="frame">
            <div class="frame-inner">
                @if ($logo)
                    <img class="crest" src="{{ $logo }}" alt="">
                @endif

                <p class="kicker">{{ $school->name }}</p>
                <p class="title">Certificate of Academic Excellence</p>
                <p class="subtitle">{{ $section }} &middot; {{ $academic_year }}</p>

                <p class="presented">This certificate is proudly presented to</p>
                <p class="name">{{ $s['name'] }}</p>

                @php
                    $ord = match ((int) $s['rank']) { 1 => '1st', 2 => '2nd', 3 => '3rd', default => $s['rank'].'th' };
                @endphp
                <div class="rank-badge">{{ $ord }}</div>
                <p class="rank-label">{{ $ord }} Place — Top of Class</p>

                <p class="detail">
                    In recognition of outstanding academic performance
                    @if (!empty($s['overall_average']))
                        with an overall average of <strong>{{ $s['overall_average'] }}%</strong>
                    @endif
                    for {{ $academic_year }}.
                </p>

                <div class="sign-row">
                    <div class="sign-cell"><div class="sign-line">Homeroom Teacher</div></div>
                    <div class="sign-cell"><div class="sign-line">Principal</div></div>
                </div>
            </div>
        </div>
    </div>
    @endforeach
</body>
</html>
