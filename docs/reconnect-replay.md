# Reconnect and replay

The socket is a low-latency hint, not the source of truth. Each browser stores the last applied incident sequence.

1. `incoming == last + 1`: apply it.
2. `incoming <= last`: ignore the duplicate.
3. `incoming > last + 1`: stop applying live events and call `GET /api/incidents/{id}/events?after_sequence={last}`.
4. For normal gaps the API returns ordered missing events. For a gap larger than 1,000 events it returns a fresh incident snapshot plus a bounded recent stream.
5. Only resume normal live application after replay/snapshot recovery completes.

This works after laptop sleep, Reverb restart, Redis interruption or a browser being offline. Durable state never depends on socket delivery.
