<?php
use App\Models\{User, Student, Enrollment, Invoice, Section, AcademicYear, Branch, Grade};
$parent = User::where('email', 'parent@demo.test')->firstOrFail();
$branch = Branch::first();
$year = AcademicYear::first() ?? AcademicYear::create(['school_id' => $parent->school_id, 'name' => '2026', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_current' => true]);
$grade = Grade::first() ?? Grade::create(['school_id' => $parent->school_id, 'name' => 'Grade 4', 'sort_order' => 4]);
$section = Section::first() ?? Section::create(['branch_id' => $branch->id, 'grade_id' => $grade->id, 'academic_year_id' => $year->id, 'name' => 'A']);
$student = Student::create([
    'school_id' => $parent->school_id, 'branch_id' => $section->branch_id,
    'student_id_number' => 'STU-UAT001', 'first_name' => 'Bethlehem', 'father_name' => 'Kebede',
    'gender' => 'female', 'enrollment_date' => '2026-09-01', 'status' => 'active', 'id_card_token' => Student::newCardToken(),
]);
Enrollment::create(['student_id' => $student->id, 'section_id' => $section->id, 'enrolled_on' => '2026-09-01', 'status' => 'active']);
$student->guardians()->attach($parent->id, ['relationship' => 'father']);
$inv = Invoice::create(['student_id' => $student->id, 'academic_year_id' => AcademicYear::first()->id, 'frequency' => 'annual', 'period_start' => '2026-09-01', 'invoice_number' => 'INV-UAT-1']);
$inv->items()->create(['description' => 'Tuition', 'quantity' => 1, 'unit_amount' => 1000, 'amount' => 1000]);
echo "invoice id={$inv->id} balance=".$inv->balance().PHP_EOL;
