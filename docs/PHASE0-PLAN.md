# Phase 0 — Architecture Review, Risks and Spike Plan

Status: **in progress** · Engine facts checked: 2026-11-16 · Scope: Phase 0 only (no webmail UI)

## 1. Verified facts about the candidate engine

Checked against the official sources on 2026-11-16, not from memory. Stalwart changed a lot in v0.16 and 1.x, so older guides and blog posts are out of date.

| Topic | Finding | Source |
|---|---|---|
| Version | **v1.1.1** (released 2026-11-16). v1.0 was the first stable release in late October 2026. v0.16 rebuilt the configuration model. | GitHub releases API; release coverage |
| Pin (Windows) | `stalwart-x86_64-pc-windows-msvc.zip`, SHA-256 `4275B825026E8093EFC998C12B130C95EC7BACE7FC60FE2C24B19FF9E3FC7E14`. This matches the digest in the release's Sigstore bundle. The binary has no Authenticode signature. | Local check |
| Pin (container) | `stalwartlabs/stalwart:v1.1.1@sha256:5ebdf474b237161742fe4309396af4ae8797c938e8e6eb7e2d4837332c4f9851` | Docker Hub API |
| Licence | Dual-licensed: **AGPL-3.0** or the proprietary **Stalwart Enterprise License v2 (SELv2)**. Enterprise-only features include multi-tenancy, SCIM, sharded storage, live telemetry, AI spam, alerts, archiving, metrics and tracing stores. atLocal v1 needs none of these. | README; schema reference |
| Protocols | JMAP (Mail, Submission, Sieve, Calendars, Contacts, Files), IMAP4rev2/rev1, POP3, SMTP, ManageSieve, CalDAV/CardDAV/WebDAV | README |
| Configuration model | `config.json` holds **only the data-store pointer**. Everything else lives in the store and is managed through a **JMAP management API** (`urn:stalwart:jmap`, types prefixed `x:`, schema at `/api/schema`). Headless setup uses **recovery mode** (`STALWART_RECOVERY_MODE=1`, `STALWART_RECOVERY_ADMIN`). | docs: declarative deployments, CLI, schema reference |
| Authentication | OAuth 2.0 authorization code (PKCE S256), device code and refresh grants. Registered `OAuthClient` objects, app passwords, API keys with restricted permissions, TOTP and passkeys. Directory backends: internal, LDAP, OIDC or SQL. | docs: auth/oauth, endpoints, Account object |
| Windows | Native x86_64 build. It has **no Windows service mode** (NSSM is documented) and **stops only on Ctrl-C**. | docs: install/windows |

## 2. Architecture review

### What holds
- The `MailProvider` and `ProvisioningProvider` adapter boundary. JMAP fits both: RFC 8621 for mail, and `urn:stalwart:jmap` for provisioning.
- Storage ownership: the engine owns messages, folders and attachments. The app DB owns identity mapping, settings and audit metadata.
- Internal-only delivery and the "use an engine, don't build one" principle.

### What must change or be made explicit
1. **The engine needs internet by default.** On first start it downloads the WebUI bundle and the spam-filter rules from GitHub. The spam filter also **follows URLs found in message bodies**. Other defaults also reach out: ACME, DNSBL lookups, MX delivery and DMARC/TLS reports. All of these must be turned off or redirected to `file://` resources. The spike's provisioning plan does this. See the offline hardening section in SPIKE-RESULTS.
2. **The default relay policy is unsafe for atLocal.** `MtaStageRcpt.allowRelaying` defaults to `!is_empty(authenticated_as)`, which lets *any authenticated user relay to external domains*. The spike sets it to `false` so external recipients are rejected at RCPT time instead of queued and retried.
3. **Separate the mail domain from service hostnames (proposed ADR-008).** Addresses can stay `alice@companyname.local`. For internal-only delivery, the mail domain never needs to resolve: clients connect to a server hostname and the engine delivers locally. Server hostnames should live in a unicast-safe private zone such as `mail.companyname.internal` (ICANN reserved `.internal` for private use in 2024), which avoids the mDNS conflict entirely. Measured on this Windows 11 host: an unconfigured `mail.companyname.local` took **7.2 s to fail**.
4. **Per-user access (ADR-007) has a concrete path.** atLocal webmail would be a registered OAuth client using authorization code + PKCE. Tokens stay server-side, and logout calls `/auth/revoke`. Stalwart's sign-in page is part of its WebUI bundle, so the bundle must be mirrored offline. **A decision is needed on the identity source of truth** (see section 5).
5. **Least-privilege provisioning (proposed ADR-009).** In production the provisioning service uses a dedicated **API key** with `Replace` permissions limited to the needed `sys*` permissions. The spike uses the recovery admin as a development-only shortcut.
6. **No public CA is reachable offline**, so TLS needs an internal CA plus a documented way to distribute trust to clients. The spike models this with a local development CA.
7. **API churn.** Stalwart went from v0.16 (breaking) to v1.0 to v1.1.1 in a few weeks. To contain this: pin by digest or hash, keep all engine calls in the adapter, run contract tests against the pinned version and document an upgrade procedure.
8. **Windows is for development only.** The engine has no service mode on Windows and stops only on Ctrl-C. Production stays on Linux, per ADR-005.

