# ChatChi | چت‌چی

A lightweight, secure live chat plugin for WordPress with a standalone, SaaS-style agent panel.

## Requirements

- WordPress 6.0+
- PHP 7.4+

## Development

The plugin is built in phases. Each phase is designed, implemented, tested and approved on its own.

| Phase | Scope | Status |
|---|---|---|
| 0 | Design System + UI/UX Direction ([docs/design-system.md](docs/design-system.md)) | ✅ |
| 1 | Plugin Foundation + Architecture | ✅ |
| 2 | Database Layer | ✅ |
| 3 | User / Visitor Identity | ✅ |
| 4 | Conversation System | ✅ |
| 5 | Message System | ✅ |
| 6 | REST API | ✅ |
| 7 | Frontend Chat Widget | ✅ |
| 8 | Chat Admin Panel (standalone SaaS-style app) | ⏳ |
| 9 | Agent Management | |
| 10 | Welcome Message + Widget Display Rules + Shortcode | |
| 11 | Rate Limiting + Anti-Spam + Security Hardening | |
| 12 | Plugin Settings | |
| 13 | Integration + Testing + Optimization | |

## Architecture

```text
chatchi.php              Bootstrap: constants, requirements check, autoloader, hooks
uninstall.php            Removes all plugin data on delete
includes/                PSR-4 root for the ChatChi\ namespace
├── Plugin.php           Kernel: service container + module boot
├── Conversations/       Conversation service, access policy (Actor), inbox summaries
├── Messages/            Message service, text rules, paging
├── Rest/                chatchi/v1 controllers (widget, agent), presenter, CSRF guard
├── Frontend/            Widget loader, color tokens, interface strings
├── Core/
│   ├── Container.php    Lazy service container
│   ├── Contracts/       Interfaces (Module)
│   ├── Installer.php    Versioned, idempotent, lock-protected install/upgrade
│   ├── InstallNotice.php
│   ├── Activator.php
│   ├── Deactivator.php
│   └── I18n.php
├── Database/
│   ├── Table.php        Table names
│   ├── Schema.php       dbDelta definitions
│   ├── DatabaseException.php
│   └── Repositories/    Prepared-statement-only data access per table
├── Models/              Visitor, Agent, Conversation, Message
└── Visitors/            Identity (cookie token), start-form fields and validation
assets/widget/           Chat widget (vanilla JS in Shadow DOM, CSS)
assets/fonts/vazirmatn/  Bundled Vazirmatn variable font (OFL)
languages/               chatchi.pot + Persian (fa_IR) translation, text domain: chatchi
docs/                    Design and architecture documents
```

### Extension hooks

| Hook | Type | Purpose |
|---|---|---|
| `chatchi_register_services` | action | Register services in the container before modules boot |
| `chatchi_modules` | filter | Add or remove modules |
| `chatchi_loaded` | action | All modules are registered |
| `chatchi_installed` | action | Install/upgrade finished on the current site |
| `chatchi_activated` / `chatchi_deactivated` | action | Plugin lifecycle |
| `chatchi_visitor_cookie_lifetime` | filter | Visitor identity lifetime in seconds (1 hour – 400 days, default 30 days) |
| `chatchi_profile_fields` | filter | Start-form fields: `enabled` / `required` per field |
| `chatchi_normalize_phone` | filter | Accept or reject mobile numbers (default: Iranian mobiles) |
| `chatchi_conversation_started` | action | A visitor opened a new conversation |
| `chatchi_conversation_status_changed` | action | Status moved (`new` → `active` → `closed`) |
| `chatchi_conversation_closed` | action | A conversation was closed, with the actor who closed it |
| `chatchi_pre_send_message` | filter | Return a `WP_Error` to refuse a message before it is stored |
| `chatchi_message_sent` | action | A new message was stored (not fired for retried duplicates) |
| `chatchi_messages_read` | action | Messages were marked as read |
| `chatchi_message_max_length` | filter | Maximum message length in characters (default 2000) |
| `chatchi_widget_enabled` | filter | Whether the widget loads on the current page |
| `chatchi_widget_position` | filter | `left` (default) or `right` |
| `chatchi_primary_color` | filter | Widget primary color; hover, soft and text colors are derived |
| `chatchi_widget_config` | filter | Full widget configuration |

## Database

| Table | Purpose | Key indexes |
|---|---|---|
| `{prefix}chatchi_visitors` | Visitor identity (hashed cookie token) and contact info | `UNIQUE token_hash`, `user_id` |
| `{prefix}chatchi_agents` | Agents, one per WordPress user | `UNIQUE user_id`, `is_active` |
| `{prefix}chatchi_conversations` | Conversation state and latest activity | `UNIQUE open_visitor_id` (one open conversation per visitor), `(visitor_id,status)`, `(status,last_message_at)`, `last_message_at`, `agent_id` |
| `{prefix}chatchi_messages` | Messages and read state | `(conversation_id,id)`, `UNIQUE (conversation_id,client_id)` |

All datetimes are stored in UTC. Relations are enforced in the application layer.

## REST API (`chatchi/v1`)

All responses are JSON with `Cache-Control: no-store`. Errors use the standard WordPress shape `{code, message, data: {status}}`. Message `body` is plain text and must be rendered as text, never as HTML. Dates are ISO 8601 UTC.

### Widget (visitors)

Identified by the HttpOnly `chatchi_visitor` cookie (logged-in users: by account, when `X-WP-Nonce` is sent). Requests from other origins are rejected.

| Method | Route | Purpose |
|---|---|---|
| GET | `/widget/session` | Visitor, open conversation, unread count, start-form config. Never sets a cookie |
| POST | `/widget/conversations` | Start: `name`, `email`, `phone`, `page_url` → visitor + open conversation |
| GET | `/widget/conversations/{id}/messages` | `after_id` (polling), `before_id` (history), `limit` 1–50 |
| POST | `/widget/conversations/{id}/messages` | `body`, optional `client_id` (UUID, makes retries idempotent) |
| POST | `/widget/conversations/{id}/read` | Mark agent messages read, optional `up_to_id` |
| POST | `/widget/conversations/{id}/close` | Close own conversation |

### Agent panel

Logged-in user with `X-WP-Nonce` and an active agent record.

| Method | Route | Purpose |
|---|---|---|
| GET | `/agent/me` | Current agent |
| GET | `/agent/conversations` | Inbox: `status`, `search`, `page`, `per_page` (≤100); `X-WP-Total` headers |
| GET | `/agent/conversations/{id}` | Conversation with visitor, assigned agent, last message, unread count |
| GET/POST | `/agent/conversations/{id}/messages` | Same as widget |
| POST | `/agent/conversations/{id}/read` | Mark visitor messages read |
| POST | `/agent/conversations/{id}/close` | Close |
