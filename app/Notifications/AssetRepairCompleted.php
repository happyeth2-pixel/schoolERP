<?php

namespace App\Notifications;

use App\Models\Asset;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app only. Sent to whoever the asset is assigned to once its repair is done -- fixed and coming
 * back, or found to be damaged -- so they are not left wondering. */
class AssetRepairCompleted extends Notification
{
    use Queueable;

    public function __construct(
        public Asset $asset,
        public string $outcome,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $locale = NotificationLocale::for($notifiable);
        $key = $this->outcome === 'fixed' ? 'notifications.asset_repair_completed.body_fixed' : 'notifications.asset_repair_completed.body_damaged';

        return [
            'title' => __('notifications.asset_repair_completed.title', [], $locale),
            'body' => __($key, ['name' => $this->asset->name, 'tag' => $this->asset->asset_tag], $locale),
            'asset_id' => $this->asset->id,
        ];
    }
}
