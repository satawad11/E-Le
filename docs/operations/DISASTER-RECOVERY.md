# Disaster Recovery (DR) Plan

## 1. DR Objectives
- **Recovery Point Objective (RPO):** 24 hours. (Data loss up to the last 02:00 AM automated daily backup is deemed acceptable by business units. Extended RPO to 1 hour can be achieved by streaming binlogs, deferred to post-v1.0.)
- **Recovery Time Objective (RTO):** 4 hours. (Time from declared disaster to fully operational system).

## 2. Disaster Scenarios & Response

### Scenario A: Database Corruption or Accidental Deletion
**Impact:** Application data invalid or missing; containers and host intact.
**Recovery Steps:**
1. Put the system in Maintenance Mode (or stop `app`/`worker` containers).
2. Retrieve the latest verified daily encrypted backup from off-site storage.
3. Follow the Database Restore Procedure (`BACKUP-RESTORE.md`).
4. Validate `audit_logs` consistency.
5. Restart application containers and disable Maintenance Mode.
**Expected RTO:** < 1 Hour.

### Scenario B: Complete Host / Server Loss
**Impact:** Total loss of Docker host, local volumes, databases, and configuration.
**Recovery Steps:**
1. **Provision New Host:** Spin up a new production VM with Docker and Docker Compose installed. (Estimated: 30 mins)
2. **Retrieve Config:** Clone the repository and fetch production `.env` and `docker-compose.yml` from the secure configuration vault (e.g., AWS Secrets Manager, 1Password). (Estimated: 15 mins)
3. **Container Initialization:** Run `docker-compose up -d db redis` to initialize infrastructure. Wait for MySQL to become ready. (Estimated: 10 mins)
4. **Retrieve Data Backups:** Download latest DB and File backups from off-site storage (e.g., S3). (Estimated: 30 mins)
5. **Restore Data:** Execute DB and File restore procedures. (Estimated: 45 mins)
6. **Application Start:** Run `docker-compose up -d` to start all `app`, `web`, `worker`, and `scheduler` containers. (Estimated: 10 mins)
7. **DNS Update:** Point domain DNS records to the new host IP. (Propagation may vary).
**Expected RTO:** < 3 Hours (Excluding DNS propagation).

## 3. Communication Plan
- **Detection:** Alerts triggered via monitoring (e.g., HTTP 5xx spikes, container exit alerts).
- **Declaration:** Operations Lead declares DR scenario and notifies stakeholders.
- **Resolution:** Post-recovery, stakeholders are notified of system restoration and the exact Recovery Point (data timestamp) achieved.

## 4. Periodic Testing
- A full **Scenario B** DR Drill must be executed every 6 months.
- The outcome of the drill, including the actual RTO achieved and data validation, must be documented in DR Drill Reports.
