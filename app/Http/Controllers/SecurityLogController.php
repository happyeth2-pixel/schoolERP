<?php

namespace App\Http\Controllers;

use App\Models\SecurityEvent;
use App\Support\SchoolCalendar;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Sign-in security events (failed tries, cool-downs, locks, resets), for people who may read the audit log. */
class SecurityLogController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $user->can('view-audit-log') || abort(403);

        // this school's events, plus the ones that belong to no school (numbers that are nobody's, and network addresses)
        $base = SecurityEvent::query()->where(fn ($q) => $q->where('school_id', $user->school_id)->orWhereNull('school_id'));

        $events = (clone $base)
            ->with(['user:id,name', 'actor:id,name'])
            ->when($request->filled('event'), fn ($q) => $q->where('event', $request->query('event')))
            ->latest('created_at')->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (SecurityEvent $e) => [
                'id' => $e->id,
                'event' => $e->event,
                'who' => $e->user?->name,
                'identifier' => $e->identifier,
                'actor' => $e->actor?->name,
                'ip_address' => $e->ip_address,
                'context' => $e->context,
                'created_at' => SchoolCalendar::dateTime($e->created_at, $user),
            ]);

        return Inertia::render('AuditLog/Security', [
            'events' => $events,
            'filters' => $request->only('event'),
            'eventTypes' => (clone $base)->distinct()->orderBy('event')->pluck('event'),
        ]);
    }
}
