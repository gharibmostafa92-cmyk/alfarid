<?php

declare(strict_types=1);

namespace App\Services\Ai\Shipping\Tools;

use App\Models\City;
use App\Models\ShippingCompany;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use Illuminate\Support\Facades\DB;

final class DiagnoseShippingCoverageTool implements AgentTool
{
    public function name(): string
    {
        return 'diagnose_shipping_coverage';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function risk(): string
    {
        return 'low';
    }

    public function permissions(): array
    {
        return ['shipping.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'تقرير فجوات إعداد الشحن: مدن بلا منطقة، مناطق بلا تعريفة، تداخل مدن، وأيام أو مبالغ غير صالحة. قراءة فقط.',
            'parameters' => ['type' => 'OBJECT', 'properties' => (object) []],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $issues = [];
        $push = function (string $code, string $message, array $extra = []) use (&$issues): void {
            if (count($issues) >= 80) {
                return;
            }
            $issues[] = ['code' => $code, 'message' => $message] + $extra;
        };

        $covered = DB::table('shipping_zone_cities')
            ->join('shipping_zones', 'shipping_zones.id', '=', 'shipping_zone_cities.shipping_zone_id')
            ->where('shipping_zones.is_active', true)
            ->pluck('shipping_zone_cities.city_id');
        $used = DB::table('customer_addresses')->distinct()->pluck('city_id');
        City::query()->where('is_active', true)
            ->where(function ($q) use ($used): void {
                $q->whereIn('id', $used)->orWhere('shipping_cost', '>', 0);
            })
            ->when($covered->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $covered))
            ->orderBy('id')->limit(30)->get(['id', 'name_ar'])
            ->each(fn (City $city) => $push('city_without_active_zone', 'المدينة مستخدمة أو لها تكلفة احتياط ومش مربوطة بمنطقة نشطة.', ['city_id' => $city->id, 'name_ar' => $city->name_ar]));

        ShippingZone::query()->where('is_active', true)->whereDoesntHave('rates')->orderBy('id')->limit(20)->get()
            ->each(fn (ShippingZone $zone) => $push('zone_without_rate', 'منطقة نشطة بلا تعريفة.', ['zone_id' => $zone->id, 'name' => $zone->name]));

        ShippingRate::query()->with('company:id,name,is_active')->whereHas('company', fn ($q) => $q->where('is_active', false))->orderBy('id')->limit(20)->get()
            ->each(fn (ShippingRate $rate) => $push('rate_on_inactive_company', 'التعريفة مربوطة بشركة موقوفة فمش هتدخل في العرض.', ['rate_id' => $rate->id, 'company_id' => $rate->shipping_company_id]));

        $overlaps = DB::table('shipping_zone_cities')
            ->join('shipping_zones', 'shipping_zones.id', '=', 'shipping_zone_cities.shipping_zone_id')
            ->where('shipping_zones.is_active', true)
            ->groupBy('shipping_zone_cities.city_id')
            ->havingRaw('count(distinct shipping_zone_cities.shipping_zone_id) > 1')
            ->limit(20)
            ->pluck('shipping_zone_cities.city_id');
        foreach ($overlaps as $cityId) {
            $push('city_in_multiple_zones', 'المدينة في أكتر من منطقة نشطة. العرض ياخد أول شركة حسب المعرّف مش الأرخص.', ['city_id' => (int) $cityId]);
        }

        ShippingRate::query()->orderBy('id')->limit(200)->get()->each(function (ShippingRate $rate) use ($push): void {
            if ((int) $rate->estimated_days_min > (int) $rate->estimated_days_max) {
                $push('days_inverted', 'الحد الأدنى لأيام التوصيل أكبر من الأقصى.', ['rate_id' => $rate->id]);
            }
            foreach (['base_cost' => 'تكلفة', 'cod_fee' => 'رسوم التحصيل', 'free_shipping_threshold' => 'عتبة الشحن المجاني'] as $field => $label) {
                if ($rate->{$field} !== null && (float) $rate->{$field} < 0) {
                    $push('negative_amount', $label.' سالبة.', ['rate_id' => $rate->id]);
                }
            }
        });

        ShippingCompany::query()->where('is_active', true)->whereDoesntHave('rates')->orderBy('id')->limit(20)->get()
            ->each(fn (ShippingCompany $company) => $push('active_company_without_rate', 'شركة نشطة بلا أي تعريفة.', ['company_id' => $company->id, 'name' => $company->name]));

        return ToolOutcome::data(['issues' => $issues, 'count' => count($issues)]);
    }
}
