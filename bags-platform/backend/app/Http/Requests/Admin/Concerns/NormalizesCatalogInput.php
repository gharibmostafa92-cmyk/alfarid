<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Concerns;

trait NormalizesCatalogInput
{
    protected function normalizeCatalogIdentifiers(): void
    {
        // L3: barcodes are case-insensitive-unique — normalize to uppercase like
        // SKUs so abc and ABC cannot coexist as separate rows.
        $changes = [];
        foreach (['slug' => 'strtolower', 'sku' => 'strtoupper', 'barcode' => 'strtoupper'] as $field => $normalize) {
            if (is_string($value = $this->input($field))) {
                $changes[$field] = $normalize($value);
            }
        }
        if (is_array($variants = $this->input('variants'))) {
            foreach ($variants as &$variant) {
                if (is_array($variant) && isset($variant['sku']) && is_string($variant['sku'])) {
                    $variant['sku'] = strtoupper($variant['sku']);
                }
                if (is_array($variant) && isset($variant['barcode']) && is_string($variant['barcode'])) {
                    $variant['barcode'] = strtoupper($variant['barcode']);
                }
            }
            unset($variant);
            $changes['variants'] = $variants;
        }
        $this->merge($changes);
    }
}
