<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiWhatsappIdentity;
use App\Models\AiWhatsappInbound;
use App\Models\User;
use App\Services\Ai\WhatsApp\WhatsAppGatewayClient;
use App\Services\Ai\WhatsApp\WhatsAppGatewayException;
use App\Services\Ai\WhatsApp\WhatsAppIdentityService;
use App\Services\BranchAccess;
use App\Services\SearchTerm;
use App\Services\StaffAdministration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WhatsAppAdminController extends Controller
{
    public function status(Request $request, BranchAccess $branches, WhatsAppGatewayClient $gateway): JsonResponse
    {
        $this->guard($request, $branches);

        return response()->json(['data' => $this->safeStatus($gateway)]);
    }

    public function qr(Request $request, BranchAccess $branches, WhatsAppGatewayClient $gateway): JsonResponse
    {
        $this->guard($request, $branches);
        try {
            return response()->json(['data' => $gateway->qr()]);
        } catch (WhatsAppGatewayException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function logout(Request $request, BranchAccess $branches, WhatsAppGatewayClient $gateway): JsonResponse
    {
        $this->guard($request, $branches);
        try {
            return response()->json(['data' => $gateway->logout()]);
        } catch (WhatsAppGatewayException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function identities(Request $request, BranchAccess $branches): JsonResponse
    {
        $this->guard($request, $branches);
        $rows = AiWhatsappIdentity::query()->with('user:id,name')->latest('id')->limit(200)->get()
            ->map(fn (AiWhatsappIdentity $identity): array => [
                'id' => $identity->id,
                'user_id' => $identity->user_id,
                'name' => $identity->user?->name,
                'phone_e164' => $identity->phone_e164,
                'status' => $identity->status,
                'verified_at' => $identity->verified_at?->toIso8601String(),
                'has_pin' => $identity->pin_hash !== null,
                'last_inbound_at' => $identity->last_inbound_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function storeIdentity(Request $request, BranchAccess $branches, WhatsAppIdentityService $identities): JsonResponse
    {
        $this->guard($request, $branches);
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'phone' => ['required', 'string', 'max:30'],
            'pin' => ['nullable', 'digits_between:4,8'],
        ]);
        $target = User::query()->findOrFail($data['user_id']);
        $identity = $identities->register($request->user('staff'), $target, $data['phone'], $data['pin'] ?? null);

        return response()->json(['data' => ['id' => $identity->id, 'status' => $identity->status, 'phone_e164' => $identity->phone_e164]], 201);
    }

    public function link(Request $request, AiWhatsappIdentity $identity, BranchAccess $branches, WhatsAppIdentityService $identities): JsonResponse
    {
        $this->guard($request, $branches);

        return response()->json(['data' => $identities->issueLink($request->user('staff'), $identity)]);
    }

    public function revoke(Request $request, AiWhatsappIdentity $identity, BranchAccess $branches): JsonResponse
    {
        $this->guard($request, $branches);
        DB::transaction(function () use ($request, $identity): void {
            StaffAdministration::lock();
            StaffAdministration::authorize($request->user('staff'), ['ai_agent.settings']);
            $identity->forceFill(['status' => 'revoked', 'unlocked_until' => null])->save();
            StaffAdministration::audit($request->user('staff'), 'ai_whatsapp.revoked', 'ai_whatsapp_identity', $identity->id, ['status'], [], ['status' => 'revoked']);
        });

        return response()->json(['data' => ['status' => 'revoked']]);
    }

    public function staff(Request $request, BranchAccess $branches): JsonResponse
    {
        $this->guard($request, $branches);
        $term = trim((string) $request->query('q', ''));
        $rows = User::query()
            ->where('is_system', false)
            ->where('is_active', true)
            ->when($term !== '', fn ($query) => $query->where('name', 'ilike', SearchTerm::pattern($term)))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name']);

        return response()->json(['data' => $rows]);
    }

    public function monitor(Request $request, BranchAccess $branches, WhatsAppGatewayClient $gateway): JsonResponse
    {
        $this->guard($request, $branches);
        $since = now()->subDay();
        $counts = AiWhatsappInbound::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('status, ignore_reason, COUNT(*) as aggregate')
            ->groupBy('status', 'ignore_reason')
            ->get();
        $recent = AiWhatsappInbound::query()->latest('id')->limit(30)->get(['id', 'status', 'ignore_reason', 'created_at', 'updated_at']);

        return response()->json([
            'data' => [
                'gateway' => $this->safeStatus($gateway),
                'counts' => $counts,
                'recent' => $recent,
            ],
        ]);
    }

    private function guard(Request $request, BranchAccess $branches): void
    {
        $branches->assertCentralAccess($request->user('staff'));
        StaffAdministration::authorize($request->user('staff'), ['ai_agent.settings']);
    }

    /** @return array<string, mixed> */
    private function safeStatus(WhatsAppGatewayClient $gateway): array
    {
        try {
            return $gateway->status();
        } catch (WhatsAppGatewayException) {
            return ['state' => 'unreachable'];
        }
    }
}
