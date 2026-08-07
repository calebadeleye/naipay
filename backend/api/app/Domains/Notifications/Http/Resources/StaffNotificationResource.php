<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Http\Resources;

use App\Domains\Notifications\Models\StaffNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StaffNotification
 */
final class StaffNotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'action_url' => $this->action_url,
            'read' => $this->isRead(),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