## 3. Risk register

| ID | Risk | Impact | Mitigation | Status |
|---|---|---|---|---|
| R1 | Engine makes outbound calls (WebUI, spam rules, URL following, DNSBL, ACME, reports) | Offline failures; data leaving the LAN | Hardening plan, egress snapshots, full run with the WAN disconnected | Mitigation coded; **verification pending** |
| R2 | Authenticated users can relay externally by default | External mail leaks or queues | `allowRelaying=false`, plus an SMTP/JMAP relay test | Coded; **test pending** |
| R3 | Unauthenticated SMTP accepts spoofed internal senders | Internal phishing | Production profile exposes authenticated submission only; spoof probes | **Test pending** |
| R4 | `.local` mDNS conflicts and slow failures on clients | Clients can't reach the server | ADR-008: mail domain separated from hostnames; per-OS tests | Windows measured; macOS/Linux pending |
| R5 | Per-user auth relies on Stalwart's sign-in page and WebUI bundle | UX, branding, offline packaging | Mirror `webui.zip` locally; OAuth spike | Pending decision |
| R6 | Rapid engine release cadence and breaking changes | Upgrade breakage | Pin, adapter, contract tests, upgrade runbook | Pinned |
| R7 | Recovery admin left enabled; recovery port binds all interfaces | Admin takeover | Spike-only; production removes the env var and uses a scoped API key | Documented |
| R8 | Supply chain: unsigned Windows binary | Tampered engine | SHA-256 pin, plus full Sigstore verification in Phase 1 | Partial |
| R9 | Backup consistency between app DB and engine store | Unrecoverable restore | Engine `--export`; Phase 4 restore test | Not started |
| R10 | Licensing: AGPL engine + SELv2 features; atLocal AGPL proposal | Legal | Use AGPL features only; legal review before release | Open |

## 4. Phase 0 steps

| # | Step | Status |
|---|---|---|
| 0.1 | Verify engine version, licence, APIs and auth options from primary sources | **Done** |
| 0.2 | Fetch the pinned engine and verify its hash | **Done.** Full Sigstore verification deferred. |
| 0.3 | Spike tooling: JMAP client, provisioner, per-user mailbox, SMTP/IMAP probes, scenario, offline tests | **Done.** 16/16 offline tests pass. |
| 0.4 | Start the engine, discover the real API, provision the domain, users, listeners and hardening | **Blocked:** needs approval to execute `stalwart.exe` on this machine |
| 0.5 | Acceptance scenario (send, receive, reply, authorisation, relay, spoofing, IMAP) and persistence after restart | Blocked on 0.4 |
| 0.6 | Full run with the WAN physically disconnected | Needs operator action |
| 0.7 | Per-user OAuth token flow with the WebUI bundle mirrored offline | After 0.4 and the identity decision |
| 0.8 | Docker Compose environment on Linux, with an internal network for egress isolation | **Blocked:** no Docker or WSL distribution on this host |
| 0.9 | `.local` and `.internal` resolution on macOS and Linux clients | Needs equipment |
| 0.10 | Go/no-go in `SPIKE-RESULTS.md` | After the steps above |

## 5. Decisions requested at the Phase 0 gate
1. **Identity source of truth.** Option A: Stalwart's internal directory holds credentials, and atLocal stores the mapping, roles and profile. Option B: atLocal's MySQL is authoritative, and Stalwart reads it through its SQL directory. Option C: an existing LDAP or Active Directory.
2. **Hostname zone.** Accept ADR-008, with service hostnames under `.internal` and addresses kept on `.local`.
3. **Sign-in UX.** Accept Stalwart's hosted sign-in page through OAuth, or invest in a custom flow.
4. **Spike host.** Install Docker (Linux VM or Docker Desktop with WSL2) so step 0.8 can run, or accept native Windows evidence for Phase 0.
