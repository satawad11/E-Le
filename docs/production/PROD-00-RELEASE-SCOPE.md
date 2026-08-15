# E-la v1.0.0 Release Scope (PROD-00)

**Date:** 2026-08-15
**Version:** v1.0.0

This document defines the definitive feature scope and boundaries for E-la v1.0.0 Release. Any feature not explicitly listed in the "Included" section is deferred to a future minor or major version.

## 1. Features Included in v1.0.0

### Core System & Platform
- **Identity & Personnel:** Canonical user profiles, organization units (`org_units`), and manager assignments (`org_unit_managers`).
- **Authorization & Security:** RBAC, ABAC, Explicit Deny, and Organization Scopes.
- **Workflow Engine:** Versioned workflow definitions, delegation, shadow comparison, and central action logs.
- **Time Foundation:** Work schedules (Asia/Bangkok), holidays, and centralized time calculations.
- **Audit & Security:** Central dual-write audit logs, private file storage with MIME/size validation, and signed UUID identifiers.

### Business Modules
- **Leave Module:** Government leave types, quotas, cancellation, document generation, and backward-compatible approval logic.
- **Attendance Module:** Vendor-neutral raw event ingestion (Web, Manual, Import, Device), correction workflow, and reconciliation service.
- **Overtime Module:** Separate management for OT Orders, OT Assignments, and actual/certified Time Records.
- **Saraban Module:** Official document registration, circulars, workflow routing, and sealed content hash for e-signatures.
- **Notification Center:** Event-driven notification registry, versioned templates, In-App, Email, LINE OA, and Telegram adapters.
- **Reporting Module:** Scope-safe unified reports and data exports across all active modules.

## 2. Features Deferred to Post-v1.0

### Deferred Business Modules
- Finance Module
- Procurement Module
- Asset Management Module
- Planning & Budget Module
- Vehicle Booking Module
- Room Booking Module
- Maintenance Module
- Meeting Module

### Deferred Specific Capabilities
- **Performance Evaluation Engine:** Scoring, weighting, thresholds, and performance metrics calculation logic.
- **Overtime Compensation:** Calculation of OT rates and monetary remuneration.
- **Cross-Midnight Attendance Rules:** Default rule implementation for overnight shifts (requires future policy approval).
- **ZKTeco/Specific Vendor SDK integration:** Specific biometric hardware protocol integration (dependent on hardware procurement and vendor selection).
- **Asynchronous/Queued Exports:** Bulk queued generation for very large report exports.
