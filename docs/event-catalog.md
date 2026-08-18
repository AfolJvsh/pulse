# Durable event catalog
Initial events: `IncidentCreated`, `SeverityChanged`, `StatusChanged`. Planned catalog adds `ParticipantAdded`, `CommentAdded`, `CommentEdited`, `ActionItemCreated`, `ActionItemAssigned`, `ActionItemCompleted`, `MonitoringSignalAdded`, and `IncidentResolved`. Presence/typing are intentionally ephemeral and never pollute the durable timeline.
