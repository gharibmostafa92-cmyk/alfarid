<?php

declare(strict_types=1);

namespace App\Services\Ai\Storefront\Tools;

use App\Enums\BannerLinkType;
use App\Enums\BannerPosition;
use App\Models\AiAttachment;
use App\Models\AiPendingAction;
use App\Models\User;
use App\Services\Ai\Contracts\ExecutesOutsideTransaction;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Catalog\BannerService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class CreateBannerTool implements ExecutesOutsideTransaction, WriteTool
{
    public function __construct(private ProposalBuilder $proposals, private BannerService $banners) {}

    public function executesOutsideTransaction(AiPendingAction $action): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'create_banner';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function risk(): string
    {
        return 'medium';
    }

    public function permissions(): array
    {
        return ['store.manage'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'إنشاء بانر من صورة مرفوعة في المحادثة. من غير مرفق ارفض وقول ارفع الصورة من شاشة المتجر أو أرفقها هنا. يحتاج تأكيد.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['title', 'position', 'link_type', 'attachment_id'],
                'properties' => (object) [
                    'title' => ['type' => 'STRING'],
                    'position' => ['type' => 'STRING', 'enum' => ['main_slider', 'middle_banner', 'popup']],
                    'link_type' => ['type' => 'STRING', 'enum' => ['product', 'category', 'external', 'none']],
                    'link_value' => ['type' => 'STRING'],
                    'attachment_id' => ['type' => 'STRING'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $attachmentId = trim((string) ($args['attachment_id'] ?? ''));
        if ($attachmentId === '') {
            return ToolOutcome::denied('ارفع الصورة من شاشة المتجر أو أرفقها هنا.', auditable: false);
        }
        $conv = $this->proposals->conversation();
        if (! $conv) {
            return ToolOutcome::denied('ارفع الصورة من شاشة المتجر أو أرفقها هنا.', auditable: false);
        }
        $attachment = AiAttachment::query()->where('user_id', $actor->id)->where('conversation_id', $conv->id)->find($attachmentId);
        if (! $attachment || ! Storage::disk('ai_private')->exists($attachment->path)) {
            return ToolOutcome::denied('ارفع الصورة من شاشة المتجر أو أرفقها هنا.', auditable: false);
        }
        $data = $this->validated($args);

        return $this->proposals->make(
            $actor,
            $this->name(),
            $data + ['attachment_id' => $attachment->id],
            risk: $this->risk(),
            title: 'إنشاء بانر: '.$data['title'],
            before: null,
            after: $data,
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        BannerLinks::validateTarget($action->args);
        $attachment = AiAttachment::query()->where('user_id', $action->user_id)->findOrFail($action->args['attachment_id']);
        $disk = Storage::disk('ai_private');
        $tmp = tempnam(sys_get_temp_dir(), 'banner');
        if ($tmp === false) {
            return ToolOutcome::result(false, 'تعذّر تجهيز الصورة.');
        }
        file_put_contents($tmp, $disk->get($attachment->path));
        $size = @getimagesize($tmp);
        if ($size === false || $size[0] < 600 || $size[1] < 300 || $size[0] > 4000 || $size[1] > 4000) {
            @unlink($tmp);

            return ToolOutcome::result(false, 'الصورة لازم تبقى بين 600×300 و 4000×4000.');
        }
        $file = new UploadedFile($tmp, basename($attachment->path), $attachment->mime ?: 'image/jpeg', null, true);
        try {
            $banner = $this->banners->create($action->args, $file);
        } finally {
            @unlink($tmp);
        }

        return ToolOutcome::result(true, 'تم إنشاء البانر.', ['banner_id' => $banner->id]);
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function validated(array $args): array
    {
        $data = [
            'title' => trim((string) ($args['title'] ?? '')),
            'position' => (string) ($args['position'] ?? ''),
            'link_type' => (string) ($args['link_type'] ?? 'none'),
            'link_value' => $args['link_value'] ?? null,
        ];
        Validator::make($data, [
            'title' => ['required', 'string', 'max:120'],
            'position' => ['required', Rule::enum(BannerPosition::class)],
            'link_type' => ['required', Rule::enum(BannerLinkType::class)->except([BannerLinkType::Brand])],
        ])->validate();
        BannerLinks::validateTarget($data);

        return $data;
    }
}
