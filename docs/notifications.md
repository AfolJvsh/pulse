# Notification delivery

Email and webhook notifications are asynchronous and derived from committed incident events. `FanoutIncidentNotifications` creates a unique delivery record for `(incident_event_id, user_id, channel)` before queueing delivery.

Delivery is **at least once**. Webhooks include an idempotency key and HMAC signature; consumers should deduplicate by the key. Delivery retries use bounded exponential backoff. A terminal failure is retained as `dead` for inspection rather than silently discarded.

Reverb broadcast delivery is independently at-least-once. Clients deduplicate by incident sequence, so a publisher crash after broadcast but before marking the outbox row published is safe.
