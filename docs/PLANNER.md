# Pulse — Real-Time Incident Collaboration SaaS

## 1. Project objective

Build a real-time incident-response workspace. Teams open incidents, join live rooms, change severity/status, post timeline updates, assign responders/actions, and see authoritative changes immediately across connected clients.

The engineering question is: **what happens when multiple users mutate the same state concurrently and connections are unreliable?**

## 2. Engineering signals

- WebSockets/broadcasting.
- Presence.
- Durable events.
- Event ordering.
- Reconnect/replay.
- Optimistic UI.
- Optimistic concurrency/versioned writes.
- Authorization on private channels.
- Event-driven notifications.
- Transactional outbox as an advanced reliability feature.

## 3. Recommended architecture

```text
Commands / initial state:
React → HTTP → Laravel → PostgreSQL

Live propagation:
Laravel domain event → broadcast layer/Redis → WebSocket → clients

Presence:
Client ↔ presence channel ↔ WebSocket server
```

Use HTTP for commands and snapshots. Use WebSockets for live event delivery rather than forcing every operation through sockets.

## 4. Incident lifecycle

Statuses:

```text
open → investigating → mitigated → resolved → closed
```

Optional reopen:
`resolved/closed → open`

Severity:
- SEV1 critical.
- SEV2 high.
- SEV3 medium.
- SEV4 low.

Allowed transitions should be explicit in a state machine/domain service.

## 5. Domain model

### organizations
`id, name`

### teams
`id, organization_id, name`

### incidents
`id(UUID), organization_id, incident_number, title, description, severity, status, commander_user_id, version, started_at, mitigated_at, resolved_at`

### incident_participants
`incident_id, user_id, role, joined_at`

### incident_events
`id(bigserial), incident_id, sequence, event_type, actor_user_id, payload_json, occurred_at, client_command_id`

### comments
`id, incident_id, user_id, body, version, created_at, edited_at`

### action_items
`id, incident_id, title, assignee_user_id, status, version, due_at`

### notification_preferences
Per-user settings for incident notifications.

## 6. Durable event catalog

Examples:
- IncidentCreated.
- SeverityChanged.
- StatusChanged.
- CommanderAssigned.
- ParticipantAdded.
- CommentAdded.
- CommentEdited.
- ActionItemCreated.
- ActionItemAssigned.
- ActionItemCompleted.
- MonitoringSignalAdded.
- IncidentResolved.

Important events are persisted before live delivery. WebSocket delivery is not the source of truth.

## 7. Event ordering

Every incident event receives a monotonic `sequence` scoped to that incident.

Client tracks `lastSequence`.

Rules:
- `incoming == last + 1`: apply.
- `incoming <= last`: duplicate/old; ignore safely.
- `incoming > last + 1`: gap; fetch missing events.

This gives you a real recovery model rather than assuming socket delivery is perfect.

## 8. Reconnect/replay

Endpoint concept:

```text
GET /incidents/{id}/events?after_sequence=123
```

Reconnect flow:
1. Socket disconnects.
2. Other users continue creating events.
3. Client reconnects.
4. Client requests events after its last sequence.
5. Missing events apply in order.
6. Live subscription continues.

For very large gaps, return a fresh incident snapshot plus recent event stream.

## 9. Concurrency strategy

Use entity versions/optimistic concurrency for shared state.

Example command:

```json
{
  "severity": "critical",
  "expected_version": 7
}
```

Update succeeds only if current version is 7, then increments.

If version changed, return conflict with latest authoritative state.

Use transactions for:
- Entity mutation.
- Version increment.
- Incident event persistence.

Broadcast after successful commit (or through outbox later).

## 10. Optimistic UI

Good optimistic candidates:
- Comment post.
- Action-item creation.
- Acknowledgement/reaction.

Use a `client_command_id` to reconcile temporary local state with authoritative server event.

Sensitive state like severity/status should prefer server-confirmed UI or very clear rollback/conflict handling.

## 11. Presence

Do not confuse durable participants with ephemeral online presence.

- Participant = persisted responder/member.
- Presence = currently connected/viewing.

Show:
- Connected users.
- Who is viewing the incident.
- Optional typing indicator.

Do not store typing/presence noise in the durable incident event timeline.

## 12. Incident-room UI

Core layout:
- Incident header: severity/status/commander.
- Live responders/presence.
- Timeline of human + system events.
- Comment/update composer.
- Action items.
- Incident notes.
- Key timestamps.

Timeline example:

```text
10:02 Incident opened
10:04 Sarah joined
10:06 Severity HIGH → CRITICAL
10:08 David: “Rolling back deployment”
10:11 Action assigned to Sarah
10:16 Monitoring: latency recovered
10:19 Status → mitigated
```

