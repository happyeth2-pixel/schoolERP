<?php

/**
 * English is the reference: every key the other three locales are checked against (see
 * tests/Unit/NotificationLocalizationTest.php) lives here first, with a real value.
 * See LOCALIZATION.md for how these get into a Notification's toArray()/toMail() --
 * always with an explicit $locale argument (the recipient's, via
 * App\Support\Localization\NotificationLocale), never the ambient app()->getLocale().
 */
return [

    'student_marked_absent' => [
        'title' => 'Absence recorded',
        'body' => ':student was marked absent on :date. If this is a mistake, the school may correct it and you will be notified.',
    ],

    'attendance_corrected' => [
        'title' => 'Attendance corrected',
        'body' => "Correction: :student's attendance on :date was changed from :from to :to. Please disregard the earlier absence notice.",
    ],

    'complaint_submitted' => [
        'title' => 'New complaint',
        'body' => '":subject" from :name',
    ],

    'announcement_published' => [
        'title' => 'New announcement',
        'body' => 'New announcement: :title',
    ],

    'complaint_updated' => [
        'title' => 'Complaint update',
        'body' => 'Your complaint ":subject" is now :status.',
        'status' => [
            'open' => 'open',
            'in_progress' => 'in progress',
            'resolved' => 'resolved',
        ],
    ],

    'invoice_generated' => [
        'title' => 'New invoice',
        'body' => 'New invoice for :student:period: :amount ETB due.',
    ],

    'payment_refunded' => [
        'title_refunded' => 'Payment refunded',
        'title_reversed' => 'Payment reversed',
        'body_refunded' => ':amount ETB of a payment for :student was refunded. Reason: :reason',
        'body_reversed' => ':amount ETB of a payment for :student was reversed. Reason: :reason',
    ],

    'payment_rejected' => [
        'title' => 'Payment rejected',
        'body' => 'Your payment of :amount ETB was rejected: :reason',
    ],

    'payment_verified' => [
        'title' => 'Payment confirmed',
        'body' => 'Your payment of :amount ETB was confirmed. Receipt :receipt.',
    ],

    'low_stock' => [
        'title' => 'Low stock',
        'body' => ':item at :store is down to :quantity -- at or below the reorder level.',
    ],

    'requisition_submitted' => [
        'title' => 'Requisition to review',
        'body' => 'Requisition :number from :requester needs your review.',
    ],

    'requisition_approved' => [
        'title' => 'Requisition approved',
        'body' => 'Requisition :number was approved.',
    ],

    'requisition_rejected' => [
        'title' => 'Requisition rejected',
        'body' => 'Requisition :number was rejected: :reason',
    ],

    'asset_assigned' => [
        'title' => 'Asset assigned to you',
        'body' => ':name (:tag) has been assigned to you.',
    ],

    'asset_repair_completed' => [
        'title' => 'Repair finished',
        'body_fixed' => ':name (:tag) is repaired and back with you.',
        'body_damaged' => ':name (:tag) was found damaged and could not be repaired.',
    ],

    'purchase_request_submitted' => [
        'title' => 'Purchase request to review',
        'body' => 'Purchase request :number from :requester needs your review.',
    ],

    'purchase_request_approved' => [
        'title' => 'Purchase request approved',
        'body' => 'Purchase request :number was approved.',
    ],

    'purchase_request_rejected' => [
        'title' => 'Purchase request rejected',
        'body' => 'Purchase request :number was rejected: :reason',
    ],

    'purchase_request_fulfilled' => [
        'title' => 'Purchase request fulfilled',
        'body' => 'Everything on purchase request :number has now arrived.',
    ],

    'account_invitation' => [
        'subject' => 'Set your password for :school',
        'greeting' => 'Hello :name,',
        'line_created' => 'An account has been created for you at :school by :invited_by.',
        'line_created_no_inviter' => 'An account has been created for you at :school.',
        'action' => 'Set your password',
        'line_expires' => 'This link works once and expires on :expires.',
        'line_ignore' => "If you weren't expecting this, you can ignore this email.",
    ],

    'contract_expiring_soon' => [
        'title' => 'Employment contract expiring soon',
        'body' => ':name\'s contract ends on :date.',
    ],

    'loan_overdue' => [
        'title' => 'Library loan overdue',
        'body' => '":title" borrowed by :borrower was due on :date.',
    ],

    'attendance_correction_requested' => [
        'title' => 'Attendance correction needs review',
        'body' => 'A correction was requested for :name\'s attendance on :date.',
    ],

    'attendance_correction_approved' => [
        'title' => 'Attendance correction approved',
        'body' => 'Your correction for :name\'s attendance on :date was approved.',
    ],

    'attendance_correction_rejected' => [
        'title' => 'Attendance correction rejected',
        'body' => 'Your correction for :name\'s attendance on :date was rejected.',
    ],

    'leave_request_submitted' => [
        'title' => 'Leave request needs review',
        'body' => ':name requested :type from :start to :end.',
    ],

    'leave_request_approved' => [
        'title' => 'Leave request approved',
        'body' => 'Your :type request from :start to :end was approved.',
    ],

    'leave_request_rejected' => [
        'title' => 'Leave request rejected',
        'body' => 'Your :type request from :start to :end was rejected.',
    ],

    'performance_review_finalized' => [
        'title' => 'Performance review finalized',
        'body' => 'Your performance review for :cycle is ready to view.',
    ],

    'payslip_ready' => [
        'title' => 'Payslip ready',
        'body' => 'Your payslip for :period is ready to view.',
    ],

    'timetable_changed' => [
        'title' => 'Timetable changed',
        'body' => 'Your schedule for :subject with :section was added or changed.',
    ],

    'calendar_event_published' => [
        'title' => 'New calendar event',
        'body' => 'New calendar event: :title',
    ],

    'assignment_published' => [
        'title' => 'New assignment',
        'body' => 'New :subject assignment: :title',
    ],

    'assignment_due_soon' => [
        'title' => 'Assignment due tomorrow',
        'body' => ':subject assignment ":title" is due tomorrow.',
    ],

];
