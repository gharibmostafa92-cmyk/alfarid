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
use Illuminate\Validation\Rule;

/**
 * Handles secure file uploads (images and price lists) for AI assistant conversations.
 */
class AiAttachmentController extends Controller
{
    public function store(Request $request, AiConversation $conversation): JsonResponse
    {
        $user = $request->user('staff');
        abort_unless($conversation->user_id === $user->id, 403);

        // Disallow uploads if the AI agent is disabled, user is inactive, or spending limits are exceeded.
        app(AgentOrchestrator::class)->guard($user);

        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:4'],
            'files.*' => Rule::forEach(static function ($file): array {
                if ($file instanceof UploadedFile && in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'txt'], true)) {
                    return ['bail', 'required', 'file', 'mimes:csv,txt', 'mimetypes:text/csv,text/plain', 'max:1024',
                        static function (string $attribute, UploadedFile $value, \Closure $fail): void {
                            $content = $value->getContent();
                            if (str_contains($content, "\0") || preg_match('/^(?:\xEF\xBB\xBF)?\s*<\?(?:php\b|=)/i', $content)) {
                                $fail('المرفق لازم يكون ملف أسعار نصي صالح.');
                            }
                        },
                    ];
                }

                return ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:5120', new MaxImagePixels];
            }),
        ]);

        // Enforce a per-user pending attachment quota to prevent storage exhaustion from unconsumed files.
        $quota = max(1, (int) config('services.gemini.max_pending_attachments_per_user', 40));
        $pending = AiAttachment::query()->where('user_id', $user->id)->whereNull('consumed_at')->count();
        abort_if($pending + count($request->file('files')) > $quota, 429, 'وصلت للحد الأقصى من المرفقات غير المستخدمة؛ استخدمها أو احذف المحادثة أولًا.');

        /** @var list<UploadedFile> $files */
        $files = array_values((array) $request->file('files', []));
        // Use shared private disk so queued workers and web nodes share access across prepare, execute, and prune.
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
                // Remove stored file if database creation fails to prevent orphaned artifacts.
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
