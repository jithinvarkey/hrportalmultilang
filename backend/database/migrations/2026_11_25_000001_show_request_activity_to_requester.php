<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Assignment / manager-approval entries are part of the activity log the requester can see.
 * They used to be stored as internal; free-text internal notes are left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->systemEntries()->update(['is_internal' => false]);
    }

    public function down(): void
    {
        $this->systemEntries()->update(['is_internal' => true]);
    }

    private function systemEntries(): Builder
    {
        return DB::table('request_comments')->where(function (Builder $q) {
            $q->where('comment', 'like', 'Request assigned to % and is now in progress.')
                ->orWhere('comment', 'like', 'Approved by manager. Assigned to % (HR Manager) for final approval.');
        });
    }
};
