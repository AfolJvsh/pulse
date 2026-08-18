# Failure drills

- **Kill Reverb:** HTTP commands continue committing. Outbox rows accumulate and publish after Reverb returns.
- **Kill Redis:** live delivery and queue work pause; PostgreSQL incident state/events remain authoritative.
- **Kill a broadcaster after send:** the outbox lease expires and the event may broadcast twice; sequence dedupe makes this safe.
- **Disconnect a browser:** perform several commands elsewhere, reconnect, and verify gap replay reaches `incident.last_sequence`.
- **Concurrent severity changes:** send two commands with the same expected version. Exactly one wins; the other receives HTTP 409 with latest state.
- **Notification provider failure:** the delivery moves through retry states and eventually `dead` without blocking incident commands.
