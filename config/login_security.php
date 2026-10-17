<?php

/*
| Sign-in abuse protection. Every number here can be changed without touching code (or overridden in .env).
| See AUTHENTICATION.md, "Login abuse protection", for how the layers fit together.
|
| The layers, from the person outwards:
|   account   failures against one phone number / email, from anywhere: short, growing cool-downs, then a temporary lock
|   device    a browser that has signed in as that person before is judged on its own record, so someone else guessing
|             at their account cannot lock them out of their own device
|   ip        failures from one network address are watched, never locked: when a network is clearly attacking, only
|             browsers that are NOT known to the account are slowed down. A school's shared address is never shut.
|   burst     a hard cap on how fast one number can be tried from one address (stops scripts even inside the free tries)
*/

return [
    'account' => [
        // tries a person gets with no waiting between them: a wait follows the last one (3 -> the 3rd failure starts the first wait)
        'free_attempts' => (int) env('LOGIN_FREE_ATTEMPTS', 3),
        // seconds to wait after the 1st, 2nd, 3rd ... wait-earning failure (the last value repeats)
        'cooldowns' => [10, 30, 60, 300, 900],
        // total failures (without a quiet spell) that start a temporary lock, and how long it lasts
        'lock_after' => (int) env('LOGIN_LOCK_AFTER', 10),
        'lock_minutes' => (int) env('LOGIN_LOCK_MINUTES', 30),
        // failures are forgotten after this long without another one
        'forget_after_minutes' => (int) env('LOGIN_FORGET_AFTER_MINUTES', 60),
    ],

    'device' => [
        'cookie' => 'sd',
        'days' => (int) env('LOGIN_DEVICE_DAYS', 180),
        'max_accounts' => 10,
    ],

    'ip' => [
        'window_minutes' => (int) env('LOGIN_IP_WINDOW_MINUTES', 10),
        // failures from one address within the window that count as "attacking" (a busy school stays far below this)
        'failure_alert' => (int) env('LOGIN_IP_FAILURE_ALERT', 25),
        // different accounts failing from one address within the window (credential stuffing)
        'distinct_alert' => (int) env('LOGIN_IP_DISTINCT_ALERT', 8),
        'pressure_minutes' => (int) env('LOGIN_IP_PRESSURE_MINUTES', 10),
        // while under pressure, unknown browsers from that address wait this many seconds between attempts
        'pressure_delay' => (int) env('LOGIN_IP_PRESSURE_DELAY', 5),
        // failures in the window after which that wait grows, and to what
        'hard_ceiling' => (int) env('LOGIN_IP_HARD_CEILING', 300),
        'hard_delay' => (int) env('LOGIN_IP_HARD_DELAY', 30),
    ],

    'burst' => [
        'attempts' => (int) env('LOGIN_BURST_ATTEMPTS', 8),
        'per_seconds' => (int) env('LOGIN_BURST_SECONDS', 60),
    ],

    'events' => [
        'keep_days' => (int) env('LOGIN_EVENTS_KEEP_DAYS', 180),
    ],
];
