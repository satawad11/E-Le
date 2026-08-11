# RBAC + ABAC

## Permission format
`MODULE.RESOURCE.ACTION` เช่น `LEAVE.REQUEST.APPROVE`.

## RBAC
Roles bundle permissions. Roles are configurable, not business truth.

## ABAC attributes
organization, unit, personnel type, ownership, assignment, temporary delegation, effective time, resource status.

## Scope examples
SELF, UNIT, DESCENDANTS, ORGANIZATION, ASSIGNED, CUSTOM.

## Evaluation
Explicit deny > explicit scoped allow > role allow > default deny. Server must check action + resource + scope.
