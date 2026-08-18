# Transactional outbox
The domain transaction writes state + durable timeline event + outbox message atomically. A separate worker publishes outbox messages to Reverb and retries failure. Replays remain correct even while broadcasting is unavailable because the timeline is the source of truth.
