<?php

namespace App\Http\Controllers;

use App\Exports\SimpleArrayExport;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\SchoolCalendar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $user->can('view-audit-log') || abort(403);

        $logs = $this->filtered($request)
            ->with('user')
            ->latest('created_at')
            ->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->user?->name ?? 'System',
                'auditable_type' => class_basename($log->auditable_type),
                'auditable_id' => $log->auditable_id,
                'subject_label' => $log->subject_label,
                'before' => $log->before,
                'after' => $log->after,
                'note' => $log->note,
                'ip_address' => $log->ip_address,
                'created_at' => SchoolCalendar::dateTime($log->created_at, $user),
            ]);

        $base = AuditLog::where('school_id', $user->school_id);

        return Inertia::render('AuditLog/Index', [
            'logs' => $logs,
            'filters' => $request->only('q', 'action', 'entity', 'user_id', 'from', 'to'),
            'entities' => (clone $base)->distinct()->pluck('auditable_type')->map(fn ($t) => class_basename($t))->sort()->values(),
            'actions' => (clone $base)->distinct()->orderBy('action')->pluck('action'),
            'actors' => User::whereIn('id', (clone $base)->whereNotNull('user_id')->distinct()->pluck('user_id'))->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function export(Request $request)
    {
        $request->user()->can('view-audit-log') || abort(403);
        $user = $request->user();

        $logs = $this->filtered($request)->with('user')->latest('created_at')->latest('id')->get();

        $rows = [
            [$user->school->name],
            ['Audit Log — '.SchoolCalendar::date(SchoolCalendar::today(), $user)],
            ['When', 'Who', 'Action', 'Record', 'Before', 'After', 'Reason / note', 'From IP'],
        ];

        foreach ($logs as $log) {
            $rows[] = [
                SchoolCalendar::dateTime($log->created_at, $user),
                $log->user?->name ?? 'System',
                $log->action,
                trim(class_basename($log->auditable_type).' #'.$log->auditable_id.' '.$log->subject_label),
                $log->before ? json_encode($log->before, JSON_UNESCAPED_UNICODE) : '',
                $log->after ? json_encode($log->after, JSON_UNESCAPED_UNICODE) : '',
                $log->note ?? '',
                $log->ip_address ?? '',
            ];
        }

        return Excel::download(new SimpleArrayExport($rows, 'Audit Log'), 'audit-log.xlsx');
    }

    /** The school's entries, narrowed by whatever filters were chosen (the export honours the same ones). */
    private function filtered(Request $request): Builder
    {
        $query = AuditLog::where('school_id', $request->user()->school_id);

        $query->when($request->filled('action'), fn ($q) => $q->where('action', $request->query('action')));
        $query->when($request->filled('entity'), fn ($q) => $q->where('auditable_type', 'like', '%\\'.$request->query('entity')));
        $query->when($request->filled('user_id'), fn ($q) => $q->where('user_id', (int) $request->query('user_id')));
        $query->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', SchoolCalendar::businessDayStartUtc($request->query('from'))));
        $query->when($request->filled('to'), fn ($q) => $q->where('created_at', '<', SchoolCalendar::businessDayStartUtc($request->query('to'))->addDay()));
        $query->when($request->filled('q'), function ($q) use ($request) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $request->query('q')).'%';
            $q->where(fn ($w) => $w->where('subject_label', 'like', $term)->orWhere('note', 'like', $term)->orWhere('action', 'like', $term));
        });

        return $query;
    }
}
