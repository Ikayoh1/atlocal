# Implementation Plan — atLocal

## Phase 0 — Architecture and mail engine spike (first task)
- Record pinned candidate engine version, license/edition, protocol support, administration and authentication capabilities.
- Produce Docker Compose LAN-only test environment with persistent storage, no external runtime dependency, and locally available images/packages.
- Configure `companyname.local` domain, Alice/Bob test accounts, internal-only routing and mail submission.
- Demonstrate offline send/receive/reply and persistence after restart.
- Verify a safe user-scoped API access pattern (JMAP preferred if supported by chosen edition).
- Test Windows/macOS/Linux `.local` resolution where equipment is available; record limitations.
- Deliver `docs/SPIKE-RESULTS.md` with exact commands, results, open risks and go/no-go decision.

**Exit gate:** Do not start full webmail until mail engine identity and message APIs work securely without internet.

## Phase 1 — Repository and application skeleton
- Composer project, PHP routing, local Carbon asset bundle, Docker/deployment structure.
- Environment config and validation, health endpoints, logging, tests, CI plan with offline-capable builds.
- MySQL migrations for users, mail accounts, aliases, groups, settings, audit and jobs.

## Phase 2 — Identity and administration
- Local authentication and secure sessions; role checks.
- Provisioning adapter, account creation/suspension, quotas, directory.
- Per-user mail access tokens/identity integration; negative authorization tests.

## Phase 3 — Mail vertical slice
- Inbox/list, view, compose/send, reply, Sent, Drafts, Trash, flags.
- Attachments, search, recipient autocomplete, error and retry handling.
- Integrate engine through adapter; no direct DB access to mail store.

## Phase 4 — Operational completeness
- Groups, notifications, admin health, quotas, audit events.
- Backup/restore scripts and disaster recovery runbook.
- Upgrade/rollback procedure, offline image/package import, monitoring.

## Phase 5 — Pilot and v1 release
- 20–30 users across departments, then scale toward 150–500 with load testing.
- Test internet outage, LAN/VLAN reachability, certificate renewal, server restart, disk full, restore.
- Publish operator guide, install guide, user guide, release notes, license inventory and security policy.

## First Codex prompt
> Read AGENTS.md and all documents under docs/. Implement Phase 0 only. First propose a detailed spike plan and list assumptions. Verify Stalwart's actual current APIs, identity options, edition and license before choosing integration methods. Build a reproducible internal-only mail proof of concept using pinned dependencies, local persistent storage and safe sample credentials. Demonstrate send/receive/reply with no internet, verify external relay rejection, test message persistence after restart, and document exact steps and limitations in docs/SPIKE-RESULTS.md. Do not build the full UI or invent unverified API endpoints. Stop and request approval at the Phase 0 exit gate.
