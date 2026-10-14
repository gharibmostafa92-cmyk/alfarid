<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name',
    'email',
    'phone',
    'password',
    'type',
    'status',
    'tax_number',
    'commercial_register',
])]
#[Hidden(['password', 'remember_token'])]
class Customer extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'type' => CustomerType::class,
            'status' => CustomerStatus::class,
        ];
    }
}
