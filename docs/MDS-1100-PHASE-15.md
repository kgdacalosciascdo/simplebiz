# MDS-1100 Settings & Administration - Phase 15 Closure

Status: `MDS-1100 FULLY IMPLEMENTED`

This phase completes the documented SimpleBIZ Free-edition Settings & Administration scope from `MDS-1100 Settings & Administration Module Design Specification v1.1`. It adds the administrative workspace, account and preference surfaces, company configuration lifecycle, session and audit controls, safe administration export, and protected ownership transfer. It does not create a second authentication system, a second master registry, a second transaction ledger, a second report engine, or a local substitute for Commerce, Usage, external notification delivery, SSO, SCIM, or integrations.

## Scope and authority

MDS-1100 owns the administrative presentation and configuration contracts. The following ownership boundaries remain explicit:

- MDS-000/Core owns credentials, Sanctum sessions, company context, membership authorization, idempotency, audit recording, and the existing setup guard.
- MDS-1000 owns currencies, branches, tax references, document references, business partners, products, categories, units, and registry lifecycle rules.
- MDS-200 through MDS-800 own transaction workflows, operational numbering use, balances, and source effects.
- MDS-900 owns reports, analytics, export scheduling, and report output contracts.
- Commerce owns commercial subscription, billing, entitlement, and usage facts when those services are configured.
- External notification and integration providers own delivery, provider credentials, OAuth, webhooks, SSO, SCIM, and external synchronization.

The repository contains the MDS-1100 specification but not the separately named `UID-000`, `WF-SET-001`, `WF-ACC-001`, or `UI-SET-001` companion documents referenced by it. Where those identifiers are referenced, this implementation follows the MDS-1100 authority, existing Core contracts, and the documented Free-edition boundary.

## Closure matrix

| Specification area | Free-edition result | Backend evidence | UI evidence | Status |
| --- | --- | --- | --- | --- |
| 5.1 Settings workspace | Live permission-filtered catalog, search, plan state, setup progress, attention items, recent activity, and safe destinations | `GET /api/v1/settings/workspace`, `SettingsService::workspace` | `SettingsWorkspace.tsx` | IMPLEMENTED |
| 5.2 Company profile | Identity, legal/contact metadata, branding references, localization, fiscal month, format defaults, document/module/notification defaults, optimistic version, and immutable change history | Existing company profile endpoint extended; `configuration_change_sets`; `CompanyProfileController` | `CompanyProfilePage.tsx` | IMPLEMENTED |
| 5.3 Users and access | Existing scoped invitations/status/roles plus history, session-safe access presentation, and ownership safeguards | `UserAccessService`, user history route, settings access endpoint | Existing `UsersAccessPage`, Account access panel | IMPLEMENTED |
| 5.4 Roles and permissions | Protected predefined roles and server-side permission checks remain authoritative; custom role authoring is outside Free | Existing Core role/permission tables plus settings permission seeds | Access and module panels disclose the boundary | IMPLEMENTED / CUSTOM EXCLUDED |
| 5.5 Account and subscription | Profile, preferences, company access, edition state, security handoff, and honest unavailable Commerce/Usage state | `GET /settings/account`, profile/preferences endpoints | `AccountSubscriptionPage.tsx` | IMPLEMENTED |
| 5.6 Numbering | Standard numbering is presented through company document defaults and remains enforced by source transaction modules; no duplicate settings-owned numbering engine | Company document preferences and published configuration contract | Financial & Documents panel | IMPLEMENTED / SOURCE-OWNED |
| 5.7 Financial, tax, and documents | Company currency, locale, fiscal period, format, paper, tax-reference metadata, and effective source/version are available; MDS-1000 remains master owner | Company profile/configuration endpoints and MDS-1000 references | Company Profile and Configuration pages | IMPLEMENTED |
| 5.8 Workflow and approvals | Basic Free state and discovery boundary are shown; advanced approval designer/configuration is not fabricated | Module catalog and configuration `approval_result` contract | Modules & Workflow panel | IMPLEMENTED / ADVANCED EXCLUDED |
| 5.9 Notifications | User preferences, mandatory in-app notices, and delivery-provider state are available; external email/SMS/push are not claimed | Notification endpoints and persisted user preferences | Notifications panel | IMPLEMENTED / EXTERNAL DELIVERY UNAVAILABLE |
| 5.10 Modules and preferences | Edition-aware module catalog, source owner, enabled state, and safe-disable contract are available | `GET /settings/modules` | Modules & Workflow panel | IMPLEMENTED |
| 5.11 Data and exports | Scoped administrative export contains safe settings/access/change/audit data, expiry, download, and audit evidence; operational/report exports remain source-owned | `admin_export_requests`, create/show/download endpoints | Data & Integrations panel | IMPLEMENTED |
| 5.12 Integrations | Honest discovery-only state; no provider credentials or fake connection status | Connected products and integration boundary response | Data & Integrations panel | IMPLEMENTED / PROVIDER UNAVAILABLE |
| 5.13 Security and audit | Core-owned sessions, session termination, administrative audit search/pagination, immutable change history, and protected ownership transfer | Sessions, audit, change-set, ownership-transfer endpoints | Security & Audit page and account panel | IMPLEMENTED |

## Data and migration changes

Migration `2026_08_15_000053_complete_settings_phase15` is forward-only and applied locally as migration batch 29. It adds:

