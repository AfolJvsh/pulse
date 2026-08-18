<?php

namespace App\Http\Controllers;

use App\Models\{Incident, IncidentEvent, NotificationDelivery, OutboxMessage};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;

final class OperationsController
{
    public function metrics(Request $request, string $organizationId): JsonResponse
    {
        abort_unless($request->user()->organizations()->whereKey($organizationId)->exists(), 403);
        $incidentIds = Incident::where('organization_id', $organizationId)->pluck('id');
        $pending = OutboxMessage::whereIn('aggregate_id', $incidentIds)->whereNull('published_at')->count();
        $oldest = OutboxMessage::whereIn('aggregate_id', $incidentIds)->whereNull('published_at')->min('created_at');
        $eventIds = IncidentEvent::whereIn('incident_id', $incidentIds)->pluck('id');
        $since = now()->subHour();
        $eventsLastHour = IncidentEvent::whereIn('incident_id', $incidentIds)->where('occurred_at', '>=', $since)->count();

        $broadcastLatencies = OutboxMessage::whereIn('aggregate_id', $incidentIds)
            ->whereNotNull('published_at')->where('created_at', '>=', $since)
            ->get(['created_at', 'published_at'])
            ->map(fn ($m) => $m->created_at->diffInMilliseconds($m->published_at))->sort()->values();
        $persistenceLatencies = IncidentEvent::whereIn('incident_id', $incidentIds)
            ->where('occurred_at', '>=', $since)->whereNotNull('persistence_latency_ms')
            ->pluck('persistence_latency_ms')->map(fn ($v) => (int) $v)->sort()->values();
        $notificationLatencies = NotificationDelivery::whereIn('incident_event_id', $eventIds)
            ->whereNotNull('delivered_at')->where('created_at', '>=', $since)
            ->get(['created_at', 'delivered_at'])
            ->map(fn ($d) => $d->created_at->diffInMilliseconds($d->delivered_at))->sort()->values();

        $oldestAge = $oldest ? max(0, now()->diffInSeconds(Carbon::parse($oldest))) : null;
        $socketKey = "pulse:active-sockets:$organizationId";
        Redis::zremrangebyscore($socketKey, '-inf', (string) time());
        $activeSockets = (int) Redis::zcard($socketKey);
        $conflicts = (int) (Redis::get("pulse:metrics:conflicts:$organizationId") ?? 0);
        $replays = (int) (Redis::get("pulse:metrics:replays:$organizationId") ?? 0);
        $reconnects = (int) (Redis::get("pulse:metrics:reconnects:$organizationId") ?? 0);

        return response()->json([
            'window' => '1h',
            'incidents' => $incidentIds->count(),
            'active_socket_connections' => $activeSockets,
            'broadcast_events_per_second' => round($eventsLastHour / 3600, 4),
            'reconnects' => $reconnects,
            'replays' => $replays,
            'conflicts' => $conflicts,
            'command_conflict_rate' => $eventsLastHour ? round($conflicts / max(1, $eventsLastHour), 6) : 0,
            'outbox_pending' => $pending,
            'outbox_oldest_age_seconds' => $oldestAge,
            'broadcast_latency_ms' => ['p50' => $this->pct($broadcastLatencies, .5), 'p95' => $this->pct($broadcastLatencies, .95)],
            'event_persistence_latency_ms' => ['p50' => $this->pct($persistenceLatencies, .5), 'p95' => $this->pct($persistenceLatencies, .95)],
            'notification_queue_latency_ms' => ['p50' => $this->pct($notificationLatencies, .5), 'p95' => $this->pct($notificationLatencies, .95)],
            'notifications' => [
                'pending' => NotificationDelivery::whereIn('incident_event_id', $eventIds)->whereIn('status', ['pending', 'failed'])->count(),
                'dead' => NotificationDelivery::whereIn('incident_event_id', $eventIds)->where('status', 'dead')->count(),
                'delivered' => NotificationDelivery::whereIn('incident_event_id', $eventIds)->where('status', 'delivered')->count(),
            ],
        ]);
    }

    private function pct($values, float $p): ?int
    {
        if ($values->isEmpty()) return null;
        return (int) $values[(int) floor(($values->count() - 1) * $p)];
    }
}
