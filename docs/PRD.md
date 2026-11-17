# Product Requirements Document — atLocal

Version: 0.1 (draft) | Status: pre-alpha

## Problem
Organisations need employee-to-employee email that remains available on their own network during internet outages, without subscriptions to public hosted mail services.

## Target users
Employees; department managers; IT/mail administrators; optional external integrators. Initial target: 50–500 accounts per organisation; scale targets require measurement.

## Product boundaries
- Standalone application and deployment.
- Mail is internal-only: no internet delivery, public inbound MX, or external relay in v1.
- An active LAN/private WAN connection to the server is required. Offline does **not** mean disconnected clients can exchange messages.
- WorkOS integration is optional and deferred until the standalone core works.

## Functional requirements
| ID | Requirement | Priority |
|---|---|---|
| F01 | Create, disable and manage users/mailboxes | Must |
| F02 | Log in securely with per-user mailbox isolation | Must |
| F03 | Compose, send, receive, reply and forward internal messages | Must |
| F04 | Inbox, Sent, Drafts, Trash and read/unread states | Must |
| F05 | Attachments with configurable size limits | Must |
| F06 | Search mailbox messages | Must |
| F07 | Internal staff directory and recipient autocomplete | Must |
| F08 | Configurable `.local` organisation domain and aliases | Must |
| F09 | Mailbox quota and basic admin settings | Must |
| F10 | LAN-only operation without internet | Must |
| F11 | Administrative audit events without logging mail content | Must |
| F12 | Backup and documented restoration | Must |
| F13 | Departmental distribution groups | Should |
| F14 | Web UI notifications | Should |
| F15 | Shared mailboxes, calendar and mobile offline cache | Later |

## Non-functional requirements
- Secure access control and explicit mail account mapping.
- No external runtime calls or CDNs.
- Recoverable persistent storage; documented RPO/RTO to be agreed with each client.
- Accessibility: keyboard support, readable contrast and responsive design.
- Observability: health checks, structured logs, disk/quota monitoring.
- Versioned, repeatable deployment and migrations.

## Acceptance scenarios
1. On a network with no internet route, two users exchange and reply to mail.
2. Invalid external-domain recipients are rejected with a clear message; no public relay attempt occurs.
3. User A cannot read User B's messages by manipulating API identifiers.
4. Restarting services preserves sent/received messages and attachments.
5. A backup can restore an account and its messages in a fresh test deployment.
6. Windows, macOS and Linux clients resolve configured `.local` services on target VLANs; limitations documented.

## Out of scope for v1
Public email federation; Microsoft 365 migration; calendar sync; internet delivery; mobile native apps; end-to-end encryption; cross-site active-active clustering.

## Open questions
Actual client OS mix, identity source, storage quotas, backup policy, supported browsers, target hardware, desired distribution list semantics, and regulatory requirements.
