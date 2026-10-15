<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Customer\ApproveCustomerRequest;
use App\Http\Requests\Admin\Customer\RejectCustomerRequest;
use App\Http\Requests\Admin\Customer\StoreCustomerRequest;
use App\Http\Requests\Admin\Customer\SuspendCustomerRequest;
use App\Http\Requests\Admin\Customer\UpdateCustomerRequest;
use App\Http\Resources\Admin\CustomerResource;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Services\CustomerManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** إدارة العملاء واعتماد حسابات الجملة في لوحة الإدارة. */
class CustomerController extends Controller implements HasMiddleware
{
    public function __construct(private readonly CustomerManagementService $customers) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:customers.view', only: ['index', 'show', 'downloadDocument']),
            new Middleware('can:customers.create', only: ['store']),
            new Middleware('can:customers.update', only: ['update']),
            new Middleware('can:customers.delete', only: ['destroy']),
            new Middleware('can:customers.approve', only: ['approve', 'reject', 'suspend']),
        ];
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(array_column(CustomerType::cases(), 'value'))],
            'status' => ['nullable', Rule::in(array_column(CustomerStatus::cases(), 'value'))],
            'customer_group_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return CustomerResource::collection($this->customers->paginate($filters));
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = $this->customers->create($request->validated());

        return CustomerResource::make($customer)
            ->additional(['message' => 'تم إنشاء العميل بنجاح'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Customer $customer): CustomerResource
    {
        return new CustomerResource($this->customers->find($customer->id));
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): CustomerResource
    {
        return new CustomerResource($this->customers->update($customer, $request->validated()));
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->customers->delete($customer);

        return response()->json(['message' => 'تم حذف العميل بنجاح']);
    }

    public function approve(ApproveCustomerRequest $request, Customer $customer): CustomerResource
    {
        $customer = $this->customers->approve($customer, $request->validated(), $request->user()->id);

        return (new CustomerResource($customer))->additional(['message' => 'تم اعتماد الحساب بنجاح']);
    }

    public function reject(RejectCustomerRequest $request, Customer $customer): CustomerResource
    {
        $customer = $this->customers->reject($customer, $request->validated()['reason']);

        return (new CustomerResource($customer))->additional(['message' => 'تم رفض الحساب']);
    }

    public function suspend(SuspendCustomerRequest $request, Customer $customer): CustomerResource
    {
        $customer = $this->customers->suspend($customer, $request->validated()['reason']);

        return (new CustomerResource($customer))->additional(['message' => 'تم إيقاف الحساب وإنهاء جلساته']);
    }

    /** تنزيل مستند تحقق من التخزين الخاص (scoped: المستند تابع لهذا العميل حصرًا). */
    public function downloadDocument(Customer $customer, CustomerDocument $document): StreamedResponse
    {
        return Storage::disk('local')->download($document->file_path, $document->original_name);
    }
}
