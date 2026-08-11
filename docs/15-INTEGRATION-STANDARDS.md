# INTEGRATION STANDARDS
Use adapter/provider pattern. External attendance vendor systems are integration sources, not final attendance authority.

Support REST API, webhook, read-only database connector, SFTP/file import and custom adapter. Preserve raw events, normalize, map identity/device, deduplicate, process, reconcile and monitor.

All connectors need timeout, retry, cursor/checkpoint, health state and audit.
