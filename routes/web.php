<?php

use App\Http\Controllers\AcademicStructureController;
use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AttendanceReportController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BookItemController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\ComplaintController;
use App\Http\Controllers\ContactInquiryController;
use App\Http\Controllers\GalleryImageController;
use App\Http\Controllers\PublicSiteController;
use App\Http\Controllers\SiteAnnouncementController;
use App\Http\Controllers\SiteContentController;
use App\Http\Controllers\FamilyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\FeeStructureController;
use App\Http\Controllers\GradeBandController;
use App\Http\Controllers\GradeController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\IdCardController;
use App\Http\Controllers\InventoryCategoryController;
use App\Http\Controllers\InventoryItemController;
use App\Http\Controllers\InventoryStoreController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\RequisitionController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\StocktakeController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\PurchaseRequestController;
use App\Http\Controllers\QuotationController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountingOverviewController;
use App\Http\Controllers\JournalController;
use App\Http\Controllers\JournalEntryController;
use App\Http\Controllers\AccountingPeriodController;
use App\Http\Controllers\FinancialReportController;
use App\Http\Controllers\SupplierBillController;
use App\Http\Controllers\SupplierPaymentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\EmploymentContractController;
use App\Http\Controllers\StaffAttendanceController;
use App\Http\Controllers\LeaveTypeController;
use App\Http\Controllers\LeaveAllocationController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\PerformanceReviewCycleController;
use App\Http\Controllers\PerformanceReviewController;
use App\Http\Controllers\PayrollSettingsController;
use App\Http\Controllers\PayrollRunController;
use App\Http\Controllers\PeriodController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\BookCopyController;
use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\CalendarEventController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\DisciplineIncidentController;
use App\Http\Controllers\LibraryLoanController;
use App\Http\Controllers\LibrarySettingsController;
use App\Http\Controllers\StudentMedicalRecordController;
use App\Http\Controllers\PeriodAttendanceController;
use App\Http\Controllers\TimetableController;
use App\Http\Controllers\MarkBatchController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OptionalServiceController;
use App\Http\Controllers\ParentController;
use App\Http\Controllers\ParentMessageController;
use App\Http\Controllers\PaymentAccountController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PromotionController;
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

Route::get('/', [PublicSiteController::class, 'home'])->name('home');
Route::get('/about', [PublicSiteController::class, 'about'])->name('about');
Route::get('/contact', [PublicSiteController::class, 'contact'])->name('contact');
Route::post('/contact', [PublicSiteController::class, 'submitContact'])->middleware('throttle:contact')->name('contact.submit');

Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified', 'throttle:dashboard'])->name('dashboard');

// Public, no-login ID card verification -- this is what a card's QR code
// points to, so it deliberately lives outside the auth middleware group.
Route::get('/verify/{token}', [IdCardController::class, 'verify'])
    ->where('token', '[A-Za-z0-9]{40}')
    ->middleware(['throttle:id-verify', 'privacy'])
    ->name('id-card.verify');

