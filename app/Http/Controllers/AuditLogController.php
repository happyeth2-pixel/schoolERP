<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
}
