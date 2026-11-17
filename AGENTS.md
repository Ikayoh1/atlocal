# AGENTS.md — Instructions for Codex

You are implementing **atLocal**, an independent open-source internal email system. Read `README.md`, `docs/PRD.md`, `docs/ARCHITECTURE.md`, `docs/SECURITY.md`, `docs/DESIGN.md`, `docs/IMPLEMENTATION.md`, and `docs/DECISIONS.md` before editing.

## Non-negotiable rules
- Do not implement an SMTP server, message delivery engine, or mail storage protocol from scratch. Integrate a maintained standards-based engine behind an adapter.
- Do not make WorkOS a runtime, database, authentication, or deployment dependency.
- Do not require internet, external fonts/CDNs, hosted APIs, online activation, or cloud telemetry at runtime.
- Preserve configurable email domains, with `.local` as the initial default; document and test mDNS conflicts rather than assuming DNS behavior.
- Never use a mail-server superadmin credential to fetch ordinary users' mail without verifiable per-user authorization and isolation.
- Keep secrets out of source control, logs, screenshots and fixtures. Supply `.env.example`, never real credentials.
- Avoid speculative mail engine API calls. Verify actual supported API, edition and version in a reproducible integration spike.
- Keep PHP code modular, typed where possible, with prepared statements, CSRF protection, output escaping and secure sessions.
- Mail messages and attachments belong in the mail engine; app DB stores identities, mappings, settings and non-content audit metadata.
- Every feature requires tests, documentation, migration/rollback considerations and offline validation.
- Do not claim production-ready without documented restore, security, load and LAN-only tests.

## Workflow
1. Explain proposed changes and files touched.
2. Implement the smallest working vertical slice.
3. Add automated tests and execute them where tooling exists.
4. Report commands executed, test outcomes, risks, and remaining TODOs.
5. Seek approval before changing architecture, adding external dependencies, or implementing destructive migrations.

## Definition of done
A feature is done only when functionality, authorization, failure behavior, automated tests, user-visible errors, docs and offline operation are addressed.

## Initial Codex assignment
Follow `docs/IMPLEMENTATION.md` Phase 0 only. Produce a runnable LAN-only mail engine proof of concept and documented API/identity findings before developing a full UI.
