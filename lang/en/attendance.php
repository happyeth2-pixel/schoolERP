<?php

// Mirrors resources/js/lang/en.json's "attendance.status_*" keys -- kept as a separate
// backend copy (not shared with the frontend bundle) so a backend-only context, like a
// Notification's toArray(), can render a status word without loading the JS i18n stack.
return [
    'status' => [
        'present' => 'Present',
        'absent' => 'Absent',
        'late' => 'Late',
        'excused' => 'Excused',
        'sick' => 'Sick',
    ],
];
