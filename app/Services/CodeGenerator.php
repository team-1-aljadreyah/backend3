<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CodeGenerator
{
    public function __construct(private readonly AdvancedSettingsService $advanced) {}

    public function nextProjectCode(): string
    {
        return $this->advanced->codePrefix('project').'-'.$this->nextSequence('project');
    }

    public function nextTaskCode(): string
    {
        return $this->advanced->codePrefix('task').'-'.(1000 + $this->nextSequence('task'));
    }

    public function nextBugCode(): string
    {
        return $this->advanced->codePrefix('bug').'-'.$this->nextSequence('bug');
    }

    private function nextSequence(string $name): int
    {
        $row = DB::table('code_sequences')->where('name', $name)->lockForUpdate()->first();
        if (! $row) {
            DB::table('code_sequences')->insert(['name' => $name, 'last_value' => 1]);

            return 1;
        }

        $next = ((int) $row->last_value) + 1;
        DB::table('code_sequences')->where('name', $name)->update(['last_value' => $next]);

        return $next;
    }
}
