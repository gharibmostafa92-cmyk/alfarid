<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Services\SettingService;
use Spatie\Permission\Models\Role;

trait StaffAdministrationRequests
{
    private function expectedStaffState(string $method, string $uri, array $payload): array
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (in_array(strtoupper($method), ['PATCH', 'PUT', 'DELETE'], true)
            && preg_match('~/admin/(users|roles)/([0-9]+)$~', $path, $target) && ! array_key_exists('expected_version', $payload)) {
            $model = $target[1] === 'users' ? User::class : Role::class;
            $payload['expected_version'] = (int) ($model::find($target[2])?->lock_version ?? 0);
        }
        if (strtoupper($method) === 'PUT' && str_ends_with($path, '/admin/settings') && ! array_key_exists('expected', $payload)) {
            $payload['expected'] = [];
            foreach (array_keys($payload) as $key) {
                if ($key !== 'expected') {
                    $payload['expected'][$key] = app(SettingService::class)->get($key);
                }
            }
        }

        return $payload;
    }
}
