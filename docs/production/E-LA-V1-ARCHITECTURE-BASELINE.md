# E-la v1.0.0 Architecture Baseline (PROD-00)

**Date:** 2026-08-15
**Version:** v1.0.0

This document serves as the formal freeze point for E-la v1.0.0 architecture. No changes are permitted to these baselines without an explicit architectural change request or a critical security/bug fix during the UAT and deployment phases.

## 1. Database Architecture Freeze
- **Engine:** MySQL 8.0.46
- **Schema:** 55+ baseline tables including all additive migrations up to `LEGACY-12`.
- **Constraint:** Schema is **FROZEN**. No `CREATE`, `ALTER`, or `DROP` statements are allowed. All data structures, including `checked varchar` statuses, UUID constraints, and central `audit_logs` are locked.

## 2. API Contracts Freeze
- **Structure:** RESTful JSON APIs standard.
- **Constraint:** Request and response schemas are **FROZEN**. Changes that introduce breaking backwards compatibility or alter the established payload structure (e.g., Attendance raw event payloads, Notification Center payload formats) are forbidden.

## 3. Permission Naming Freeze
- **Standard:** `module.resource.action` (e.g., `leave.requests.approve`).
- **Constraint:** Permission names and legacy aliases are **FROZEN**. No new permission keys may be introduced. Renaming or removing legacy aliases is deferred until post-v1.0 usage monitoring.

## 4. Module Boundaries Freeze
- **Architecture:** Modular Monolith (Laravel 13).
- **Constraint:** Inter-module dependencies are **FROZEN**. Core does not import business modules. Modules must communicate via established Core Contracts and Services. The deployment switch mechanism via `.env` (e.g., `MODULE_LEAVE_ENABLED=true`) is locked as the standard toggle mechanism.

## 5. Workflow Contracts Freeze
- **Engine:** Central generic Workflow Engine.
- **Constraint:** The generic workflow schema (`workflow_definitions`, `workflow_versions`, `workflow_instances`, `workflow_actions`) is **FROZEN**. Leave module remains in shadow mode (parallel execution without cutover) until explicit operational authorization.

## 6. Menu Structure Freeze
- **Architecture:** Frontend-driven routing mapped to module availability.
- **Constraint:** The navigation menu hierarchy and UI routing are **FROZEN**. No new top-level menu items or restructuring of the current presentation layer is permitted. Menu visibility remains solely for presentation and does not bypass backend authorization.

## 7. Role & Permission Baseline Freeze
- **Constraint:** The baseline roles (e.g., Super Admin, Employee, Manager) and their mapped permissions/scopes are **FROZEN**. Dynamic assignments via `org_unit_managers` are allowed, but the underlying capability mapping matrix cannot be altered for v1.0.

---
**Status:** FROZEN
**Target:** E-la v1.0.0 Release Candidate
