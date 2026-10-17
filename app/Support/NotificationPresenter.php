<?php

namespace App\Support;

use App\Models\Payment;
use Illuminate\Notifications\DatabaseNotification;

/**
 * A notification should lead somewhere: one tap from "Payment rejected" to that payment's bill, not a dead end the
 * person has to go searching from. Used by both the bell's shared prop (HandleInertiaRequests) and the full
 * Notifications page, so a link only needs to be taught here once.
 */
class NotificationPresenter
{
    public static function present(DatabaseNotification $n): array
    {
        return [
            'id' => $n->id,
            'title' => $n->data['title'] ?? '',
            'body' => $n->data['body'] ?? '',
            'read_at' => $n->read_at,
            'created_at' => $n->created_at->diffForHumans(),
            'action_url' => self::actionUrl($n),
        ];
    }

    private static function actionUrl(DatabaseNotification $n): ?string
    {
        $data = $n->data;

        return match (class_basename($n->type)) {
            'AnnouncementPublished' => route('announcements.index'),
            'StudentMarkedAbsent', 'AttendanceCorrected' => isset($data['student_id']) ? route('family.attendance', ['student' => $data['student_id']]) : null,
            'InvoiceGenerated', 'PaymentRefunded' => isset($data['invoice_id']) ? route('invoices.show', $data['invoice_id']) : null,
            'PaymentVerified', 'PaymentRejected' => self::invoiceUrlForPayment($data['payment_id'] ?? null),
            'ComplaintSubmitted', 'ComplaintUpdated' => isset($data['complaint_id']) ? route('complaints.show', $data['complaint_id']) : null,
            'LowStockAlert' => isset($data['item_id']) ? route('inventory-items.show', $data['item_id']) : null,
            'RequisitionSubmitted', 'RequisitionApproved', 'RequisitionRejected' => isset($data['requisition_id']) ? route('requisitions.show', $data['requisition_id']) : null,
            'AssetAssigned', 'AssetRepairCompleted' => isset($data['asset_id']) ? route('assets.show', $data['asset_id']) : null,
            'PurchaseRequestSubmitted', 'PurchaseRequestApproved', 'PurchaseRequestRejected', 'PurchaseRequestFulfilled' => isset($data['purchase_request_id']) ? route('purchase-requests.show', $data['purchase_request_id']) : null,
            'ContractExpiringSoon' => isset($data['employee_id']) ? route('employees.show', $data['employee_id']) : null,
            'AttendanceCorrectionRequested' => route('staff-attendance.corrections'),
            'AttendanceCorrectionReviewed' => isset($data['employee_id']) ? route('employees.show', $data['employee_id']) : null,
            'LeaveRequestSubmitted' => route('leave-requests.pending'),
            // The recipient here is always the employee the leave is FOR -- often a self-service-only
            // teacher/accountant with no employee-view at all, so this must never point at the HR-only
            // employees.show page (it would 403 for exactly the person it's meant to reach).
            'LeaveRequestReviewed' => route('leave-requests.mine'),
            'PerformanceReviewFinalized' => route('performance-reviews.mine'),
            'PayslipReady' => route('payslips.mine'),
            // The recipient is always the teacher the slot is FOR, who may hold no timetable-view at all
            // (teachers get no timetable permission by default) -- route to their own self-service page,
            // never an HR/admin-only one, learning Leave/Performance's own fixed regression from the start.
            'TimetableChanged' => route('timetable.mine'),
            // Any signed-in account can open the calendar (no permission gate, same as Announcements) --
            // safe to route every audience, including parents/students, straight to the full list.
            'CalendarEventPublished' => route('calendar.index'),
            // No dedicated single-loan page exists -- the library index already surfaces this loan in its
            // own active/overdue list, the same "route to the list, not a nonexistent detail page" choice
            // LowStockAlert already makes.
            'LoanOverdue' => route('library.index'),
            // The recipient is always a guardian -- route straight to the child this one is about.
            'AssignmentPublished', 'AssignmentDueSoon' => isset($data['student_id']) ? route('family.homework', ['student' => $data['student_id']]) : route('family.homework'),
            default => null,
        };
    }

    private static function invoiceUrlForPayment(?int $paymentId): ?string
    {
        if (! $paymentId) {
            return null;
        }

        $invoiceId = Payment::whereKey($paymentId)->value('invoice_id');

        return $invoiceId ? route('invoices.show', $invoiceId) : null;
    }
}
