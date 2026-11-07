<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StocktakeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Physical stock count session for verifying inventory accuracy at a branch.
 */
class Stocktake extends Model
{
    public function scans(): HasMany
    {
        return $this->hasMany(StocktakeScan::class);
    }

    protected $fillable = [
        'stocktake_number', 'branch_id', 'location_id', 'status',
        'created_by', 'approved_by', 'approved_at', 'notes',
    ];

    protected $casts = [
        'status' => StocktakeStatus::class,
        'approved_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(StocktakeItem::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
