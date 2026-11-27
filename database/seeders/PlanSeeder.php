<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Subscription plans from the client's Tier Plan sheet (Training Revenue
 * Stream v3.1, 18 Nov 2026), monthly prices. Not final: the Revenue Breakdown
 * sheet shows different prices (provider RM200, freelancer RM100, company per
 * head), so these are data the client can change, not code.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            // Training providers: Demand Marketplace in their state (T1) or every state (T2).
            ['provider-t1', 'Training Provider Tier 1', 'provider', 1, 50000, null, null, ['demand_marketplace' => 'state', 'can_list_hrdc' => true]],
            ['provider-t2', 'Training Provider Tier 2', 'provider', 2, 100000, null, null, ['demand_marketplace' => 'all', 'can_list_hrdc' => true]],
            // Freelance trainers: T1 also lets them list HRDC programmes.
            ['freelancer-t1', 'Freelance Trainer Tier 1', 'freelancer', 1, 20000, null, null, ['demand_marketplace' => 'state', 'can_list_hrdc' => true]],
            ['freelancer-t2', 'Freelance Trainer Tier 2', 'freelancer', 2, 50000, null, null, ['demand_marketplace' => 'all', 'can_list_hrdc' => true]],
            // Companies ("Future" in the workbook): career path tools; a subscribed company also pays lower commission rates.
            ['company-t1', 'Company Tier 1 (1-20 staff)', 'company_hr', 1, 20000, 1, 20, ['career_path' => true, 'staff_competency' => true, 'online_support' => true]],
            ['company-t2', 'Company Tier 2 (21-50 staff)', 'company_hr', 2, 40000, 21, 50, ['career_path' => true, 'staff_competency' => true, 'online_support' => true]],
            ['company-t3', 'Company Tier 3 (51-100 staff)', 'company_hr', 3, 100000, 51, 100, ['career_path' => true, 'staff_competency' => true, 'online_support' => true]],
            ['company-premium', 'Company Premium (101+ staff)', 'company_hr', 4, 500000, 101, null, ['career_path' => true, 'staff_competency' => true, 'online_support' => true]],
        ];

        foreach ($plans as [$code, $name, $audience, $tier, $price, $min, $max, $features]) {
            Plan::updateOrCreate(['code' => $code], [
                'name' => $name,
                'audience' => $audience,
                'tier' => $tier,
                'price_sen' => $price,
                'interval' => 'month',
                'min_staff' => $min,
                'max_staff' => $max,
                'features' => $features,
                'active' => true,
            ]);
        }
    }
}
