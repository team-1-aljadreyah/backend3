
# Enterprise Laravel Multi-Tenancy Architecture

## 1. Purpose

This reference defines architecture and security requirements for Laravel applications serving multiple organizations, companies, customers, tenants, or isolated workspaces.

Tenant isolation is a security boundary, not merely a database filtering convention.

A defect in tenant isolation may expose one customer's data to another customer.

Apply these rules whenever multi-tenancy exists or is being introduced.

## 2. Identify the Tenancy Strategy

Before implementation, identify the actual isolation model.

Common strategies:

### Shared database and shared schema

- Tenants share tables.
- Tenant-owned records contain a tenant identifier.
- Every relevant query and operation must enforce tenant scope.

### Shared database and separate schemas

- Tenants use separate database schemas.
- Schema selection must be controlled by trusted tenant resolution.
- Connection and schema lifecycle must be handled safely.

### Separate database per tenant

- Each tenant has an isolated database.
- Connection selection must use trusted server-side configuration.
- Database credentials and connection switching require careful protection.

Do not select a strategy without considering:

- Data isolation requirements.
- Number of tenants.
- Data volume.
- Operational complexity.
- Backup and restoration.
- Migration strategy.
- Reporting requirements.
- Regulatory obligations.
- Cost and infrastructure constraints.

Do not mix tenancy strategies casually.

## 3. Trusted Tenant Resolution

Never trust a tenant identifier merely because it appears in:

- Request parameters.
- Request body.
- Query strings.
- HTTP headers.
- Cookies.
- Route parameters.
- Frontend state.

Resolve tenant context using an authoritative and verified mechanism.

Possible sources include:

- Authenticated membership.
- Verified subdomain or custom domain.
- Trusted API credentials.
- Explicit server-side tenant selection after membership validation.

Validate that the authenticated principal is allowed to access the resolved tenant.

Reject missing, invalid, suspended, or unauthorized tenant contexts.

Never silently fall back to a default tenant.

## 4. Tenant-Aware Data Access

Every tenant-owned read and write must enforce tenant scope.

Review:

- Eloquent queries.
- Relationships.
- Query Builder operations.
- Raw SQL.
- Joins.
- Aggregations.
- Search queries.
- Reports.
- Exports.
- Bulk operations.
- Administrative endpoints.

Global scopes may help, but they are not the only security control.

Never rely on a global scope as the sole tenant security boundary.

Use explicit tenant-aware query constraints at critical application boundaries.

Prevent tenant-owned records from being associated with records belonging to another tenant.

Use foreign keys, unique constraints, and composite constraints where supported and appropriate.

## 5. Data Integrity

For shared-schema tenancy:

- Include tenant ownership in relevant unique constraints.
- Prevent cross-tenant foreign-key associations.
- Validate tenant consistency across relationships.
- Define deletion and retention behavior.
- Prevent accidental tenant reassignment.
- Review database constraints, not only application validation.

Do not allow an ordinary update request to change a record's tenant identifier.

Tenant ownership changes require a separate explicitly authorized business operation.

## 6. Authorization

Tenant access and role permission are separate checks.

Verify:

1. Authenticated identity.
2. Active tenant membership.
3. Tenant status.
4. Required capability.
5. Resource tenant ownership.
6. Resource-specific permissions.
7. Applicable business state.

A user who is an administrator in one tenant must not automatically become an administrator in another.

Platform administration and tenant business-data access must remain separate unless explicitly authorized.

Cross-tenant support access requires explicit policy, restricted privileges, and auditability.

## 7. Cache Isolation

Every tenant-dependent cache entry must have a tenant-safe key.

Consider:

- Cache keys.
- Cache tags.
- Cached permissions.
- Cached reports.
- Cached user profiles.
- Cached API responses.
- Locks.
- Rate-limit identifiers.

Never reuse tenant-dependent cached data across tenants.

Include tenant identity in cache keys where the cached value depends on tenant context.

Define invalidation behavior when tenant membership or permissions change.

## 8. Queue and Background Job Isolation

Every tenant-dependent job must carry enough trusted context to identify its tenant.

Before processing:

- Restore tenant context.
- Validate the tenant.
- Establish the correct database connection or scope.
- Process only authorized tenant data.
- Clear tenant context after processing.

Do not assume that a queue worker retains the correct tenant context between jobs.

Protect against context leakage in long-lived workers.

Review:

- Retries.
- Failed jobs.
- Delayed jobs.
- Scheduled tasks.
- Batch processing.
- Unique jobs.
- Job serialization.

A retry must not execute against an unintended tenant.

## 9. File and Storage Isolation

Tenant files must have explicit ownership and access rules.

Consider tenant-specific storage paths or buckets where appropriate.

Verify:

- Upload authorization.
- Download authorization.
- File ownership.
- Tenant ownership.
- Temporary URL scope.
- Signed URL expiration.
- Deletion.
- Retention.
- Exports.
- Backup and restoration.

A file identifier or storage path must not grant access to another tenant's files.

## 10. Search and Reporting Isolation

Tenant isolation must apply to:

- Search indexes.
- Full-text search.
- Analytics.
- Aggregations.
- Dashboard queries.
- CSV and Excel exports.
- Scheduled reports.
- Data warehouse integrations.
- External reporting services.

Apply authorization and tenant scope before aggregation.

Do not expose restricted tenant information through aggregate reports or cached analytics.

Define whether cross-tenant platform reporting is permitted and who may access it.

## 11. Logs and Audit Isolation

Include safe tenant context in relevant operational logs.

Do not expose secrets or unnecessary personal data.

Audit records should identify the tenant where applicable.

Restrict cross-tenant audit access.

Support access must be traceable and auditable.

## 12. Tenant Lifecycle

Define behavior for:

- Tenant creation.
- Tenant activation.
- Tenant suspension.
- Tenant reactivation.
- Tenant deletion.
- Tenant archival.
- Subscription expiration.
- Membership removal.
- Tenant ownership changes.
- Data export.
- Data retention.
- Data restoration.

Never delete tenant data automatically without approved retention and recovery rules.

## 13. Testing Requirements

Every significant multi-tenant feature should include negative isolation tests.

Test scenarios:

- Tenant A cannot read Tenant B records.
- Tenant A cannot update Tenant B records.
- Tenant A cannot delete Tenant B records.
- Tenant A cannot download Tenant B files.
- Tenant A cannot access Tenant B exports.
- Tenant A cannot use Tenant B search results.
- Tenant A cannot access Tenant B cached data.
- Jobs cannot process records under the wrong tenant.
- Reports cannot aggregate unauthorized tenant data.
- Changing a request tenant identifier does not bypass authorization.
- Removed memberships no longer grant access.
- Suspended tenants cannot perform prohibited operations.

Test relationships and nested resources as well as direct endpoints.

## 14. Completion Standard

Do not declare multi-tenancy production-ready based only on tenant_id filtering.

Verify the entire data access lifecycle:

- Authentication.
- Tenant resolution.
- Authorization.
- Persistence.
- Relationships.
- Cache.
- Queues.
- Files.
- Search.
- Reporting.
- Exports.
- Administration.
- Logging.
- Backup and recovery.

Record any unverified isolation boundary as a residual security risk.
