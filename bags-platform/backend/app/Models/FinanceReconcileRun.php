<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceReconcileRun extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['branch_id', 'checked', 'problem_count', 'samples'];

    protected $casts = [
        'branch_id' => 'integer',
        'checked' => 'integer',
        'problem_count' => 'integer',
        'samples' => 'array',
    ];
}
