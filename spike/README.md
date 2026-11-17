# Phase 0 spike: LAN-only mail exchange

This is throwaway evidence-gathering code, not the atLocal application. It shows two employees, `alice@companyname.local` and `bob@companyname.local`, exchanging mail through a pinned Stalwart engine with no internet dependency. The `MailProvider` and `ProvisioningProvider` adapters in Phase 1 will be built from `php/src/`.

## Layout
| Path | Purpose |
|---|---|
| `spike.config.json` | Non-secret settings: engine pin, mail domain, hostname, bind IP, ports |
| `php/src/Jmap` | RFC 8620 client. Requests are pinned to the configured base URL and never follow redirects. |
| `php/src/Engine/StalwartProvisioner.php` | Idempotent provisioning and offline hardening through the management JMAP API (`x:` types) |
| `php/src/Mail/UserMailbox.php` | Per-user JMAP mail: send, list, get, reply. The account ID always comes from the user's own session. |
| `php/src/Probe` | Raw SMTP and IMAP clients for relay, spoofing and interoperability tests |
| `php/bin/*.php` | `init`, `discover`, `provision`, `scenario run`, `scenario verify-persistence` |
| `php/tests/run.php` | Offline unit tests (no engine needed) |
| `windows/*.ps1` | Fetch and verify the engine, start, stop (graceful Ctrl-C), egress snapshot, full run |

Generated material, including secrets, the dev CA, engine data, logs and results, goes to `var/`, which git ignores. The scripts never print passwords.

## Run (Windows, native engine)
```powershell
php spike\php\tests\run.php                 # offline unit tests
powershell -ExecutionPolicy Bypass -File spike\windows\fetch-engine.ps1   # pinned download + SHA-256 check
powershell -ExecutionPolicy Bypass -File spike\windows\run-spike.ps1      # full Phase 0 run
```
Results are written to `var\spike\results-run.json`, `results-verify-persistence.json`, `discovery-*.json`, `engine-settings-*.json`, `egress-*.json` and `run-*.log`.

If Windows Firewall asks about `stalwart.exe` during recovery mode, choose **Cancel**. That mode binds all interfaces, as documented, and the spike only needs loopback.

### Offline proof
1. Run `fetch-engine.ps1` while connected, or copy `var\engine\` across on removable media and pass `-OfflineArchive`.
2. Disconnect the WAN uplink and keep the LAN or loopback up.
3. Delete `var\stalwart\data` and `var\spike` for a clean store, then run `run-spike.ps1`.
4. Attach the `egress-*.json` files and the transcript to `docs/SPIKE-RESULTS.md`.

## Using a second machine on the LAN
Set `bindIp` in `spike.config.json` to this host's LAN address. Re-run `run-spike.ps1`; provisioning adds the new listeners and warns about the old ones (add `--prune-listeners` to `provision.php` to remove them). Clients must trust `var\certs\atlocal-dev-ca.pem` and resolve `mail.companyname.local`, or a hostname under the proposed `.internal` zone, to that address.

## Spike-only shortcuts (not production patterns)
- The recovery admin (`STALWART_RECOVERY_ADMIN`) is set on every start. Production would use a scoped API key (proposed ADR-009).
- A development CA with keys on local disk. Production needs the organisation's own CA process.
- An unauthenticated SMTP listener on port 2525, used only to probe relay and spoofing behaviour.
- Tests use HTTP Basic auth for each user. Webmail will use OAuth tokens (step 0.7).
