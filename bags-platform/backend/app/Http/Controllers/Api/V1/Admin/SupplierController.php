<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Supplier\StoreSupplierUserRequest;
use App\Http\Requests\Admin\Supplier\SupplierRequest;
use App\Http\Requests\Admin\Supplier\UpdateSupplierUserRequest;
use App\Http\Resources\Admin\SupplierResource;
use App\Http\Resources\Admin\SupplierUserResource;
use App\Models\Supplier;
use App\Models\SupplierUser;
use App\Services\SearchTerm;
use App\Services\SupplierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * إدارة الموردين وحسابات موظفيهم (بوابة المورد):
 * الرصيد الجاري دفتري — يبدأ من الافتتاحي ثم يتحرك بالفواتير والدفعات فقط.
 */
class SupplierController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:suppliers.view', only: ['index', 'show', 'users']),
            new Middleware('can:suppliers.create', only: ['store', 'storeUser']),
            new Middleware('can:suppliers.update', only: ['update', 'updateUser']),
            new Middleware('can:suppliers.delete', only: ['destroy', 'destroyUser']),
        ];
    }

    public function __construct(protected SupplierService $suppliers) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $search = trim((string) $request->query('search', ''));
        $suppliers = Supplier::query()
            ->when($request->query('is_active') !== null, fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($search !== '', fn ($q) => $q->where(function ($w) use ($search): void {
                $pattern = SearchTerm::pattern($search);
                $w->where('name', 'ilike', $pattern)
                    ->orWhere('code', 'ilike', $pattern)
                    ->orWhere('company_name', 'ilike', $pattern);
            }))
            ->latest('id')
            ->paginate(20);

        return SupplierResource::collection($suppliers);
    }

    public function store(SupplierRequest $request): JsonResponse
    {
        $supplier = $this->suppliers->create($request->validated());

        return SupplierResource::make($supplier)->response()->setStatusCode(201);
    }

    public function show(Supplier $supplier): SupplierResource
    {
        return SupplierResource::make($supplier->load('users'));
    }

    public function update(SupplierRequest $request, Supplier $supplier): SupplierResource
    {
        return SupplierResource::make($this->suppliers->update($supplier, $request->validated()));
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        if ($supplier->purchaseOrders()->exists() || $supplier->invoices()->exists()
            || $supplier->payments()->exists() || $supplier->users()->exists()) {
            throw new BusinessException('لا يمكن حذف مورد له أوامر شراء أو فواتير أو دفعات أو حسابات مستخدمين؛ عطّله بدلًا من حذفه.');
        }
        $supplier->delete();

        return response()->json(['message' => 'تم حذف المورد.']);
    }

    // ---------- حسابات موظفي المورد ----------

    public function users(Supplier $supplier): AnonymousResourceCollection
    {
        return SupplierUserResource::collection($supplier->users()->latest('id')->get());
    }

    public function storeUser(StoreSupplierUserRequest $request, Supplier $supplier): JsonResponse
    {
        $user = $supplier->users()->create($request->validated());

        return SupplierUserResource::make($user)->response()->setStatusCode(201);
    }

    public function updateUser(UpdateSupplierUserRequest $request, Supplier $supplier, SupplierUser $user): SupplierUserResource
    {
        abort_if((int) $user->supplier_id !== (int) $supplier->id, 404);
        $data = $request->validated();
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $user->update($data);

        return SupplierUserResource::make($user->fresh());
    }

    public function destroyUser(Supplier $supplier, SupplierUser $user): JsonResponse
    {
        abort_if((int) $user->supplier_id !== (int) $supplier->id, 404);
        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'تم حذف حساب موظف المورد.']);
    }
}
