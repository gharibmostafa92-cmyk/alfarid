<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog\Tools;

use App\Exceptions\BusinessException;
use App\Models\AiAttachment;
use App\Models\AiPendingAction;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\Catalog\CatalogArgs;
use App\Services\Ai\Catalog\CatalogPresenter;
use App\Services\Ai\Contracts\ExecutesOutsideTransaction;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Catalog\ProductMediaService;
use App\Services\StaffAdministration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class AttachProductImagesTool implements ExecutesOutsideTransaction, WriteTool
{
    public function executesOutsideTransaction(AiPendingAction $action): bool
    {
        return true;
    }

    public function __construct(
        private ProductMediaService $media,
        private ProposalBuilder $proposals,
        private CatalogPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'attach_product_images';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function risk(): string
    {
        return 'low';
    }

    public function permissions(): array
    {
        return ['products.update'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'ربط صور مرفوعة في المحادثة (attachments) بمنتج أو بلون/متغير محدد.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['product_id', 'attachment_ids'],
                'properties' => (object) [
                    'product_id' => ['type' => 'INTEGER'],
                    'attachment_ids' => [
                        'type' => 'ARRAY',
                        'items' => ['type' => 'STRING'],
                    ],
                    'variant_id' => ['type' => 'INTEGER'],
                    'color' => ['type' => 'STRING'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = CatalogArgs::normalizeDigitsInArray($args);
        $product = Product::with('variants')->find((int) ($args['product_id'] ?? 0));
        if (! $product) {
            return ToolOutcome::denied('المنتج مش موجود.', auditable: false);
        }

        $rawIds = array_values(array_unique(array_filter(array_map('trim', (array) ($args['attachment_ids'] ?? [])), fn ($v) => $v !== '')));
        if ($rawIds === [] || count($rawIds) > 4) {
            throw ValidationException::withMessages(['attachment_ids' => ['لازم تبعت من صورة لـ 4 صور مرفوعة في المحادثة.']]);
        }

        // Strictly scope attachments to the current conversation to prevent cross-conversation leaks.
        $conv = $this->proposals->conversation();
        if (! $conv) {
            throw ValidationException::withMessages(['attachment_ids' => ['الإرفاق متاح من داخل المحادثة فقط؛ ارفع الصور في المحادثة أولًا.']]);
        }
        $attachments = AiAttachment::query()
            ->where('user_id', $actor->id)
            ->where('conversation_id', $conv->id)
            ->whereIn('id', $rawIds)
            ->get()
            ->keyBy('id');

        if ($attachments->count() !== count($rawIds)) {
            throw ValidationException::withMessages(['attachment_ids' => ['بعض الصور المرفقة غير موجودة أو لا تخص هذه المحادثة.']]);
        }

        // Verify image files exist on shared storage disk.
        $disk = Storage::disk('ai_private');
        foreach ($attachments as $att) {
            if (! $disk->exists($att->path)) {
                throw ValidationException::withMessages(['attachment_ids' => ['ملف الصورة المرفقة لم يعد متاحًا؛ ارفع الصورة من جديد.']]);
            }
        }

        $variantId = null;
        $targetLabel = 'المنتج بالكامل';

        if (! empty($args['variant_id'])) {
            $variant = $product->variants->firstWhere('id', (int) $args['variant_id']);
            if (! $variant) {
                throw ValidationException::withMessages(['variant_id' => ['المتغير المحدد لا يتبع هذا المنتج.']]);
            }
            $variantId = $variant->id;
            $targetLabel = "المتغير {$variant->sku}";
        } elseif (! empty($args['color']) && is_string($args['color'])) {
            $resolvedColor = CatalogArgs::resolveColor($args['color']);
            $matchedVariant = $product->variants
                ->sortBy('id')
                ->first(fn ($v) => mb_strtolower((string) $v->color_name_en) === mb_strtolower($resolvedColor['color_name_en']));
            if (! $matchedVariant) {
                throw ValidationException::withMessages([
                    'color' => ["مفيش متغير باللون «{$resolvedColor['color_name_ar']}» في المنتج {$product->code}."],
                ]);
            }
            $variantId = $matchedVariant->id;
            $targetLabel = "اللون {$resolvedColor['color_name_ar']} ({$matchedVariant->sku})";
        }

        $clientUploadIds = array_map(fn (string $ulid) => self::deterministicUuidFromUlid($ulid), $rawIds);

        return $this->proposals->make(
            $actor,
            $this->name(),
            [
                'product_id' => $product->id,
                'variant_id' => $variantId,
                'attachment_ids' => $rawIds,
                'client_upload_ids' => $clientUploadIds,
            ],
            risk: $this->risk(),
            title: 'إرفاق '.count($rawIds)." صور للمنتج {$product->code} ({$targetLabel})",
            before: null,
            after: [
                'product_code' => $product->code,
                'target' => $targetLabel,
                'images_count' => count($rawIds),
            ],
        );
    }

    public static function deterministicUuidFromUlid(string $ulid): string
    {
        $hash = sha1('ai-attachment:'.strtolower($ulid));
        $timeLow = substr($hash, 0, 8);
        $timeMid = substr($hash, 8, 4);
        $timeHiAndVersion = dechex((hexdec(substr($hash, 12, 4)) & 0x0FFF) | 0x5000);
        $clockSeqHiAndReserved = dechex((hexdec(substr($hash, 16, 4)) & 0x3FFF) | 0x8000);
        $node = substr($hash, 20, 12);

        return sprintf(
            '%08s-%04s-%04s-%04s-%012s',
            $timeLow,
            $timeMid,
            str_pad($timeHiAndVersion, 4, '0', STR_PAD_LEFT),
            str_pad($clockSeqHiAndReserved, 4, '0', STR_PAD_LEFT),
            $node
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $product = Product::findOrFail((int) $action->args['product_id']);
        $attachmentIds = (array) ($action->args['attachment_ids'] ?? []);
        $clientUploadIds = (array) ($action->args['client_upload_ids'] ?? []);
        $variantId = isset($action->args['variant_id']) && $action->args['variant_id'] !== null ? (int) $action->args['variant_id'] : null;

        // Defense-in-depth: limit execution strictly to attachments owned by the action initiator.
        $attachments = AiAttachment::query()
            ->where('user_id', $action->user_id)
            ->whereIn('id', $attachmentIds)
            ->get()
            ->keyBy('id');

        // Stream content from shared storage via get() rather than local paths.
        $disk = Storage::disk('ai_private');
        $uploadedFiles = [];
        $resolvedClientIds = [];
        $tmpFiles = [];
        try {
            foreach ($attachmentIds as $index => $attId) {
                /** @var AiAttachment|null $att */
                $att = $attachments->get($attId);
                if (! $att || ! $disk->exists($att->path)) {
                    throw new BusinessException('أحد ملفات الصور المرفقة لم يعد موجودًا؛ ارفع الصور من جديد.');
                }
                $tmp = tempnam(sys_get_temp_dir(), 'ai-att');
                if ($tmp === false) {
                    throw new BusinessException('تعذّر تجهيز الصورة للرفع؛ حاول مجددًا.');
                }
                $tmpFiles[] = $tmp;
                file_put_contents($tmp, $disk->get($att->path));
                $uploadedFiles[] = new UploadedFile(
                    $tmp,
                    basename($att->path),
                    $att->mime ?: 'image/jpeg',
                    null,
                    true
                );
                $resolvedClientIds[] = $clientUploadIds[$index] ?? self::deterministicUuidFromUlid((string) $attId);
            }

            $storedMedia = $this->media->upload(
                $product,
                [
                    'collection' => 'images',
                    'variant_id' => $variantId,
                    'client_upload_ids' => $resolvedClientIds,
                ],
                $uploadedFiles,
                null
            );
        } finally {
            foreach ($tmpFiles as $tmp) {
                @unlink($tmp);
            }
        }

        AiAttachment::query()
            ->whereIn('id', $attachmentIds)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        StaffAdministration::audit(
            $actor,
            'product.media_attached',
            'product',
            $product->id,
            ['media_ids'],
            [],
            ['media_ids' => $storedMedia->pluck('id')->all(), 'count' => $storedMedia->count()],
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'product:'.$product->id, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, 'اترفعت '.$storedMedia->count()." صور للمنتج {$product->code}.", [
            'product_id' => $product->id,
            'code' => $product->code,
            'media_ids' => $storedMedia->pluck('id')->all(),
            'deep_link' => '#/products/'.$product->id,
        ]);
    }
}
