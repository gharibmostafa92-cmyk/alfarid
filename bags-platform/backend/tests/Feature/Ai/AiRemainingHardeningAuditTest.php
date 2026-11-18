<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Exceptions\BusinessException;
use App\Models\AiAttachment;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\City;
use App\Models\Setting;
use App\Models\ShippingCompany;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\Shipping\ShippingArgs;
use App\Services\Ai\Shipping\Tools\DeleteShippingRateTool;
use App\Services\Ai\Shipping\Tools\DiagnoseShippingCoverageTool;
use App\Services\Ai\Shipping\Tools\SyncZoneCitiesTool;
use App\Services\Ai\Shipping\Tools\UpsertShippingRateTool;
use App\Services\Ai\ToolRegistry;
use App\Services\Shared\SettingService;
use App\Services\Shared\SpokenQuantity;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AiRemainingHardeningAuditTest extends TestCase
{
    use DatabaseTransactions;

    private function makeStaff(array $perms = []): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (array_merge($perms, ['inventory.all_branches']) as $p) {
            Permission::findOrCreate($p, 'staff');
            $user->givePermissionTo($p);
        }

        return $user;
    }

    public function test_prepare_bulk_failure_does_not_leave_cancelled_child_rows(): void
    {
        $this->seed(\Database\Seeders\SettingSeeder::class);
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
        Setting::updateOrCreate(['key' => 'ai_enabled'], ['value' => '1', 'group' => 'ai', 'type' => 'boolean']);
        Setting::updateOrCreate(['key' => 'ai_shipping_tools_enabled'], ['value' => '1', 'group' => 'ai', 'type' => 'boolean']);
        app(SettingService::class)->clearCache();

        $staff = $this->makeStaff(['ai_agent.use', 'shipping.update', 'shipping.create']);
        $conv = AiConversation::create(['user_id' => $staff->id, 'title' => 'Bulk', 'model' => 'gemini-2.5-flash']);
        $company = ShippingCompany::factory()->create(['is_active' => true]);

        $beforeCount = AiPendingAction::where('conversation_id', $conv->id)->count();
        $outcome = app(ToolRegistry::class)->dispatch($staff, $conv, [
            'name' => 'bulk_actions',
            'args' => [
                'items' => [
                    ['tool' => 'upsert_shipping_zone', 'args' => ['name' => 'Zone A', 'is_active' => true]],
                    // High-risk tool in bulk triggers failure after first item prepared
                    ['tool' => 'set_shipping_company_status', 'args' => ['company_id' => $company->id, 'is_active' => false]],
                ],
            ],
        ]);
        $this->assertSame('denied', $outcome->type);
        $this->assertSame($beforeCount, AiPendingAction::where('conversation_id', $conv->id)->where('status', 'cancelled')->count());
    }

    public function test_shipping_rate_duplicate_throws_409_and_undo_audits_and_touches_zone(): void
    {
        $staff = $this->makeStaff(['shipping.create', 'shipping.update', 'shipping.delete']);
        $conv = AiConversation::create(['user_id' => $staff->id, 'title' => 'Ship', 'model' => 'gemini-2.5-flash']);
        app(ProposalBuilder::class)->setConversation($conv);

        $company = ShippingCompany::factory()->create(['is_active' => true]);
        $zone = ShippingZone::factory()->create(['is_active' => true]);
        $city1 = City::factory()->create(['is_active' => true]);
        $city2 = City::factory()->create(['is_active' => true]);
        $zone->cities()->sync([$city1->id]);

        // UpsertShippingRateTool duplicate create must throw 409 BusinessException
        $rateTool = app(UpsertShippingRateTool::class);
        $prep = $rateTool->prepare($staff, [
            'shipping_company_id' => $company->id,
            'shipping_zone_id' => $zone->id,
            'base_cost' => 50,
            'estimated_days_min' => 1,
            'estimated_days_max' => 3,
        ]);
        $action = AiPendingAction::where('conversation_id', $conv->id)->latest('id')->firstOrFail();

        ShippingRate::factory()->create([
            'shipping_company_id' => $company->id,
            'shipping_zone_id' => $zone->id,
            'base_cost' => 45,
        ]);

        $caught = false;
        try {
            $rateTool->execute($staff, $action);
        } catch (BusinessException $e) {
            $caught = $e->status === 409;
        }
        $this->assertTrue($caught, 'Expected 409 BusinessException on duplicate shipping rate creation');

        // SyncZoneCitiesTool::undo() must touch updated_at and record audit log
        $syncTool = app(SyncZoneCitiesTool::class);
        $syncPrep = $syncTool->prepare($staff, ['zone_id' => $zone->id, 'city_ids' => [$city2->id]]);
        $syncAction = AiPendingAction::where('conversation_id', $conv->id)->latest('id')->firstOrFail();
        $syncTool->execute($staff, $syncAction);
        $beforeTouch = $zone->fresh()->updated_at?->format('Y-m-d H:i:s.u');
        $this->travel(2)->seconds();
        $syncTool->undo($staff, $syncAction->fresh());
        $afterTouch = $zone->fresh()->updated_at?->format('Y-m-d H:i:s.u');
        $this->assertNotSame($beforeTouch, $afterTouch);
        $this->assertTrue(DB::table('staff_audit_logs')->where('actor_id', $staff->id)->where('action', 'shipping.zone_cities_undone')->exists());

        // DeleteShippingRateTool::undo() must throw 409 if zone was deleted
        $rate = ShippingRate::where('shipping_company_id', $company->id)->where('shipping_zone_id', $zone->id)->firstOrFail();
        $delTool = app(DeleteShippingRateTool::class);
        $delPrep = $delTool->prepare($staff, ['rate_id' => $rate->id]);
        $delAction = AiPendingAction::where('conversation_id', $conv->id)->latest('id')->firstOrFail();
        $delTool->execute($staff, $delAction);
        $zone->delete();

        $delCaught = false;
        try {
            $delTool->undo($staff, $delAction->fresh());
        } catch (BusinessException $e) {
            $delCaught = $e->status === 409;
        }
        $this->assertTrue($delCaught, 'Expected 409 BusinessException when undoing deleted rate for deleted zone');
    }

    public function test_controllers_secrets_attachments_and_spoken_quantity_hardening(): void
    {
        // F-15: secretKey
        $this->assertTrue(ShippingArgs::secretKey('api_token'));
        $this->assertTrue(ShippingArgs::secretKey('auth_password'));
        $this->assertTrue(ShippingArgs::secretKey('private_key'));

        // F-17: SpokenQuantity
        $this->assertSame(3000.0, SpokenQuantity::parse('تلات تلاف'));
        $this->assertNull(SpokenQuantity::parse('ميه ميه'));

        // F-13: AiMessageController::store() rejects empty payload with 422
        $staff = $this->makeStaff(['ai_agent.use']);
        $conv = AiConversation::create(['user_id' => $staff->id, 'title' => 'Msg', 'model' => 'gemini-2.5-flash']);
        $otherConv = AiConversation::create(['user_id' => $this->makeStaff(['ai_agent.use'])->id, 'title' => 'Other', 'model' => 'gemini-2.5-flash']);

        $this->seed(\Database\Seeders\SettingSeeder::class);
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
        Setting::updateOrCreate(['key' => 'ai_enabled'], ['value' => '1', 'group' => 'ai', 'type' => 'boolean']);
        app(SettingService::class)->clearCache();

        \Illuminate\Support\Facades\Auth::forgetGuards();
        $staffToken = $staff->createToken('test')->plainTextToken;
        $this->withToken($staffToken)
            ->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", [])
            ->assertStatus(422);

        // F-17: AiConversationController::index() ?all=false only returns own conversations
        $staffWithLogs = $this->makeStaff(['ai_agent.use', 'ai_agent.logs.view', 'customers.view']);
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $logsToken = $staffWithLogs->createToken('test')->plainTextToken;
        $res = $this->withToken($logsToken)
            ->getJson('/api/v1/admin/ai/conversations?all=0')
            ->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertNotContains($otherConv->id, $ids);

        // F-16: AiAdministrationController::settings() requires ai_agent.settings or settings.view
        $this->withToken($staffToken)
            ->getJson('/api/v1/admin/ai/settings')
            ->assertStatus(403);

        // F-16: DiagnoseShippingCoverageTool executes cleanly
        $diag = app(DiagnoseShippingCoverageTool::class)->handle($staff, []);
        $this->assertSame('data', $diag->type);
    }
}
