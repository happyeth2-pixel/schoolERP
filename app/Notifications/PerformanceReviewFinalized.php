<?php

namespace App\Notifications;

use App\Models\PerformanceReview;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app only, to the employee's OWN linked account, once their review for a cycle is finalized -- same
 * "the decision belongs to the person it affects" rule as LeaveRequestReviewed. */
class PerformanceReviewFinalized extends Notification
{
    use Queueable;

    public function __construct(
        public PerformanceReview $performanceReview,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $locale = NotificationLocale::for($notifiable);

        return [
            'title' => __('notifications.performance_review_finalized.title', [], $locale),
            'body' => __('notifications.performance_review_finalized.body', [
                'cycle' => $this->performanceReview->cycle->name,
            ], $locale),
            'performance_review_id' => $this->performanceReview->id,
            'employee_id' => $this->performanceReview->employee_id,
        ];
    }
}
