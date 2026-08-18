<?php

namespace App\Events;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final readonly class IncidentEventBroadcast implements ShouldBroadcastNow
{
    public function __construct(public string $incidentId, public array $event) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("incidents.{$this->incidentId}")];
    }

    public function broadcastAs(): string
    {
        return 'incident.event';
    }

    public function broadcastWith(): array
    {
        return $this->event;
    }
}
