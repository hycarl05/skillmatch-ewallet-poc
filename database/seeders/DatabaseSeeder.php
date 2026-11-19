<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Training;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Demo data: one of every kind of SkillMatch user, plus a few trainings. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $make = fn (string $name, UserRole $role, array $extra = []) => User::factory()->create([
            'name' => $name,
            'email' => trim(strtolower(preg_replace('/[^a-z]+/i', '.', $name)), '.').'@demo.skillmatch.test',
            'role' => $role,
        ] + $extra);

        $make('SkillMatch Admin', UserRole::Platform, ['organisation' => 'SkillMatch']);

        $aisyah = $make('Aisyah (individual)', UserRole::Individual);

        $hr = $make('Nadia (HR)', UserRole::CompanyHr, ['organisation' => 'Maju Jaya Sdn Bhd']);
        $make('Farid (staff)', UserRole::Staff, ['company_id' => $hr->id, 'organisation' => 'Maju Jaya Sdn Bhd']);
        $make('Mei Ling (staff)', UserRole::Staff, ['company_id' => $hr->id, 'organisation' => 'Maju Jaya Sdn Bhd']);

        $provider = $make('Akademi Digital (provider)', UserRole::Provider, [
            'organisation' => 'Akademi Digital Sdn Bhd', 'bank_name' => 'Maybank', 'bank_account_last4' => '4417',
        ]);
        $hafiz = $make('Hafiz (freelance trainer)', UserRole::Freelancer, ['bank_name' => 'CIMB', 'bank_account_last4' => '2208']);
        $priya = $make('Priya (freelance trainer)', UserRole::Freelancer, ['bank_name' => 'RHB', 'bank_account_last4' => '7731']);

        Training::create([
            'seller_id' => $hafiz->id,
            'title' => 'Laravel for Beginners (2 days)',
            'description' => 'Sold directly by a freelance trainer: 2-way split (trainer + SkillMatch).',
            'price_sen' => 45000,
        ]);
        Training::create([
            'seller_id' => $provider->id,
            'title' => 'Leadership Essentials',
            'description' => 'Sold by a training provider and delivered by a hired freelance trainer: 3-way split (provider + freelancer + SkillMatch).',
            'price_sen' => 120000,
            'freelancer_id' => $priya->id,
            'freelancer_share_sen' => 40000,
        ]);
        Training::create([
            'seller_id' => $provider->id,
            'title' => 'Excel for HR Teams',
            'description' => 'Sold by a training provider: 2-way split.',
            'price_sen' => 35000,
        ]);
        Training::create([
            'seller_id' => $priya->id,
            'title' => 'Public Speaking Coaching',
            'description' => 'A training provider can also buy this to hire the trainer (provider -> freelancer payment).',
            'price_sen' => 60000,
        ]);

        // Starting SkillPoints so the discount can be tried straight away.
        $aisyah->skillpointTransactions()->create(['points' => 5000, 'reason' => 'Welcome bonus']);
        $hr->skillpointTransactions()->create(['points' => 10000, 'reason' => 'Welcome bonus']);
    }
}
