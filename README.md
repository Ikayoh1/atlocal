# atLocal

**Open-source email for private networks.**

atLocal is a standalone, self-hosted internal email platform for organisations. Employees exchange mail over an organisation's LAN or private WAN without public internet access. Default example address: `isaac@bleugates.local`.

> Status: planning / pre-alpha. No production readiness claims.

## Product principles
1. Offline-first: no runtime dependency on internet, external CDNs, hosted authentication, telemetry, or license checks.
2. Independent: no WorkOS dependency; integrations are optional.
3. Standards-based: use an existing mail engine; do not reinvent SMTP/IMAP/JMAP.
4. Secure by default: per-user authorization, TLS, least privilege, backups, auditable administration.
5. Open source: proposed AGPL-3.0 for original code, subject to dependency and legal review.
6. Domain-configurable: default `companyname.local`, with explicit mDNS/DNS compatibility testing.

## Documents
- [Product requirements](docs/PRD.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Design system](docs/DESIGN.md)
- [Security model](docs/SECURITY.md)
- [Implementation plan](docs/IMPLEMENTATION.md)
- [Architecture decisions](docs/DECISIONS.md)
- [Phase 0 review, risks and plan](docs/PHASE0-PLAN.md)
- [Phase 0 spike results](docs/SPIKE-RESULTS.md) (in progress)
- [Spike runbook](spike/README.md)
- [Codex operating instructions](AGENTS.md)

## First acceptance demonstration
With WAN/internet disconnected and LAN operating: admin creates Alice and Bob; Alice sends Bob a message; Bob reads and replies; Alice sees the reply; an unauthorized user cannot access either mailbox; service restart preserves mail.

## Local development
Deployment files and source code will be added incrementally. Do not assume this repository is executable yet. Start with the spike in `docs/IMPLEMENTATION.md`.
