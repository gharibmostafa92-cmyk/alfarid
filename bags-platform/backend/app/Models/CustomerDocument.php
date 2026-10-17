<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerDocumentStatus;
use App\Enums\CustomerDocumentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مستند تحقق تابع لعميل (سجل تجاري/بطاقة ضريبية/بطاقة رقم قومي).
 * الملفات نفسها في تخزين خاص (storage/app/private) — لا روابط عامة أبدًا.
 */
#[Fillable(['customer_id', 'document_type', 'file_path', 'original_name', 'mime_type', 'status'])]
class CustomerDocument extends Model
{
    public static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\\/]/u', '_', $name) ?? 'document';

        return mb_substr($name, 0, 250) ?: 'document';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    protected function casts(): array
    {
        return [
            'document_type' => CustomerDocumentType::class,
            'status' => CustomerDocumentStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }
}
