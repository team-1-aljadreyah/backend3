<?php

namespace App\Providers;

use App\Models\Announcement;
use App\Models\Bug;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\Task;
use App\Policies\AnnouncementPolicy;
use App\Policies\BugPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\TaskPolicy;
use App\Policies\TemplatePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(Bug::class, BugPolicy::class);
        Gate::policy(Announcement::class, AnnouncementPolicy::class);
        Gate::policy(ProjectTemplate::class, TemplatePolicy::class);

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute((int) env('LOGIN_RATE_LIMIT_PER_MIN', 30))->by((string) $request->ip());
        });

        RateLimiter::for('api', function (Request $request) {
            $key = $request->user()?->id ? 'u:'.$request->user()->id : 'ip:'.$request->ip();

            return Limit::perMinute((int) env('API_RATE_LIMIT_PER_MIN', 240))->by($key);
        });
    }
}
