<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Http\Controllers;

use App\Domains\Identity\Models\Staff;
use App\Domains\Notifications\Http\Resources\StaffNotificationResource;
use App\Domains\Notifications\Models\StaffNotification;
use App\Support\Http\ApiResponse;
use App\Support\Query\FilterType;
use App\Support\Query\QueryPipeline;
use App\Support\Query\QuerySpecification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A staff member's own notification inbox — the bell in the topbar.
 *
 * Every action here is implicitly scoped to the authenticated staff member;
 * there is no cross-staff visibility and no domain permission gate, since
 * this is a personal inbox rather than a shared resource.
 */
final class NotificationController
{
    public function index(Request $request): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $specification = QuerySpecification::make(
            searchable: [],
            filters: [
                'type' => FilterType::Exact,
            ],
            sortable: ['created_at'],
            defaultSort: ['-created_at'],
        );

        $query = StaffNotification::query()->where('staff_id', $actor->getKey());

        $notifications = QueryPipeline::for($request, $specification)->paginate($query);

        return ApiResponse::paginated(
            $notifications->through(fn (StaffNotification $notification) => new StaffNotificationResource($notification)),
            message: 'Notifications retrieved.',
            meta: [
                'unread_count' => StaffNotification::query()
                    ->where('staff_id', $actor->getKey())
                    ->unread()
                    ->count(),
            ],
        );
    }

    public function markRead(Request $request, StaffNotification $notification): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        if ($notification->staff_id !== $actor->getKey()) {
            return ApiResponse::notFound();
        }

        if (! $notification->isRead()) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return ApiResponse::success(new StaffNotificationResource($notification), 'Notification marked as read.');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        StaffNotification::query()
            ->where('staff_id', $actor->getKey())
            ->unread()
            ->update(['read_at' => now()]);

        return ApiResponse::noContent('All notifications marked as read.');
    }
}
