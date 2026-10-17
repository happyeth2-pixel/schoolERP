<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\Invoice;
use App\Models\InventoryItem;
use App\Models\InventoryStore;
use App\Models\LeaveRequest;
use App\Models\PayrollRun;
use App\Models\PerformanceReview;
use App\Models\StaffAttendance;
use App\Services\Finance;
use App\Models\Payment;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Support\SchoolCalendar;
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
                default => [],
            },
        ]);
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

        $studentsQuery = Student::where('school_id', $user->school_id)->enrolled();
        $teachersQuery = User::where('school_id', $user->school_id)->whereHas('roles', fn ($q) => $q->where('name', 'teacher'));

        if (! $user->isOrgWide()) {
            $studentsQuery->where('branch_id', $user->branch_id);
            $teachersQuery->where('branch_id', $user->branch_id);
        }

        $today = SchoolCalendar::today()->toDateString();
        $todayRecords = AttendanceRecord::whereDate('date', $today)
            ->whereHas('enrollment', fn ($q) => $q->whereIn('section_id', $sectionIds))
            ->get();

        $marked = $todayRecords->count();
        $present = $todayRecords->whereIn('status', ['present', 'late'])->count();

        // Money figures follow the same permissions as the finance screens (a front-desk registrar, who holds
        // none of them, used to be sent every fee figure and the pending-payment count regardless), so they are
        // only computed and sent to people who could open those screens anyway. null = "not for you".
        $seesMoney = $user->can('view-financial-reports') || $user->can('manage-invoices') || $user->can('manage-fee-structures'); // verifying proofs alone shows the queue, not school-wide totals
        $money = $seesMoney ? Finance::summarize(Finance::invoicesFor($user)->get()) : null;

        return [
            'total_students' => $studentsQuery->count(),
            'total_teachers' => $teachersQuery->count(),
            'today_attendance_rate' => $marked > 0 ? round($present / $marked * 100, 1) : null,
            'today_marked' => $marked,
            'fee_collection_rate' => $money['collection_rate'] ?? null,
            'fees_total' => $money['due'] ?? null,
            'fees_paid' => $money['collected'] ?? null,
            'outstanding_fees' => $money['outstanding'] ?? null,
            'fees_waived' => $money['waived'] ?? null,
            'fees_refunded' => $money['refunded'] ?? null,
            'pending_payments_count' => $user->can('verify-payments') ? $this->pendingPaymentsCount($user) : null,
            'inventory' => $this->inventoryStats($user),
            'requisitions' => $this->requisitionStats($user),
            'procurement' => $this->procurementStats($user),
            'assets' => $this->assetStats($user),
            'hr' => $this->hrStats($user),
        ];
    }

    /** HR Module, Phase G (final phase): a snapshot across everything the module built in Phases A-F.
     * null = this person has no HR view at all. Each sub-figure inside is independently gated on its OWN
     * phase's permission, the exact same "null = not for you" convention assetStats()/requisitionStats()
     * already use -- a branch_admin who can see employees but was never given payroll-view still gets
     * employee/contract/leave figures, just not a payroll one. */
    private function hrStats(User $user): ?array
    {
        $canHr = $user->can('employee-view') || $user->can('employee-manage') || $user->hasRole('auditor');
        if (! $canHr) {
            return null;
        }

        $employeeScope = fn ($q) => $q->where('school_id', $user->school_id)
            ->when(! $user->isOrgWide(), fn ($q2) => $q2->where('branch_id', $user->branch_id));

        $totalEmployees = Employee::where($employeeScope)->where('employment_status', 'active')->count();

        $canContracts = $user->can('contract-view') || $user->can('contract-manage') || $user->hasRole('auditor');
        $contractsExpiringSoon = $canContracts
            ? EmploymentContract::where('status', 'expiring')->whereHas('employee', $employeeScope)->count()
            : null;

        $canAttendance = $user->can('staff-attendance-view') || $user->can('staff-attendance-manage') || $user->hasRole('auditor');
        $attendanceToday = null;
        if ($canAttendance) {
            $today = SchoolCalendar::today()->toDateString();
            $todayRecords = StaffAttendance::whereDate('date', $today)->whereHas('employee', $employeeScope)->get();
            $marked = $todayRecords->count();
            $present = $todayRecords->whereIn('status', ['present', 'late'])->count();
            $attendanceToday = ['marked' => $marked, 'rate' => $marked > 0 ? round($present / $marked * 100, 1) : null];
        }

        $canLeave = $user->can('leave-view') || $user->can('leave-approve') || $user->hasRole('auditor');
        $pendingLeaveRequests = $canLeave
            ? LeaveRequest::where('status', 'submitted')->whereHas('employee', $employeeScope)->count()
            : null;

        $canPerformance = $user->can('performance-view') || $user->can('performance-manage') || $user->hasRole('auditor');
        $reviewsFinalizedThisYear = $canPerformance
            ? PerformanceReview::where('status', 'finalized')->whereYear('finalized_at', now()->year)->whereHas('employee', $employeeScope)->count()
            : null;

        $canPayroll = $user->can('payroll-view') || $user->can('payroll-manage') || $user->hasRole('auditor');
        $latestPayrollRun = null;
        if ($canPayroll) {
            $run = PayrollRun::where('school_id', $user->school_id)
                ->when(! $user->isOrgWide(), fn ($q) => $q->where('branch_id', $user->branch_id))
                ->orderByDesc('year')->orderByDesc('month')->first();
            $latestPayrollRun = $run ? ['label' => $run->label(), 'status' => $run->status] : null;
        }

        return [
            'total_employees' => $totalEmployees,
            'contracts_expiring_soon' => $contractsExpiringSoon,
            'attendance_today' => $attendanceToday,
            'pending_leave_requests' => $pendingLeaveRequests,
            'reviews_finalized_this_year' => $reviewsFinalizedThisYear,
            'latest_payroll_run' => $latestPayrollRun,
        ];
    }

    /** null = this person cannot open the asset screens, so nothing is sent. */
    private function assetStats(User $user): ?array
    {
        if (! $user->canViewOrManage('asset-view')) {
            return null;
        }

        $query = \App\Models\Asset::where('school_id', $user->school_id)
            ->when(! $user->isOrgWide(), fn ($q) => $q->where(fn ($q2) => $q2->whereNull('branch_id')->orWhere('branch_id', $user->branch_id)));

        return [
            'total' => (clone $query)->count(),
            'under_repair' => (clone $query)->where('status', 'under_repair')->count(),
        ];
    }

    /** null = this person has no requisition permission at all, so nothing is sent. */
    private function requisitionStats(User $user): ?array
    {
        if (! $user->can('requisition-create') && ! $user->can('requisition-approve') && ! $user->can('requisition-issue')) {
            return null;
        }

        $pendingApprovals = null;
        if ($user->can('requisition-approve')) {
            $storeIds = InventoryStore::where('school_id', $user->school_id)
                ->when(! $user->isOrgWide(), fn ($q) => $q->where(fn ($q2) => $q2->whereNull('branch_id')->orWhere('branch_id', $user->branch_id)))
                ->pluck('id');
            $pendingApprovals = \App\Models\Requisition::where('school_id', $user->school_id)->where('status', 'submitted')->whereIn('store_id', $storeIds)->count();
        }

        $myPending = \App\Models\Requisition::where('requester_id', $user->id)->whereIn('status', ['submitted', 'approved', 'partially_issued'])->count();

        return ['pending_approvals' => $pendingApprovals, 'my_pending' => $myPending];
    }

    /** §3 hardening: Procurement had no dashboard presence at all, unlike its sibling Inventory/Requisitions
     * (see requisitionStats() above, which this mirrors exactly). null = this person holds none of the
     * procurement permissions, so nothing is sent. Counts respect the large-purchase approval threshold
     * split: a plain `purchase-approve` holder's count excludes requests that actually need
     * `purchase-approve-large`, and vice versa -- never double-counted, never shown to someone who can't
     * actually act on it. */
    private function procurementStats(User $user): ?array
    {
        $canApprove = $user->can('purchase-approve');
        $canApproveLarge = $user->can('purchase-approve-large');
        if (! $user->can('purchase-request-create') && ! $canApprove && ! $canApproveLarge) {
            return null;
        }

        $pendingApprovals = null;
        if ($canApprove || $canApproveLarge) {
            $query = \App\Models\PurchaseRequest::where('school_id', $user->school_id)->where('status', 'submitted');
            if (! $user->isOrgWide()) {
                $query->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $user->branch_id));
            }
            $pendingApprovals = $query->with('items')->get()
                ->filter(fn ($r) => $r->needsLargeApproval() ? $canApproveLarge : $canApprove)
                ->count();
        }

        $myPending = \App\Models\PurchaseRequest::where('requester_id', $user->id)->whereIn('status', ['submitted', 'approved'])->count();

        return ['pending_approvals' => $pendingApprovals, 'my_pending' => $myPending];
    }

    /** null = this person cannot open the inventory screens either, so nothing is sent (same rule as $seesMoney above). */
    private function inventoryStats(User $user): ?array
    {
        if (! $user->canViewOrManage('inventory-view')) {
            return null;
        }

        $storeIds = InventoryStore::where('school_id', $user->school_id)
            ->when(! $user->isOrgWide(), fn ($q) => $q->where(fn ($q2) => $q2->whereNull('branch_id')->orWhere('branch_id', $user->branch_id)))
            ->pluck('id');

        $items = InventoryItem::where('school_id', $user->school_id)
            ->with(['balances' => fn ($q) => $q->whereIn('store_id', $storeIds)])
            ->get();

        $lowStock = 0;
        $outOfStock = 0;
        foreach ($items as $item) {
            $onHand = $item->totalOnHand($item->balances);
            if ($onHand <= 0) {
                $outOfStock++;
            } elseif ($item->isLowStock($onHand)) {
                $lowStock++;
            }
        }

        return [
            'total_items' => $items->count(),
            'low_stock' => $lowStock,
            'out_of_stock' => $outOfStock,
        ];
    }

    private function accountantStats(User $user): array
    {
        $money = Finance::summarize(Finance::invoicesFor($user)->get());
        $today = Finance::cashFlow($user, ...Finance::today());
        $month = Finance::cashFlow($user, ...Finance::thisMonth());

        return [
            // money that moved in the period, net of refunds (any year's invoice)
            'collected_today' => $today['net'],
            'collected_this_month' => $month['net'],
            'refunded_today' => $today['refunded'],
            'refunded_this_month' => $month['refunded'],
            // position of the current year's invoices
            'fees_total' => $money['due'],
            'fees_paid' => $money['collected'],
            'outstanding_balance' => $money['outstanding'],
            'fees_waived' => $money['waived'],
            'pending_payments_count' => $this->pendingPaymentsCount($user),
            'inventory' => $this->inventoryStats($user),
            'requisitions' => $this->requisitionStats($user),
        ];
    }

    private function teacherStats(User $user): array
    {
        $homerooms = Section::where('class_teacher_id', $user->id)
            ->whereHas('academicYear', fn ($q) => $q->where('is_current', true))
            ->with('grade')
            ->get();

        $today = SchoolCalendar::today()->toDateString();

        return [
            'homerooms' => $homerooms->map(function (Section $s) use ($today) {
                $rosterCount = $s->enrollments()->active()->count();
                $markedCount = AttendanceRecord::whereDate('date', $today)
                    ->whereHas('enrollment', fn ($q) => $q->where('section_id', $s->id))
                    ->count();

                return [
                    'id' => $s->id,
                    'label' => "{$s->grade->name} {$s->name}",
                    'grade' => $s->grade->name,
                    'section_name' => $s->name,
                    'roster_count' => $rosterCount,
                    'marked_count' => $markedCount,
                    'attendance_status' => $rosterCount === 0
                        ? 'empty'
                        : ($markedCount === 0 ? 'none' : ($markedCount < $rosterCount ? 'partial' : 'complete')),
                ];
            }),
            // classes they teach a subject in but are NOT class teacher of: their attendance belongs to that class's own teacher
            'taught_only' => $user->teachingAssignments()->with('section.grade')->get()
                ->map(fn ($a) => $a->section)
                ->filter(fn ($sec) => $sec && $sec->class_teacher_id !== $user->id)
                ->unique('id')
                ->map(fn ($sec) => "{$sec->grade->name} {$sec->name}")
                ->values(),
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
        $todayString = SchoolCalendar::today()->toDateString();
        $today = $records->first(fn ($r) => $r->date->format('Y-m-d') === $todayString);

        return [
            'rate' => round($present / $records->count() * 100, 1),
            'today_status' => $today?->status,
        ];
    }

    /**
     * Everything the parent's home page shows, per child of THEIR OWN (the guardian link is the only way in): the
     * class, today's attendance and the last few days, and the money for the current school year added up across
     * all of the child's bills (a child can have several once bills are made per term or month), with each bill
     * listed so a parent can open the one that is due. Only figures that already existed are used; the money is
     * Finance's, never recalculated here.
     */
    private function parentStats(User $user): array
    {
        $user->loadMissing('children.currentEnrollment.section.grade');

        return [
            'children' => $user->children->sortBy(fn (Student $c) => mb_strtolower($c->first_name))->map(function (Student $child) {
                $attendance = $this->attendanceRateFor($child->id);

                $invoices = Invoice::where('student_id', $child->id)
                    ->whereHas('academicYear', fn ($q) => $q->where('is_current', true))
                    ->with('items', 'payments.refunds')
                    ->orderBy('period_start')
                    ->get();
                $sum = Finance::summarize($invoices);
                // the bill to open first: the oldest one that still owes something, otherwise the latest
                $next = $invoices->first(fn (Invoice $i) => $i->balance() > 0) ?? $invoices->last();
                $waiting = $invoices->flatMap->payments->where('status', 'pending');

                $recent = AttendanceRecord::whereIn('enrollment_id', $child->enrollments()->pluck('enrollments.id'))
                    ->orderByDesc('date')->limit(7)->get(['date', 'status']);

                $section = $child->currentEnrollment?->section;

                return [
                    'id' => $child->id,
                    'name' => $child->fullName(),
                    'first_name' => $child->first_name,
                    'class' => $section ? "{$section->grade->name} {$section->name}" : null,
                    'attendance_rate' => $attendance['rate'],
                    'today_status' => $attendance['today_status'],
                    'recent_attendance' => $recent->map(fn ($r) => ['date' => $r->date->toDateString(), 'status' => $r->status])->values(),
                    'invoice_id' => $next?->id,
                    'invoice_total' => $invoices->isEmpty() ? null : $sum['net'],
                    'invoice_paid' => $invoices->isEmpty() ? null : $sum['collected'],
                    'balance' => $invoices->isEmpty() ? null : $sum['outstanding'],
                    'bills' => $invoices->map(function (Invoice $i) {
                        $f = $i->figures();

                        return [
                            'id' => $i->id,
                            'label' => $i->period_label ?? 'Whole year',
                            'total' => $f['net'],
                            'paid' => $f['collected'],
                            'balance' => $f['outstanding'],
                            'status' => $f['status'],
                        ];
                    })->values(),
                    'payments_waiting' => $waiting->count(),
                    'payments_waiting_amount' => round((float) $waiting->sum('amount'), 2),
                ];
            })->values(),
        ];
    }
}
