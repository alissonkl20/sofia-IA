<?php

namespace Database\Seeders;

use App\Models\BusinessHour;
use App\Models\Setting;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Setting::set('ai_enabled', true);
        Setting::set('human_online', false);

        foreach (range(0, 6) as $day) {
            BusinessHour::query()->updateOrCreate(
                ['day_of_week' => $day],
                [
                    'is_closed' => $day === 0,
                    'opens_at' => $day === 0 ? null : '08:00:00',
                    'closes_at' => $day === 0 ? null : '18:00:00',
                ],
            );
        }
    }
}
