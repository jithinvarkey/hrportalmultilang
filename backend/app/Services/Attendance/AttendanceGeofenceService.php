<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\AttendanceLocation;

class AttendanceGeofenceService
{
    private const EARTH_RADIUS_METERS = 6371000;

    /** A fix less precise than this could really be outside the radius, so it is not trusted. */
    public const MAX_ACCURACY_METERS = 150;

    /**
     * Decide whether a phone's reported position is inside an active attendance location.
     *
     * @return array{allowed: bool, message: string|null, location: AttendanceLocation|null, distance_meters: int|null}
     */
    public function check(float $latitude, float $longitude, float $accuracy): array
    {
        $locations = AttendanceLocation::where('is_active', true)->get()
            ->map(fn (AttendanceLocation $location) => [
                'location' => $location,
                'distance' => (int) round($this->distanceInMeters(
                    $latitude,
                    $longitude,
                    $location->latitude,
                    $location->longitude
                )),
            ])
            ->sortBy('distance')
            ->values();

        if ($locations->isEmpty()) {
            return $this->denied(__('No attendance location has been set up. Please contact HR.'));
        }

        if ($accuracy > self::MAX_ACCURACY_METERS) {
            return $this->denied(__('Your location is not accurate enough (:accuracy m). Move near a window or outdoors and try again.', [
                'accuracy' => (int) round($accuracy),
            ]));
        }

        // Radii can differ, so the nearest site is not always the one that contains the phone.
        $inside = $locations->first(fn (array $row) => $row['distance'] <= $row['location']->radius_meters);

        if ($inside) {
            return [
                'allowed' => true,
                'message' => null,
                'location' => $inside['location'],
                'distance_meters' => $inside['distance'],
            ];
        }

        $nearest = $locations->first();

        return $this->denied(__('You are :distance m from :location. Punching is only allowed within :radius m.', [
            'distance' => $nearest['distance'],
            'location' => $nearest['location']->name,
            'radius' => $nearest['location']->radius_meters,
        ]), $nearest['distance']);
    }

    /** Great-circle distance between two coordinates (haversine). */
    public function distanceInMeters(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $latDelta = deg2rad($toLat - $fromLat);
        $lngDelta = deg2rad($toLng - $fromLng);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($lngDelta / 2) ** 2;

        return self::EARTH_RADIUS_METERS * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function denied(string $message, ?int $distance = null): array
    {
        return ['allowed' => false, 'message' => $message, 'location' => null, 'distance_meters' => $distance];
    }
}
