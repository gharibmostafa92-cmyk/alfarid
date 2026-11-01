<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Location;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\SearchTerm;
use App\Services\StaffAdministration;
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
        // مصدر واحد للقناع: StaffAdministration (حتى لا يتباين القناع
        // بين التدقيق وعرض الكيانات).
        return StaffAdministration::maskEmail($email);
    }

    /** تقنيع رقم موبايل: 11 رقمًا يظهر أول 3 وآخر 3 فقط، غير كده ***. */
    public static function maskPhone(?string $p): string
    {
        return $p && strlen($p) === 11 ? substr($p, 0, 3).'*****'.substr($p, -3) : '***';
    }

    /** تقنيع رقم ضريبي/سجل تجاري: أول حرفين وآخر حرفين فقط. */
    public static function maskLegal(?string $v): ?string
    {
        if ($v === null || trim($v) === '') {
            return null;
        }
        $v = trim($v);

        return mb_strlen($v) <= 4 ? '***' : mb_substr($v, 0, 2).'***'.mb_substr($v, -2);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'قيد المراجعة',
            'approved' => 'معتمد',
            'rejected' => 'مرفوض',
            'suspended' => 'موقوف',
            default => $status,
        };
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'retail' => 'قطاعي',
            'wholesale' => 'جملة',
            'distributor' => 'موزع',
            default => $type,
        };
    }

    /**
     * @return list<array{id:int,label:string,meta:string}>
     *                                                      لا يوجد branch scoping هنا — مقصود، زي REST بالظبط
     *                                                      (CustomerController مبيستخدمش BranchAccess).
     */
    public function customers(User $actor, string $query = '', array $filters = []): array
    {
        $q = Customer::query()
            ->when(($filters['business_only'] ?? false), fn ($x) => $x->whereIn('type', ['wholesale', 'distributor']))
            ->when(isset($filters['type']), fn ($x) => $x->where('type', $filters['type']))
            ->when(isset($filters['status']), fn ($x) => $x->where('status', $filters['status']))
            ->when(isset($filters['customer_group_id']), fn ($x) => $x->where('customer_group_id', $filters['customer_group_id']))
            ->when(isset($filters['pending_older_than_hours']), fn ($x) => $x->where('status', 'pending')->where('created_at', '<', now()->subHours((int) $filters['pending_older_than_hours'])));
        if (trim($query) !== '') {
            // رقم موبايل (بما فيه الأرقام العربية) يُبحث بالبادئة بعد تطبيعه.
            if (preg_match('/^[0-9٠-٩۰-۹\s().+-]+$/u', trim($query))) {
                $q->whereLike('phone', SearchTerm::prefix(Customer::normalizePhone($query)), caseSensitive: false);
            } else {
                $q->whereLike('search_text', SearchTerm::pattern($query), caseSensitive: false);
            }
        }

        return $q->orderBy('id')->limit(50)->get()
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'label' => $c->company_name ? $c->name.' — '.$c->company_name : $c->name,
                'meta' => self::maskPhone($c->phone).' · '.self::statusLabel($c->status->value).' · '.self::typeLabel($c->type->value),
            ])
            ->values()
            ->all();
    }

    /** @return list<array{id:int,label:string,meta:string}> */
    public function customerGroups(string $query = '', array $filters = []): array
    {
        return CustomerGroup::query()->withCount('customers')
            ->when(array_key_exists('active', $filters), fn ($x) => $x->where('is_active', $filters['active']))
            ->when(trim($query) !== '', fn ($x) => $x->where(fn ($w) => $w
                ->where('name', 'ilike', SearchTerm::pattern($query))
                ->orWhere('code', 'ilike', SearchTerm::pattern($query))))
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(fn (CustomerGroup $g) => ['id' => $g->id, 'label' => $g->name.' ('.$g->code.')', 'meta' => 'خصم: '.$g->discount_percentage.'% · عملاء: '.$g->customers_count.($g->is_active ? '' : ' · متوقفة')])
            ->values()
            ->all();
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
            // العملاء بلا branch scoping — زي REST بالظبط.
            'customer' => Customer::query()->find($id),
            'customer_group' => CustomerGroup::query()->find($id),
            default => null,
        };
        abort_unless($model, 422, 'الاختيار مش موجود أو خارج نطاقك.');

        // اسم العميل كتبه العميل بنفسه ورايح للنموذج كمرجع: نشيل رموز التحكم ونحدّ الطول.
        $label = in_array($type, ['customer', 'customer_group'], true) ? mb_substr((string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $model->name), 0, 120) : $model->name;

        return ['entity' => $type, 'id' => $type === 'permission' ? $model->name : $model->id, 'label' => $label];
    }
}
