# Security and Privacy Baseline — atLocal

## Threat model
Threats: unauthorized mailbox access, credential theft, admin misuse, malicious attachments, spoofed internal senders, SMTP relay abuse, compromised LAN device, backup theft, ransomware, denial of service and accidental data loss.

## Required controls
- Authenticated sessions with secure/HttpOnly/SameSite cookies, session rotation, idle timeout, CSRF protection.
- Enforce per-user mailbox authorization on **every** read/write/search/attachment operation; no trusting client-supplied mailbox IDs.
- Strong password hashing (Argon2id where appropriate), rate limiting, lockout/alert policy, optional local MFA later.
- TLS on web/API and mail protocols; certificate provisioning for offline clients documented.
- Mail engine admin credentials confined to server-side provisioning service, not exposed to browsers.
- Restrict recipients to approved local domains/accounts; deny relay and external delivery by default.
- Sanitize rendered HTML mail, block active content, avoid remote resource loads and tracking pixels.
- Restrict attachment size/types, store safely, scan where feasible using offline-updated tools; never execute uploads.
- Least-privilege DB credentials, prepared SQL, output escaping, validated input and security headers.
- Avoid message body/attachment content in app logs. Audit admin actions with identity, action, time, target and outcome.
- Encrypt backups, separate credentials, restrict access and test restore procedures.
- Keep secrets in environment/secret files outside version control; rotate credentials and provide incident response guidance.

## Security acceptance tests
- Horizontal privilege escalation attempts against all mailbox endpoints.
- Session fixation, CSRF and brute-force tests.
- HTML email XSS and remote image tests.
- External relay rejection and sender spoofing tests.
- Attachment traversal, malicious content and quota exhaustion tests.
- Recovery from stolen/expired credentials and failed restore tests.

## Release gate
No production deployment without security review, documented admin procedures, tested backups, and dependency/license inventory.
