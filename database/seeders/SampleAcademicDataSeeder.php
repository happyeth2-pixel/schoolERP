<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Branch;
use App\Models\Enrollment;
use App\Models\FeeStructure;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SampleAcademicDataSeeder extends Seeder
{
    private const BOY_NAMES = ['Yohannes', 'Dawit', 'Nathnael', 'Biniam', 'Amanuel', 'Elias', 'Mikiyas', 'Abenezer', 'Henok', 'Kidus', 'Ermias', 'Nahom', 'Yared', 'Solomon', 'Bereket'];

    private const GIRL_NAMES = ['Bethlehem', 'Ruth', 'Hana', 'Sara', 'Meklit', 'Betelhem', 'Rahel', 'Tsion', 'Eden', 'Liya', 'Feven', 'Marta', 'Selamawit', 'Helen', 'Meron'];

    private const FAMILY_NAMES = ['Tesfaye', 'Alemu', 'Girma', 'Mekonnen', 'Bekele', 'Worku', 'Haile', 'Getachew', 'Abebe', 'Tadesse', 'Kebede', 'Desta', 'Fikru', 'Yilma', 'Tefera'];

    private int $nameIndex = 0;

    private int $familyIndex = 0;

    /**
     * Populates Grade 1-8 with real students (and their guardians) plus
     * a Registrar and a Cashier actor, for testing at a realistic
     * volume. Deliberately NOT part of the default `db:seed` chain --
     * run it explicitly with
     * `php artisan db:seed --class=SampleAcademicDataSeeder`. This is
     * bulk sample data, not the 7 fixed tier-demo accounts, and must
     * never get auto-applied to a real deployment on container boot.
     *
     * Idempotent: re-running skips any grade whose section already has
     * 5+ active enrollments, and every lookup keys off a natural key
     * (grade name, section name, invoice-per-student-per-year, etc.)
     * so nothing duplicates.
     */
    public function run(): void
    {
        $school = School::firstOrCreate(['code' => 'DEMO'], ['name' => 'Demo Academy', 'calendar_preference' => 'both']);
        $branch = Branch::firstOrCreate(['school_id' => $school->id, 'code' => 'MAIN'], ['name' => 'Main Campus', 'is_main' => true]);

        $year = AcademicYear::firstOrCreate(
            ['school_id' => $school->id, 'name' => '2026/2027'],
            ['start_date' => '2026-09-01', 'end_date' => '2027-07-01', 'is_current' => true]
        );
        if (! $year->is_current) {
            AcademicYear::where('school_id', $school->id)->update(['is_current' => false]);
            $year->update(['is_current' => true]);
        }

        $teachers = $this->ensureTeachers($school, $branch);
        $this->ensureRegistrar($school, $branch);
        $this->ensureCashier($school, $branch);

        foreach (range(1, 8) as $level) {
            $grade = Grade::firstOrCreate(
                ['school_id' => $school->id, 'name' => "Grade {$level}"],
                ['sort_order' => $level]
            );

            $section = Section::firstOrCreate(
                ['branch_id' => $branch->id, 'grade_id' => $grade->id, 'academic_year_id' => $year->id, 'name' => 'A'],
                ['capacity' => 40, 'class_teacher_id' => $teachers[$level % count($teachers)]->id]
            );

            $fee = FeeStructure::firstOrCreate(
                ['branch_id' => $branch->id, 'grade_id' => $grade->id, 'academic_year_id' => $year->id, 'name' => 'Tuition'],
                ['amount' => 1000 + $level * 100]
            );

            if ($section->enrollments()->where('status', 'active')->count() < 5) {
                foreach (['female', 'female', 'male', 'male', 'male'] as $gender) {
                    $this->createStudent($school, $branch, $section, $gender);
                }
            }

            foreach ($section->enrollments()->where('status', 'active')->get() as $enrollment) {
                if (Invoice::where('student_id', $enrollment->student_id)->where('academic_year_id', $year->id)->where('frequency', 'annual')->where('period_start', $year->start_date)->exists()) {
                    continue;
                }

                $invoice = Invoice::create([
                    'student_id' => $enrollment->student_id,
                    'academic_year_id' => $year->id,
                    'frequency' => 'annual',
                    'period_start' => $year->start_date,
                    'invoice_number' => $this->nextInvoiceNumber($school->id),
                ]);
                $invoice->items()->create(['description' => $fee->name, 'amount' => $fee->amount]);
            }
        }

        $this->seedSamplePayments($year);
    }

    private function createStudent(School $school, Branch $branch, Section $section, string $gender): void
    {
        $first = $gender === 'female'
            ? self::GIRL_NAMES[$this->nameIndex % count(self::GIRL_NAMES)]
            : self::BOY_NAMES[$this->nameIndex % count(self::BOY_NAMES)];
        $father = self::FAMILY_NAMES[$this->familyIndex % count(self::FAMILY_NAMES)];
        $grandfather = self::FAMILY_NAMES[($this->familyIndex + 3) % count(self::FAMILY_NAMES)];
        $motherFirst = self::GIRL_NAMES[($this->nameIndex + 5) % count(self::GIRL_NAMES)];
        $this->nameIndex++;
        $this->familyIndex++;

        $age = 5 + (int) filter_var($section->grade->name, FILTER_SANITIZE_NUMBER_INT);
        $birthYear = 2026 - $age;

        $student = Student::create([
            'school_id' => $school->id,
            'branch_id' => $branch->id,
            'student_id_number' => $this->nextStudentIdNumber($school->id),
            'first_name' => $first,
            'father_name' => $father,
            'grandfather_name' => $grandfather,
            'mother_name' => $this->nameIndex % 2 === 0 ? "{$motherFirst} {$father}" : null,
            'gender' => $gender,
            'date_of_birth' => sprintf('%d-%02d-%02d', $birthYear, random_int(1, 12), random_int(1, 28)),
            'enrollment_date' => '2026-09-01',
            'status' => 'active',
            'id_card_token' => Str::random(40),
        ]);

        Enrollment::create([
            'student_id' => $student->id,
            'section_id' => $section->id,
            'enrolled_on' => '2026-09-01',
            'status' => 'active',
        ]);

        $guardianEmail = Str::slug("{$first}.{$father}.{$student->id}", '.').'@family.test';
        $guardian = User::firstOrCreate(
            ['email' => $guardianEmail],
            [
                'school_id' => $school->id,
                'branch_id' => $branch->id,
                'name' => "{$father} {$grandfather}",
                'password' => 'password',
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );
        if (! $guardian->hasRole('parent')) {
            $guardian->assignTier('parent');
        }
        $student->guardians()->syncWithoutDetaching([$guardian->id => ['relationship' => 'father']]);
    }

    private function ensureTeachers(School $school, Branch $branch): array
    {
        $emails = [
            'teacher@demo.test' => 'Samuel Girma',
            'teacher2@demo.test' => 'Meseret Alemu',
            'teacher3@demo.test' => 'Yared Solomon',
        ];

        $teachers = [];
        foreach ($emails as $email => $name) {
            $teacher = User::firstOrCreate(
                ['email' => $email],
                [
                    'school_id' => $school->id,
                    'branch_id' => $branch->id,
                    'name' => $name,
                    'password' => 'password',
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]
            );
            if (! $teacher->hasRole('teacher')) {
                $teacher->assignTier('teacher', 'Teacher');
            }
            $teachers[] = $teacher;
        }

        return $teachers;
    }

    /**
     * "Someone who registers students" -- a branch_admin-tier account
     * trimmed down to registration duties only, demonstrating that
     * permissions live on the user, not the tier: two branch_admin
     * accounts (Principal, Registrar) end up with very different
     * capabilities.
     */
    private function ensureRegistrar(School $school, Branch $branch): User
    {
        $registrar = User::firstOrCreate(
            ['email' => 'registrar@demo.test'],
            [
                'school_id' => $school->id,
                'branch_id' => $branch->id,
                'name' => 'Tigist Alemu',
                'password' => 'password',
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        $registrar->assignTier('branch_admin', 'Registrar');
        $registrar->syncPermissions(['manage-students', 'manage-parents', 'manage-classes']);

        return $registrar;
    }

    /**
     * "Someone who checks payments" -- an accountant-tier account
     * trimmed to payment verification only, without the ability to
     * change fee amounts.
     */
    private function ensureCashier(School $school, Branch $branch): User
    {
        $cashier = User::firstOrCreate(
            ['email' => 'cashier@demo.test'],
            [
                'school_id' => $school->id,
                'branch_id' => $branch->id,
                'name' => 'Helen Tesfaye',
                'password' => 'password',
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        $cashier->assignTier('accountant', 'Cashier');
        $cashier->syncPermissions(['manage-invoices', 'verify-payments', 'view-financial-reports']);

        return $cashier;
    }

    /**
     * A handful of pending payment claims so the Cashier actor has an
     * actual queue to verify instead of an empty inbox.
     */
    private function seedSamplePayments(AcademicYear $year): void
    {
        $methods = ['bank_transfer', 'mobile_money', 'cash'];

        $invoices = Invoice::where('academic_year_id', $year->id)
            ->whereDoesntHave('payments')
            ->with('student.guardians')
            ->inRandomOrder()
            ->limit(6)
            ->get();

        foreach ($invoices as $i => $invoice) {
            $guardian = $invoice->student->guardians->first();
            if (! $guardian) {
                continue;
            }

            Payment::create([
                'invoice_id' => $invoice->id,
                'amount' => $invoice->totalAmount(),
                'method' => $methods[$i % count($methods)],
                'reference_number' => 'REF-'.strtoupper(Str::random(6)),
                'submitted_by' => $guardian->id,
                'status' => 'pending',
            ]);
        }
    }

    private function nextStudentIdNumber(int $schoolId): string
    {
        $prefix = 'STU-2026-';
        $count = Student::where('school_id', $schoolId)
            ->where('student_id_number', 'like', "{$prefix}%")
            ->count();

        return $prefix.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }

    private function nextInvoiceNumber(int $schoolId): string
    {
        $prefix = 'INV-'.date('Y').'-';
        $count = Invoice::whereHas('student', fn ($q) => $q->where('school_id', $schoolId))
            ->where('invoice_number', 'like', "{$prefix}%")
            ->count();

        return $prefix.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }
}
