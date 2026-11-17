# UI / UX Design Specification — atLocal

## Identity
Product: **atLocal**. Positioning: *Open-source email for private networks.* Interface tone: calm, clear, functional. Use IBM Carbon-inspired layout and interaction patterns, subject to applicable licenses. Do not imply IBM endorsement.

## Design principles
- Function first: fast inbox, keyboard-friendly navigation, low visual noise.
- Local assets only: no Google Fonts, external scripts, CDN CSS or remote icons.
- Responsive: desktop first, usable on tablets/mobile LAN browsers.
- Accessible: semantic HTML, focus states, labels, contrast, error feedback.

## Core screens
1. Sign-in: organisation identity, username/password, clear LAN-only availability messaging.
2. Inbox: left folders/navigation, message list with sender/subject/date/unread, search, reading pane.
3. Compose: To, Cc (optional), subject, plain-text and sanitized rich-text, attachments, send/save draft.
4. Thread detail: chronological conversation, safe rendering, download attachments, reply/forward.
5. Directory: search employees and departmental groups.
6. Settings: signature, display preferences, mailbox usage.
7. Admin: users, aliases, groups, quotas, service health, audit events.

## States
Loading, empty inbox, empty search, unauthorized, attachment rejected, quota exceeded, server unavailable, send pending, send confirmed, retry-safe error.

## UI components
Top bar, sidebar, data table/message list, inline notifications, dialogs, form validation, pagination, keyboard shortcuts (later). Never use color as the only indicator of read/unread or error.

## Initial design deliverables
Static layouts and functional HTML/CSS prototypes for inbox, compose, thread and admin; validate keyboard and mobile flows before extensive styling.
