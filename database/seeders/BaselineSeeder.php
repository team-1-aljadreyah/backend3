<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BaselineSeeder extends Seeder
{
    public function run(): void
    {
        $now = now('UTC');

        DB::table('app_settings')->updateOrInsert(
            ['id' => 1],
            [
                'due_soon_days' => 1,
                'lock_after' => 5,
                'lock_minutes' => 15,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        foreach (['project', 'task', 'bug'] as $seq) {
            DB::table('code_sequences')->updateOrInsert(
                ['name' => $seq],
                ['last_value' => 0]
            );
        }

        DB::table('performance_criteria')->updateOrInsert(
            ['id' => 1],
            [
                'items' => json_encode([], JSON_UNESCAPED_UNICODE),
                'approved_by' => null,
                'approved_at' => null,
                'updated_at' => $now,
            ]
        );
    }
}
