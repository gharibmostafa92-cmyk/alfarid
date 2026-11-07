<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key-value configuration store for system preferences, business policies, and numbering schemes.
 */
class Setting extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'group',
        'key',
        'value',
        'type',
    ];
}
