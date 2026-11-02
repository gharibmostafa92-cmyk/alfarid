<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiAttachment;
use App\Models\AiConversation;
use App\Rules\MaxImagePixels;
use App\Services\Ai\AgentOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AiAttachmentController extends Controller
{
    public function store(Request $request, AiConversation $conversation): JsonResponse
    {
        $user = $request->user('staff');
        abort_unless($conversation->user_id === $user->id, 403);

        // L1: لا رفع والمساعد مقفول أو المستخدم غير نشط أو فوق الميزانية.
        app(AgentOrchestrator::class)->guard($user);

        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:4'],
            'files.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:5120', new MaxImagePixels],
        ]);

        // L1: حصة مرفقات غير مستهلكة لكل مستخدم — الرفع بلا سقف كان يتيح
        // ملء التخزين المشترك (4 ملفات × 5MB كل دقيقة لكل مستخدم).
        $quota = max(1, (int) config('services.gemini.max_pending_attachments_per_user', 40));
        $pending = AiAttachment::query()->where('user_id', $user->id)->whereNull('consumed_at')->count();
        abort_if($pending >= $quota, 429, 'وصلت للحد الأقصى من الصور المرفقة غير المستخدمة؛ استخدمها أو احذف المحادثة أولًا.');

        /** @var list<UploadedFile> $files */
        $files = array_values((array) $request->file('files', []));
        // H3: تخزين مشترك خاص بدل القرص المحلي — كل سيرفر worker يرى
        // نفس الملفات في prepare/execute/prune.
        $disk = Storage::disk('ai_private');
        $created = [];

        foreach ($files as $file) {
            $ulid = (string) Str::ulid();
            $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
            $relativePath = "ai-attachments/{$conversation->id}/{$ulid}.{$ext}";
            $content = (string) $file->getContent();
            $sha256 = hash('sha256', $content);

            $disk->put($relativePath, $content);

            try {
                $attachment = AiAttachment::create([
                    'id' => $ulid,
                    'conversation_id' => $conversation->id,
                    'user_id' => $user->id,
                    'path' => $relativePath,
                    'mime' => (string) $file->getMimeType(),
                    'size' => (int) $file->getSize(),
                    'sha256' => $sha256,
                ]);
            } catch (\Throwable $e) {
                // L1: فشل الإدخال بعد كتابة الملف كان يترك ملفًا يتيمًا.
                $disk->delete($relativePath);

                throw $e;
            }

            $created[] = [
                'id' => $attachment->id,
                'mime' => $attachment->mime,
                'size' => $attachment->size,
                'sha256' => $attachment->sha256,
            ];
        }

        return response()->json(['data' => $created], 201);
    }
}
