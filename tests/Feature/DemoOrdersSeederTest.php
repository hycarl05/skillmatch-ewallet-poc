<?php

namespace Tests\Feature;

use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoOrdersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DemoOrdersSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_spreads_orders_over_60_days_through_the_real_flows_and_the_books_balance(): void
    {
        config(['skillmatch.gateways.fake.fee_percent' => 3, 'skillmatch.gateways.fake.fee_fixed_sen' => 100]);
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoOrdersSeeder::class);

        $this->assertGreaterThan(80, Order::count());
        $this->assertSame((int) LedgerEntry::sum('debit_sen'), (int) LedgerEntry::sum('credit_sen'), 'books balance');
        $this->assertFalse(Carbon::hasTestNow(), 'clock is restored');
        $this->assertFalse(Order::where('created_at', '>', now())->exists(), 'nothing in the future');

        foreach (['pending_payment', 'paid', 'completed', 'confirmed', 'failed', 'refunded'] as $status) {
            $this->assertTrue(Order::where('status', $status)->exists(), "has a {$status} order");
        }
        $this->assertTrue(Refund::where('status', Refund::REQUESTED)->exists(), 'leaves refund requests for the admin');
        $this->assertTrue(Refund::where('status', Refund::SUCCEEDED)->exists());

        $admin = User::where('role', 'platform')->firstOrFail();
        $days = collect($this->withHeader('X-Demo-User', (string) $admin->id)->getJson('/api/platform/dashboard?days=90')->json('orders'))
            ->pluck('day')->unique();
        $this->assertGreaterThan(45, $days->count(), 'orders on most days');

        $before = Order::count();
        $this->seed(DemoOrdersSeeder::class);
        $this->assertSame($before, Order::count(), 'refuses to seed twice');
    }
}
