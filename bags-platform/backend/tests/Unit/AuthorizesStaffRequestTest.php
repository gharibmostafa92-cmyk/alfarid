<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Requests\Admin\Concerns\AuthorizesStaffRequest;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Tests\TestCase;

class AuthorizesStaffRequestTest extends TestCase
{
    public function test_it_rejects_default_guard_user_when_staff_guard_is_empty(): void
    {
        $defaultGuardUser = new class extends User {
            public bool $is_active = true;

            public function can($abilities, $arguments = []): bool
            {
                return true;
            }
        };

        $request = new class($defaultGuardUser) extends FormRequest {
            use AuthorizesStaffRequest;

            public function __construct(private readonly User $fallbackUser)
            {
                parent::__construct();
            }

            public function user($guard = null): mixed
            {
                return $guard === 'staff' ? null : $this->fallbackUser;
            }

            public function resolvedStaffUser(): ?User
            {
                return $this->staffRequestUser();
            }

            public function canDo(string $permission): bool
            {
                return $this->authorizeStaffPermission($permission);
            }
        };

        $this->assertNull($request->resolvedStaffUser());
        $this->assertFalse($request->canDo('brands.create'));
    }

    public function test_it_rejects_inactive_staff_user(): void
    {
        $inactiveStaff = new class extends User {
            public function getAttribute($key): mixed
            {
                if ($key === 'is_active') {
                    return false;
                }

                return parent::getAttribute($key);
            }

            public function can($abilities, $arguments = []): bool
            {
                return true;
            }
        };

        $request = new class($inactiveStaff) extends FormRequest {
            use AuthorizesStaffRequest;

            public function __construct(private readonly User $staffUser)
            {
                parent::__construct();
            }

            public function user($guard = null): mixed
            {
                return $guard === 'staff' ? $this->staffUser : null;
            }

            public function resolvedStaffUser(): ?User
            {
                return $this->staffRequestUser();
            }

            public function canDo(string $permission): bool
            {
                return $this->authorizeStaffPermission($permission);
            }
        };

        $this->assertNull($request->resolvedStaffUser());
        $this->assertFalse($request->canDo('brands.create'));
    }

    public function test_it_authorizes_active_staff_user_with_permission(): void
    {
        $activeStaff = new class extends User {
            public function getAttribute($key): mixed
            {
                if ($key === 'is_active') {
                    return true;
                }

                return parent::getAttribute($key);
            }

            public function can($abilities, $arguments = []): bool
            {
                return $abilities === 'brands.create';
            }
        };

        $request = new class($activeStaff) extends FormRequest {
            use AuthorizesStaffRequest;

            public function __construct(private readonly User $staffUser)
            {
                parent::__construct();
            }

            public function user($guard = null): mixed
            {
                return $guard === 'staff' ? $this->staffUser : null;
            }

            public function resolvedStaffUser(): ?User
            {
                return $this->staffRequestUser();
            }

            public function canDo(string $permission): bool
            {
                return $this->authorizeStaffPermission($permission);
            }
        };

        $this->assertSame($activeStaff, $request->resolvedStaffUser());
        $this->assertTrue($request->canDo('brands.create'));
        $this->assertFalse($request->canDo('brands.delete'));
    }
}
