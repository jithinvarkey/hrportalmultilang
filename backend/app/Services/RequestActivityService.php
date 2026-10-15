<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

class RequestActivityService
{
    public function record(Model $subject, string $logName, string $event, string $description, array $properties = []): void
    {
        $properties = array_filter($properties, function ($value) {
            return $value !== null;
        });

        activity($logName)
            ->event($event)
            ->causedBy(auth()->user())
            ->performedOn($subject)
            ->withProperties($properties)
            ->log($description);
    }

    public function timeline(Model $subject): Collection
    {
        return Activity::query()
            ->with('causer')
            ->where('subject_type', get_class($subject))
            ->where('subject_id', $subject->getKey())
            ->orderBy('created_at')
            ->get()
            ->map(function (Activity $activity) {
                $properties = collect($activity->properties ?? []);

                return [
                    'id' => $activity->id,
                    'log_name' => $activity->log_name,
                    'event' => $activity->event,
                    'description' => $activity->description,
                    'causer_name' => $activity->causer ? $activity->causer->name : 'System',
                    'properties' => $properties,
                    'from_status' => $properties->get('from_status'),
                    'to_status' => $properties->get('to_status'),
                    'notes' => $properties->get('notes') ?? $properties->get('reason'),
                    'created_at' => $activity->created_at,
                ];
            });
    }
}
