# CORE-04 RBAC + ABAC
Implement roles, permissions, direct grants/denies, scoped assignments and effective dates.
Permission evaluator input: subject, action, resource, organization/unit, ownership, assignment, time.
Tests must enumerate forbidden cross-user/cross-unit object access (IDOR).
Dynamic menu consumes effective permission result but API remains authoritative.
