<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Pricing\StoreCustomerGroupRequest;
use App\Http\Requests\Admin\Pricing\UpdateCustomerGroupRequest;
use App\Http\Resources\Admin\CustomerGroupResource;
use App\Models\CustomerGroup;
use App\Services\BranchAccess;
use App\Services\CustomerGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class CustomerGroupController extends Controller implements HasMiddleware
{
    public function __construct(private readonly CustomerGroupService $groups) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:customer_groups.view', only: ['index', 'show']),
            new Middleware('can:customer_groups.create', only: ['store']),
            new Middleware('can:customer_groups.update', only: ['update']),
            new Middleware('can:customer_groups.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return CustomerGroupResource::collection($this->groups->paginate($filters));
    }

    public function store(StoreCustomerGroupRequest $request): JsonResponse
    {
        app(BranchAccess::class)->assertCentralAccess($request->user());
        $group = $this->groups->create($request->validated());

        return CustomerGroupResource::make($group)
            ->additional(['message' => 'تم إنشاء مجموعة العملاء بنجاح'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(CustomerGroup $customerGroup): CustomerGroupResource
    {
        return CustomerGroupResource::make($customerGroup->loadCount('customers'));
    }

    public function update(UpdateCustomerGroupRequest $request, CustomerGroup $customerGroup): CustomerGroupResource
    {
        app(BranchAccess::class)->assertCentralAccess($request->user());

        return CustomerGroupResource::make($this->groups->update($customerGroup, $request->validated()));
    }

    public function destroy(Request $request, CustomerGroup $customerGroup): JsonResponse
    {
        app(BranchAccess::class)->assertCentralAccess($request->user());
        $this->groups->delete($customerGroup);

        return response()->json(['message' => 'تم حذف مجموعة العملاء بنجاح']);
    }
}
