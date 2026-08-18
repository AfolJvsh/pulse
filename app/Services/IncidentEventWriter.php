<?php

namespace App\Services;

use App\Jobs\PublishOutbox;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\OutboxMessage;
use Illuminate\Support\Facades\DB;

final class IncidentEventWriter
{
    public function appendLocked(Incident $incident, string $type, ?int $actorId, array $payload, ?string $clientCommandId = null): IncidentEvent
    {
        $persistStarted = hrtime(true);
        $sequence = $incident->last_sequence + 1;
        $event = IncidentEvent::create([
            'incident_id' => $incident->id,
            'sequence' => $sequence,
            'event_type' => $type,
            'actor_user_id' => $actorId,
            'payload_json' => $payload,
            'occurred_at' => now(),
            'client_command_id' => $clientCommandId,
        ]);

        $incident->last_sequence = $sequence;
        $incident->save();

        OutboxMessage::create([
            'aggregate_type' => 'incident',
            'aggregate_id' => $incident->id,
            'incident_event_id' => $event->id,
            'kind' => 'broadcast',
            'dedupe_key' => "incident-event:{$event->id}",
            'event_type' => 'incident.event',
            'payload_json' => [
                'id' => $event->id,
                'incident_id' => $incident->id,
                'sequence' => $sequence,
                'event_type' => $type,
                'actor_user_id' => $actorId,
                'payload' => $payload,
                'occurred_at' => $event->occurred_at->toISOString(),
                'client_command_id' => $clientCommandId,
            ],
            'available_at' => now(),
        ]);

        $event->persistence_latency_ms = max(0, (int) ((hrtime(true) - $persistStarted) / 1_000_000));
        $event->saveQuietly();

        DB::afterCommit(fn () => PublishOutbox::dispatch()->onQueue('broadcasts'));
        return $event;
    }
}
