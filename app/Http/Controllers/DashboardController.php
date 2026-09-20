<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $role = $user->getRoleNames()->first();

        return Inertia::render('Dashboard', [
            'stats' => match ($role) {
                'owner', 'branch_admin', 'auditor' => $this->orgStats($user),
                'teacher' => $this->teacherStats($user),
                'accountant' => $this->accountantStats($user),
                'parent' => $this->parentStats($user),
                'student' => $this->studentStats($user),
                default => [],
            },
        ]);
    }

    private function invoicesInScope(User $user)
    {
        return Invoice::whereHas('student', function ($q) use ($user) {
            $q->whereIn('branch_id', $user->school->branches()->pluck('id'));
            if (! $user->isOrgWide()) {
                $q->where('branch_id', $user->branch_id);
            }
        })->whereHas('academicYear', fn ($q) => $q->where('is_current', true))
          ->with('items', 'payments');
    }

    private function pendingPaymentsCount(User $user): int
    {
        return Payment::where('status', 'pending')
            ->whereHas('invoice.student', function ($q) use ($user) {
                $q->whereIn('branch_id', $user->school->branches()->pluck('id'));
                if (! $user->isOrgWide()) {
                    $q->where('branch_id', $user->branch_id);
                }
            })->count();
    }

    private function scopedSectionIds(User $user)
    {
        $query = Section::whereIn('branch_id', $user->school->branches()->pluck('id'))
            ->whereHas('academicYear', fn ($q) => $q->where('is_current', true));

        if (! $user->isOrgWide()) {
            $query->where('branch_id', $user->branch_id);
        }

        return $query->pluck('id');
    }

    private function orgStats(User $user): array
    {
        $sectionIds = $this->scopedSectionIds($user);

        $studentsQuery = Student::where('school_id', $user->school_id)->where('status', 'active');
        $teachersQuery = User::where('school_id', $user->school_id)->whereHas('roles', fn ($q) => $q->where('name', 'teacher'));

        if (! $user->isOrgWide()) {
            $studentsQuery->where('branch_id', $user->branch_id);
            $teachersQuery->where('branch_id', $user->branch_id);
        }

        $today = now()->format('Y-m-d');
        $todayRecords = AttendanceRecord::where('date', $today)
            ->whereHas('enrollment', fn ($q) => $q->whereIn('section_id', $sectionIds))
            ->get();

        $marked = $todayRecords->count();
        $present = $todayRecords->whereIn('status', ['present', 'late'])->count();

        $invoices = $this->invoicesInScope($user)->get();
        $totalDue = $invoices->sum(fn (Invoice $i) => $i->totalAmount());
        $totalPaid = $invoices->sum(fn (Invoice $i) => $i->paidAmount());

        return [
            'total_students' => $studentsQuery->count(),
            'total_teachers' => $teachersQuery->count(),
            'today_attendance_rate' => $marked > 0 ? round($present / $marked * 100, 1) : null,
            'today_marked' => $marked,
            'fee_collection_rate' => $totalDue > 0 ? round($totalPaid / $totalDue * 100, 1) : null,
            'fees_total' => round($totalDue, 2),
            'fees_paid' => round($totalPaid, 2),
            'outstanding_fees' => round($totalDue - $totalPaid, 2),
            'pending_payments_count' => $this->pendingPaymentsCount($user),
        ];
    }

    private function accountantStats(User $user): array
    {
        $invoices = $this->invoicesInScope($user)->get();
        $totalDue = $invoices->sum(fn (Invoice $i) => $i->totalAmount());
        $totalPaid = $invoices->sum(fn (Invoice $i) => $i->paidAmount());

        $verifiedToday = Payment::where('status', 'verified')
            ->whereDate('verified_at', now())
            ->whereHas('invoice.student', function ($q) use ($user) {
                $q->whereIn('branch_id', $user->school->branches()->pluck('id'));
                if (! $user->isOrgWide()) {
                    $q->where('branch_id', $user->branch_id);
                }
            })->sum('amount');

        $verifiedThisMonth = Payment::where('status', 'verified')
            ->whereMonth('verified_at', now()->month)
            ->whereYear('verified_at', now()->year)
            ->whereHas('invoice.student', function ($q) use ($user) {
                $q->whereIn('branch_id', $user->school->branches()->pluck('id'));
                if (! $user->isOrgWide()) {
                    $q->where('branch_id', $user->branch_id);
                }
            })->sum('amount');

        return [
            'collected_today' => (float) $verifiedToday,
            'collected_this_month' => (float) $verifiedThisMonth,
            'fees_total' => round($totalDue, 2),
            'fees_paid' => round($totalPaid, 2),
            'outstanding_balance' => round($totalDue - $totalPaid, 2),
            'pending_payments_count' => $this->pendingPaymentsCount($user),
        ];
    }

    private function teacherStats(User $user): array
    {
        $homerooms = Section::where('class_teacher_id', $user->id)
            ->whereHas('academicYear', fn ($q) => $q->where('is_current', true))
            ->with('grade')
            ->get();

        $today = now()->format('Y-m-d');

        return [
            'homerooms' => $homerooms->map(fn (Section $s) => [
                'id' => $s->id,
                'label' => "{$s->grade->name} {$s->name}",
                'attendance_taken_today' => AttendanceRecord::where('date', $today)
                    ->whereHas('enrollment', fn ($q) => $q->where('section_id', $s->id))
                    ->exists(),
            ]),
            'teaching_assignments' => $user->teachingAssignments()->with(['section.grade', 'subject'])->get()->map(fn ($a) => [
                'subject' => $a->subject->name,
                'section' => "{$a->section->grade->name} {$a->section->name}",
            ]),
        ];
    }

    private function attendanceRateFor(int $studentId): ?array
    {
        $records = AttendanceRecord::whereHas(
            'enrollment',
            fn ($q) => $q->where('student_id', $studentId)
                ->whereHas('section.academicYear', fn ($q2) => $q2->where('is_current', true))
        )->get();

        if ($records->isEmpty()) {
            return ['rate' => null, 'today_status' => null];
        }

        $present = $records->whereIn('status', ['present', 'late'])->count();
        $todayString = now()->format('Y-m-d');
        $today = $records->first(fn ($r) => $r->date->format('Y-m-d') === $todayString);

        return [
            'rate' => round($present / $records->count() * 100, 1),
            'today_status' => $today?->status,
        ];
    }

    private function parentStats(User $user): array
    {
        return [
            'children' => $user->children->map(function (Student $child) {
                $attendance = $this->attendanceRateFor($child->id);

                $invoice = Invoice::where('student_id', $child->id)
                    ->whereHas('academicYear', fn ($q) => $q->where('is_current', true))
                    ->with('items', 'payments')
                    ->first();

                return [
                    'id' => $child->id,
                    'name' => $child->fullName(),
                    'attendance_rate' => $attendance['rate'],
                    'today_status' => $attendance['today_status'],
                    'invoice_id' => $invoice?->id,
                    'invoice_total' => $invoice?->totalAmount(),
                    'invoice_paid' => $invoice?->paidAmount(),
                    'balance' => $invoice?->balance(),
                ];
            }),
        ];
    }

    private function studentStats(User $user): array
    {
        $student = Student::where('school_id', $user->school_id)
            ->whereHas('guardians', fn ($q) => $q->whereKey($user->id))
            ->first();

        // Students don't yet have their own login-linked student_id;
        // in this phase a Student account's stats are blank until that
        // link exists (added when the Student portal itself is built).
        if (! $student) {
            return ['student_id' => null, 'attendance_rate' => null, 'today_status' => null];
        }

        return ['student_id' => $student->id, ...$this->attendanceRateFor($student->id)];
    }
}
