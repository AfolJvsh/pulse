# Pulse Planner Implementation Status

This matrix maps `docs/PLANNER.md` to the current implementation.

| Milestone | Status | Implementation evidence |
|---|---|---|
| M0 — Foundation | Complete | Docker, auth/organizations, durable teams, PostgreSQL/Redis, CI |
| M1 — Incident domain over HTTP | Complete | `IncidentCommandService`, incidents, comments, action items, severity/status, durable event writer |
| M2 — Live broadcasting | Complete | Reverb configuration, private incident channels, `IncidentEventBroadcast`, React subscription/reducer layer |
| M3 — Presence | Complete | private presence authorization and incident-room connected responder UI |
| M4 — Ordering + recovery | Complete | monotonic per-incident sequence, `SequenceGuard`, replay endpoint, duplicate/gap handling and reconnect flow |
| M5 — Concurrency | Complete | entity versions, expected-version commands, `VersionConflict`, 409 handling and client conflict recovery |
| M6 — Async notifications | Complete | preferences, fan-out/delivery jobs, unique delivery identity and retry behavior |
| M7 — Transactional outbox | Complete | state/event/outbox in one transaction, publisher worker, retry-safe sequenced rebroadcast |
| M8 — Portfolio polish | Complete | real-time/event/replay/concurrency/outbox docs, failure drills, socket load tool and multi-client incident UI |

## Critical invariants covered

- Presence is ephemeral WebSocket connection state; participant/team/commander membership is durable PostgreSQL state.
- Every logical incident mutation appends a sequenced durable event.
- Clients ignore duplicate sequence numbers and replay gaps before applying later events.
- Client command IDs make optimistic command replay idempotent.
- Transactional outbox acknowledgement occurs only after the full side-effect bundle is queued; retry may rebroadcast a duplicate event but cannot silently drop notification fan-out.
- Notification deliveries have unique `(event,user,channel)` identity and are at-least-once.
- Versioned incident notes and incident updates reject stale writers rather than silently overwriting state.

## Validation evidence

- Domain suite: `tests/standalone.php`.
- Feature tests: `tests/Feature/`.
- Socket/load tooling: `tools/socket_load.mjs`.
