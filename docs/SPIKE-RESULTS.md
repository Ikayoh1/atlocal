# Phase 0 Spike Results

Status: **IN PROGRESS. No go/no-go decision yet.** Last updated: 2026-11-16.

This file records only what was actually run. Results that are pending are labelled as pending.

## Environment
- Host: Windows 11 Pro (10.0.22000), PHP 8.3.31 (curl, openssl, pdo_mysql), OpenSSL 3.0.20.
- No Docker, WSL distribution, Composer or git on the host. The spike therefore uses the native Windows engine build. The Docker Compose step is open (see PHASE0-PLAN, step 0.8).
- Engine: Stalwart **v1.1.1**. Pins and sources are in [PHASE0-PLAN.md](PHASE0-PLAN.md#1-verified-facts-about-the-candidate-engine).

## Executed so far

| Command | Result |
|---|---|
| `curl` download of `stalwart-x86_64-pc-windows-msvc.zip` v1.1.1 + `.sigstore.json` | OK, 56,968,267 bytes |
| `Get-FileHash -Algorithm SHA256` | `4275B825…3FC7E14`, equal to `messageSignature.messageDigest` in the Sigstore bundle (Rekor logIndex 3924704740) |
| `Get-AuthenticodeSignature stalwart.exe` | `NotSigned` |
| `Resolve-DnsName mail.companyname.local` (no DNS or hosts entry) | Failed after **7,240 ms**; `companyname.local` failed after 2,345 ms |
| `php -l` on all spike PHP files | 0 errors |
| `php spike/php/tests/run.php` | **16/16 passed**. Two defects were found and fixed first: a duplicate certificate on re-apply, and EC key generation unsupported on this PHP/OpenSSL build (switched to RSA-3072). |
| `php spike/php/bin/init.php` | Random secrets, dev CA and server certificate, and engine `config.json` created under `var/` (git-ignored) |
| PowerShell parser check of `spike/windows/*.ps1` | 0 parse errors |
| **Executing `stalwart.exe`** | **Not run.** The local permission policy blocked executing the downloaded binary. Awaiting the operator's approval. |

## Offline hardening applied by the provisioning plan
These settings are coded in `StalwartProvisioner::hardenForIsolatedLan()` and the domain and listener setup, and unit-tested against a fake engine. They have **not yet been confirmed on the real engine**.

| Engine default | Why it matters | Spike setting |
|---|---|---|
| `MtaStageRcpt.allowRelaying = !is_empty(authenticated_as)` | Any logged-in user can relay to external domains | `{"else":"false"}` |
| `SpamSettings.spamFilterRulesUrl` = GitHub; spam filter follows body URLs | Outbound HTTP at startup and for each message | `enable=false`, `spamFilterRulesUrl=null` |
| `Application` (WebUI) `resourceUrl` = GitHub | Download at first start | `enable=false`. Phase 0.7 will use a `file://` mirror instead. |
| Domain: ACME, DKIM and DNS automation, DMARC/TLS-RPT reports | Needs a public CA, DNS and outbound mail | Manual, Manual, Manual, `reportAddressUri=null` |
| Default listeners (25/465/993/443 on all interfaces) | Exposure beyond the intended LAN | Explicit listeners on `bindIp` only, all with TLS |
| Engine process environment | Any HTTP library egress | `HTTP(S)_PROXY=http://127.0.0.1:9`. This is a best-effort guard only and does not stop DNS or raw TCP. |

## Pending checks (`spike/windows/run-spike.ps1`)
- Recovery-mode discovery: real `/api/schema`, JMAP session capabilities, OAuth metadata.
- Provisioning on the real engine, run twice to confirm idempotency.
- Acceptance: Alice→Bob, Bob reads and replies, Alice sees the threaded reply. Wrong-password rejection over JMAP and IMAP.
- Authorisation: cross-account `Email/query`, `Mailbox/get` and `Email/get`; blob download; user calling management methods; JMAP `From:` spoofing.
- Routing: external recipient via JMAP and SMTP AUTH; unauthenticated relay; unknown local recipient; unauthenticated local-sender spoofing; authenticated `MAIL FROM` spoofing.
- Persistence after a graceful restart, plus a new send after restart.
- Egress snapshots after start, after the scenario and after restart.
- With the WAN unplugged: repeat the full run.

## Open risks
See the [risk register](PHASE0-PLAN.md#3-risk-register).

## Go/no-go
Not decided. The criteria: every PASS above holds on the real engine, no FAIL remains, every FINDING has an accepted mitigation, the egress run with the WAN disconnected is clean, and the identity decision has been made.
