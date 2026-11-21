<?php

namespace App\Console\Commands;

use App\Services\AttachmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Throwable;

class DeploymentCheckCommand extends Command
{
    protected $signature = 'app:deployment-check';

    protected $description = 'Run safe, read-only deployment health checks for cPanel without exposing secrets or mutating records.';

    /**
     * Expected application tables (excluding dynamic checks for extra tables).
     *
     * @var array<int, string>
     */
    private const REQUIRED_TABLES = [
        'advanced_settings',
        'advanced_settings_logs',
        'announcement_reads',
        'announcements',
        'app_settings',
        'attachments',
        'audit_logs',
        'avatars',
        'bug_events',
        'bugs',
        'cache',
        'cache_locks',
        'chat_group_members',
        'chat_groups',
        'chat_reads',
        'code_sequences',
        'direct_conversations',
        'failed_jobs',
        'github_items',
        'github_webhook_deliveries',
        'job_batches',
        'jobs',
        'login_security',
        'messages',
        'migrations',
        'monitor_notes',
        'notification_preferences',
        'notifications',
        'password_reset_tokens',
        'performance_criteria',
        'performance_review_scores',
        'performance_reviews',
        'personal_access_tokens',
        'project_events',
        'project_github_settings',
        'project_members',
        'project_phases',
        'project_statuses',
        'project_templates',
        'projects',
        'sessions',
        'sprints',
        'task_approvals',
        'task_comments',
        'task_dependencies',
        'task_events',
        'tasks',
        'teams',
        'users',
    ];

    /**
     * PHP extensions required for Laravel 12 and Project-3 API features.
     *
     * @var array<int, string>
     */
    private const REQUIRED_EXTENSIONS = [
        'pdo',
        'pdo_mysql',
        'mbstring',
        'openssl',
        'fileinfo',
        'tokenizer',
        'xml',
        'ctype',
        'json',
        'curl',
        'bcmath',
        'gd',
    ];

    public function handle(): int
    {
        $failures = 0;

        $this->line('Project-3 Deployment Check (read-only)');
        $this->line(str_repeat('-', 56));

        // 1. Laravel Boot & Application Key
        $appKeySet = filled(config('app.key'));
        $envName = (string) config('app.env');
        $debugMode = (bool) config('app.debug');
        if ($appKeySet) {
            $this->reportPass('Application key', "Configured (env={$envName}, debug=".($debugMode ? 'true' : 'false').')');
        } else {
            $this->reportFail('Application key', 'APP_KEY is empty or missing');
            $failures++;
        }

        // 2. PHP Version & Extensions
        if (PHP_VERSION_ID >= 80200) {
            $this->reportPass('PHP version', PHP_VERSION.' (>= 8.2 required)');
        } else {
            $this->reportFail('PHP version', PHP_VERSION.' (< 8.2 unsupported)');
            $failures++;
        }

        $missingExts = [];
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            if (! extension_loaded($ext)) {
                $missingExts[] = $ext;
            }
        }
        if ($missingExts === []) {
            $this->reportPass('PHP extensions', count(self::REQUIRED_EXTENSIONS).' required extensions loaded');
        } else {
            $this->reportFail('PHP extensions', 'Missing: '.implode(', ', $missingExts));
            $failures++;
        }

        // 3. Database Connection (read-only, no credential output)
        $dbConnected = false;
        try {
            $row = DB::selectOne('SELECT VERSION() AS engine_version, DATABASE() AS active_db');
            $engine = (string) ($row->engine_version ?? 'unknown');
            $activeDb = (string) ($row->active_db ?? 'unknown');
            $this->reportPass('Database connection', "Connected (engine={$engine}, schema={$activeDb})");
            $dbConnected = true;
        } catch (Throwable $e) {
            $this->reportFail('Database connection', 'Failed to connect to configured database ('.$e->getCode().')');
            $failures++;
        }

