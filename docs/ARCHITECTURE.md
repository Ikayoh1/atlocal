# Architecture — atLocal

## Logical components
```text
Browser (LAN) --HTTPS--> atLocal Web UI / PHP API
                              |-- Auth / RBAC / directory --> MySQL/MariaDB
                              |-- Mail adapter (per-user auth) --> Mail engine (JMAP/IMAP/SMTP)
                              |                                |--> Mail data + attachments
                              |-- Audit / settings / provisioning jobs
Admin browser --HTTPS-------->|-- Admin API --> Mail engine provisioning interface
Internal DNS --> local service hostnames
Backup agent --> independent backup destination
```

## Proposed stack
- Core PHP (supported current release), Composer, modular MVC-ish services; no large framework required initially.
- MySQL/MariaDB for app identity, mapping, preferences, audit and provisioning state.
- IBM Carbon design patterns with locally bundled CSS/icons/fonts; no CDN.
- Stalwart as **candidate** mail engine, pending version/API/licensing and per-user auth proof of concept. Engine behind `MailProvider` interface to allow replacement.
- Docker Compose for development and supported Linux VM/bare-metal production deployment.

## Key boundaries
`MailProvider`: `listMailboxes`, `listMessages`, `getMessage`, `sendMessage`, `saveDraft`, `setFlags`, `moveMessage`, `searchMessages`, `getAttachment`. Exact method signatures and authentication determined by verified engine capabilities.

`ProvisioningProvider`: create/suspend mailbox, configure aliases/quota, synchronize identity. Admin-only operations must be isolated from user mailbox operations.

## Identity model
App user has immutable internal ID; mail account has immutable engine account ID and configurable address. One-to-one mapping initially; aliases/groups separate. Prefer mail-engine-supported identity integration or delegated user tokens; do not implement broad superadmin impersonation.

## Storage ownership
App DB: users, accounts, aliases, groups, notifications, settings, audit metadata, provisioning jobs. Mail engine: messages, folders, attachments, read state, indexes. No duplicate canonical message storage.

## Network and DNS
- Default example: `alice@companyname.local`.
- `.local` is reserved for mDNS (RFC 6762); DNS behavior varies by client OS and network. Test resolution on each supported OS and VLAN. Offer configurable domain as mitigation.
- Configure internal hostname, name resolution, server certificates and mail domain. Do not assume an internal unicast DNS zone overrides mDNS consistently.
- Block outbound SMTP/public relays; only authenticated internal senders and local recipients.
- All application assets and required dependencies must be installable offline.

## Failure behavior
- Mail engine unavailable: clear error; do not claim message was sent.
- Database unavailable: reject authentication/administrative operations safely.
- Duplicate send retries: implement client/server idempotency where supported; do not silently duplicate mail.
- Disk near full: alert and reject writes safely; retain recoverable existing messages.
- Backups must cover both app metadata and engine storage consistently.

## Deployment separation
Prefer separate application and mail-engine services/VMs with independent persistent volumes, even if colocated initially. Never expose mail administration APIs to ordinary employee networks.
