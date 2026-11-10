<?php

namespace App\Observers;

use App\Models\StaffAction;
use Illuminate\Database\Eloquent\Model;

class StaffAuditObserver
{
    public function created(Model $record): void
    {
        $this->log($record, 'created');
    }

    public function updated(Model $record): void
    {
        $this->log($record, 'updated');
    }

    public function deleted(Model $record): void
    {
        $this->log($record, 'deleted');
    }

    private function log(Model $record, string $action): void
    {
        if (! auth()->user()?->is_staff) {
            return;
        }
        StaffAction::create(['user_id' => auth()->id(), 'action' => $record->getTable().'.'.$action, 'subject_type' => $record->getMorphClass(), 'subject_id' => $record->getKey(), 'metadata' => ['fields' => array_keys($record->getChanges()), 'status' => $record->getAttribute('status')]]);
    }
}
