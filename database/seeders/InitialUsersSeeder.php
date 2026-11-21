<?php

namespace Database\Seeders;

use App\Models\LoginSecurity;
use App\Models\PerformanceCriteria;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InitialUsersSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(BaselineSeeder::class);

        $domain = ltrim(trim((string) env('SEED_EMAIL_DOMAIN', 'follow.ama8.uk')), '@');
        $overridePassword = env('SEED_USER_PASSWORD');

        $team = Team::query()->firstOrCreate(
            ['name' => 'فريق التطوير'],
            ['name_en' => 'Development team']
        );

        $definitions = [
            [
                'role' => 'ceo',
                'email' => "ceo@{$domain}",
                'name' => 'مريم العبيدي',
                'name_en' => 'Maryam Al-Obaidi',
                'title' => 'المديرة التنفيذية',
                'team_id' => null,
            ],
            [
                'role' => 'pm',
                'email' => "pm@{$domain}",
                'name' => 'أحمد كريم',
                'name_en' => 'Ahmed Kareem',
                'title' => 'مدير المشاريع',
                'team_id' => null,
            ],
            [
                'role' => 'monitor',
                'email' => "monitor@{$domain}",
                'name' => 'هدى ناصر',
                'name_en' => 'Huda Nasser',
                'title' => 'مسؤولة المتابعة والتقييم',
                'team_id' => null,
            ],
            [
                'role' => 'lead',
                'email' => "lead@{$domain}",
                'name' => 'مصطفى جواد',
                'name_en' => 'Mustafa Jawad',
                'title' => 'قائد فريق التطوير',
                'team_id' => $team->id,
            ],
            [
                'role' => 'employee',
                'email' => "employee@{$domain}",
                'name' => 'سارة علي',
                'name_en' => 'Sara Ali',
                'title' => 'مطورة برمجيات',
                'team_id' => $team->id,
            ],
            [
                'role' => 'admin',
                'email' => "admin@{$domain}",
                'name' => 'حسن رعد',
                'name_en' => 'Hassan Raad',
                'title' => 'مسؤول النظام',
                'team_id' => null,
            ],
        ];

        $rows = [];
        $ceoUser = null;
        $leadUser = null;

        DB::transaction(function () use ($definitions, $overridePassword, $team, &$rows, &$ceoUser, &$leadUser) {
            foreach ($definitions as $def) {
                $existing = User::query()->where('email', $def['email'])->first();
                if ($existing) {
                    $rows[] = [
                        'id' => $existing->id,
                        'role' => $existing->role,
                        'email' => $existing->email,
                        'password' => '(unchanged - existing user)',
                    ];
                    if ($existing->role === 'ceo') {
                        $ceoUser = $existing;
                    }
                    if ($existing->role === 'lead') {
                        $leadUser = $existing;
                    }

                    continue;
                }

                $plainPassword = is_string($overridePassword) && $overridePassword !== ''
                    ? $overridePassword
                    : Str::password(16, symbols: false);

                $user = User::create([
                    'name' => $def['name'],
                    'name_en' => $def['name_en'],
                    'email' => $def['email'],
                    'role' => $def['role'],
                    'title' => $def['title'],
                    'team_id' => $def['team_id'],
                    'active' => true,
                    'password' => $plainPassword,
                ]);

                LoginSecurity::create([
                    'user_id' => $user->id,
                    'failed_logins' => 0,
                ]);

                if ($user->role === 'ceo') {
                    $ceoUser = $user;
                }
                if ($user->role === 'lead') {
                    $leadUser = $user;
                }

                $rows[] = [
                    'id' => $user->id,
                    'role' => $user->role,
                    'email' => $user->email,
                    'password' => $plainPassword,
                ];
            }

            if ($leadUser && $team->lead_id === null) {
                $team->lead_id = $leadUser->id;
                $team->save();
            }

            $criteria = PerformanceCriteria::current();
            if (($criteria->items ?? []) === []) {
                $now = now('UTC');
                $criteria->items = [
                    ['id' => 'completion', 'name' => 'إنجاز المهام', 'name_en' => 'Tasks done', 'kind' => 'auto', 'metric' => 'completion', 'weight' => 25],
                    ['id' => 'on_time', 'name' => 'الالتزام بالمواعيد', 'name_en' => 'On time', 'kind' => 'auto', 'metric' => 'on_time', 'weight' => 25],
                    ['id' => 'quality', 'name' => 'جودة العمل', 'name_en' => 'Quality of work', 'kind' => 'manual', 'weight' => 30],
                    ['id' => 'teamwork', 'name' => 'التعاون والتواصل', 'name_en' => 'Teamwork', 'kind' => 'manual', 'weight' => 20],
                ];
                $criteria->approved_by = $ceoUser?->id;
                $criteria->approved_at = $ceoUser ? $now : null;
                $criteria->updated_at = $now;
                $criteria->save();
            }
        });

        if ($this->command) {
            $this->command->info('Seeded initial company users (one per role):');
            $this->command->table(['ID', 'Role', 'Email', 'Password'], $rows);
        }
    }
}
