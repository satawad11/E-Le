# Observability & Monitoring Plan

## 1. Application & Security Logs
- **Log Format:** Structured JSON logging is enabled by default (`LOG_CHANNEL=structured`). This ensures logs can be ingested by centralized systems (e.g., ELK, Datadog, Grafana Loki).
- **Log Destination:** `php://stderr` (Docker standard). Rotation is delegated to the Docker daemon (`max-size` and `max-file` options in `docker-compose.yml`).
- **Correlation:** Every incoming API request generates or accepts an `X-Request-ID` via `AttachRequestContext`. This ID is automatically appended to all log entries, facilitating cross-service tracing.
- **Audit Logs:** Dual-write database audit trail (`audit_logs`) captures all business mutations with actor UUID, before/after values, and request correlation.

## 2. Infrastructure Health & Metrics
- **Health Endpoints:**
  - `GET /ping`: Basic application liveness check. Returns `{"message": "pong"}`.
  - `GET /up`: Framework readiness check. 
  - *Security Note:* Both endpoints are publicly accessible for Load Balancers but **do not expose** environment variables, credentials, or stack traces.
- **HTTP Errors (5xx & Slow Requests):** Monitored via API Gateway/Nginx access logs and backend structured logs.
- **Disk & Storage Usage:** `private_uploads` volume must be monitored via host metrics (e.g., Prometheus Node Exporter) to alert on >80% usage.

## 3. Queue & Background Jobs
- **Queue Health:** `jobs` and `failed_jobs` tables must be monitored. Alerts should trigger if `failed_jobs` count > 0 or `jobs` queue depth > 100 for more than 5 minutes.
- **Scheduled Jobs:** Cron jobs trigger the Laravel Scheduler (`php artisan schedule:run`). Scheduler success/failure is logged to the standard structured output.

## 4. Alerting Thresholds
- **Critical:** `5xx` error rate > 1% in 5 minutes.
- **Critical:** Health endpoint timeout or HTTP 502/503.
- **High:** Queue depth > 500 or Failed jobs > 10.
- **Medium:** Disk usage > 80%.
- **Low:** Increase in 4xx (Authentication/Authorization) errors (may indicate brute-force attempts).