        // 4. Required Tables & Migrations
        if ($dbConnected) {
            try {
                $rawTables = DB::select('SHOW TABLES');
                $presentTables = [];
                foreach ($rawTables as $item) {
                    $values = array_values((array) $item);
                    if (isset($values[0])) {
                        $presentTables[] = (string) $values[0];
                    }
                }

                $missingTables = array_values(array_diff(self::REQUIRED_TABLES, $presentTables));
                if ($missingTables === []) {
                    $this->reportPass('Database tables', count($presentTables).' tables present; all '.count(self::REQUIRED_TABLES).' required tables found');
                } else {
                    $this->reportFail('Database tables', 'Missing tables: '.implode(', ', $missingTables));
                    $failures++;
                }

                $migrationCount = Schema::hasTable('migrations') ? DB::table('migrations')->count() : 0;
                if ($migrationCount >= 12) {
                    $this->reportPass('Migrations table', "{$migrationCount} completed migration records present");
                } else {
                    $this->reportFail('Migrations table', "Expected >= 12 migration records, found {$migrationCount}");
                    $failures++;
                }

                $userCount = DB::table('users')->count();
                $teamCount = DB::table('teams')->count();
                $criteriaCount = DB::table('performance_criteria')->count();
                $this->reportPass('Baseline records', "users={$userCount}, teams={$teamCount}, performance_criteria={$criteriaCount}");
            } catch (Throwable) {
                $this->reportFail('Schema inspection', 'Unable to inspect tables or migration records');
                $failures++;
            }
        }

        // 5. Writable Directories
        $writablePaths = [
            'storage' => storage_path(),
            'storage/logs' => storage_path('logs'),
            'storage/framework/cache' => storage_path('framework/cache'),
            'storage/framework/sessions' => storage_path('framework/sessions'),
            'storage/framework/views' => storage_path('framework/views'),
            'storage/app/private' => storage_path('app/private'),
            'storage/app/private/attachments' => storage_path('app/private/attachments'),
            'storage/app/private/avatars' => storage_path('app/private/avatars'),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ];
        $unwritable = [];
        foreach ($writablePaths as $label => $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                $unwritable[] = $label;
            }
        }
        if ($unwritable === []) {
            $this->reportPass('Directory permissions', 'All '.count($writablePaths).' runtime directories exist and are writable');
        } else {
            $this->reportFail('Directory permissions', 'Missing or not writable: '.implode(', ', $unwritable));
            $failures++;
        }

        // 6. Private Filesystem Disk
        $defaultDisk = (string) config('filesystems.default', 'local');
        $attachmentDisk = AttachmentService::diskName();
        $diskConfig = config("filesystems.disks.{$attachmentDisk}");
        if (is_array($diskConfig) && ($diskConfig['driver'] ?? null) === 'local') {
            $this->reportPass('Filesystem disk', "default={$defaultDisk}, attachments={$attachmentDisk}");
        } else {
            $this->reportFail('Filesystem disk', "Attachment disk '{$attachmentDisk}' is not properly configured");
            $failures++;
        }

        // 7. Sanctum & API v1 Routes
        $sanctumAvailable = class_exists(Sanctum::class);
        $apiRoutesCount = 0;
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1')) {
                $apiRoutesCount++;
            }
        }
        if ($sanctumAvailable && $apiRoutesCount >= 80) {
            $this->reportPass('Sanctum & API v1', "Sanctum loaded; {$apiRoutesCount} /api/v1 routes registered");
        } else {
            $this->reportFail('Sanctum & API v1', "Sanctum=".($sanctumAvailable ? 'yes' : 'no').", routes={$apiRoutesCount}");
            $failures++;
        }

        // 8. CORS & Queue Configuration
        $allowedOrigins = (array) config('cors.allowed_origins', []);
        $retryAfter = (int) config('queue.connections.database.retry_after', 0);
        $hasWildcard = in_array('*', $allowedOrigins, true);
        if ($allowedOrigins !== [] && ! $hasWildcard && $retryAfter > 40) {
            $this->reportPass('CORS & Queue', 'Allowed origins: '.implode(', ', $allowedOrigins)."; queue retry_after={$retryAfter}s");
        } else {
            $this->reportFail('CORS & Queue', 'Invalid CORS origins or queue retry_after <= 40');
            $failures++;
        }

        $this->line(str_repeat('-', 56));
        if ($failures === 0) {
            $this->info('OVERALL STATUS: PASS (0 failures)');

            return self::SUCCESS;
        }

        $this->error("OVERALL STATUS: FAIL ({$failures} check(s) failed)");

        return self::FAILURE;
    }

    private function reportPass(string $label, string $detail): void
    {
        $this->line("[PASS] {$label}: {$detail}");
    }

    private function reportFail(string $label, string $detail): void
    {
        $this->error("[FAIL] {$label}: {$detail}");
    }
}
