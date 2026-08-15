# Operations Incident Runbook

This runbook outlines immediate response procedures for common system anomalies detected via observability tools.

## Incident 1: HTTP 5xx Spikes (Internal Server Error)
**Symptom:** Load balancer or monitoring alerts indicate >1% HTTP 500 responses.
**Action Plan:**
1. Check backend structured logs (`docker logs e-la-app --tail 100`).
2. Search by `"level_name": "ERROR"` or `"level_name": "EMERGENCY"`.
3. Extract `X-Request-ID` to trace the full request lifecycle.
4. Verify database connection limits and status (`docker exec e-la-db mysqladmin status`).
5. **Mitigation:** If capacity-related, restart/scale application workers. If bug-related, identify the endpoint and communicate partial degradation.

## Incident 2: Queue Accumulation / Failed Jobs
**Symptom:** `jobs` table count exceeds threshold or `failed_jobs` > 0.
**Action Plan:**
1. Check `failed_jobs` table to identify the failing job class and exception.
2. If related to external APIs (e.g., LINE OA timeouts), verify provider status.
3. **Mitigation:** Fix the root cause and retry using `docker exec e-la-app php artisan queue:retry all`. If jobs are stuck, restart the queue worker `docker-compose restart worker`.

## Incident 3: High Storage Usage (>85%)
**Symptom:** Node exporter or Docker host metrics report `/backups` or `private_uploads` nearing capacity.
**Action Plan:**
1. Verify which directory is consuming space: `docker exec e-la-app du -sh storage/app/private_uploads`.
2. **Mitigation:** Extend host volume storage. For backups, verify if the retention script (deleting files > 30 days) is executing successfully.

## Incident 4: Missing Audit Logs or Blank Activity
**Symptom:** Data is mutated but `audit_logs` are not being generated.
**Action Plan:**
1. **Critical:** This indicates a bypass of the Application layer (e.g., direct database mutation) or a failure in the `AuditLogger` service.
2. **Mitigation:** Cross-reference timestamps with MySQL connection logs. Verify `APP_ENV` and event listeners.

## Incident 5: Suspicious Authorization Failures (403/401)
**Symptom:** Sudden spike in 401 Unauthorized or 403 Forbidden logs.
**Action Plan:**
1. Aggregate the `remote_addr` or `user_id` from the structured logs.
2. **Mitigation:** Block offending IPs at the WAF/Nginx layer. Ensure rate limiting (`throttle:10,1`) is actively returning 429 status codes.
