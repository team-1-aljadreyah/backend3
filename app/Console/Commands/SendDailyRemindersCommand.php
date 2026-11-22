<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;

class SendDailyRemindersCommand extends Command
{
    protected $signature = 'app:send-daily-reminders {--day= : Optional Baghdad date override (YYYY-MM-DD) for missed reminders}';

    protected $description = 'Generate idempotent daily reminders for due-soon tasks, overdue tasks, late bugs, and birthdays in Baghdad time.';

    public function handle(NotificationService $notifications): int
    {
        $day = $this->option('day');
        $counts = $notifications->sendDailyReminders(is_string($day) && $day !== '' ? $day : null);

        $this->info(sprintf(
            'Daily reminders sent — due soon: %d, overdue: %d, late bugs: %d, birthdays soon: %d, birthdays today: %d',
            $counts['tasks_due_soon'],
            $counts['tasks_overdue'],
            $counts['bugs_late'],
            $counts['birthdays_soon'],
            $counts['birthdays_today']
        ));

        return self::SUCCESS;
    }
}
