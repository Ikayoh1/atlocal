# Architecture Decision Records (initial)

## ADR-001 — Standalone product
**Accepted.** atLocal must run independently of WorkOS; integrations optional.

## ADR-002 — Internal-only email
**Accepted.** No internet email federation in v1. Mail must function over available private network without WAN internet.

## ADR-003 — Default `.local` addresses
**Accepted with known risk.** Default addresses use `username@companyname.local`. `.local` is reserved for mDNS; test multi-OS/VLAN name resolution and permit configurable domain alternatives. Do not promise universal unicast DNS resolution.

## ADR-004 — Existing mail engine
**Provisional.** Evaluate Stalwart first; pin version, review AGPL/edition terms and verify APIs/identity. Keep adapter boundary.

## ADR-005 — Technology stack
**Provisional.** Core PHP, MySQL/MariaDB, locally packaged IBM Carbon-inspired frontend; Docker Compose for dev, Linux on-prem for production.

## ADR-006 — Open-source licensing
**Proposed, not legally finalized.** AGPL-3.0 for atLocal original code; review third-party licenses, redistribution obligations and trademark policy before publishing.

## ADR-007 — Authentication
**Open.** Must provide true per-user mailbox isolation. Validate engine-compatible delegated/user auth before committing to API implementation.
*Phase 0 note (2026-11-16):* Stalwart 1.x supports OAuth 2.0 authorization code with PKCE S256 for registered `OAuthClient`s, plus refresh, revocation and introspection. The proposed direction: atLocal webmail is a registered client, tokens stay server-side, and no admin impersonation is used. This depends on the identity source-of-truth decision in PHASE0-PLAN section 5, and on mirroring the WebUI bundle that serves the sign-in page offline.

## ADR-004 addendum — pinned candidate (2026-11-16)
Stalwart **v1.1.1** is pinned for Phase 0, by SHA-256 for the Windows build and by digest for the container image (see PHASE0-PLAN section 1). It is dual-licensed AGPL-3.0 / SELv2; atLocal uses AGPL features only. Status remains **Provisional** until SPIKE-RESULTS shows a go decision.

## ADR-008 — Mail domain separate from service hostnames
**Proposed (Phase 0).** Email addresses may use `companyname.local`, which never has to resolve because delivery is local. Server hostnames should use a unicast-safe private zone, for example `mail.companyname.internal`, which avoids mDNS conflicts and slow client fallbacks (7.2 s measured on Windows 11). Both stay configurable.

## ADR-009 — Provisioning via engine management API with least privilege
**Proposed (Phase 0).** `ProvisioningProvider` calls Stalwart's management JMAP API (`urn:stalwart:jmap`) using a dedicated API key whose permissions are replaced with the minimal `sys*` set. It never uses the recovery admin, never connects from browser-facing code, and is never used for mailbox content access.
