<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Customer;

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
use App\Services\Customer\CustomerManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Customer administration, status transitions, and wholesale document verification.
 */
class CustomerController extends Controller implements HasMiddleware
{
    public function __construct(private readonly CustomerManagementService $customers) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:customers.view', only: ['index', 'show', 'history']),
            new Middleware('can:customers.documents.view', only: ['downloadDocument', 'reviewDocument', 'storeDocument']),
            new Middleware('can:customers.update', only: ['storeDocument']),
            new Middleware('can:customers.create', only: ['store']),
            new Middleware('can:customers.update', only: ['update']),
            new Middleware('can:customers.delete', only: ['destroy', 'restore']),
            new Middleware('can:customers.approve', only: ['approve', 'reject', 'suspend', 'reactivate', 'reviewDocument']),
        ];
    }

    /**
     * List customers with optional status, group, type, and trigram search filtering.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::enum(CustomerType::class)],
            'business_only' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::enum(CustomerStatus::class)],
            'customer_group_id' => ['nullable', 'integer', 'min:1'],
            // Trigram index requires at least 3 characters to prevent full table scans and slow COUNT queries.
            'search' => ['nullable', 'string', 'min:3', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'trashed' => ['nullable', Rule::in(['only'])],
        ]);

        return CustomerResource::collection($this->customers->paginate($filters));
    }

    /**
     * Create a new customer profile directly from admin panel.
     */
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = $this->customers->create($request->validated(), $request->user()->id);

        return CustomerResource::make($customer)
            ->additional(['message' => 'تم إنشاء العميل بنجاح'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Retrieve full customer profile details.
     */
    public function show(Customer $customer): CustomerResource
    {
        return CustomerResource::make($this->customers->find($customer->id));
    }

    /**
     * Update customer profile attributes under advisory lock.
     */
    public function update(UpdateCustomerRequest $request, Customer $customer): CustomerResource
    {
        return CustomerResource::make($this->customers->update($customer, $request->validated(), $request->user()->id));
    }

    /**
     * Soft delete a customer profile.
     */
    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        $this->customers->delete($customer, $request->user()->id);

        return response()->json(['message' => 'تم حذف العميل بنجاح']);
    }

    /**
     * Approve a pending wholesale customer registration and assign credit limits.
     */
    public function approve(ApproveCustomerRequest $request, Customer $customer): CustomerResource
    {
        $customer = $this->customers->approve($customer, $request->validated(), $request->user()->id);

        return CustomerResource::make($customer)->additional(['message' => 'تم اعتماد الحساب بنجاح']);
    }

    /**
     * Reject a pending wholesale registration with rejection reason.
     */
    public function reject(RejectCustomerRequest $request, Customer $customer): CustomerResource
    {
        $customer = $this->customers->reject($customer, $request->validated()['reason'], $request->user()->id);

        return CustomerResource::make($customer)->additional(['message' => 'تم رفض الحساب']);
    }

    /**
     * Suspend an active customer and revoke active login tokens.
     */
    public function suspend(SuspendCustomerRequest $request, Customer $customer): CustomerResource
    {
        $customer = $this->customers->suspend($customer, $request->validated()['reason'], $request->user()->id);

        return CustomerResource::make($customer)->additional(['message' => 'تم إيقاف الحساب وإنهاء جلساته']);
    }

    /**
     * Reactivate a suspended customer back to their pre-suspension status.
     */
    public function reactivate(Request $request, Customer $customer): CustomerResource
    {
        $customer = $this->customers->reactivate($customer, $request->user()->id);

        return CustomerResource::make($customer)->additional(['message' => 'تم إعادة تفعيل الحساب']);
    }

    /**
     * Paginated audit log entries for a customer in reverse chronological order.
     */
    public function history(Customer $customer): JsonResponse
    {
        $logs = CustomerAuditLog::query()->where('customer_id', $customer->id)->with('actor:id,name')
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(25);
        $logs->through(fn (CustomerAuditLog $log) => [
            'action' => $log->action,
            'actor' => $log->actor ? ['id' => $log->actor->id, 'name' => $log->actor->name] : null,
            'metadata' => $log->metadata,
            'created_at' => $log->created_at,
        ]);

        return response()->json($logs);
    }

    /**
     * Download customer verification document from private storage.
     */
    public function downloadDocument(Customer $customer, CustomerDocument $document): StreamedResponse
    {
        abort_unless(Storage::disk('r2')->exists($document->file_path), 404, 'المستند غير موجود.');
        CustomerAuditLog::record($customer, 'document_downloaded', request()->user()->id, ['document_id' => $document->id]);

        return Storage::disk('r2')->download($document->file_path, CustomerDocument::safeName($document->original_name));
    }

    /**
     * Restore a soft-deleted customer record.
     */
    public function restore(Request $request, Customer $customer): CustomerResource
    {
        return CustomerResource::make($this->customers->restore($customer, $request->user()->id));
    }

    /**
     * Review and update verification status of a customer document.
     */
    public function reviewDocument(Request $request, Customer $customer, CustomerDocument $document): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(CustomerDocumentStatus::class)],
            'note' => ['nullable', 'string', 'max:500', 'required_if:status,rejected'],
        ]);
        $document = $this->customers->reviewDocument($customer, $document, $data['status'], $request->user()->id, $data['note'] ?? null);

        return response()->json(['data' => ['id' => $document->id, 'status' => $document->status, 'review_note' => $document->review_note]]);
    }

    /**
     * Upload an additional verification document for a customer.
     */
    public function storeDocument(Request $request, Customer $customer): JsonResponse
    {
        $request->validate(['document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:5120']]);
        $document = $this->customers->uploadDocument($customer, $request->file('document'), (int) $request->user()->id);

        return response()->json(['data' => ['id' => $document->id, 'status' => $document->status]], 201);
    }
}
