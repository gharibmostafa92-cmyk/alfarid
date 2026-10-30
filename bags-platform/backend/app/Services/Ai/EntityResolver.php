<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Branch;
use App\Models\Location;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\SearchTerm;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class EntityResolver
{
    public function __construct(private BranchAccess $branches) {}

    public static function normalize(string $value): string
    {
        $v = mb_strtolower(trim($value));
        $v = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $v);
        $v = str_replace(['ة'], 'ه', $v);
        $v = str_replace(['ى'], 'ي', $v);
        $v = preg_replace('/[\x{064B}-\x{065F}\x{0640}]/u', '', $v) ?? $v;

        return preg_replace('/\s+/u', ' ', $v) ?? $v;
    }

    private function restricted(?User $actor): ?int
    {
        if (! $actor) {
            return null;
        }
        try {
            return $this->branches->restrictedBranch($actor);
        } catch (\Throwable) {
            return -1;
        }
    }

    /** @return list<array{id:int,label:string,meta:string}> */
    public function branches(User $actor, string $query = '', array $filters = []): array
    {
        $restricted = $this->restricted($actor);
        if ($restricted === -1) {
            return [];
        }
        // M6/L5: الفلترة في SQL (ILIKE محمي بـ SearchTerm::escape) مع LIMIT —
        // استعلام بلا نتيجة ما يمسحش الجدول كله ولا يعمل ذاكرة lazy.
        return Branch::query()->withCount('users')
            ->when($restricted !== null, fn ($x) => $x->whereKey($restricted))
            ->when(array_key_exists('active', $filters), fn ($x) => $x->where('is_active', $filters['active']))
            ->when(isset($filters['type']), fn ($x) => $x->where('type', $filters['type']))
            ->when(isset($filters['city']), fn ($x) => $x->where('city', $filters['city']))
            ->when($query !== '', fn ($x) => $x->where(fn ($w) => $w
                ->where('name', 'ilike', SearchTerm::pattern($query))
                ->orWhere('code', 'ilike', SearchTerm::pattern($query))
                ->orWhere('city', 'ilike', SearchTerm::pattern($query))))
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(fn (Branch $b) => ['id' => $b->id, 'label' => $b->name.' ('.$b->code.')', 'meta' => trim(($b->city ?? '').' · '.($b->is_active ? 'نشط' : 'متوقف').' · موظفين: '.$b->users_count)])
            ->values()
            ->all();
    }

    /** @return list<array{id:int,label:string,meta:string}> */
    public function users(User $actor, string $query = ''): array
    {
        $restricted = $this->restricted($actor);
        if ($restricted === -1) {
            return [];
        }

        return User::query()->where('is_system', false)
            ->when($restricted !== null, fn ($x) => $x->where('branch_id', $restricted))
            ->when($query !== '', fn ($x) => $x->where(fn ($w) => $w
                ->where('name', 'ilike', SearchTerm::pattern($query))
                ->orWhere('email', 'ilike', SearchTerm::pattern($query))
                ->orWhere('phone', 'ilike', SearchTerm::pattern($query))))
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(fn (User $u) => ['id' => $u->id, 'label' => $u->name, 'meta' => $this->maskEmail($u->email).' · '.($u->is_active ? 'نشط' : 'معطّل')])
            ->values()
            ->all();
    }

    /** @return list<array{id:int,label:string,meta:string}> */
    public function roles(string $query = ''): array
    {
        return Role::query()->withCount(['users', 'permissions'])
            ->where('guard_name', 'staff')
            ->when($query !== '', fn ($x) => $x->where('name', 'ilike', SearchTerm::pattern($query)))
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(fn (Role $r) => ['id' => $r->id, 'label' => $r->name, 'meta' => 'موظفين: '.$r->users_count.' · صلاحيات: '.$r->permissions_count])
            ->values()
            ->all();
    }

    /** @return list<array{id:int,label:string,meta:string}> */
    public function locations(User $actor, string $query = '', ?int $branchId = null): array
    {
        $restricted = $this->restricted($actor);
        if ($restricted === -1) {
            return [];
        }
        // M1: قيد الفرع يطبق أولًا — مقيّد بفرع ما يقدرش يوجّه branch_id لفرع تاني.
        // M6/L5: نفس الفلترة السلكية المحمية مع LIMIT.
        return Location::query()
            ->when($restricted !== null, fn ($q) => $q->where('branch_id', $restricted), fn ($q) => $q->when($branchId !== null, fn ($w) => $w->where('branch_id', $branchId)))
            ->when($query !== '', fn ($x) => $x->where(fn ($w) => $w
                ->where('name', 'ilike', SearchTerm::pattern($query))
                ->orWhere('code', 'ilike', SearchTerm::pattern($query))))
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(fn (Location $l) => [
                'id' => $l->id,
                'label' => $l->name.' ('.$l->code.')',
                'meta' => trim(($l->type?->value ?? '').' · '.($l->is_active ? 'نشط' : 'معطّل').' · فرع: '.$l->branch_id),
            ])
            ->values()
            ->all();
    }

    public function maskEmail(?string $email): string
    {
        if (! $email || ! str_contains($email, '@')) {
            return '';
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 2).'***@'.$domain;
    }

    /** @param list<string> $fields */
    public function matches(string $query, array $fields): bool
    {
        $nq = self::normalizeForSearch($query);
        if ($nq === '') {
            return true;
        }
        foreach ($fields as $f) {
            if (str_contains(self::normalizeForSearch($f), $nq)) {
                return true;
            }
        }

        return false;
    }

    public static function normalizeForSearch(string $value): string
    {
        return preg_replace('/(^|\s)ال/u', '$1', self::normalize($value)) ?? self::normalize($value);
    }

    public function exactBranch(User $actor, string $query): ?Branch
    {
        $opts = $this->branches($actor, $query);
        if (count($opts) !== 1) {
            return null;
        }

        return Branch::find($opts[0]['id']);
    }

    public function reference(User $actor, array $reference): array
    {
        $type = $reference['entity'] ?? '';
        abort_unless(app(ToolRegistry::class)->canReadEntity($actor, $type), 403, 'المرجع خارج صلاحيات العرض.');
        $id = $reference['id'] ?? null;
        $branch = $this->restricted($actor);
        $model = match ($type) {
            'branch' => Branch::query()->when($branch !== null, fn ($q) => $q->whereKey($branch))->find($id),
            'user' => User::query()->where('is_system', false)->when($branch !== null, fn ($q) => $q->where('branch_id', $branch))->find($id),
            'role' => Role::query()->where('guard_name', 'staff')->find($id),
            'permission' => Permission::query()->where('guard_name', 'staff')->where('name', $id)->first(),
            'location' => Location::query()->when($branch !== null, fn ($q) => $q->where('branch_id', $branch))->find($id),
            default => null,
        };
        abort_unless($model, 422, 'الاختيار مش موجود أو خارج نطاقك.');

        return ['entity' => $type, 'id' => $type === 'permission' ? $model->name : $model->id, 'label' => $model->name];
    }
}
