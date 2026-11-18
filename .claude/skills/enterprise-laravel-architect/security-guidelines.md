
# Enterprise Laravel Security Guidelines

## 1. Security Principles

Apply defense in depth.

Never assume that:
- A hidden UI control is authorization.
- A random identifier prevents unauthorized access.
- A validated request is automatically authorized.
- HTTPS alone makes an integration secure.
- A signed URL is always safe.
- A successful authentication implies permission to access every resource.

Identify trust boundaries and protect every server-side operation.

## 2. Threat Modeling

For security-sensitive features, identify:

- Assets being protected.
- Trust boundaries.
- Actors and privileges.
- Untrusted inputs.
- External dependencies.
- Attack surfaces.
- Abuse scenarios.
- Potential impact.
- Preventive and detective controls.

Prioritize broken access control, data exposure, privilege escalation, injection, authentication weaknesses, and supply-chain risks.

## 3. Authentication

Verify authentication behavior for:

- Login.
- Logout.
- Registration.
- Password reset.
- Email verification.
- Account suspension.
- Session expiration.
- Token expiration.
- Token revocation.
- Multi-factor authentication when required.

Do not invent custom authentication mechanisms when established Laravel mechanisms meet the requirements.

## 4. Authorization and IDOR

Every protected operation must verify:

1. Authenticated identity.
2. Required action permission.
3. Resource ownership or permitted scope.
4. Resource state and applicable business rules.

Test:
- Access to another user's resources.
- Cross-project access.
- Cross-team access.
- Cross-organization access.
- Nested resource access.
- Bulk operations.
- Exports.
- Downloads.
- Background job authorization.

Never trust owner_id, user_id, role_id, organization_id, project_id, or similar client-supplied identifiers without server-side verification.

## 5. SQL Injection and Mass Assignment

- Use parameterized queries.
- Never concatenate untrusted input into SQL.
- Whitelist sortable and filterable columns.
- Never pass arbitrary client fields directly into model updates.
- Use explicit validation and writable-field control.
- Review raw expressions and dynamic query construction.
- Avoid unsafe deserialization.

## 6. SSRF Protection

For features that fetch external URLs:

- Allow only required URL schemes, normally HTTPS.
- Prefer explicit hostname allowlists for known providers.
- Reject localhost, loopback, private, link-local, multicast, and reserved IP ranges.
- Resolve and validate DNS results.
- Consider DNS rebinding.
- Revalidate every redirect destination.
- Disable redirects when they are unnecessary.
- Apply connection and total request timeouts.
- Limit response size and processing time.
- Avoid forwarding internal credentials or authorization headers.
- Never allow arbitrary user-controlled URLs to access internal services or cloud metadata endpoints.

Do not rely only on checking the original URL string.

## 7. CORS and Browser Security

- Define explicit allowed origins.
- Avoid wildcard origins when credentials are enabled.
- Restrict methods and headers.
- Avoid reflecting arbitrary Origin values.
- Configure preflight behavior deliberately.
- Keep CORS separate from authentication and authorization.
- Review CSRF protection for cookie-authenticated requests.

## 8. Session Security

When using session authentication:

- Use Secure cookies over HTTPS.
- Use HttpOnly where appropriate.
- Configure SameSite deliberately.
- Regenerate sessions after authentication and privilege changes.
- Invalidate sessions during logout.
- Apply appropriate expiration.
- Protect state-changing requests against CSRF.
- Do not expose session identifiers in logs or URLs.

## 9. Password Reset and Account Recovery

- Avoid user enumeration.
- Apply rate limiting.
- Generate cryptographically secure random tokens.
- Store token hashes rather than reusable plaintext tokens.
- Enforce expiration.
- Enforce single use.
- Invalidate previous tokens appropriately.
- Invalidate or review existing sessions after password changes.
- Notify users of important account security events.
- Never log reset tokens.

## 10. API Token Security

- Apply least privilege.
- Use minimal scopes.
- Set expiration where supported.
- Support revocation.
- Rotate credentials when required.
- Protect token storage.
- Avoid exposing tokens in responses, logs, or error messages.
- Distinguish user tokens, service credentials, and provider credentials.

## 11. Open Redirects

- Never blindly redirect to arbitrary user-supplied URLs.
- Prefer internal route identifiers or allowlisted destinations.
- Validate redirect destinations after decoding and normalization.
- Test encoded and alternative URL representations.
- Avoid relying only on string-prefix checks.

## 12. File Upload and Download Security

- Validate actual content where possible.
- Do not trust extensions or client MIME headers alone.
- Apply size and resource limits.
- Generate server-controlled names.
- Prevent path traversal.
- Store private files outside publicly accessible locations where appropriate.
- Authorize every download.
- Avoid treating an unguessable URL as authorization.
- Consider malware scanning.
- Define retention and deletion rules.

## 13. Webhook Security

- Verify signatures against the correct raw request body.
- Use constant-time comparison where applicable.
- Validate provider identity.
- Validate timestamps and replay windows.
- Deduplicate provider event identifiers.
- Handle duplicate and out-of-order delivery.
- Avoid trusting unverified webhook payloads.
- Apply timeouts and bounded processing.
- Process expensive work asynchronously.
- Record safe integration status and failure information.

## 14. Sensitive Data and Logging

Never log:
- Passwords.
- Access tokens.
- API secrets.
- Session identifiers.
- Private keys.
- Full payment credentials.
- Unnecessary personal data.

Use structured logs and correlation identifiers.

Redact sensitive values.

Keep business audit history separate from operational logs and security events.

Restrict access to logs and audit records.

## 15. Production Configuration

Review:

- APP_DEBUG=false.
- Secure APP_KEY management.
- HTTPS enforcement.
- Secure cookie configuration.
- Production CORS rules.
- Database credentials.
- Queue credentials.
- Storage permissions.
- Secret management.
- Error reporting.
- Debug endpoints.
- Exposed backup and environment files.
- Unnecessary public directories.
- Framework and dependency updates.

Never expose environment files, application secrets, stack traces, or internal infrastructure details.

## 16. Dependency and Supply-Chain Security

- Inspect new dependencies before introduction.
- Verify maintenance and compatibility.
- Prefer trusted package sources.
- Review dependency advisories.
- Avoid abandoned or suspicious packages.
- Review Composer scripts before installing unfamiliar dependencies.
- Do not run untrusted installation scripts.
- Use lock files consistently.
- Avoid unnecessary dependency upgrades.

## 17. Security Verification

For every relevant feature, test both allowed and denied behavior.

Security findings should include:

- Severity: Critical, High, Medium, Low, Informational.
- Affected component.
- Attack scenario.
- Business and technical impact.
- Evidence.
- Recommended fix.
- Verification method.
- Residual risk.

Do not declare a system secure merely because no issue was found in a limited review.

Clearly state review scope and limitations.
