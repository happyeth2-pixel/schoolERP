<?php

namespace App\Http\Controllers;

use App\Exports\SimpleArrayExport;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $request->user()->can('view-audit-log') || abort(403);

        $logs = AuditLog::where('school_id', $request->user()->school_id)
            ->with('user')
            ->latest('created_at')
            ->paginate(30)
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->user?->name ?? 'System',
                'auditable_type' => class_basename($log->auditable_type),
                'auditable_id' => $log->auditable_id,
                'before' => $log->before,
                'after' => $log->after,
                'note' => $log->note,
                'created_at' => $log->created_at->format('M j, Y g:i A'),
            ]);

        return Inertia::render('AuditLog/Index', ['logs' => $logs]);
    }

    public function export(Request $request)
    {
        $request->user()->can('view-audit-log') || abort(403);
        $user = $request->user();

        $logs = AuditLog::where('school_id', $user->school_id)
            ->with('user')
            ->latest('created_at')
            ->get();

        $rows = [
            [$user->school->name],
            ['Audit Log — '.now()->format('M j, Y')],
            ['When', 'Who', 'Action', 'Record', 'Before', 'After', 'Note'],
        ];

        foreach ($logs as $log) {
            $rows[] = [
                $log->created_at->format('M j, Y g:i A'),
                $log->user?->name ?? 'System',
                $log->action,
                class_basename($log->auditable_type).' #'.$log->auditable_id,
                $log->before ? json_encode($log->before) : '',
                $log->after ? json_encode($log->after) : '',
                $log->note ?? '',
            ];
        }

        return Excel::download(new SimpleArrayExport($rows, 'Audit Log'), 'audit-log.xlsx');
    }
}
