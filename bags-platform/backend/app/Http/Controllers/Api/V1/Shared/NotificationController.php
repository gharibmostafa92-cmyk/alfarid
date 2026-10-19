<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * متحكم الإشعارات الموحد لكل البوابات (متجر/جملة/مورد/إدارة/Nقاط البيع):
 * يعمل مع أي notifiable مصادق (User/Customer/SupplierUser) عبر العلاقة
 * polymorphic في جدول notifications القياسي.
 */
class NotificationController extends Controller
{
    /** قائمة إشعارات المستخدم الحالي (20 لكل صفحة) + عدد غير المقروء. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $page = $user->notifications()->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn ($notification): array => [
                'id' => $notification->id,
                'type' => class_basename($notification->type),
                'data' => $notification->data,
                'read_at' => $notification->read_at?->toIso8601String(),
                'created_at' => $notification->created_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'unread_count' => $user->unreadNotifications()->count(),
            ],
        ]);
    }

    /** تعليم إشعار واحد كمقروء (إشعار غيره = 404). */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
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
}
