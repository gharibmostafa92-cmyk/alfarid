<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog;

use App\Http\Requests\Admin\Catalog\VariantRules;
use App\Models\ProductVariant;

final class UpdateVariantRules
{
    /** @return array<string, array<mixed>> */
    public static function for(ProductVariant $variant): array
    {
        $rules = VariantRules::base($variant->id);
        foreach ($rules as $field => $ruleList) {
            array_unshift($ruleList, 'sometimes');
            $rules[$field] = $ruleList;
        }

        return $rules;
    }
}
