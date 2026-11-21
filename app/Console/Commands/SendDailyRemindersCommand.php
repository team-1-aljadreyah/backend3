<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;

class SendDailyRemindersCommand extends Command
{
    protected $signature = 'app:send-daily-reminders';

    protected $description = 'Generate idempotent daily reminders for due-soon tasks, overdue tasks, and late bugs in Baghdad time.';

    public function handle(NotificationService $notifications): int
    {
        $counts = $notifications->sendDailyReminders();

        $this->info(sprintf(
            'Daily reminders sent — due soon: %d, overdue: %d, late bugs: %d',
            $counts['tasks_due_soon'],
            $counts['tasks_overdue'],
            $counts['bugs_late']
        ));

        return self::SUCCESS;
    }
}
