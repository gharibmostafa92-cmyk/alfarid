<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\TransferStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Transfer\ApproveTransferRequest;
use App\Http\Requests\Admin\Transfer\ReceiveTransferRequest;
use App\Http\Requests\Admin\Transfer\ShipTransferRequest;
use App\Http\Requests\Admin\Transfer\StoreTransferRequest;
use App\Http\Requests\Admin\Transfer\UpdateTransferRequest;
use App\Http\Resources\Admin\StockTransferResource;
use App\Models\StockTransfer;
use App\Services\BranchAccess;
use App\Services\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class TransferController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly TransferService $transfers,
        private readonly BranchAccess $branchAccess,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:transfers.view', only: ['index', 'show']),
            new Middleware('can:transfers.create', only: ['store']),
            new Middleware('can:transfers.update', only: ['update']),
            new Middleware('can:transfers.delete', only: ['destroy']),
            new Middleware('can:transfers.approve', only: ['approve']),
            new Middleware('can:transfers.ship', only: ['ship']),
            new Middleware('can:transfers.receive', only: ['receive']),
            new Middleware('can:transfers.update', only: ['cancel']),
        ];
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'from_branch_id' => ['nullable', 'integer', 'min:1'],
            'to_branch_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(array_column(TransferStatus::cases(), 'value'))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $filters['staff_branch_id'] = $this->branchAccess->restrictedBranch($request->user());

        return StockTransferResource::collection($this->transfers->paginate($filters));
    }

    public function store(StoreTransferRequest $request): JsonResponse
    {
        $transfer = $this->transfers->create($request->validated(), $request->user()->id, $this->requiredIdempotencyKey($request));

        return StockTransferResource::make($transfer)
            ->additional(['message' => 'تم إنشاء التحويل بنجاح'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, StockTransfer $transfer): StockTransferResource
    {
        $this->branchAccess->assertBranch($request->user()->id, $transfer->from_branch_id, $transfer->to_branch_id);

        return StockTransferResource::make($this->transfers->find($transfer->id));
    }

    public function update(UpdateTransferRequest $request, StockTransfer $transfer): StockTransferResource
    {
        return StockTransferResource::make($this->transfers->update($transfer, $request->validated(), $request->user()->id));
    }

    public function destroy(Request $request, StockTransfer $transfer): JsonResponse
    {
        $this->transfers->delete($transfer, $request->user()->id);

        return response()->json(['message' => 'تم حذف التحويل بنجاح']);
    }

    public function approve(ApproveTransferRequest $request, StockTransfer $transfer): StockTransferResource
    {
        $transfer = $this->transfers->approve(
            $transfer,
            $request->quantitiesByItemId(),
            (int) $request->user()->id,
            $request->expectedUpdatedAt(),
            $request->expectedVersion(),
        );

        return StockTransferResource::make($transfer)->additional(['message' => 'تم اعتماد التحويل وحجز الكميات']);
    }

    public function ship(ShipTransferRequest $request, StockTransfer $transfer): StockTransferResource
    {
        $transfer = $this->transfers->ship(
            $transfer,
            $request->quantitiesByItemId(),
            (int) $request->user()->id,
            $request->expectedUpdatedAt(),
            $request->expectedVersion(),
        );

        return StockTransferResource::make($transfer)->additional(['message' => 'تم شحن التحويل بنجاح']);
    }

    public function receive(ReceiveTransferRequest $request, StockTransfer $transfer): StockTransferResource
    {
        $transfer = $this->transfers->receive(
            $transfer,
            $request->quantitiesByItemId(),
            (int) $request->user()->id,
            $request->expectedUpdatedAt(),
            $request->expectedVersion(),
        );

        return StockTransferResource::make($transfer)->additional(['message' => 'تم استلام التحويل بنجاح']);
    }

    public function cancel(Request $request, StockTransfer $transfer): StockTransferResource
    {
        $transfer = $this->transfers->cancel($transfer, $request->user()->id);

        return StockTransferResource::make($transfer)->additional(['message' => 'تم إلغاء التحويل']);
    }
}
