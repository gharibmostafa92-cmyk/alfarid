<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Concerns;

trait NormalizesCatalogInput
{
    protected function normalizeCatalogIdentifiers(): void
    {
        $changes = [];
        foreach (['slug' => 'strtolower', 'sku' => 'strtoupper'] as $field => $normalize) {
            if (is_string($value = $this->input($field))) {
                $changes[$field] = $normalize($value);
            }
        }
        if (is_array($variants = $this->input('variants'))) {
            foreach ($variants as &$variant) {
                if (is_array($variant) && isset($variant['sku']) && is_string($variant['sku'])) {
                    $variant['sku'] = strtoupper($variant['sku']);
                }
            }
            unset($variant);
            $changes['variants'] = $variants;
        }
        $this->merge($changes);
    }
}
