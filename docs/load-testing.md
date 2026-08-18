# Load testing

The socket harness joins the **real private presence incident channel**, generates an HTTP command burst, and measures end-to-end event latency from durable `occurred_at` to WebSocket delivery.

```bash
PULSE_TOKEN='...' INCIDENT_ID='...' \
CLIENTS=100 EVENTS=250 DURATION_SECONDS=10 \
npm run load:socket
```

It reports:
- opened/subscribed connections;
- commands emitted;
- expected vs observed event deliveries;
- socket/auth errors;
- p50/p95/max durable-event → socket latency.

During a run also inspect `/api/organizations/{organizationId}/metrics` for command conflicts, replay requests, outbox backlog/oldest age, one-hour event throughput, broadcast p50/p95, and notification backlog.

A correct run may contain duplicate transport deliveries and HTTP 409s in separate concurrency drills. It must not contain duplicate durable incident sequence numbers or silently lose committed events; clients discard old sequence numbers and replay gaps over HTTP.
