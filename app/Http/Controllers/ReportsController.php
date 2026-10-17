<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Production-readiness audit finding: every report/export in this system already existed and worked,
 * but each lived at its own route with no single place listing them all -- a user had to already know
 * where "Trial Balance" or "Attendance Report" lived. This page changes nothing about any report
 * itself; it is a read-only index. Visibility of each entry is decided server-side, by the EXACT same
 * permission check its own controller already enforces -- never hidden only in the browser.
 */
class ReportsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $accounting = $user->can('accounting-view') || $user->can('accounting-manage') || $user->hasRole('auditor');

        $groups = [
            [
                'key' => 'academic',
                'items' => [
                    ['key' => 'attendance_report', 'route' => 'attendance-report.index', 'show' => $user->can('view-attendance-reports')],
                ],
            ],
            [
                'key' => 'finance',
                'items' => [
                    ['key' => 'invoices_export', 'route' => 'invoices.export', 'download' => true, 'show' => $user->can('manage-invoices') || $user->can('view-financial-reports')],
                ],
            ],
            [
                'key' => 'accounting',
                'items' => [
                    ['key' => 'trial_balance', 'route' => 'reports.trial-balance', 'show' => $accounting],
                    ['key' => 'ledger', 'route' => 'reports.ledger', 'show' => $accounting],
                    ['key' => 'income_statement', 'route' => 'reports.income-statement', 'show' => $accounting],
                    ['key' => 'balance_sheet', 'route' => 'reports.balance-sheet', 'show' => $accounting],
                    ['key' => 'cash_summary', 'route' => 'reports.cash-summary', 'show' => $accounting],
                    ['key' => 'ar_aging', 'route' => 'reports.ar-aging', 'show' => $accounting],
                    ['key' => 'ap_aging', 'route' => 'reports.ap-aging', 'show' => $accounting],
                    ['key' => 'reconciliation', 'route' => 'reports.reconciliation', 'show' => $accounting],
                ],
            ],
            [
                'key' => 'people',
                'items' => [
                    ['key' => 'students_export', 'route' => 'students.export', 'download' => true, 'show' => $user->canViewOrManage('manage-students')],
                    ['key' => 'staff_export', 'route' => 'staff.export', 'download' => true, 'show' => $user->canViewOrManage('manage-staff')],
                ],
            ],
            [
                'key' => 'oversight',
                'items' => [
                    ['key' => 'audit_log', 'route' => 'audit-log.index', 'show' => $user->can('view-audit-log')],
                    ['key' => 'security_log', 'route' => 'security-log.index', 'show' => $user->can('view-audit-log')],
                ],
            ],
        ];

        // every group's items are filtered server-side -- an empty group is dropped entirely
        $groups = collect($groups)
            ->map(fn ($g) => ['key' => $g['key'], 'items' => collect($g['items'])->filter(fn ($i) => $i['show'])->map(fn ($i) => [
                'key' => $i['key'],
                'url' => route($i['route']),
                'download' => $i['download'] ?? false,
            ])->values()])
            ->filter(fn ($g) => $g['items']->isNotEmpty())
            ->values();

        return Inertia::render('Reports/Index', ['groups' => $groups]);
    }
}
