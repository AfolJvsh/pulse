<?php

use App\Models\Incident;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('incidents.{incidentId}', function ($user, string $incidentId) {
    $incident = Incident::find($incidentId);
    if (! $incident || ! $user->organizations()->whereKey($incident->organization_id)->exists()) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name];
});
