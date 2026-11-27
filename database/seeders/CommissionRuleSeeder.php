<?php

namespace Database\Seeders;

use App\Models\CommissionRule;
use Illuminate\Database\Seeder;

/**
 * The client's commission table (Revenue Breakdown sheet, Training Revenue
 * Stream v3.1). Rates are basis points of the training price.
 *
 *   Not HRDC, no subscription      20% SkillMatch / 80% trainer
 *   HRDC                           15% / 85%
 *   Subscribed company, not HRDC   18% / 77% / 5% discount to the company
 *   ... from RM100,000             15% / 75% / 10% discount
 */
class CommissionRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            ['Standard (not HRDC)', null, false, false, 0, 2000, 0, 0],
            ['HRDC training', null, true, false, 0, 1500, 0, 10],
            ['Subscribed company (not HRDC)', 'company_hr', false, true, 0, 1800, 500, 20],
            ['Subscribed company, from RM100,000 (not HRDC)', 'company_hr', false, true, 10000000, 1500, 1000, 30],
        ];

        foreach ($rules as [$name, $role, $hrdc, $needsSub, $min, $platform, $discount, $priority]) {
            CommissionRule::updateOrCreate(['name' => $name], [
                'buyer_role' => $role,
                'hrdc' => $hrdc,
                'requires_subscription' => $needsSub,
                'min_amount_sen' => $min,
                'platform_bps' => $platform,
                'buyer_discount_bps' => $discount,
                'priority' => $priority,
                'active' => true,
            ]);
        }
    }
}
