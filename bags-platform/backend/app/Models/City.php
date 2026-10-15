<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name_ar', 'name_en', 'shipping_cost', 'is_active'])]
class City extends Model
{
    use HasFactory;

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    protected function casts(): array
    {
        return [
            'shipping_cost' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
