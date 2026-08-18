# ADR 0001 — Laravel 13 + PostgreSQL

**Status:** Accepted — 2026-08-17

## Decision
Use Laravel 13 as the application framework, PostgreSQL as durable state, Redis for ephemeral coordination/queues, and React + Inertia for the operator UI.

## Why
The repository is judged on **a real-time incident collaboration system focused on event ordering, replay, presence, and concurrent mutation.**, not on framework novelty. Laravel provides durable database, queue, validation, authorization, scheduling, and test primitives while leaving the project-specific systems problem visible in application code.

## Consequences
- PostgreSQL is authoritative for durable state.
- Redis loss must not destroy durable business state.
- Long-running work is not performed inside an HTTP request.
