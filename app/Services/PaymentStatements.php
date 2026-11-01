<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Student;
use App\Support\SchoolCalendar;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * A student's full payment history as one printable page: every billing period (paid, unpaid or
 * waived) and every verified payment, plus the lifetime total paid. Reused as-is by the parent's
 * own "Fees & Payments" page and by staff's "Help a parent" desk, so both print exactly the same
 * thing -- neither builds its own copy of this.
 */
class PaymentStatements
{
    public static function pdf(Student $student): PdfDocument
    {
        $invoices = Invoice::where('student_id', $student->id)
            ->with(['items', 'payments.refunds'])
            ->orderBy('period_start')
            ->get();

        $periods = $invoices->map(function (Invoice $i) use ($student) {
            $f = $i->figures();

            return [
                'period' => $i->period_label ?? 'Whole year',
                'period_start' => SchoolCalendar::date($i->period_start, $student->school),
                'number' => $i->invoice_number,
                'total' => $f['net'],
                'paid' => $f['collected'],
                'balance' => $i->is_waived ? 0 : max(0, $f['outstanding']),
                'status' => $f['status'],
            ];
        })->values();

        $payments = $invoices->flatMap(fn (Invoice $i) => $i->payments->where('status', 'verified')->map(fn ($p) => [$i, $p]))
            ->sortBy(fn ($pair) => $pair[1]->verified_at)
            ->map(fn ($pair) => [
                'date' => SchoolCalendar::dateTime($pair[1]->verified_at, $student->school),
                'amount' => (float) $pair[1]->amount,
                'method' => str_replace('_', ' ', $pair[1]->method),
                'receipt_number' => $pair[1]->receipt_number,
                'invoice_number' => $pair[0]->invoice_number,
                'at_school' => $pair[1]->isCounter(),
            ])->values();

        $summary = Finance::summarize($invoices);

        return Pdf::loadView('statement.pdf', [
            'student' => $student,
            'periods' => $periods,
            'payments' => $payments,
            'summary' => $summary,
            'generatedAt' => SchoolCalendar::dateTime(now(), $student->school),
        ]);
    }
}
