# API STANDARDS
All endpoints use schema validation, authenticated principal where protected, authorization + scope, pagination/filter/sort standards, request correlation ID and standard errors.

Mutations support idempotency when retry is plausible. Do not expose internal sequential identifiers. Return minimal fields based on permission.