// One-time "set your password" invitation links (new staff and guardians).
Route::middleware(['guest', 'throttle:invitation', 'privacy'])->group(function () {
    Route::get('/invitation/{token}', [\App\Http\Controllers\Auth\InvitationController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{40}')->name('invitation.show');
    Route::post('/invitation/{token}', [\App\Http\Controllers\Auth\InvitationController::class, 'accept'])
        ->where('token', '[A-Za-z0-9]{40}')->name('invitation.accept');
});

Route::middleware(['auth', 'throttle:web'])->group(function () {
    Route::get('/help', [HelpController::class, 'index'])->name('help.index');

    // A live gallery of the shared UX components (resources/js/Components/ui) for developers and
    // designers. It is not part of the product, so it does not exist at all in production.
    if (! app()->environment('production')) {
        Route::get('/dev/ui-kit', fn () => \Inertia\Inertia::render('Dev/UiKit'))->name('dev.ui-kit');
    }

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::post('/profile/photo', [ProfileController::class, 'updatePhoto'])->middleware('throttle:upload')->name('profile.photo');
    Route::post('/profile/locale', [ProfileController::class, 'updateLocale'])->name('profile.locale');

    Route::get('/contact-inquiries', [ContactInquiryController::class, 'index'])->name('contact-inquiries.index');
    Route::post('/contact-inquiries/{inquiry}/read', [ContactInquiryController::class, 'markRead'])->name('contact-inquiries.read');

    Route::get('/settings/gallery', [GalleryImageController::class, 'index'])->name('gallery.index');
    Route::post('/settings/gallery', [GalleryImageController::class, 'store'])->middleware('throttle:upload')->name('gallery.store');
    Route::delete('/settings/gallery/{image}', [GalleryImageController::class, 'destroy'])->name('gallery.destroy');
    Route::post('/settings/gallery/{image}/move', [GalleryImageController::class, 'move'])->name('gallery.move');

    Route::get('/settings/website-text/{page}', [SiteContentController::class, 'edit'])->name('site-content.edit');
    Route::patch('/settings/website-text/{page}', [SiteContentController::class, 'update'])->name('site-content.update');

    Route::get('/settings/announcements', [SiteAnnouncementController::class, 'index'])->name('site-announcements.index');
    Route::post('/settings/announcements', [SiteAnnouncementController::class, 'store'])->name('site-announcements.store');
    Route::post('/settings/announcements/{announcement}/toggle', [SiteAnnouncementController::class, 'toggle'])->name('site-announcements.toggle');
    Route::delete('/settings/announcements/{announcement}', [SiteAnnouncementController::class, 'destroy'])->name('site-announcements.destroy');

    Route::get('/settings/school', [SchoolSettingsController::class, 'edit'])->name('school-settings.edit');
    Route::patch('/settings/school', [SchoolSettingsController::class, 'update'])->name('school-settings.update');
    Route::post('/settings/payment-accounts', [PaymentAccountController::class, 'store'])->name('payment-accounts.store');
    Route::delete('/settings/payment-accounts/{paymentAccount}', [PaymentAccountController::class, 'destroy'])->name('payment-accounts.destroy');

    Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
    Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');
    Route::patch('/branches/{branch}', [BranchController::class, 'update'])->name('branches.update');

    Route::get('/academic-years', [AcademicYearController::class, 'index'])->name('academic-years.index');
    Route::post('/academic-years', [AcademicYearController::class, 'store'])->name('academic-years.store');
    Route::post('/academic-years/{academicYear}/make-current', [AcademicYearController::class, 'makeCurrent'])->name('academic-years.make-current');
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

    Route::get('/sections/create', [SectionController::class, 'create'])->name('sections.create');
    Route::post('/sections', [SectionController::class, 'store'])->name('sections.store');
    Route::patch('/sections/{section}', [SectionController::class, 'update'])->name('sections.update');
    Route::delete('/sections/{section}', [SectionController::class, 'destroy'])->name('sections.destroy');

    Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
    Route::get('/staff/create', [StaffController::class, 'create'])->name('staff.create');
    Route::get('/staff/export', [StaffController::class, 'export'])->middleware('throttle:export')->name('staff.export');
    Route::post('/staff', [StaffController::class, 'store'])->middleware('throttle:admin')->name('staff.store');
    Route::patch('/staff/{staff}', [StaffController::class, 'update'])->middleware('throttle:admin')->name('staff.update');
    Route::post('/staff/{staff}/password-reset', [StaffController::class, 'resetPassword'])->middleware('throttle:admin')->name('staff.reset-password');
    Route::post('/staff/{staff}/unlock-sign-in', [StaffController::class, 'unlockSignIn'])->middleware('throttle:admin')->name('staff.unlock-sign-in');
    Route::post('/staff/{staff}/deactivate', [StaffController::class, 'deactivate'])->middleware('throttle:admin')->name('staff.deactivate');
    Route::post('/staff/{staff}/reactivate', [StaffController::class, 'reactivate'])->middleware('throttle:admin')->name('staff.reactivate');

    Route::get('/students', [StudentController::class, 'index'])->middleware('throttle:search')->name('students.index');
    Route::get('/students/export', [StudentController::class, 'export'])->middleware('throttle:export')->name('students.export');
    Route::get('/students/contacts', [StudentController::class, 'contacts'])->middleware('throttle:search')->name('students.contacts');
    Route::get('/students/{student}/help', [\App\Http\Controllers\StudentDeskController::class, 'show'])->whereNumber('student')->name('students.desk');
    Route::get('/students/{student}/fees/statement', [\App\Http\Controllers\StudentDeskController::class, 'statement'])->middleware('throttle:pdf')->whereNumber('student')->name('students.fees.statement');
    Route::get('/students/create', [StudentController::class, 'create'])->name('students.create');
    Route::post('/students', [StudentController::class, 'store'])->name('students.store');
    Route::get('/students/{student}/edit', [StudentController::class, 'edit'])->name('students.edit');
    Route::patch('/students/{student}', [StudentController::class, 'update'])->name('students.update');
    Route::post('/students/{student}/guardians', [StudentController::class, 'addGuardian'])->name('students.guardians.store');
    Route::delete('/students/{student}/guardians/{guardian}', [StudentController::class, 'removeGuardian'])->name('students.guardians.destroy');
    Route::post('/students/{student}/photo', [StudentController::class, 'uploadPhoto'])->middleware('throttle:upload')->name('students.photo');
    Route::delete('/students/{student}/photo', [StudentController::class, 'removePhoto'])->name('students.photo.destroy');
    Route::post('/students/{student}/withdraw', [StudentController::class, 'withdraw'])->name('students.withdraw');
    Route::post('/students/{student}/transfer', [StudentController::class, 'transfer'])->name('students.transfer');
    Route::get('/students/{student}/id-card', [IdCardController::class, 'show'])->name('id-card.show');
    Route::get('/sections/{section}/id-cards', [IdCardController::class, 'batch'])->name('id-card.batch');
    Route::post('/students/{student}/optional-services', [OptionalServiceController::class, 'enrollStudent'])->name('students.optional-services.store');
    Route::patch('/students/{student}/optional-services/{optionalService}', [OptionalServiceController::class, 'updateStudentService'])->name('students.optional-services.update');
    Route::delete('/students/{student}/optional-services/{optionalService}', [OptionalServiceController::class, 'withdrawStudent'])->name('students.optional-services.destroy');
    Route::get('/optional-services/{optionalService}/enroll', [OptionalServiceController::class, 'enrollForm'])->name('optional-services.enroll-form');
    Route::post('/optional-services/{optionalService}/enroll', [OptionalServiceController::class, 'bulkEnroll'])->name('optional-services.bulk-enroll');
    Route::get('/students/{student}/books', [BookItemController::class, 'catalogForStudent'])->name('students.books.index');
    Route::post('/students/{student}/books', [BookItemController::class, 'sell'])->name('students.books.store');
    Route::get('/medical-records', [StudentMedicalRecordController::class, 'index'])->name('medical-records.index');
    Route::get('/medical-records/student-search', [StudentMedicalRecordController::class, 'studentSearch'])->middleware('throttle:search')->name('medical-records.student-search');
    Route::get('/students/{student}/medical', [StudentMedicalRecordController::class, 'show'])->name('students.medical.show');
    Route::put('/students/{student}/medical', [StudentMedicalRecordController::class, 'update'])->name('students.medical.update');

    Route::get('/messages', [ParentMessageController::class, 'inbox'])->name('messages.index');
    Route::get('/students/{student}/messages', [ParentMessageController::class, 'index'])->name('students.messages.index');
    Route::post('/students/{student}/messages', [ParentMessageController::class, 'store'])->name('students.messages.store');
    Route::post('/sections/{section}/messages', [ParentMessageController::class, 'broadcast'])->name('sections.messages.broadcast');

    Route::get('/discipline', [DisciplineIncidentController::class, 'index'])->name('discipline.index');
    Route::get('/discipline/student-search', [DisciplineIncidentController::class, 'studentSearch'])->middleware('throttle:search')->name('discipline.student-search');
    Route::get('/students/{student}/discipline', [DisciplineIncidentController::class, 'forStudent'])->name('discipline.for-student');
    Route::post('/students/{student}/discipline', [DisciplineIncidentController::class, 'store'])->name('discipline.store');
    Route::put('/discipline/{incident}', [DisciplineIncidentController::class, 'update'])->name('discipline.update');
    Route::get('/discipline/{incident}/document', [DisciplineIncidentController::class, 'document'])->name('discipline.document');

    Route::get('/assignments', [AssignmentController::class, 'index'])->name('assignments.index');
    Route::post('/assignments', [AssignmentController::class, 'store'])->name('assignments.store');
    Route::put('/assignments/{assignment}', [AssignmentController::class, 'update'])->name('assignments.update');
    Route::delete('/assignments/{assignment}', [AssignmentController::class, 'destroy'])->name('assignments.destroy');
    Route::get('/assignments/{assignment}/document', [AssignmentController::class, 'document'])->name('assignments.document');

    Route::get('/library', [LibraryLoanController::class, 'index'])->name('library.index');
    Route::get('/my-library-loans', [LibraryLoanController::class, 'mine'])->name('library.mine');
    Route::get('/library/borrowers/search', [LibraryLoanController::class, 'borrowerSearch'])->middleware('throttle:search')->name('library.borrower-search');
    Route::post('/library/issue', [LibraryLoanController::class, 'issue'])->name('library.issue');
    Route::post('/library/loans/{loan}/return', [LibraryLoanController::class, 'returnLoan'])->name('library.loans.return');
    Route::post('/library/loans/{loan}/renew', [LibraryLoanController::class, 'renew'])->name('library.loans.renew');
    Route::post('/library/loans/{loan}/lost-or-damaged', [LibraryLoanController::class, 'markLostOrDamaged'])->name('library.loans.mark');
    Route::post('/library/loans/{loan}/waive-fine', [LibraryLoanController::class, 'waiveFine'])->name('library.loans.waive-fine');
    Route::post('/library/loans/{loan}/pay-fine', [LibraryLoanController::class, 'payFine'])->name('library.loans.pay-fine');

    Route::get('/library/settings', [LibrarySettingsController::class, 'edit'])->name('library-settings.edit');
    Route::put('/library/settings', [LibrarySettingsController::class, 'update'])->name('library-settings.update');

    Route::get('/library/books', [BookController::class, 'index'])->name('library-books.index');
    Route::post('/library/books', [BookController::class, 'store'])->name('library-books.store');
    Route::get('/library/books/{book}', [BookController::class, 'show'])->name('library-books.show');
    Route::put('/library/books/{book}', [BookController::class, 'update'])->name('library-books.update');
    Route::delete('/library/books/{book}', [BookController::class, 'destroy'])->name('library-books.destroy');
    Route::post('/library/books/{book}/copies', [BookCopyController::class, 'store'])->name('library-books.copies.store');
    Route::delete('/library/books/{book}/copies/{copy}', [BookCopyController::class, 'destroy'])->name('library-books.copies.destroy');
    Route::post('/library/books/{book}/copies/{copy}/lost-or-damaged', [BookCopyController::class, 'markLostOrDamaged'])->name('library-books.copies.mark');
    Route::post('/library/books/{book}/copies/{copy}/restore', [BookCopyController::class, 'restore'])->name('library-books.copies.restore');

    Route::get('/promotions', [PromotionController::class, 'index'])->name('promotions.index');
    Route::post('/promotions', [PromotionController::class, 'store'])->middleware('throttle:bulk')->name('promotions.store');

    Route::get('/parents', [ParentController::class, 'index'])->middleware('throttle:search')->name('parents.index');
    Route::get('/parents/create', [ParentController::class, 'create'])->name('parents.create');
    Route::post('/parents', [ParentController::class, 'store'])->middleware('throttle:admin')->name('parents.store');
    Route::get('/parents/search', [ParentController::class, 'search'])->middleware('throttle:search')->name('parents.search');
    Route::patch('/parents/{parent}', [ParentController::class, 'update'])->middleware('throttle:admin')->name('parents.update');
    Route::post('/parents/{parent}/password-reset', [ParentController::class, 'resetPassword'])->middleware('throttle:admin')->name('parents.reset-password');
    Route::post('/parents/{parent}/deactivate', [ParentController::class, 'deactivate'])->middleware('throttle:admin')->name('parents.deactivate');
    Route::post('/parents/{parent}/reactivate', [ParentController::class, 'reactivate'])->middleware('throttle:admin')->name('parents.reactivate');

    Route::get('/teaching-assignments', [TeachingAssignmentController::class, 'index'])->name('teaching-assignments.index');
    Route::post('/teaching-assignments', [TeachingAssignmentController::class, 'store'])->name('teaching-assignments.store');
    Route::delete('/teaching-assignments/{teachingAssignment}', [TeachingAssignmentController::class, 'destroy'])->name('teaching-assignments.destroy');
    Route::patch('/sections/{section}/class-teacher', [TeachingAssignmentController::class, 'setClassTeacher'])->name('sections.class-teacher');

    Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('/attendance/{section}', [AttendanceController::class, 'show'])->name('attendance.show');
    Route::get('/attendance/{section}/export', [AttendanceController::class, 'exportRoster'])->middleware('throttle:export')->name('attendance.export');
    Route::post('/attendance/{section}', [AttendanceController::class, 'store'])->name('attendance.store');
    Route::get('/attendance-report', [AttendanceReportController::class, 'index'])->name('attendance-report.index');

    Route::get('/notifications', [NotificationController::class, 'index'])->middleware('throttle:notifications')->name('notifications.index');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->middleware('throttle:notifications')->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->middleware('throttle:notifications')->name('notifications.read-all');

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
    Route::get('/mark-batches/{markBatch}/view', [MarkBatchController::class, 'viewForApproval'])->name('mark-batches.view');
    Route::post('/mark-batches/{markBatch}/approve', [MarkBatchController::class, 'approve'])->name('mark-batches.approve');
    Route::post('/mark-batches/{markBatch}/send-back', [MarkBatchController::class, 'sendBack'])->name('mark-batches.send-back');
    Route::post('/mark-batches/{markBatch}/publish', [MarkBatchController::class, 'publish'])->name('mark-batches.publish');
    Route::post('/mark-batches/{markBatch}/reopen', [MarkBatchController::class, 'reopen'])->name('mark-batches.reopen');

    Route::get('/students/{student}/report-card', [ReportCardController::class, 'show'])->name('report-card.show');
    Route::get('/students/{student}/report-card.pdf', [ReportCardController::class, 'pdf'])->middleware('throttle:pdf')->name('report-card.pdf');

    Route::get('/my/attendance', [FamilyController::class, 'attendance'])->name('family.attendance');
    Route::get('/my/report-cards', [FamilyController::class, 'reportCards'])->name('family.report-cards');
    Route::get('/my/homework', [FamilyController::class, 'homework'])->name('family.homework');
    Route::get('/my/timetable', [FamilyController::class, 'timetable'])->name('family.timetable');
    Route::get('/my/results', [FamilyController::class, 'results'])->name('family.results');
    Route::get('/my/invoices', [FamilyController::class, 'invoices'])->name('family.invoices');
    Route::get('/my/invoices/{student}/statement', [FamilyController::class, 'statement'])->middleware('throttle:pdf')->whereNumber('student')->name('family.invoices.statement');
    Route::get('/my/payments', [FamilyController::class, 'paymentsHub'])->name('family.payments');

    Route::get('/announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
    Route::get('/announcements/new', [AnnouncementController::class, 'create'])->name('announcements.create');
    Route::post('/announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
    Route::patch('/announcements/{announcement}', [AnnouncementController::class, 'update'])->name('announcements.update');
    Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy'])->name('announcements.destroy');

    Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');

    Route::get('/audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');
    Route::get('/audit-log/export', [AuditLogController::class, 'export'])->middleware('throttle:export')->name('audit-log.export');
    Route::get('/audit-log/security', [\App\Http\Controllers\SecurityLogController::class, 'index'])->name('security-log.index');

    Route::get('/complaints', [ComplaintController::class, 'index'])->name('complaints.index');
    Route::get('/complaints/create', [ComplaintController::class, 'create'])->name('complaints.create');
    Route::post('/complaints', [ComplaintController::class, 'store'])->name('complaints.store');
    Route::get('/complaints/{complaint}', [ComplaintController::class, 'show'])->name('complaints.show');
    Route::post('/complaints/{complaint}/status', [ComplaintController::class, 'updateStatus'])->name('complaints.status');

    Route::get('/fee-structures', [FeeStructureController::class, 'index'])->name('fee-structures.index');
    Route::get('/fee-structures/new', [FeeStructureController::class, 'create'])->name('fee-structures.create');
    Route::get('/fee-structures/{feeStructure}/edit', [FeeStructureController::class, 'edit'])->name('fee-structures.edit');
    Route::post('/fee-structures', [FeeStructureController::class, 'store'])->name('fee-structures.store');
    Route::patch('/fee-structures/{feeStructure}', [FeeStructureController::class, 'update'])->name('fee-structures.update');
    Route::delete('/fee-structures/{feeStructure}', [FeeStructureController::class, 'destroy'])->name('fee-structures.destroy');

    Route::get('/my-after-school-programs', [OptionalServiceController::class, 'myPrograms'])->name('optional-services.mine');
    Route::get('/optional-services', [OptionalServiceController::class, 'index'])->name('optional-services.index');
    Route::get('/optional-services/new', [OptionalServiceController::class, 'create'])->name('optional-services.create');
    Route::post('/optional-services', [OptionalServiceController::class, 'store'])->name('optional-services.store');
    Route::patch('/optional-services/{optionalService}', [OptionalServiceController::class, 'update'])->name('optional-services.update');
    Route::delete('/optional-services/{optionalService}', [OptionalServiceController::class, 'destroy'])->name('optional-services.destroy');
    Route::get('/books', [BookItemController::class, 'index'])->name('books.index');
    Route::post('/books', [BookItemController::class, 'store'])->name('books.store');
    Route::patch('/books/{book}', [BookItemController::class, 'update'])->name('books.update');
    Route::delete('/books/{book}', [BookItemController::class, 'destroy'])->name('books.destroy');

    Route::get('/inventory/items', [InventoryItemController::class, 'index'])->name('inventory-items.index');
    Route::get('/inventory/items/new', [InventoryItemController::class, 'create'])->name('inventory-items.create');
    Route::post('/inventory/items', [InventoryItemController::class, 'store'])->name('inventory-items.store');
    Route::get('/inventory/items/{inventoryItem}', [InventoryItemController::class, 'show'])->name('inventory-items.show');
    Route::patch('/inventory/items/{inventoryItem}', [InventoryItemController::class, 'update'])->name('inventory-items.update');
    Route::delete('/inventory/items/{inventoryItem}', [InventoryItemController::class, 'destroy'])->name('inventory-items.destroy');

    Route::get('/inventory/stores', [InventoryStoreController::class, 'index'])->name('inventory-stores.index');
    Route::post('/inventory/stores', [InventoryStoreController::class, 'store'])->name('inventory-stores.store');
    Route::patch('/inventory/stores/{inventoryStore}', [InventoryStoreController::class, 'update'])->name('inventory-stores.update');
    Route::delete('/inventory/stores/{inventoryStore}', [InventoryStoreController::class, 'destroy'])->name('inventory-stores.destroy');

    Route::post('/inventory/categories', [InventoryCategoryController::class, 'store'])->name('inventory-categories.store');
    Route::patch('/inventory/categories/{inventoryCategory}', [InventoryCategoryController::class, 'update'])->name('inventory-categories.update');
    Route::delete('/inventory/categories/{inventoryCategory}', [InventoryCategoryController::class, 'destroy'])->name('inventory-categories.destroy');

    Route::get('/inventory/movements', [StockMovementController::class, 'index'])->name('stock-movements.index');
    Route::post('/inventory/movements/receive', [StockMovementController::class, 'receive'])->name('stock-movements.receive');
    Route::post('/inventory/movements/issue', [StockMovementController::class, 'issue'])->name('stock-movements.issue');
    Route::post('/inventory/movements/transfer', [StockMovementController::class, 'transfer'])->name('stock-movements.transfer');
    Route::post('/inventory/movements/adjust', [StockMovementController::class, 'adjust'])->name('stock-movements.adjust');

    Route::get('/requisitions', [RequisitionController::class, 'index'])->name('requisitions.index');
    Route::get('/requisitions/new', [RequisitionController::class, 'create'])->name('requisitions.create');
    Route::post('/requisitions', [RequisitionController::class, 'store'])->name('requisitions.store');
    Route::get('/requisitions/{requisition}', [RequisitionController::class, 'show'])->name('requisitions.show');
    Route::delete('/requisitions/{requisition}', [RequisitionController::class, 'destroy'])->name('requisitions.destroy');
    Route::post('/requisitions/{requisition}/submit', [RequisitionController::class, 'submit'])->name('requisitions.submit');
    Route::post('/requisitions/{requisition}/approve', [RequisitionController::class, 'approve'])->name('requisitions.approve');
    Route::post('/requisitions/{requisition}/reject', [RequisitionController::class, 'reject'])->name('requisitions.reject');
    Route::post('/requisitions/{requisition}/issue', [RequisitionController::class, 'issue'])->name('requisitions.issue');

    Route::get('/assets', [AssetController::class, 'index'])->name('assets.index');
    Route::get('/assets/new', [AssetController::class, 'create'])->name('assets.create');
    Route::post('/assets', [AssetController::class, 'store'])->name('assets.store');
    Route::get('/assets/{asset}', [AssetController::class, 'show'])->name('assets.show');
    Route::post('/assets/{asset}/assign', [AssetController::class, 'assign'])->name('assets.assign');
    Route::post('/assets/{asset}/reassign', [AssetController::class, 'reassign'])->name('assets.reassign');
    Route::post('/assets/{asset}/return', [AssetController::class, 'returnToStore'])->name('assets.return');
    Route::post('/assets/{asset}/transfer', [AssetController::class, 'transfer'])->name('assets.transfer');
    Route::post('/assets/{asset}/repair-start', [AssetController::class, 'startRepair'])->name('assets.repair-start');
    Route::post('/assets/{asset}/repair-complete', [AssetController::class, 'completeRepair'])->name('assets.repair-complete');
    Route::post('/assets/{asset}/mark-lost', [AssetController::class, 'markLost'])->name('assets.mark-lost');
    Route::post('/assets/{asset}/mark-damaged', [AssetController::class, 'markDamaged'])->name('assets.mark-damaged');
    Route::post('/assets/{asset}/retire', [AssetController::class, 'retire'])->name('assets.retire');
    Route::post('/assets/{asset}/dispose', [AssetController::class, 'dispose'])->name('assets.dispose');

    Route::get('/stocktakes', [StocktakeController::class, 'index'])->name('stocktakes.index');
    Route::post('/stocktakes', [StocktakeController::class, 'store'])->name('stocktakes.store');
    Route::get('/stocktakes/{stocktake}', [StocktakeController::class, 'show'])->name('stocktakes.show');
    Route::post('/stocktakes/{stocktake}/counts', [StocktakeController::class, 'saveCounts'])->name('stocktakes.save-counts');
    Route::post('/stocktakes/{stocktake}/complete', [StocktakeController::class, 'complete'])->middleware('throttle:bulk')->name('stocktakes.complete');

    Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::get('/suppliers/new', [SupplierController::class, 'create'])->name('suppliers.create');
    Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
    Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
    Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
    Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');

    Route::get('/purchase-requests', [PurchaseRequestController::class, 'index'])->name('purchase-requests.index');
    Route::get('/purchase-requests/new', [PurchaseRequestController::class, 'create'])->name('purchase-requests.create');
    Route::post('/purchase-requests', [PurchaseRequestController::class, 'store'])->name('purchase-requests.store');
    Route::get('/purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'show'])->name('purchase-requests.show');
    Route::delete('/purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'destroy'])->name('purchase-requests.destroy');
    Route::post('/purchase-requests/{purchaseRequest}/submit', [PurchaseRequestController::class, 'submit'])->name('purchase-requests.submit');
    Route::post('/purchase-requests/{purchaseRequest}/approve', [PurchaseRequestController::class, 'approve'])->name('purchase-requests.approve');
    Route::post('/purchase-requests/{purchaseRequest}/reject', [PurchaseRequestController::class, 'reject'])->name('purchase-requests.reject');
    Route::post('/purchase-requests/{purchaseRequest}/quotations', [QuotationController::class, 'store'])->name('quotations.store');
    Route::post('/quotations/{quotation}/select', [QuotationController::class, 'select'])->name('quotations.select');
    Route::delete('/quotations/{quotation}', [QuotationController::class, 'destroy'])->name('quotations.destroy');

    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
    Route::post('/purchase-requests/{purchaseRequest}/purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
    Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('purchase-orders.show');
    Route::post('/purchase-orders/{purchaseOrder}/send', [PurchaseOrderController::class, 'send'])->name('purchase-orders.send');
    Route::post('/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');
    Route::delete('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])->name('purchase-orders.destroy');
    Route::post('/purchase-orders/{purchaseOrder}/goods-receipts', [GoodsReceiptController::class, 'store'])->name('goods-receipts.store');

    Route::get('/supplier-bills', [SupplierBillController::class, 'index'])->name('supplier-bills.index');
    Route::get('/supplier-bills/new', [SupplierBillController::class, 'create'])->name('supplier-bills.create');
    Route::post('/supplier-bills', [SupplierBillController::class, 'store'])->name('supplier-bills.store');
    Route::get('/supplier-bills/{supplierBill}', [SupplierBillController::class, 'show'])->name('supplier-bills.show');
    Route::post('/supplier-bills/{supplierBill}/payments', [SupplierPaymentController::class, 'store'])->name('supplier-payments.store');
    Route::post('/supplier-payments/{supplierPayment}/reverse', [SupplierPaymentController::class, 'reverse'])->name('supplier-payments.reverse');

    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('/employees/new', [EmployeeController::class, 'create'])->name('employees.create');
    Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
    Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');

    Route::get('/departments', [DepartmentController::class, 'index'])->name('departments.index');
    Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
    Route::put('/departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
    Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])->name('departments.destroy');

    Route::get('/positions', [PositionController::class, 'index'])->name('positions.index');
    Route::post('/positions', [PositionController::class, 'store'])->name('positions.store');
    Route::put('/positions/{position}', [PositionController::class, 'update'])->name('positions.update');
    Route::delete('/positions/{position}', [PositionController::class, 'destroy'])->name('positions.destroy');

    Route::post('/employees/{employee}/contracts', [EmploymentContractController::class, 'store'])->name('employment-contracts.store');
    Route::put('/employment-contracts/{employmentContract}', [EmploymentContractController::class, 'update'])->name('employment-contracts.update');
    Route::delete('/employment-contracts/{employmentContract}', [EmploymentContractController::class, 'destroy'])->name('employment-contracts.destroy');
    Route::get('/employment-contracts/{employmentContract}/document', [EmploymentContractController::class, 'document'])->name('employment-contracts.document');

    Route::get('/staff-attendance', [StaffAttendanceController::class, 'index'])->name('staff-attendance.index');
    Route::post('/staff-attendance', [StaffAttendanceController::class, 'store'])->name('staff-attendance.store');
    Route::get('/staff-attendance/corrections', [StaffAttendanceController::class, 'corrections'])->name('staff-attendance.corrections');
    Route::post('/staff-attendance/{staffAttendance}/corrections', [StaffAttendanceController::class, 'requestCorrection'])->name('staff-attendance.request-correction');
    Route::post('/staff-attendance-corrections/{staffAttendanceCorrection}/approve', [StaffAttendanceController::class, 'approveCorrection'])->name('staff-attendance-corrections.approve');
    Route::post('/staff-attendance-corrections/{staffAttendanceCorrection}/reject', [StaffAttendanceController::class, 'rejectCorrection'])->name('staff-attendance-corrections.reject');

    Route::get('/leave-types', [LeaveTypeController::class, 'index'])->name('leave-types.index');
    Route::post('/leave-types', [LeaveTypeController::class, 'store'])->name('leave-types.store');
    Route::put('/leave-types/{leaveType}', [LeaveTypeController::class, 'update'])->name('leave-types.update');
    Route::delete('/leave-types/{leaveType}', [LeaveTypeController::class, 'destroy'])->name('leave-types.destroy');

    Route::post('/employees/{employee}/leave-allocations', [LeaveAllocationController::class, 'store'])->name('leave-allocations.store');
    Route::put('/leave-allocations/{leaveAllocation}', [LeaveAllocationController::class, 'update'])->name('leave-allocations.update');
    Route::delete('/leave-allocations/{leaveAllocation}', [LeaveAllocationController::class, 'destroy'])->name('leave-allocations.destroy');

    Route::get('/my-leave', [LeaveRequestController::class, 'mine'])->name('leave-requests.mine');
    Route::get('/leave-requests/pending', [LeaveRequestController::class, 'pending'])->name('leave-requests.pending');
    Route::post('/employees/{employee}/leave-requests', [LeaveRequestController::class, 'store'])->name('leave-requests.store');
    Route::put('/leave-requests/{leaveRequest}', [LeaveRequestController::class, 'update'])->name('leave-requests.update');
    Route::delete('/leave-requests/{leaveRequest}', [LeaveRequestController::class, 'destroy'])->name('leave-requests.destroy');
    Route::post('/leave-requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve'])->name('leave-requests.approve');
    Route::post('/leave-requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject'])->name('leave-requests.reject');
    Route::get('/leave-requests/{leaveRequest}/document', [LeaveRequestController::class, 'document'])->name('leave-requests.document');

    Route::get('/performance-cycles', [PerformanceReviewCycleController::class, 'index'])->name('performance-cycles.index');
    Route::post('/performance-cycles', [PerformanceReviewCycleController::class, 'store'])->name('performance-cycles.store');
    Route::put('/performance-cycles/{cycle}', [PerformanceReviewCycleController::class, 'update'])->name('performance-cycles.update');
    Route::delete('/performance-cycles/{cycle}', [PerformanceReviewCycleController::class, 'destroy'])->name('performance-cycles.destroy');

    Route::get('/my-performance', [PerformanceReviewController::class, 'mine'])->name('performance-reviews.mine');
    Route::post('/employees/{employee}/performance-reviews', [PerformanceReviewController::class, 'store'])->name('performance-reviews.store');
    Route::put('/performance-reviews/{performanceReview}', [PerformanceReviewController::class, 'update'])->name('performance-reviews.update');
    Route::delete('/performance-reviews/{performanceReview}', [PerformanceReviewController::class, 'destroy'])->name('performance-reviews.destroy');

    Route::get('/payroll-settings', [PayrollSettingsController::class, 'edit'])->name('payroll-settings.edit');
    Route::put('/payroll-settings/pension', [PayrollSettingsController::class, 'updatePensionRates'])->name('payroll-settings.pension');
    Route::post('/payroll-settings/brackets', [PayrollSettingsController::class, 'storeBracket'])->name('payroll-tax-brackets.store');
    Route::put('/payroll-settings/brackets/{bracket}', [PayrollSettingsController::class, 'updateBracket'])->name('payroll-tax-brackets.update');
    Route::delete('/payroll-settings/brackets/{bracket}', [PayrollSettingsController::class, 'destroyBracket'])->name('payroll-tax-brackets.destroy');

    Route::get('/my-payslips', [PayrollRunController::class, 'mine'])->name('payslips.mine');
    Route::get('/payroll-runs', [PayrollRunController::class, 'index'])->name('payroll-runs.index');
    Route::post('/payroll-runs', [PayrollRunController::class, 'store'])->middleware('throttle:bulk')->name('payroll-runs.store');
    Route::get('/payroll-runs/{run}', [PayrollRunController::class, 'show'])->name('payroll-runs.show');
    Route::post('/payroll-runs/{run}/finalize', [PayrollRunController::class, 'finalize'])->middleware('throttle:bulk')->name('payroll-runs.finalize');
    Route::delete('/payroll-runs/{run}', [PayrollRunController::class, 'destroy'])->name('payroll-runs.destroy');

    Route::get('/periods', [PeriodController::class, 'index'])->name('periods.index');
    Route::post('/periods', [PeriodController::class, 'store'])->name('periods.store');
    Route::put('/periods/{period}', [PeriodController::class, 'update'])->name('periods.update');
    Route::delete('/periods/{period}', [PeriodController::class, 'destroy'])->name('periods.destroy');

    Route::get('/rooms', [RoomController::class, 'index'])->name('rooms.index');
    Route::post('/rooms', [RoomController::class, 'store'])->name('rooms.store');
    Route::put('/rooms/{room}', [RoomController::class, 'update'])->name('rooms.update');
    Route::delete('/rooms/{room}', [RoomController::class, 'destroy'])->name('rooms.destroy');

    Route::get('/my-timetable', [TimetableController::class, 'mine'])->name('timetable.mine');
    Route::get('/timetable', [TimetableController::class, 'index'])->name('timetable.index');
    Route::post('/timetable', [TimetableController::class, 'store'])->name('timetable.store');
    Route::put('/timetable/{timetableSlot}', [TimetableController::class, 'update'])->name('timetable.update');
    Route::delete('/timetable/{timetableSlot}', [TimetableController::class, 'destroy'])->name('timetable.destroy');

    Route::get('/period-attendance', [PeriodAttendanceController::class, 'index'])->name('period-attendance.index');
    Route::get('/period-attendance/{timetableSlot}', [PeriodAttendanceController::class, 'show'])->name('period-attendance.show');
    Route::post('/period-attendance/{timetableSlot}', [PeriodAttendanceController::class, 'store'])->name('period-attendance.store');

    Route::get('/calendar', [CalendarEventController::class, 'index'])->name('calendar.index');
    Route::post('/calendar', [CalendarEventController::class, 'store'])->name('calendar.store');
    Route::put('/calendar/{calendarEvent}', [CalendarEventController::class, 'update'])->name('calendar.update');
    Route::delete('/calendar/{calendarEvent}', [CalendarEventController::class, 'destroy'])->name('calendar.destroy');

    Route::get('/accounting/overview', [AccountingOverviewController::class, 'index'])->middleware('throttle:reports')->name('accounting.overview');

    Route::get('/accounting/accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('/accounting/accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::patch('/accounting/accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
    Route::delete('/accounting/accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');
    Route::post('/accounting/accounts/install-template', [AccountController::class, 'installTemplate'])->name('accounts.install-template');

    Route::get('/accounting/journals', [JournalController::class, 'index'])->name('journals.index');
    Route::post('/accounting/journals', [JournalController::class, 'store'])->name('journals.store');
    Route::patch('/accounting/journals/{journal}', [JournalController::class, 'update'])->name('journals.update');

    Route::get('/accounting/periods', [AccountingPeriodController::class, 'index'])->name('accounting-periods.index');
    Route::post('/accounting/periods', [AccountingPeriodController::class, 'store'])->name('accounting-periods.store');
    Route::post('/accounting/periods/{accountingPeriod}/close', [AccountingPeriodController::class, 'close'])->name('accounting-periods.close');
    Route::post('/accounting/periods/{accountingPeriod}/reopen', [AccountingPeriodController::class, 'reopen'])->name('accounting-periods.reopen');

    Route::get('/accounting/journal-entries', [JournalEntryController::class, 'index'])->name('journal-entries.index');
    Route::get('/accounting/journal-entries/new', [JournalEntryController::class, 'create'])->name('journal-entries.create');
    Route::post('/accounting/journal-entries', [JournalEntryController::class, 'store'])->name('journal-entries.store');
    Route::get('/accounting/journal-entries/{journalEntry}', [JournalEntryController::class, 'show'])->name('journal-entries.show');
    Route::get('/accounting/journal-entries/{journalEntry}/edit', [JournalEntryController::class, 'edit'])->name('journal-entries.edit');
    Route::patch('/accounting/journal-entries/{journalEntry}', [JournalEntryController::class, 'update'])->name('journal-entries.update');
    Route::post('/accounting/journal-entries/{journalEntry}/post', [JournalEntryController::class, 'post'])->name('journal-entries.post');
    Route::post('/accounting/journal-entries/{journalEntry}/reverse', [JournalEntryController::class, 'reverse'])->name('journal-entries.reverse');
    Route::delete('/accounting/journal-entries/{journalEntry}', [JournalEntryController::class, 'destroy'])->name('journal-entries.destroy');

    Route::middleware('throttle:reports')->group(function () {
        Route::get('/accounting/reports/ledger', [FinancialReportController::class, 'ledger'])->name('reports.ledger');
        Route::get('/accounting/reports/trial-balance', [FinancialReportController::class, 'trialBalance'])->name('reports.trial-balance');
        Route::get('/accounting/reports/income-statement', [FinancialReportController::class, 'incomeStatement'])->name('reports.income-statement');
        Route::get('/accounting/reports/balance-sheet', [FinancialReportController::class, 'balanceSheet'])->name('reports.balance-sheet');
        Route::get('/accounting/reports/cash-summary', [FinancialReportController::class, 'cashSummary'])->name('reports.cash-summary');
        Route::get('/accounting/reports/ar-aging', [FinancialReportController::class, 'arAging'])->name('reports.ar-aging');
        Route::get('/accounting/reports/ap-aging', [FinancialReportController::class, 'apAging'])->name('reports.ap-aging');
        Route::get('/accounting/reports/reconciliation', [FinancialReportController::class, 'reconciliation'])->name('reports.reconciliation');
    });

    Route::get('/invoices', [InvoiceController::class, 'index'])->middleware('throttle:search')->name('invoices.index');
    Route::get('/invoices/export', [InvoiceController::class, 'export'])->middleware('throttle:export')->name('invoices.export');
    Route::get('/invoices/new', [InvoiceController::class, 'wizard'])->name('invoices.wizard');
    Route::get('/invoices/generate/preview', [InvoiceController::class, 'generatePreview'])->middleware('throttle:bulk')->name('invoices.generate.preview');
    Route::post('/invoices/generate', [InvoiceController::class, 'generate'])->middleware('throttle:bulk')->name('invoices.generate');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::patch('/invoices/{invoice}/discount', [InvoiceController::class, 'setDiscount'])->name('invoices.discount');
    Route::post('/invoices/{invoice}/toggle-waive', [InvoiceController::class, 'toggleWaive'])->name('invoices.toggle-waive');
    Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
    Route::delete('/invoices', [InvoiceController::class, 'destroyMany'])->name('invoices.destroy-many');
    Route::post('/invoices/batches/{batchId}/cancel', [InvoiceController::class, 'cancelBatch'])->name('invoices.batches.cancel');

    Route::post('/invoices/{invoice}/payments', [PaymentController::class, 'store'])->name('payments.store');
    Route::get('/invoices/{invoice}/record-payment', [PaymentController::class, 'recordForm'])->name('payments.record.create');
    Route::post('/invoices/{invoice}/record-payment', [PaymentController::class, 'record'])->name('payments.record');
    Route::get('/payments/pending', [PaymentController::class, 'index'])->name('payments.pending');
    Route::get('/payments/{payment}', [PaymentController::class, 'review'])->whereNumber('payment')->name('payments.review');
    Route::post('/payments/{payment}/verify', [PaymentController::class, 'verify'])->name('payments.verify');
    Route::post('/payments/{payment}/refund', [PaymentController::class, 'refund'])->name('payments.refund');
    Route::post('/payments/{payment}/reject', [PaymentController::class, 'reject'])->name('payments.reject');
    Route::get('/payments/{payment}/proof', [PaymentController::class, 'proof'])->name('payments.proof');
    Route::get('/payments/{payment}/receipt', [PaymentController::class, 'receipt'])->middleware('throttle:pdf')->name('payments.receipt');
});

require __DIR__.'/auth.php';
