# ATT-02 Device/Vendor Integration
Primary current flow: scanner -> vendor management system -> E-la connector. Also support direct devices later.

Pipeline: Source Registry -> Connector -> Raw Event -> Normalize -> Personnel/Device Mapping -> Deduplicate -> Attendance Processor -> Reconciliation.
Connectors: REST, webhook, read-only DB, SFTP/file, custom.
Store source_event_id or stable fingerprint; keep cursor, retry/dead-letter, last sync, health. Vendor status such as IN/OUT is evidence; E-la policy determines final attendance status. Avoid storing face/fingerprint templates centrally.
