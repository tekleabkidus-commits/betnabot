<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\MenuItem;
use Illuminate\Database\Seeder;

/**
 * Safe Betna starter menu. Never seeds another company's external URLs.
 * Staff can add verified Betna website buttons in the admin panel.
 */
class MenuSeeder extends Seeder
{
    public function run(): void
    {
        if (MenuItem::query()->exists()) {
            return;
        }

        $about = MenuItem::query()->create(['action_type' => 'reply', 'position' => 0]);
        $about->translations()->create([
            'lang' => 'en',
            'label' => 'ℹ️ About Betna',
            'reply_text' => "Welcome to Betna! Use this bot for information, updates and support.",
        ]);

        $invite = MenuItem::query()->create(['action_type' => 'invite', 'position' => 1]);
        $invite->translations()->create(['lang' => 'en', 'label' => '🎉 Invite friends']);
    }
}
