<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Unified notification controller managing polymorphic in-app alerts across all portals.
 */
class NotificationController extends Controller
{
    /**
     * Lists paginated notifications for the authenticated notifiable entity with unread count.
     *
     * @param  Request  $request  Authenticated request
     * @return JsonResponse Paginated notifications with meta
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $unreadOnly = $request->boolean('unread_only');
        $query = $unreadOnly ? $user->unreadNotifications() : $user->notifications();
        $page = $query->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn ($notification) => $this->formatNotification($notification))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                // unread_only=1 already counted the unread set via paginate();
                // a second identical COUNT(*) doubles write-table load on the
                // hottest bell-poll endpoint in every portal.
                'unread_count' => $unreadOnly ? $page->total() : $user->unreadNotifications()->count(),
            ],
        ]);
    }

    /**
     * Marks a specific notification as read.
     *
     * @param  Request  $request  Authenticated request
     * @param  string  $id  UUID of the notification
     * @return JsonResponse Read confirmation and remaining unread count
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        abort_unless(Str::isUuid($id), 404);
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json([
            'data' => [
                'id' => $notification->id,
                'read_at' => $notification->read_at?->toIso8601String(),
            ],
            'meta' => ['unread_count' => $request->user()->unreadNotifications()->count()],
        ]);
    }

    /**
     * Marks unread notifications present at the start in bounded update batches.
     *
     * @param  Request  $request  Authenticated request
     * @return JsonResponse Bulk read confirmation
     */
    public function readAll(Request $request): JsonResponse
    {
        $user = $request->user();
        $startedAt = now();
        do {
            // Scan the partial index (notifiable_*, created_at DESC) in its
            // own order: forward ASC scans force a sort node in memory on
            // top of the index, while DESC is an index-only walk. No
            // iteration cap: capping would silently leave items unread, and
            // each batch removes its rows from the unread set, so the loop
            // always terminates.
            $ids = $user->unreadNotifications()->where('created_at', '<=', $startedAt)
                ->reorder()->orderByDesc('created_at')->limit(1000)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $user->unreadNotifications()->whereIn('id', $ids)->update(['read_at' => $startedAt]);
        } while ($ids->count() === 1000);

        return response()->json([
            'data' => ['read_all' => true],
            'meta' => ['unread_count' => $user->unreadNotifications()->count()],
        ]);
    }

    private function formatNotification(mixed $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => class_basename($notification->type),
            'data' => $notification->data,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
