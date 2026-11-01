<?php

namespace App\Events;

use App\Support\NotificationPresenter;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Pushed the instant any of the app's ~36 Notification classes writes its row (see the `created` hook on
 * DatabaseNotification in AppServiceProvider::boot()) -- one place that makes every kind of in-app alert
 * (attendance, payments, messages, HR, inventory, discipline, everything that already uses ->notify())
 * real-time, without touching any of those 36 classes individually. ShouldBroadcastNow (not ShouldBroadcast):
 * sent synchronously over HTTP to Reverb right here, so it still works even if no queue worker happens to
 * be running -- a silently-never-delivered push would be far worse than the extra few milliseconds this
 * costs the request that triggered it.
 */
class NotificationBroadcast implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(private DatabaseNotification $notification) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("App.Models.User.{$this->notification->notifiable_id}")];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /**
     * Exactly the shape the bell's own `recent` list already uses (NotificationPresenter also computes
     * `action_url` per notification type) -- one place teaches the frontend that shape, not two.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return NotificationPresenter::present($this->notification);
    }
}
