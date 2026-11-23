<?php

use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\AdvancedSettingsController;
use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AvatarController;
use App\Http\Controllers\Api\V1\BugController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\GithubController;
use App\Http\Controllers\Api\V1\MonitoringController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PerformanceController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\PushController;
use App\Http\Controllers\Api\V1\RatingController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SprintController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TemplateController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public auth, immutable avatar files, signed downloads & webhook endpoints
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::get('/avatars/{user}/{file}', [AvatarController::class, 'show'])
        ->whereNumber('user')
        ->where('file', '[a-f0-9]{32}\.(webp|jpg)')
        ->name('avatars.show');
    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])
        ->whereNumber('attachment')
        ->name('attachments.download');
    Route::post('/webhooks/github', [GithubController::class, 'webhook']);

    // Protected endpoints (Bearer token + active user + read-only guard)
    Route::middleware(['auth:sanctum', 'active.user', 'throttle:api', 'read.only'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::put('/me/password', [AuthController::class, 'changePassword'])->middleware('throttle:login');
        Route::put('/me/avatar', [AvatarController::class, 'update']);
        Route::delete('/me/avatar', [AvatarController::class, 'destroyOwn']);
        Route::get('/users', [AuthController::class, 'users']);
        Route::delete('/users/{id}/avatar', [AvatarController::class, 'destroyUser'])->whereNumber('id');
        Route::get('/teams', [AdminController::class, 'teams']);
        Route::get('/search', [SearchController::class, 'index']);
        Route::get('/site/live', [AdvancedSettingsController::class, 'live']);

        // Projects & setup
        Route::get('/projects', [ProjectController::class, 'index']);
        Route::post('/projects', [ProjectController::class, 'store']);
        Route::get('/projects/{id}', [ProjectController::class, 'show'])->whereNumber('id');
        Route::patch('/projects/{id}', [ProjectController::class, 'update'])->whereNumber('id');
        Route::put('/projects/{id}/statuses', [ProjectController::class, 'saveStatuses'])->whereNumber('id');
        Route::put('/projects/{id}/phases', [ProjectController::class, 'savePhases'])->whereNumber('id');
        Route::get('/projects/{id}/history', [ProjectController::class, 'history'])->whereNumber('id');

        // Sprints & templates
        Route::get('/projects/{id}/sprints', [SprintController::class, 'index'])->whereNumber('id');
        Route::post('/projects/{id}/sprints', [SprintController::class, 'store'])->whereNumber('id');
        Route::patch('/sprints/{id}', [SprintController::class, 'update'])->whereNumber('id');
        Route::delete('/sprints/{id}', [SprintController::class, 'destroy'])->whereNumber('id');
        Route::post('/sprints/{id}/close', [SprintController::class, 'close'])->whereNumber('id');

        Route::get('/templates', [TemplateController::class, 'index']);
        Route::post('/projects/{id}/template', [TemplateController::class, 'store'])->whereNumber('id');
        Route::delete('/templates/{id}', [TemplateController::class, 'destroy'])->whereNumber('id');

        // Tasks
        Route::get('/projects/{id}/tasks', [TaskController::class, 'index'])->whereNumber('id');
        Route::post('/projects/{id}/tasks', [TaskController::class, 'store'])->whereNumber('id');
        Route::get('/me/tasks', [TaskController::class, 'myTasks']);
        Route::get('/tasks/{id}', [TaskController::class, 'show'])->whereNumber('id');
        Route::patch('/tasks/{id}', [TaskController::class, 'update'])->whereNumber('id');
        Route::post('/tasks/{id}/status', [TaskController::class, 'move'])->whereNumber('id');
        Route::post('/tasks/{id}/approval', [TaskController::class, 'decideApproval'])->whereNumber('id');
        Route::put('/tasks/{id}/dependencies', [TaskController::class, 'setDependencies'])->whereNumber('id');
        Route::get('/tasks/{id}/comments', [TaskController::class, 'comments'])->whereNumber('id');
        Route::post('/tasks/{id}/comments', [TaskController::class, 'addComment'])->whereNumber('id');
        Route::get('/tasks/{id}/history', [TaskController::class, 'history'])->whereNumber('id');

        // Notifications
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->whereNumber('id');
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::get('/notifications/preferences', [NotificationController::class, 'preferences']);
        Route::put('/notifications/preferences', [NotificationController::class, 'savePreferences']);
        Route::post('/me/push-subscriptions', [PushController::class, 'store']);
        Route::delete('/me/push-subscriptions', [PushController::class, 'destroy']);

        // Attachments
        Route::get('/tasks/{id}/attachments', [AttachmentController::class, 'listForTask'])->whereNumber('id');
        Route::post('/tasks/{id}/attachments', [AttachmentController::class, 'uploadForTask'])->whereNumber('id');
        Route::get('/projects/{id}/attachments', [AttachmentController::class, 'listForProject'])->whereNumber('id');
        Route::post('/projects/{id}/attachments', [AttachmentController::class, 'uploadForProject'])->whereNumber('id');
        Route::get('/bugs/{id}/attachments', [AttachmentController::class, 'listForBug'])->whereNumber('id');
        Route::post('/bugs/{id}/attachments', [AttachmentController::class, 'uploadForBug'])->whereNumber('id');
        Route::get('/messages/{id}/attachments', [AttachmentController::class, 'listForMessage'])->whereNumber('id');
        Route::post('/messages/{id}/attachments', [AttachmentController::class, 'uploadForMessage'])->whereNumber('id');
        Route::delete('/attachments/{id}', [AttachmentController::class, 'destroy'])->whereNumber('id');

        // Announcements
        Route::get('/announcements', [AnnouncementController::class, 'index']);
        Route::post('/announcements', [AnnouncementController::class, 'store']);
        Route::get('/announcements/{id}', [AnnouncementController::class, 'show'])->whereNumber('id');
        Route::delete('/announcements/{id}', [AnnouncementController::class, 'destroy'])->whereNumber('id');
        Route::post('/announcements/{id}/seen', [AnnouncementController::class, 'markSeen'])->whereNumber('id');
        Route::get('/announcements/{id}/reads', [AnnouncementController::class, 'reads'])->whereNumber('id');

        // Bugs
        Route::get('/bugs', [BugController::class, 'index']);
        Route::get('/bugs/{id}', [BugController::class, 'show'])->whereNumber('id');
        Route::post('/projects/{id}/bugs', [BugController::class, 'store'])->whereNumber('id');
        Route::patch('/bugs/{id}', [BugController::class, 'update'])->whereNumber('id');
        Route::post('/bugs/{id}/status', [BugController::class, 'move'])->whereNumber('id');
        Route::get('/bugs/{id}/history', [BugController::class, 'history'])->whereNumber('id');

        // Chat
        Route::get('/chats', [ChatController::class, 'conversations']);
        Route::post('/chats/direct', [ChatController::class, 'startDirect']);
        Route::post('/chats/groups', [ChatController::class, 'createGroup']);
        Route::get('/chats/{key}/messages', [ChatController::class, 'messages']);
        Route::post('/chats/{key}/messages', [ChatController::class, 'send']);
        Route::post('/chats/{key}/read', [ChatController::class, 'markRead']);
        Route::patch('/chats/{key}', [ChatController::class, 'updateGroup']);
        Route::post('/chats/{key}/leave', [ChatController::class, 'leaveGroup']);

        // Reports
        Route::get('/reports/overview', [ReportController::class, 'overview']);

        // Performance
        Route::get('/performance', [PerformanceController::class, 'overview']);
        Route::get('/performance/people/{id}', [PerformanceController::class, 'person'])->whereNumber('id');
        Route::get('/performance/criteria', [PerformanceController::class, 'criteria']);
        Route::put('/performance/criteria', [PerformanceController::class, 'saveCriteria']);
        Route::post('/performance/criteria/approve', [PerformanceController::class, 'approveCriteria']);
        Route::post('/reviews', [PerformanceController::class, 'createReview']);
        Route::patch('/reviews/{id}', [PerformanceController::class, 'updateReview'])->whereNumber('id');
        Route::post('/reviews/{id}/finalize', [PerformanceController::class, 'finalizeReview'])->whereNumber('id');

        // Administration & audit log
        Route::get('/admin/users', [AdminController::class, 'users']);
        Route::post('/admin/users', [AdminController::class, 'createUser']);
        Route::post('/admin/users/import', [AdminController::class, 'importUsers']);
        Route::patch('/admin/users/{id}', [AdminController::class, 'updateUser'])->whereNumber('id');
        Route::post('/admin/users/{id}/unlock', [AdminController::class, 'unlockUser'])->whereNumber('id');
        Route::delete('/admin/users/{id}/sessions', [AdminController::class, 'endUserSessions'])->whereNumber('id');
        Route::get('/admin/sessions', [AdminController::class, 'sessions']);
        Route::delete('/admin/sessions/{id}', [AdminController::class, 'endSession'])->where('id', '[a-zA-Z0-9_-]+');
        Route::get('/admin/security/summary', [AdminController::class, 'securitySummary']);
        Route::post('/admin/teams', [AdminController::class, 'createTeam']);
        Route::patch('/admin/teams/{id}', [AdminController::class, 'updateTeam'])->whereNumber('id');
        Route::get('/admin/settings', [AdminController::class, 'settings']);
        Route::put('/admin/settings', [AdminController::class, 'saveSettings']);
        Route::get('/admin/health', [AdminController::class, 'health']);
        Route::get('/audit', [AdminController::class, 'audit']);
        Route::get('/audit/{id}', [AdminController::class, 'auditEntry'])->whereNumber('id');

        // Quick ratings of teams and people
        Route::get('/teams/ratings/summary', [RatingController::class, 'teamSummaries']);
        Route::get('/teams/{id}', [RatingController::class, 'teamDetail'])->whereNumber('id');
        Route::get('/teams/{id}/ratings', [RatingController::class, 'teamRatings'])->whereNumber('id');
        Route::post('/teams/{id}/ratings', [RatingController::class, 'rateTeam'])->whereNumber('id');
        Route::get('/people/ratings/summary', [RatingController::class, 'peopleSummaries']);
        Route::get('/people/{id}/ratings', [RatingController::class, 'personRatings'])->whereNumber('id');
        Route::post('/people/{id}/ratings', [RatingController::class, 'ratePerson'])->whereNumber('id');
        Route::patch('/ratings/{id}', [RatingController::class, 'update'])->whereNumber('id');
        Route::delete('/ratings/{id}', [RatingController::class, 'destroy'])->whereNumber('id');

        // Monitoring notes, requests & CEO inbox
        Route::get('/monitoring/notes', [MonitoringController::class, 'notes']);
        Route::post('/monitoring/notes', [MonitoringController::class, 'create']);
        Route::post('/monitoring/notes/{id}/send', [MonitoringController::class, 'send'])->whereNumber('id');
        Route::post('/monitoring/notes/{id}/share', [MonitoringController::class, 'share'])->whereNumber('id');
        Route::post('/monitoring/notes/{id}/seen', [MonitoringController::class, 'markSeen'])->whereNumber('id');
        Route::get('/monitoring/received', [MonitoringController::class, 'received']);
        Route::post('/monitoring/requests/{id}/forward', [MonitoringController::class, 'forwardRequest'])->whereNumber('id');
        Route::post('/monitoring/requests/{id}/answer', [MonitoringController::class, 'answerRequest'])->whereNumber('id');
        Route::get('/monitoring/report-facts', [MonitoringController::class, 'reportFacts']);
        Route::get('/ceo/inbox', [MonitoringController::class, 'inbox']);
        Route::post('/ceo/inbox/{id}/answer', [MonitoringController::class, 'answer'])->whereNumber('id');

        // CEO advanced settings
        Route::get('/ceo/advanced', [AdvancedSettingsController::class, 'get']);
        Route::put('/ceo/advanced', [AdvancedSettingsController::class, 'save']);
        Route::get('/ceo/advanced/history', [AdvancedSettingsController::class, 'history']);

        // GitHub links & settings
        Route::get('/projects/{id}/github', [GithubController::class, 'settings'])->whereNumber('id');
        Route::put('/projects/{id}/github', [GithubController::class, 'saveSettings'])->whereNumber('id');
        Route::get('/tasks/{id}/github', [GithubController::class, 'taskLinks'])->whereNumber('id');
        Route::get('/bugs/{id}/github', [GithubController::class, 'bugLinks'])->whereNumber('id');
    });
});
