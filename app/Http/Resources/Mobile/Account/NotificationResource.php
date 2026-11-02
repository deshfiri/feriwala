<?php

namespace App\Http\Resources\Mobile\Account;

use App\Domain\Notification\Data\HeaderNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the notification bell.
 *
 * The web bell's link is a web page address and is left out; the app decides
 * where a notification takes the person.
 *
 * @mixin HeaderNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** Notification identifier, for the read endpoint. */
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            /** Time phrase in the request language, e.g. "5 minutes ago". */
            'created_at_human' => $this->createdAt,
            'created_at' => $this->createdAtIso,
            'read_at' => $this->readAt,
            'is_read' => $this->readAt !== null,
        ];
    }
}
