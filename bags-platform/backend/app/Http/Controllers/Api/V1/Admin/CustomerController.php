<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CustomerDocumentStatus;
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
use App\Models\CustomerAuditLog;
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
            new Middleware('can:customers.view', only: ['index', 'show']),
            new Middleware('can:customers.documents.view', only: ['downloadDocument', 'reviewDocument']),
            new Middleware('can:customers.create', only: ['store']),
            new Middleware('can:customers.update', only: ['update']),
            new Middleware('can:customers.delete', only: ['destroy', 'restore']),
            new Middleware('can:customers.approve', only: ['approve', 'reject', 'suspend', 'reviewDocument']),
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
            'trashed' => ['nullable', Rule::in(['only'])],
        ]);

        return CustomerResource::collection($this->customers->paginate($filters));
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = $this->customers->create($request->validated(), $request->user()->id);

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
        return new CustomerResource($this->customers->update($customer, $request->validated(), $request->user()->id));
    }

    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        $this->customers->delete($customer, $request->user()->id);

        return response()->json(['message' => 'تم حذف العميل بنجاح']);
    }

    public function approve(ApproveCustomerRequest $request, Customer $customer): CustomerResource
    {
        $customer = $this->customers->approve($customer, $request->validated(), $request->user()->id);

        return (new CustomerResource($customer))->additional(['message' => 'تم اعتماد الحساب بنجاح']);
    }

    public function reject(RejectCustomerRequest $request, Customer $customer): CustomerResource
    {
        $customer = $this->customers->reject($customer, $request->validated()['reason'], $request->user()->id);

        return (new CustomerResource($customer))->additional(['message' => 'تم رفض الحساب']);
    }

    public function suspend(SuspendCustomerRequest $request, Customer $customer): CustomerResource
    {
        $customer = $this->customers->suspend($customer, $request->validated()['reason'], $request->user()->id);

        return (new CustomerResource($customer))->additional(['message' => 'تم إيقاف الحساب وإنهاء جلساته']);
    }

    /** تنزيل مستند تحقق من التخزين الخاص (scoped: المستند تابع لهذا العميل حصرًا). */
    public function downloadDocument(Customer $customer, CustomerDocument $document): StreamedResponse
    {
        abort_unless(Storage::disk('r2')->exists($document->file_path), 404, 'المستند غير موجود.');
        CustomerAuditLog::record($customer, 'document_downloaded', request()->user()->id, ['document_id' => $document->id]);

        return Storage::disk('r2')->download($document->file_path, CustomerDocument::safeName($document->original_name));
    }

    public function restore(Request $request, Customer $customer): CustomerResource
    {
        return new CustomerResource($this->customers->restore($customer, $request->user()->id));
    }

    public function reviewDocument(Request $request, Customer $customer, CustomerDocument $document): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(CustomerDocumentStatus::class)],
        ]);
        $document = $this->customers->reviewDocument($customer, $document, $data['status'], $request->user()->id);

        return response()->json(['data' => ['id' => $document->id, 'status' => $document->status]]);
    }
}
