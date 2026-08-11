# SECURITY STANDARDS
## Principles
Deny by default, least privilege, never trust frontend, defense in depth.

## Controls
- Server-side authn/authz every protected endpoint
- RBAC + ABAC + resource scope
- IDOR tests for object access
- HttpOnly/Secure/SameSite cookies
- CSRF protection where applicable
- Rate limiting and account lock controls
- Input/schema validation
- File MIME/size validation; private storage
- Secrets only in secret/env management
- Security headers
- Audit privileged actions

## Sensitive data
ID card, salary, medical attachments and biometric-related metadata require narrow scopes and access logs. Do not centrally store biometric templates unless formally required and approved.
