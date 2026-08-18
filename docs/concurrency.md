# Optimistic concurrency
Shared incident state carries a `version`. Severity/status commands must submit `expected_version`. The transaction locks the incident row and compares the expected version before mutation. A stale client receives HTTP 409 with the latest authoritative snapshot; exactly one of two same-version writers can succeed.
