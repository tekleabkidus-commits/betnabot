<?php

declare(strict_types=1);

use App\Models\MenuItem;
use App\Services\SettingsService;
use Database\Seeders\MenuSeeder;
use Database\Seeders\SettingsSeeder;

it('uses the Betna name and never seeds links to another operator', function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(MenuSeeder::class);

    $settings = app(SettingsService::class);
    $welcome = (array) $settings->get('welcome.message');

    expect($welcome['en'])->toContain('Betna')
        ->and($settings->get('webapp.url'))->toBeNull()
        ->and(MenuItem::query()->whereNotNull('url')->count())->toBe(0)
        ->and(MenuItem::query()->whereHas('translations', fn ($q) => $q->where('label', 'ℹ️ About Betna'))->exists())->toBeTrue();
});

it('ships the distinct violet Betna theme', function () {
    expect(file_exists(public_path('css/betna-theme.css')))->toBeTrue()
        ->and(file_get_contents(public_path('css/betna-theme.css')))->toContain('#6d28d9')
        ->and(file_exists(public_path('betna-mark.svg')))->toBeTrue();
});
