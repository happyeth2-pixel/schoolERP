<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\OptionalService;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Support\SchoolCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opting a student in to an optional service (transport route, after-school programme, ...), used by both the
 * student's profile and the registration wizard so the rules live in one place:
 *  - a student has at most ONE transport route per academic year: choosing another replaces the first;
 *  - the student's own price (custom_amount) is optional; NULL means "use the service's amount";
 *  - the charge lands on this period's invoice if one already exists, otherwise on the next generation run;
 *  - nothing already charged on an invoice is ever changed here (that is a billing decision).
 * Callers check permission and branch scope before calling.
 */
class StudentServices
{
    /**
     * `replaced` lists the transport routes this choice replaced; `already_billed` the ones among them whose
     * charge already sits on this period's invoice (someone may want to discount it).
     *
     * @return array{replaced: list<string>, already_billed: list<string>}
     */
    public static function enroll(User $actor, Student $student, OptionalService $service, ?float $customAmount = null, bool $billNow = true): array
    {
        return DB::transaction(function () use ($actor, $student, $service, $customAmount, $billNow) {
            if ($student->optionalServices()->whereKey($service->id)->exists()) {
                throw ValidationException::withMessages(['optional_service_id' => 'Already enrolled in this service.']);
            }

            $replaced = [];
            $alreadyBilled = [];

            if ($service->category === 'transport') {
                $others = $student->optionalServices()
                    ->where('optional_services.category', 'transport')
                    ->where('optional_services.academic_year_id', $service->academic_year_id)
                    ->where('optional_services.id', '!=', $service->id)
                    ->get();

                foreach ($others as $old) {
                    if ($billNow && self::chargeOnCurrentInvoice($student, $old)) {
                        $alreadyBilled[] = $old->name;
                    }
                    $student->optionalServices()->detach($old->id);
                    AuditLog::record($actor, 'student.service_withdrawn', $student, ['service' => $old->name], ['replaced_by' => $service->name]);
                    $replaced[] = $old->name;
                }
            }

            $student->optionalServices()->attach($service->id, [
                'enrolled_on' => SchoolCalendar::today()->toDateString(),
                'custom_amount' => $customAmount,
            ]);
            AuditLog::record($actor, 'student.service_joined', $student, null, array_filter([
                'service' => $service->name,
                'custom_amount' => $customAmount,
            ], fn ($v) => $v !== null));

            if ($billNow && ($invoice = self::currentPeriodInvoice($student, $service))) {
                $invoice->addLineFrom($service, 1, $customAmount);
            }

            return ['replaced' => $replaced, 'already_billed' => $alreadyBilled];
        });
    }

    /** Sets or clears (null) the student's own price. Invoices already generated keep their amounts. */
    public static function setCustomAmount(User $actor, Student $student, OptionalService $service, ?float $customAmount): void
    {
        $current = $student->optionalServices()->whereKey($service->id)->first();
        abort_unless($current, 404);

        $old = $current->pivot->custom_amount;
        $student->optionalServices()->updateExistingPivot($service->id, ['custom_amount' => $customAmount]);

        AuditLog::record($actor, 'student.service_price_changed', $student,
            ['service' => $service->name, 'custom_amount' => $old],
            ['service' => $service->name, 'custom_amount' => $customAmount]);
    }

    /**
     * The invoice this service's charge belongs on right now, if one already exists for the period we're in --
     * an annual invoice for the year, a termly one for whichever Term covers today, or a monthly one for the
     * current calendar month. Null means the next invoice-generation run will pick the charge up like any fee.
     */
    public static function currentPeriodInvoice(Student $student, OptionalService $service): ?Invoice
    {
        $periodStart = match ($service->frequency) {
            'annual' => $service->academicYear->start_date,
            'termly' => Term::where('academic_year_id', $service->academic_year_id)
                ->whereDate('start_date', '<=', now())
                ->whereDate('end_date', '>=', now())
                ->value('start_date'),
            'monthly' => Carbon::now()->startOfMonth()->toDateString(),
        };

        if (! $periodStart) {
            return null;
        }

        return Invoice::where('student_id', $student->id)
            ->where('academic_year_id', $service->academic_year_id)
            ->where('frequency', $service->frequency)
            ->whereDate('period_start', $periodStart)
            ->first();
    }

    private static function chargeOnCurrentInvoice(Student $student, OptionalService $service): bool
    {
        $invoice = self::currentPeriodInvoice($student, $service);

        return $invoice !== null && $invoice->items()->where('optional_service_id', $service->id)->exists();
    }
}