- Settings metadata to `companies`: branding, tax-registration references, date/number/paper defaults, document/module/notification defaults, settings version, and update timestamp.
- `user_preferences`, scoped by user and company, for personal locale, timezone, display, accessibility, notification, paper/format, and preferred branch preferences.
- `configuration_change_sets` for validated, published, superseded, versioned company configuration with previous/effective values, source, reason, correlation, and publication evidence.
- `company_ownership_transfers` for protected two-party ownership transfer lifecycle.
- `admin_export_requests` for scoped, expiring administrative export packages.
- Settings permission keys attached to existing administrator roles. No passwords, access tokens, MFA secrets, payment credentials, or provider secrets are stored in these tables.

New model/service components are `UserPreference`, `ConfigurationChangeSet`, `CompanyOwnershipTransfer`, `AdminExportRequest`, and `SettingsService`. Existing `Company`, `User`, `CompanyProfileController`, `UserAccessService`, `CompanyController`, `AuthController`, and setup permission mapping were extended without changing business transaction ownership.

## API surface

The active-company scoped API now provides:

```text
GET    /api/v1/settings/workspace
GET    /api/v1/settings/account
PATCH  /api/v1/settings/account/profile
PATCH  /api/v1/settings/account/preferences
GET    /api/v1/settings/access
GET    /api/v1/settings/sessions
DELETE /api/v1/settings/sessions/{token}
POST   /api/v1/settings/sessions/terminate-others
GET    /api/v1/settings/configuration
GET    /api/v1/settings/configuration/changes
GET    /api/v1/settings/modules
GET    /api/v1/settings/notifications
PATCH  /api/v1/settings/notifications
GET    /api/v1/settings/audit
GET    /api/v1/settings/exports
POST   /api/v1/settings/exports
GET    /api/v1/settings/exports/{export}
GET    /api/v1/settings/exports/{export}/download
POST   /api/v1/settings/ownership-transfers
POST   /api/v1/settings/ownership-transfers/{transfer}/accept
GET    /api/v1/settings/users/{user}/history
```

Existing `/api/v1/settings/company` remains the company profile contract and now publishes a versioned configuration change set. Optimistic `profile_version` checks return a conflict instead of silently overwriting a newer configuration. Idempotency keys remain required for mutating administrative actions where the existing API convention requires them.

## Effective configuration and lifecycle

The effective configuration response reports each value with its source and company settings version. The documented precedence is:

`platform default -> edition -> company -> branch/module/document -> role/user preference`

Company profile changes are validated, written atomically, audited, published as a change set, and supersede the prior published change. Approval is `not_required` for the Free company-profile path. Advanced approval workflow remains a discovery boundary. User preferences never mutate company master data.

## Security and privacy controls

- All settings routes require authentication and active company context, then apply the existing company-scoped permission middleware and service checks.
- Session responses include metadata only; raw Sanctum token values are never returned. A user can terminate an individual own session or all other own sessions.
- Owner-sensitive transfer requires current-owner password and exact confirmation, then receiving-owner password and exact acceptance. The transfer is two-party, time-limited, atomic, audited, and changes protected role assignment safely.
- Administrative exports are company-scoped, purpose-bound, audited, JSON packages, and expire after one day. They contain administration data only and do not duplicate transaction/report exports.
- Profile responses exclude setup origin IP/device metadata and other sensitive registration internals.
- Ownership, security, billing, legal, and data-lifecycle notices remain mandatory in-app preferences. External delivery is explicitly unavailable until an authoritative provider is configured.

## Frontend implementation

The settings routes now render:

- `SettingsWorkspace.tsx`: live catalog, search, Free plan state, setup progress, needs-attention list, and recent activity.
- `CompanyProfilePage.tsx`: editable company defaults with select options, version/reason handling, and safe publishing feedback.
- `AccountSubscriptionPage.tsx`: profile, preferences, access, plan/usage/billing source states, connected products, and session controls.
- `SecurityAuditPage.tsx`: session termination and searchable administrative audit history.
- `SettingsOperationsPage.tsx`: configuration/change history, module source ownership, notification preferences, scoped administrative export, and integration discovery state.

The existing user/access route remains available and now has a user history API. The frontend uses the existing React, Lucide, TanStack, and responsive styling conventions. No transaction screen or user-facing business workflow was changed by this phase.

## Validation evidence

- Migration status: all migrations through `2026_08_15_000053_complete_settings_phase15` are `Ran`.
- Focused `SettingsPhase15Test`: **4 passed, 36 assertions**.
- Full `php artisan test`: **101 passed, 1 intentionally skipped PostgreSQL-only schema test, 1,000 assertions**.
- `vendor/bin/pint --test`: passed.
- `npm run lint`: passed.
- `npm run test -- --run`: **18 passed across 10 files**.
- `npm run build`: passed; TypeScript and Vite production build completed successfully.
- `git diff --check`: passed; Git only reported existing LF-to-CRLF normalization warnings.
- Docker client is installed but the Docker Desktop Linux daemon is unavailable in this environment, so image/runtime validation could not run.
- Render CLI is not installed, so Blueprint validation could not run locally.

## Completion gate

`MDS-1100 FULLY IMPLEMENTED`

This completion statement is bounded to the documented SimpleBIZ Free-edition scope. Custom roles, advanced approval policies, commercial billing/usage, MFA delivery, SSO/SCIM, legal hold, external integrations, and provider-backed notification delivery remain explicitly outside this repository's current authority.

The next recommended phase is:

`Phase 16 - MDS-000 Core + whole-system final acceptance`

Phase 16 should consolidate cross-module acceptance, deployment/runtime validation, security review, permission matrix verification, and final documentation. No MDS-000 closure work is included in Phase 15.
