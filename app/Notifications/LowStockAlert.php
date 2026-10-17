<?php

namespace App\Notifications;

use App\Models\InventoryItem;
use App\Models\InventoryStore;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app only. Sent once, at the moment stock CROSSES into low/out of stock (see
 * Stock::notifyIfLowStockCrossed) -- never repeated for every later issue while it stays there. */
class LowStockAlert extends Notification
{
    use Queueable;

    public function __construct(
        public InventoryItem $item,
        public InventoryStore $store,
        public int $quantity,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $locale = NotificationLocale::for($notifiable);

        return [
            'title' => __('notifications.low_stock.title', [], $locale),
            'body' => __('notifications.low_stock.body', ['item' => $this->item->name, 'store' => $this->store->name, 'quantity' => $this->quantity], $locale),
            'item_id' => $this->item->id,
        ];
    }
}
