<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\FeeStructure;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\Mark;
use App\Models\MarkBatch;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\Term;
use App\Models\User;
use App\Notifications\PaymentVerified;
use App\Notifications\StudentMarkedAbsent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The extra data the usability-test tasks in USABILITY_TESTING.md need on top of DemoSchoolSeeder and
 * SampleAcademicDataSeeder -- so a participant is never asked to do something the data cannot support
 * (a parent with two children, an invoice that is already partly paid, a proof waiting to be verified,
 * a grade with no section yet, published marks for a report card...).
 *
 * Run ONLY against a throwaway/staging copy, never a real school:
 *   php artisan db:seed --class=SampleAcademicDataSeeder
 *   php artisan db:seed --class=UsabilitySessionSeeder
 * Deliberately not in DatabaseSeeder (the production container runs that on every boot) and it refuses
 * to run when APP_ENV=production. Idempotent: safe to run twice.
 *
 * Everything is created with the app's own models and rules (publishing goes through
 * MarkBatch::publishBy, so report-card snapshots exist exactly as they would for real).
 */
class UsabilitySessionSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('UsabilitySessionSeeder creates fake families and payments. It must never run in production.');
        }

        $school = School::where('code', 'DEMO')->first()
            ?? throw new RuntimeException('Run DatabaseSeeder and SampleAcademicDataSeeder first.');
        $branch = Branch::where('school_id', $school->id)->where('code', 'MAIN')->firstOrFail();
        $year = AcademicYear::where('school_id', $school->id)->where('is_current', true)->firstOrFail();

        $principal = User::where('email', 'principal@demo.test')->firstOrFail();
        $accountant = User::where('email', 'accountant@demo.test')->firstOrFail();
        $parent = User::where('email', 'parent@demo.test')->firstOrFail();
        $teacher = User::where('email', 'teacher@demo.test')->firstOrFail();
        $teacher2 = User::where('email', 'teacher2@demo.test')->firstOrFail();

        $sectionOf = fn (string $grade) => Section::where('branch_id', $branch->id)->where('academic_year_id', $year->id)
            ->whereHas('grade', fn ($q) => $q->where('name', $grade))->firstOrFail();

        // ---- teacher: a subject, a term whose exam weights add to 100, and an assignment with nothing entered yet
        $term = Term::firstOrCreate(['academic_year_id' => $year->id, 'name' => 'Term 1'], ['start_date' => '2026-09-01', 'end_date' => '2026-12-20']);
        $midterm = Exam::firstOrCreate(['school_id' => $school->id, 'term_id' => $term->id, 'name' => 'Mid-term'], ['type' => 'midterm', 'weight_percent' => 30]);
        Exam::firstOrCreate(['school_id' => $school->id, 'term_id' => $term->id, 'name' => 'Final'], ['type' => 'final', 'weight_percent' => 70]);
        $maths = Subject::firstOrCreate(['school_id' => $school->id, 'name' => 'Mathematics'], ['code' => 'MATH']);
        $english = Subject::firstOrCreate(['school_id' => $school->id, 'name' => 'English'], ['code' => 'ENG']);

        $grade3 = $sectionOf('Grade 3');                    // Samuel Girma is its homeroom teacher (SampleAcademicDataSeeder)
        TeachingAssignment::firstOrCreate(['section_id' => $grade3->id, 'subject_id' => $maths->id], ['teacher_id' => $teacher->id]);

        // ---- parent with TWO children, one invoice partly paid and verified, one unpaid
        $child1Section = $sectionOf('Grade 2');
        $child2Section = $sectionOf('Grade 5');
        $child1 = $this->unpaidChild($child1Section, $year);
        $child2 = $this->unpaidChild($child2Section, $year);
        // The sample generator recycles names, so give this family two clearly different children with the
        // parent's own family name (Kebede Worku) -- "switch child" means nothing if both read the same.
        $child1->update(['first_name' => 'Rahel', 'father_name' => 'Kebede', 'grandfather_name' => 'Worku', 'gender' => 'female']);
        $child2->update(['first_name' => 'Dawit', 'father_name' => 'Kebede', 'grandfather_name' => 'Worku', 'gender' => 'male']);
        foreach ([$child1, $child2] as $child) {
            $child->guardians()->syncWithoutDetaching([$parent->id => ['relationship' => 'father']]);
        }

        // published marks for both children's sections (a report card to look at)
        foreach ([[$child1Section, $teacher2], [$child2Section, $teacher2]] as [$section, $t]) {
            foreach ([$maths, $english] as $subject) {
                TeachingAssignment::firstOrCreate(['section_id' => $section->id, 'subject_id' => $subject->id], ['teacher_id' => $t->id]);
                $this->publishedBatch($midterm, $section, $subject, $t, $principal);
            }
        }

        // school payment accounts the parent is told to pay into
        PaymentAccount::firstOrCreate(
            ['school_id' => $school->id, 'display_name' => 'Commercial Bank of Ethiopia'],
            ['type' => 'bank', 'account_number' => '1000123456789', 'account_name' => 'Demo Academy']
        );
        PaymentAccount::firstOrCreate(
            ['school_id' => $school->id, 'display_name' => 'Telebirr'],
            ['type' => 'mobile_money', 'account_number' => '0911 00 01 11']
        );

        $invoice1 = $this->invoiceOf($child1, $year);
        $partly = $this->verifiedPayment($invoice1, $parent, $accountant, round($invoice1->totalAmount() * 0.4, 2), 'FT26-UAT-0001', 'RCT-UAT-0001');

        // a fully paid invoice the accountant can practise a refund on
        $refundChild = $this->unpaidChild($sectionOf('Grade 8'), $year);
        $refundGuardian = $refundChild->guardians()->first();
        $refundInvoice = $this->invoiceOf($refundChild, $year);
        if ($refundGuardian) {
            $this->verifiedPayment($refundInvoice, $refundGuardian, $accountant, (float) $refundInvoice->totalAmount(), 'FT26-UAT-0002', 'RCT-UAT-0002');
        }

        // a pending PARTIAL proof (with a real image) waiting in the accountant's queue
        $queueChild = $this->unpaidChild($sectionOf('Grade 7'), $year);
        $queueGuardian = $queueChild->guardians()->first();
        $amount = '-';
        if ($queueGuardian) {
            $queueInvoice = $this->invoiceOf($queueChild, $year);
            $amount = round($queueInvoice->totalAmount() * 0.5, 2);
            Payment::create([
                'invoice_id' => $queueInvoice->id, 'amount' => $amount, 'method' => 'mobile_money', 'reference_number' => 'TB-778812',
                'proof_path' => $this->fakeReceipt("UAT-QUEUE-{$queueInvoice->id}", $amount), 'submitted_by' => $queueGuardian->id, 'status' => 'pending',
            ]);
        }

        // ---- attendance history for the parent's children, including an absence and a late arrival
        $this->attendance($child1, $teacher2, absent: 2, late: 4);
        $this->attendance($child2, $teacher2, absent: 3);

        // ---- notifications the parent can find in the bell
        if ($parent->notifications()->count() === 0) {
            $parent->notify(new StudentMarkedAbsent($child1, $this->schoolDay(2)));
            $parent->notify(new PaymentVerified($partly));
        }

        // ---- accountant: a section whose invoices have NOT been generated (Grade 9, termly fee)
        $grade9 = Grade::firstOrCreate(['school_id' => $school->id, 'name' => 'Grade 9'], ['sort_order' => 9]);
        $section9 = Section::firstOrCreate(
            ['branch_id' => $branch->id, 'grade_id' => $grade9->id, 'academic_year_id' => $year->id, 'name' => 'A'],
            ['capacity' => 40, 'class_teacher_id' => $teacher2->id]
        );
        FeeStructure::firstOrCreate(
            ['branch_id' => $branch->id, 'grade_id' => $grade9->id, 'academic_year_id' => $year->id, 'name' => 'Tuition'],
            ['amount' => 1500, 'frequency' => 'termly']
        );
        foreach (['Hanna Tsegaye', 'Robel Mulugeta', 'Saron Demeke'] as $i => $full) {
            [$first, $father] = explode(' ', $full);
            $number = 'STU-UAT-G9-'.($i + 1);
            if (Student::where('student_id_number', $number)->exists()) {
                continue;
            }
            $s = Student::create([
                'school_id' => $school->id, 'branch_id' => $branch->id, 'student_id_number' => $number, 'first_name' => $first,
                'father_name' => $father, 'gender' => $i === 1 ? 'male' : 'female', 'date_of_birth' => '2011-03-1'.($i + 1),
                'enrollment_date' => '2026-09-01', 'status' => 'active', 'id_card_token' => Student::newCardToken(),
            ]);
            Enrollment::create(['student_id' => $s->id, 'section_id' => $section9->id, 'enrolled_on' => '2026-09-01', 'status' => 'active']);
        }

        // ---- administrator: a grade with NO section and NO fee structure yet
        Grade::firstOrCreate(['school_id' => $school->id, 'name' => 'Grade 10'], ['sort_order' => 10]);

        // grade included: the sample generator recycles names, so two students can share a full name
        $name = fn (?Student $st) => $st ? $st->fullName().' ('.($st->currentEnrollment?->section?->grade?->name ?? '?').')' : '(none)';
        $this->command?->info(implode("
", [
            '',
            'Usability session data ready. Every password is "password".',
            '  Receptionist   registrar@demo.test   Grade 4 - Section A has room for the new student.',
            '  Teacher        teacher@demo.test     Homeroom + Mathematics: Grade 3 - A; nothing entered yet, no attendance taken today.',
            '  Accountant     accountant@demo.test  Grade 9 (3 students, termly fee) has no invoices yet.',
            "                                       Pending PARTIAL proof (Telebirr TB-778812, ETB {$amount}) from the family of: ".$name($queueChild),
            '                                       Fully paid invoice to practise a refund on: '.$name($refundChild),
            '                                       Partly paid (40%, verified) invoice: '.$name($child1),
            '  Parent         parent@demo.test      Two children: '.$name($child1).' - invoice 40% paid; '.$name($child2).' - invoice unpaid.',
            '  Administrator  owner@demo.test       Grade 10 has no section or fee structure. (The demo Principal cannot open the audit log.)',
            '  Staff holding verify-payments (the accountant, owner) can also RECORD money received at the school: Find a student > Help a parent > Fees > Record payment received.',
            '',
        ]));
    }

    /** First active student in the section whose annual invoice has no payments yet (so numbers stay predictable). */
    private function unpaidChild(Section $section, AcademicYear $year): Student
    {
        foreach ($section->enrollments()->where('status', 'active')->with('student')->get() as $enrollment) {
            $student = $enrollment->student;
            $invoice = Invoice::where('student_id', $student->id)->where('academic_year_id', $year->id)->first();
            if ($invoice && $invoice->payments()->count() === 0) {
                return $student;
            }
        }

        throw new RuntimeException("No unpaid student left in {$section->grade->name}. Run this on a fresh SampleAcademicDataSeeder database.");
    }

    private function invoiceOf(Student $student, AcademicYear $year): Invoice
    {
        return Invoice::where('student_id', $student->id)->where('academic_year_id', $year->id)->firstOrFail();
    }

    private function verifiedPayment(Invoice $invoice, User $submittedBy, User $verifier, float $amount, string $reference, string $receipt): Payment
    {
        return Payment::firstOrCreate(
            ['receipt_number' => $receipt],
            [
                'invoice_id' => $invoice->id, 'amount' => $amount, 'method' => 'bank_transfer', 'reference_number' => $reference,
                'proof_path' => $this->fakeReceipt($receipt, $amount), 'submitted_by' => $submittedBy->id, 'status' => 'verified',
                'verified_by' => $verifier->id, 'verified_at' => now()->subDays(2),
            ]
        );
    }

    private function publishedBatch(Exam $exam, Section $section, Subject $subject, User $teacher, User $approver): void
    {
        if (MarkBatch::where('exam_id', $exam->id)->where('section_id', $section->id)->where('subject_id', $subject->id)->exists()) {
            return;
        }

        $batch = MarkBatch::create(['exam_id' => $exam->id, 'section_id' => $section->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id, 'status' => 'draft']);
        foreach ($section->enrollments()->where('status', 'active')->get() as $i => $enrollment) {
            Mark::create(['mark_batch_id' => $batch->id, 'enrollment_id' => $enrollment->id, 'score' => 55 + (($i * 7 + $subject->id * 3) % 41), 'max_score' => 100]);
        }
        $batch->update(['status' => 'submitted', 'submitted_at' => now()->subDays(6)]);
        $batch->update(['status' => 'approved', 'approved_at' => now()->subDays(5), 'approved_by' => $approver->id]);
        $batch->publishBy($approver);
    }

    private function attendance(Student $student, User $marker, int $absent, ?int $late = null): void
    {
        $enrollment = $student->currentEnrollment;
        foreach ([1, 2, 3, 4, 5, 6, 7] as $back) {
            $day = $this->schoolDay($back);
            $status = $back === $absent ? 'absent' : ($back === $late ? 'late' : 'present');
            AttendanceRecord::firstOrCreate(
                ['enrollment_id' => $enrollment->id, 'date' => $day->toDateString()],
                ['status' => $status, 'marked_by' => $marker->id]
            );
        }
    }

    /** The Nth previous weekday (skips Saturday/Sunday). */
    private function schoolDay(int $back): Carbon
    {
        $day = now()->startOfDay();
        while ($back > 0) {
            $day = $day->subDay();
            if (! $day->isWeekend()) {
                $back--;
            }
        }

        return $day;
    }

    /** A small, valid PNG that looks like a bank slip, stored where real payment proofs live. */
    private function fakeReceipt(string $label, float $amount): string
    {
        $path = 'payment-proofs/'.date('Y/m').'/'.Str::slug($label).'.png';
        if (! function_exists('imagecreatetruecolor')) {
            Storage::disk('local')->put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

            return $path;
        }

        $img = imagecreatetruecolor(360, 220);
        imagefill($img, 0, 0, imagecolorallocate($img, 250, 250, 245));
        $ink = imagecolorallocate($img, 40, 40, 60);
        imagestring($img, 5, 16, 16, 'DEMO BANK TRANSFER SLIP', $ink);
        imagestring($img, 4, 16, 60, 'Ref: '.$label, $ink);
        imagestring($img, 4, 16, 90, 'Amount: ETB '.number_format($amount, 2), $ink);
        imagestring($img, 3, 16, 190, '(test data - not a real payment)', $ink);
        ob_start();
        imagepng($img);
        Storage::disk('local')->put($path, ob_get_clean());

        return $path;
    }
}
