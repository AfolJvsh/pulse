# Pulse

Pulse is a real-time incident collaboration SaaS designed around **correctness under disconnects, duplicate/out-of-order delivery and concurrent mutation**.

## What is implemented

- Incident lifecycle, severity, commander and durable participant roles.
- Comments, editable/versioned comments, action items and a versioned shared incident note.
- Monotonic per-incident event sequences and replay-idempotent client command IDs.
- Expected-version optimistic concurrency with HTTP 409 conflict responses.
- PostgreSQL transactional outbox -> Laravel Reverb presence channels.
- Client duplicate detection, gap replay and snapshot recovery for very large gaps.
- Retryable email/webhook notification fan-out with unique delivery identities.
- Monitoring-signal events, operational metrics and outbox recovery sweeps.
- A working React/Inertia incident room that demonstrates the concurrency/replay model.

## Architecture

```text
React/Inertia ──HTTP commands──> Laravel ──transaction──> PostgreSQL
      │                              │                       │
      │                              └── outbox row ─────────┘
      │                                       │
      └── Reverb presence/events <── worker <─┤
                                              └── notification jobs -> email/webhook
```

PostgreSQL is authoritative. WebSockets are an optimization. Every live event is recoverable through the HTTP replay API.

## Run locally

```bash
cp .env.example .env
docker compose up --build -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

Open `http://localhost:8000`, register a workspace, create an incident, then open the same incident in another browser session to exercise presence and version conflicts.

## Engineering review path

1. `app/Services/IncidentCommandService.php` — row locks, expected versions and command idempotency.
2. `app/Services/IncidentEventWriter.php` — sequence assignment + outbox in one transaction.
3. `app/Jobs/PublishOutbox.php` — leased at-least-once publication.
4. `app/Jobs/FanoutIncidentNotifications.php` — idempotent async fan-out.
5. `resources/js/realtime/sequence.ts` — duplicate/gap classification and replay.
6. `docs/concurrency.md`, `docs/event-ordering.md`, `docs/reconnect-replay.md`, `docs/notifications.md`, `docs/failure-drills.md`.

## Validation

```bash
php tests/standalone.php
composer test
npm run build
CLIENTS=500 DURATION_SECONDS=60 npm run load:socket
```
