<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerDocumentStatus;
use App\Enums\CustomerDocumentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Customer verification document (commercial registry, tax card, national ID).
 * Stored securely in private object storage; never exposed via public URLs.
 */
#[Fillable(['customer_id', 'document_type', 'file_path', 'original_name', 'mime_type', 'status', 'review_note'])]
/**
 * Commercial registry, tax cards, and verification documents uploaded by wholesale merchants.
 */
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
