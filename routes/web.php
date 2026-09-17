<?php

use App\Http\Controllers\AcademicStructureController;
use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AttendanceReportController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\FeeStructureController;
use App\Http\Controllers\GradeBandController;
use App\Http\Controllers\GradeController;
use App\Http\Controllers\IdCardController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MarkBatchController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ParentController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportCardController;
use App\Http\Controllers\SchoolSettingsController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\TeachingAssignmentController;
use App\Http\Controllers\TermController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

// Public, no-login ID card verification -- this is what a card's QR code
// points to, so it deliberately lives outside the auth middleware group.
Route::get('/verify/{token}', [IdCardController::class, 'verify'])->name('id-card.verify');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::post('/profile/photo', [ProfileController::class, 'updatePhoto'])->name('profile.photo');

    Route::get('/settings/school', [SchoolSettingsController::class, 'edit'])->name('school-settings.edit');
    Route::patch('/settings/school', [SchoolSettingsController::class, 'update'])->name('school-settings.update');

    Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
    Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');
    Route::patch('/branches/{branch}', [BranchController::class, 'update'])->name('branches.update');

    Route::get('/academic-years', [AcademicYearController::class, 'index'])->name('academic-years.index');
    Route::post('/academic-years', [AcademicYearController::class, 'store'])->name('academic-years.store');
    Route::patch('/academic-years/{academicYear}', [AcademicYearController::class, 'update'])->name('academic-years.update');

    Route::post('/academic-years/{academicYear}/terms', [TermController::class, 'store'])->name('terms.store');
    Route::patch('/terms/{term}', [TermController::class, 'update'])->name('terms.update');
    Route::delete('/terms/{term}', [TermController::class, 'destroy'])->name('terms.destroy');

    // Grades & Sections share one combined page now (testers correctly
    // flagged the split as needless) -- the underlying store/update/
    // destroy actions are unchanged, only the two GET index pages merged.
    Route::get('/academic-structure', [AcademicStructureController::class, 'index'])->name('academic-structure.index');
    Route::post('/grades', [GradeController::class, 'store'])->name('grades.store');
    Route::patch('/grades/{grade}', [GradeController::class, 'update'])->name('grades.update');
    Route::delete('/grades/{grade}', [GradeController::class, 'destroy'])->name('grades.destroy');

    Route::get('/subjects', [SubjectController::class, 'index'])->name('subjects.index');
    Route::post('/subjects', [SubjectController::class, 'store'])->name('subjects.store');
    Route::patch('/subjects/{subject}', [SubjectController::class, 'update'])->name('subjects.update');
    Route::delete('/subjects/{subject}', [SubjectController::class, 'destroy'])->name('subjects.destroy');

    Route::post('/sections', [SectionController::class, 'store'])->name('sections.store');
    Route::patch('/sections/{section}', [SectionController::class, 'update'])->name('sections.update');
    Route::delete('/sections/{section}', [SectionController::class, 'destroy'])->name('sections.destroy');

    Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
    Route::get('/staff/export', [StaffController::class, 'export'])->name('staff.export');
    Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
    Route::patch('/staff/{staff}', [StaffController::class, 'update'])->name('staff.update');

    Route::get('/students', [StudentController::class, 'index'])->name('students.index');
    Route::get('/students/export', [StudentController::class, 'export'])->name('students.export');
    Route::get('/students/create', [StudentController::class, 'create'])->name('students.create');
    Route::post('/students', [StudentController::class, 'store'])->name('students.store');
    Route::get('/students/{student}/edit', [StudentController::class, 'edit'])->name('students.edit');
    Route::patch('/students/{student}', [StudentController::class, 'update'])->name('students.update');
    Route::post('/students/{student}/guardians', [StudentController::class, 'addGuardian'])->name('students.guardians.store');
    Route::delete('/students/{student}/guardians/{guardian}', [StudentController::class, 'removeGuardian'])->name('students.guardians.destroy');
    Route::post('/students/{student}/photo', [StudentController::class, 'uploadPhoto'])->name('students.photo');
    Route::get('/students/{student}/id-card', [IdCardController::class, 'show'])->name('id-card.show');

    Route::get('/parents', [ParentController::class, 'index'])->name('parents.index');
    Route::get('/parents/search', [ParentController::class, 'search'])->name('parents.search');

    Route::get('/teaching-assignments', [TeachingAssignmentController::class, 'index'])->name('teaching-assignments.index');
    Route::post('/teaching-assignments', [TeachingAssignmentController::class, 'store'])->name('teaching-assignments.store');
    Route::delete('/teaching-assignments/{teachingAssignment}', [TeachingAssignmentController::class, 'destroy'])->name('teaching-assignments.destroy');
    Route::patch('/sections/{section}/class-teacher', [TeachingAssignmentController::class, 'setClassTeacher'])->name('sections.class-teacher');

    Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('/attendance/{section}', [AttendanceController::class, 'show'])->name('attendance.show');
    Route::get('/attendance/{section}/export', [AttendanceController::class, 'exportRoster'])->name('attendance.export');
    Route::post('/attendance/{section}', [AttendanceController::class, 'store'])->name('attendance.store');
    Route::get('/attendance-report', [AttendanceReportController::class, 'index'])->name('attendance-report.index');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');

    Route::get('/grade-bands', [GradeBandController::class, 'index'])->name('grade-bands.index');
    Route::post('/grade-bands', [GradeBandController::class, 'store'])->name('grade-bands.store');
    Route::patch('/grade-bands/{gradeBand}', [GradeBandController::class, 'update'])->name('grade-bands.update');
    Route::delete('/grade-bands/{gradeBand}', [GradeBandController::class, 'destroy'])->name('grade-bands.destroy');

    Route::get('/exams', [ExamController::class, 'index'])->name('exams.index');
    Route::post('/exams', [ExamController::class, 'store'])->name('exams.store');
    Route::patch('/exams/{exam}', [ExamController::class, 'update'])->name('exams.update');
    Route::delete('/exams/{exam}', [ExamController::class, 'destroy'])->name('exams.destroy');

    Route::get('/marks', [MarkBatchController::class, 'index'])->name('marks.index');
    Route::get('/marks/{exam}/{section}/{subject}', [MarkBatchController::class, 'show'])->name('marks.show');
    Route::post('/mark-batches/{markBatch}', [MarkBatchController::class, 'store'])->name('mark-batches.store');
    Route::post('/mark-batches/{markBatch}/submit', [MarkBatchController::class, 'submit'])->name('mark-batches.submit');
    Route::get('/marks/approvals', [MarkBatchController::class, 'approvalsIndex'])->name('mark-batches.approvals');
    Route::post('/mark-batches/{markBatch}/approve', [MarkBatchController::class, 'approve'])->name('mark-batches.approve');
    Route::post('/mark-batches/{markBatch}/send-back', [MarkBatchController::class, 'sendBack'])->name('mark-batches.send-back');
    Route::post('/mark-batches/{markBatch}/publish', [MarkBatchController::class, 'publish'])->name('mark-batches.publish');
    Route::post('/mark-batches/{markBatch}/reopen', [MarkBatchController::class, 'reopen'])->name('mark-batches.reopen');

    Route::get('/students/{student}/report-card', [ReportCardController::class, 'show'])->name('report-card.show');
    Route::get('/students/{student}/report-card.pdf', [ReportCardController::class, 'pdf'])->name('report-card.pdf');

    Route::get('/audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');
    Route::get('/audit-log/export', [AuditLogController::class, 'export'])->name('audit-log.export');

    Route::get('/fee-structures', [FeeStructureController::class, 'index'])->name('fee-structures.index');
    Route::post('/fee-structures', [FeeStructureController::class, 'store'])->name('fee-structures.store');
    Route::patch('/fee-structures/{feeStructure}', [FeeStructureController::class, 'update'])->name('fee-structures.update');
    Route::delete('/fee-structures/{feeStructure}', [FeeStructureController::class, 'destroy'])->name('fee-structures.destroy');

    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/export', [InvoiceController::class, 'export'])->name('invoices.export');
    Route::post('/invoices/generate', [InvoiceController::class, 'generate'])->name('invoices.generate');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::patch('/invoices/{invoice}/discount', [InvoiceController::class, 'setDiscount'])->name('invoices.discount');
    Route::post('/invoices/{invoice}/toggle-waive', [InvoiceController::class, 'toggleWaive'])->name('invoices.toggle-waive');

    Route::post('/invoices/{invoice}/payments', [PaymentController::class, 'store'])->name('payments.store');
    Route::get('/payments/pending', [PaymentController::class, 'index'])->name('payments.pending');
    Route::post('/payments/{payment}/verify', [PaymentController::class, 'verify'])->name('payments.verify');
    Route::post('/payments/{payment}/reject', [PaymentController::class, 'reject'])->name('payments.reject');
    Route::get('/payments/{payment}/proof', [PaymentController::class, 'proof'])->name('payments.proof');
    Route::get('/payments/{payment}/receipt', [PaymentController::class, 'receipt'])->name('payments.receipt');
});

require __DIR__.'/auth.php';