## 13. Notifications

Channels:
- In-app live.
- Email.
- Outbound webhook.

Events:
- Assigned to incident.
- Mentioned.
- Severity escalated.
- Incident resolved.

Fan-out through queues. A user changing incident state should not wait on email/webhook providers.

## 14. Collaborative notes — advanced

Do not start with a CRDT.

Phase A:
- Versioned incident-notes document.
- Expected-version save.
- Conflict response.
- UI merge/reload experience.

Phase B only if desired:
- Adopt a proven CRDT/OT approach/library.
- Document why it was needed.

The project already demonstrates real-time engineering without recreating Google Docs.

## 15. Milestones

### M0 — Foundation
- Docker.
- Auth/organizations/teams.
- PostgreSQL + Redis.
- CI.

### M1 — Incident domain over HTTP
- Create incident.
- State transitions.
- Severity.
- Comments.
- Action items.
- Durable incident events.

**Exit:** fully usable without WebSockets.

### M2 — Live broadcasting
- Private incident channels.
- Server broadcasts after successful state change.
- React subscription layer.
- Live timeline/header updates.

**Exit:** two sessions see changes without refresh.

### M3 — Presence
- Presence authorization.
- Join/leave.
- Connected responder UI.

### M4 — Ordering + recovery
- Sequence numbers.
- Client duplicate handling.
- Gap detection.
- Replay endpoint.
- Reconnect tests.

**Exit:** disconnect a client, create events elsewhere, reconnect, and recover all logical changes.

### M5 — Concurrency
- Entity versions.
- Expected-version commands.
- Conflict responses.
- UI conflict recovery.
- Concurrent-write tests.

### M6 — Async notifications
- Queued notifications.
- Preferences.
- Provider failure/retry test.

### M7 — Transactional outbox
- State + event + outbox in one DB transaction.
- Outbox publisher worker.
- Retry without duplicate logical event.

### M8 — Portfolio polish
- Real-time architecture diagram.
- Event catalog.
- Ordering/replay doc.
- Concurrency doc.
- Multi-browser demo.
- Socket load test.

## 16. Transactional outbox rationale

Without an outbox:
1. DB transaction commits.
2. Broadcast call fails.
3. Connected clients miss immediate delivery.

Recovery via replay still works, but an outbox makes delivery more reliable:

```text
DB transaction:
state change + incident_event + outbox record
       ↓
worker publishes outbox
       ↓
mark sent
```

Stable event IDs/sequences keep retries logically idempotent.

## 17. Testing plan

### Unit
- State transitions.
- Severity rules.
- Event serialization.
- Conflict/version checks.

### Feature
- Tenant/channel authorization.
- Command endpoints.
- Event persistence.
- Replay endpoint.

### Concurrency
- Two updates use same expected version.
- Exactly one wins.
- Loser receives conflict/latest state.

### Real-time
- Two clients subscribe.
- Command changes state.
- Both receive event.
- Disconnect/reconnect/replay.
- Duplicate event is harmless.
- Gap triggers recovery.

### Load
- Concurrent WebSocket connections.
- Event burst in a single incident.
- p50/p95 broadcast latency.

## 18. Failure scenarios to demonstrate

- WebSocket disconnect.
- Duplicate event delivery.
- Out-of-order receipt.
- Concurrent severity mutation.
- Broadcast/Redis outage after DB success.
- Notification provider failure.

Document recovery behavior for each.

## 19. Observability

Track:
- Active socket connections.
- Broadcast events/sec.
- Broadcast latency.
- Reconnects.
- Replay/gap rate.
- Command conflict rate.
- Event persistence latency.
- Notification queue latency.

Log context:
`organization_id, incident_id, sequence, actor_id, client_command_id`.

## 20. Security

- Private organization/incident channels.
- Channel auth checks tenant membership.
- Server derives actor identity; never trusts client actor IDs.
- Presence payload exposes only necessary profile fields.
- Sanitize user-generated timeline content.
- Rate-limit high-frequency commands.

## 21. Repository docs

- `docs/realtime-architecture.md`
- `docs/event-ordering.md`
- `docs/reconnect-replay.md`
- `docs/concurrency.md`
- `docs/outbox.md`
- `docs/event-catalog.md`

## 22. Portfolio definition of done

Two or more clients can join the same incident, see presence, make live updates, recover missed events after disconnect, reject stale concurrent writes correctly, and inspect a complete durable incident timeline afterward.

## 23. Do not build yet

- Video calls.
- Screen sharing.
- Slack clone.
- Arbitrary chat channels.
- Full Google Docs clone.
- CRDT from scratch.
- Mobile apps.
- Dozens of notification integrations.

Keep the project about correctness under real-time concurrency.
