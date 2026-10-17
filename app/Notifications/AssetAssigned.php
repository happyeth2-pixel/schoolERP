<?php

namespace App\Notifications;

use App\Models\Asset;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app only. Sent to the person an asset is assigned (or reassigned) to -- they now hold something and
 * should know it. */
class AssetAssigned extends Notification
{
    use Queueable;

    public function __construct(
        public Asset $asset,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $locale = NotificationLocale::for($notifiable);

        return [
            'title' => __('notifications.asset_assigned.title', [], $locale),
            'body' => __('notifications.asset_assigned.body', ['name' => $this->asset->name, 'tag' => $this->asset->asset_tag], $locale),
            'asset_id' => $this->asset->id,
        ];
    }
}
