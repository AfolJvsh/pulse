# Event ordering
Every incident has a monotonic `last_sequence`. Mutation transactions lock the incident row, increment that sequence, persist the event, and persist its outbox message before commit.

Client rule: incoming `last+1` applies; `<=last` is duplicate/old and ignored; `>last+1` is a gap and triggers replay. This makes reconnect and out-of-order receipt explicit rather than accidental.
