<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Invoice;
use App\Models\Student;
use App\Services\Finance;
use App\Support\PhoneNumber;
use App\Support\SchoolCalendar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Help a parent": the page a member of staff opens when a parent (with or without a portal account, smartphone or
 * email) asks at the school "how much do I owe?", "was my child here today?" or "I need the report card".
 *
 * It adds NO new power. Each part is shown only to someone who already holds the permission that opens that
 * information anywhere else, inside their own branch:
 *   fees        manage-invoices or view-financial-reports   (what InvoicePolicy::view already requires)
 *   record      verify-payments                              (InvoicePolicy::record)
 *   attendance  view-attendance-reports
 *   report card manage-students, or an auditor              (StudentPolicy::viewRecords)
 *   contacts    manage-students                              (the same rule as the student profile)
 * Someone who holds none of them cannot open the page at all; someone who holds some sees the rest as a plain
 * "ask someone who can" note rather than a hidden door, so the parent is sent to the right desk.
 */
class StudentDeskController extends Controller
{
    private const STATUSES = ['present', 'late', 'absent', 'excused', 'sick'];

    public function show(Request $request, Student $student): Response
    {
        $user = $request->user();

        abort_if($user->hasRole('parent') || $user->hasRole('student'), 403);
        abort_unless($student->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $user->branch_id === $student->branch_id, 403);

        $canFees = $user->can('manage-invoices') || $user->can('view-financial-reports');
        $canAttendance = $user->can('view-attendance-reports');
        $canReport = Gate::forUser($user)->allows('viewRecords', $student);
        $canContact = $user->can('manage-students');

        abort_unless($canFees || $canAttendance || $canReport || $canContact, 403);

        $student->load(['currentEnrollment.section.grade', 'guardians']);

        return Inertia::render('Students/Desk', [
            'student' => [
                'id' => $student->id,
                'name' => $student->fullName(),
                'student_id_number' => $student->student_id_number,
                'class' => $student->currentEnrollment?->section ? "{$student->currentEnrollment->section->grade->name} {$student->currentEnrollment->section->name}" : null,
                'status' => $student->status,
                'guardians' => $student->guardians->map(fn ($g) => [
                    'name' => $g->name,
                    'relationship' => Student::relationshipLabel($g->pivot->relationship),
                    'phone' => $canContact && $g->phone ? PhoneNumber::display($g->phone) : null,
                ])->values(),
            ],
            'can' => ['fees' => $canFees, 'attendance' => $canAttendance, 'report_card' => $canReport, 'profile' => $canContact],
            'fees' => $canFees ? $this->fees($user, $student) : null,
            'attendance' => $canAttendance ? $this->attendance($student) : null,
            'reportCard' => $canReport ? [
                'view_url' => route('report-card.show', $student->id),
                'pdf_url' => route('report-card.pdf', $student->id),
            ] : null,
            'profileUrl' => $canContact ? route('students.edit', $student->id) : null,
            // read-only oversight (an auditor) may look at the profile too; only managers are offered editing
            'viewProfileUrl' => $user->canViewOrManage('manage-students') ? route('students.edit', $student->id) : null,
            'cardUrl' => $canReport ? route('id-card.show', $student->id) : null,
        ]);
    }

    private function fees($user, Student $student): array
    {
        $invoices = Invoice::where('student_id', $student->id)
            ->with(['items', 'payments.refunds'])
            ->orderByDesc('period_start')->orderByDesc('id')
            ->limit(30)->get();

        $rows = $invoices->map(function (Invoice $i) use ($user) {
            $fig = $i->figures();
            $canRecord = Gate::forUser($user)->allows('record', $i) && ! $i->is_waived && $fig['outstanding'] > 0;

            return [
                'id' => $i->id,
                'number' => $i->invoice_number,
                'period' => $i->period_label ?? 'Full Year',
                'total' => $fig['net'],
                'paid' => $fig['collected'],
                'balance' => $i->is_waived ? 0 : max(0, $fig['outstanding']),
                'waived' => (bool) $i->is_waived,
                'status' => $fig['status'],
                'waiting' => $i->payments->where('status', 'pending')->count(),
                'url' => route('invoices.show', $i->id),
                'record_url' => $canRecord ? route('payments.record.create', $i->id) : null,
            ];
        })->sortBy(fn ($r) => $r['balance'] > 0 ? 0 : 1)->values();

        $summary = Finance::summarize($invoices);

        $recent = $invoices->flatMap(fn (Invoice $i) => $i->payments->where('status', 'verified')->map(fn ($p) => [$i, $p]))
            ->sortByDesc(fn ($pair) => $pair[1]->verified_at)->take(5)
            ->map(fn ($pair) => [
                'id' => $pair[1]->id,
                'amount' => (float) $pair[1]->amount,
                'method' => $pair[1]->method,
                'receipt_number' => $pair[1]->receipt_number,
                'invoice' => $pair[0]->invoice_number,
                'when' => $pair[1]->verified_at ? SchoolCalendar::dateTime($pair[1]->verified_at, $user) : null,
                'at_school' => $pair[1]->isCounter(),
                'receipt_url' => route('payments.receipt', $pair[1]->id),
            ])->values();

        return [
            'owed' => $summary['outstanding'],
            'invoices' => $rows,
            'recent_payments' => $recent,
        ];
    }

    /** Same formula as the parent's own attendance page: present + late out of every recorded day. */
    private function attendance(Student $student): array
    {
        $base = AttendanceRecord::whereIn('enrollment_id', $student->enrollments()->pluck('enrollments.id'));
        $records = (clone $base)->orderByDesc('date')->get();

        $totals = array_fill_keys(self::STATUSES, 0);
        foreach ($records as $r) {
            $totals[$r->status] = ($totals[$r->status] ?? 0) + 1;
        }
        $marked = $records->count();
        $today = SchoolCalendar::today()->toDateString();

        return [
            'today' => ['date' => $today, 'status' => (clone $base)->whereDate('date', $today)->value('status')],
            'recent' => $records->take(10)->map(fn ($r) => ['id' => $r->id, 'date' => $r->date->toDateString(), 'status' => $r->status, 'remarks' => $r->remarks])->values(),
            'totals' => $totals + ['marked' => $marked],
            'rate' => $marked > 0 ? round(($totals['present'] + $totals['late']) / $marked * 100, 1) : null,
        ];
    }
}
