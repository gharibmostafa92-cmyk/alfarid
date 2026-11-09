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
use App\Services\Shared\SearchTerm;
use App\Services\Procurement\SupplierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

/**
 * Supplier profiles and portal staff administration with ledger balance tracking.
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

    /**
     * List paginated suppliers with case-insensitive search and status filtering.
     */
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

    /**
     * Create a new supplier profile and initialize ledger balance.
     */
    public function store(SupplierRequest $request): JsonResponse
    {
        $supplier = $this->suppliers->create($request->validated());

        return SupplierResource::make($supplier)->response()->setStatusCode(201);
    }

    /**
     * Retrieve supplier profile details and portal users.
     */
    public function show(Supplier $supplier): SupplierResource
    {
        return SupplierResource::make($supplier->loadMissing('users'));
    }

    /**
     * Update supplier profile details.
     */
    public function update(SupplierRequest $request, Supplier $supplier): SupplierResource
    {
        return SupplierResource::make($this->suppliers->update($supplier, $request->validated()));
    }

    /**
     * Delete an unused supplier profile.
     */
    public function destroy(Supplier $supplier): JsonResponse
    {
        DB::transaction(function () use ($supplier): void {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
            if ($supplier->purchaseOrders()->exists() || $supplier->invoices()->exists()
                || $supplier->payments()->exists() || $supplier->users()->exists()) {
                throw new BusinessException('لا يمكن حذف مورد له أوامر شراء أو فواتير أو دفعات أو حسابات مستخدمين؛ عطّله بدلًا من حذفه.');
            }
            $supplier->delete();
        });

        return response()->json(['message' => 'تم حذف المورد.']);
    }

    // --- Supplier Portal User Accounts ---

    /**
     * List portal user accounts associated with the supplier.
     */
    public function users(Supplier $supplier): AnonymousResourceCollection
    {
        return SupplierUserResource::collection($supplier->users()->latest('id')->get());
    }

    /**
     * Create a new portal user account for the supplier.
     */
    public function storeUser(StoreSupplierUserRequest $request, Supplier $supplier): JsonResponse
    {
        $user = $supplier->users()->create($request->validated());

        return SupplierUserResource::make($user)->response()->setStatusCode(201);
    }

    /**
     * Update supplier portal user account and invalidate tokens on credential changes.
     */
    public function updateUser(UpdateSupplierUserRequest $request, Supplier $supplier, SupplierUser $user): SupplierUserResource
    {
        $this->assertBelongsToSupplier($supplier, $user);
        $data = $request->validated();
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $user = DB::transaction(function () use ($supplier, $user, $data): SupplierUser {
            $user = $supplier->users()->lockForUpdate()->findOrFail($user->id);
            $emailChanged = isset($data['email']) && $data['email'] !== $user->email;
            $user->update($data);
            if (isset($data['password']) || $emailChanged || (isset($data['is_active']) && ! $data['is_active'])) {
                $user->tokens()->delete();
            }

            return $user;
        });

        return SupplierUserResource::make($user->fresh());
    }

    /**
     * Revoke tokens and delete a supplier portal user account.
     */
    public function destroyUser(Supplier $supplier, SupplierUser $user): JsonResponse
    {
        $this->assertBelongsToSupplier($supplier, $user);
        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'تم حذف حساب موظف المورد.']);
    }

    /**
     * Verifies that the portal user belongs to the specified supplier, aborting 404 otherwise.
     *
     * @param  Supplier  $supplier  Parent supplier
     * @param  SupplierUser  $user  Target portal user
     */
    private function assertBelongsToSupplier(Supplier $supplier, SupplierUser $user): void
    {
        abort_if((int) $user->supplier_id !== (int) $supplier->id, 404);
    }
}
