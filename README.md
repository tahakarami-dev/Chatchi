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
| 8 | Chat Admin Panel (standalone SaaS-style app) | ✅ |
| 9 | Agent Management | ✅ |
| 10 | Welcome Message + Widget Display Rules + Shortcode | ✅ |
| 11 | Rate Limiting + Anti-Spam + Security Hardening | ✅ |
| 12 | Plugin Settings | ✅ |
| 13 | Integration + Testing + Optimization | ⏳ |

## Architecture

```text
chatchi.php              Bootstrap: constants, requirements check, autoloader, hooks
uninstall.php            On delete: removes temporary data; removes everything only if Settings → General says so
includes/                PSR-4 root for the ChatChi\ namespace
├── Plugin.php           Kernel: service container + module boot
├── Agents/              Agent management service, avatar uploads
├── Conversations/       Conversation service, access policy (Actor), inbox summaries
├── Messages/            Message service, text rules, paging
├── Rest/                chatchi/v1 controllers (widget, agent, agent management, settings), presenter, CSRF guard
├── Frontend/            Widget loader, display rules, [chatchi] shortcode, color tokens, font face, interface strings
├── Admin/               Standalone agent panel page, access capability, menu entry
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
├── Security/            Rate limiter, visitor IP detection, bot checks for the start form
├── Settings/            Settings store (one autoloaded option), validation, content lookup for display rules
├── Support/             Small shared helpers (plain-text cleaning)
└── Visitors/            Identity (cookie token), start-form fields and validation
assets/shared/           Core JS (DOM, API client, dates) and design tokens shared by widget and panel
assets/widget/           Chat widget (vanilla JS in Shadow DOM, CSS)
assets/admin/            Agent panel single-page app
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
| `chatchi_visitor_cookie_lifetime` | filter | Visitor identity lifetime in seconds (1 hour – 400 days); receives the days set in Settings (default 30) |
| `chatchi_profile_fields` | filter | Start-form fields: `enabled` / `required` per field; receives the Settings choice |
| `chatchi_normalize_phone` | filter | Accept or reject mobile numbers (default: Iranian mobiles) |
| `chatchi_conversation_started` | action | A visitor opened a new conversation |
| `chatchi_conversation_status_changed` | action | Status moved (`new` → `active` → `closed`) |
| `chatchi_conversation_closed` | action | A conversation was closed, with the actor who closed it |
| `chatchi_pre_send_message` | filter | Return a `WP_Error` to refuse a message before it is stored |
| `chatchi_message_sent` | action | A new message was stored (not fired for retried duplicates) |
| `chatchi_messages_read` | action | Messages were marked as read |
| `chatchi_message_max_length` | filter | Maximum message length in characters; receives the Settings value (default 2000) |
| `chatchi_widget_enabled` | filter | Final say on whether the widget loads on the current page; receives the master switch + display-rules/shortcode decision |
| `chatchi_widget_position` | filter | `left` or `right`; receives the corner chosen in Settings (default `left`) |
| `chatchi_primary_color` | filter | Primary color of the widget and panel; receives the color chosen in Settings. Hover, soft and text colors are derived (text switches to dark below 4.5:1 contrast) |
| `chatchi_widget_config` | filter | Full widget configuration |
| `chatchi_settings` | filter | Settings as the plugin uses them (not saved) |
| `chatchi_settings_updated` | action | Settings were saved, with the validated changes |
| `chatchi_rate_limits` | filter | Limits per action (`start`, `message`, `write`) and scope (`visitor`, `ip`): `[max hits, window seconds]`; receives the Settings values |
| `chatchi_rate_limited` | action | A request was refused by a rate limit |
| `chatchi_rate_limit_error` | action | The counter table failed; the request was allowed |
| `chatchi_client_ip` | filter | The visitor IP used for rate limiting |
| `chatchi_spam_protection` | filter | Turn the start-form bot checks on/off; receives the Settings switch |
| `chatchi_spam_min_seconds` | filter | Minimum seconds between loading the widget and starting (default 2, 0–60) |
| `chatchi_spam_blocked` | action | A bot filled in the honeypot |
| `chatchi_stray_output` | action | Output printed by other code (PHP notices, stray whitespace) was removed from a ChatChi REST response |
| `chatchi_panel_csp` | filter | Content-Security-Policy of the agent panel (empty string: none) |
| `chatchi_avatar_max_bytes` | filter | Largest agent picture upload in bytes (default 2 MB, capped by the server upload limit) |

## Database

| Table | Purpose | Key indexes |
|---|---|---|
| `{prefix}chatchi_visitors` | Visitor identity (hashed cookie token) and contact info | `UNIQUE token_hash`, `user_id` |
| `{prefix}chatchi_agents` | Agents, one per WordPress user | `UNIQUE user_id`, `is_active` |
| `{prefix}chatchi_conversations` | Conversation state and latest activity | `UNIQUE open_visitor_id` (one open conversation per visitor), `(visitor_id,status)`, `(status,last_message_at)`, `last_message_at`, `agent_id` |
| `{prefix}chatchi_messages` | Messages and read state | `(conversation_id,id)`, `UNIQUE (conversation_id,client_id)` |
| `{prefix}chatchi_rate_limits` | Rate-limit counters; buckets are salted hashes, no IPs stored. Purged hourly | `bucket` (primary), `expires_at` |

All datetimes are stored in UTC. Relations are enforced in the application layer.

## REST API (`chatchi/v1`)

All responses are JSON with `Cache-Control: no-store`. Errors use the standard WordPress shape `{code, message, data: {status}}`. Message `body` is plain text and must be rendered as text, never as HTML. Dates are ISO 8601 UTC.

### Widget (visitors)

Identified by the HttpOnly `chatchi_visitor` cookie (logged-in users: by account, when `X-WP-Nonce` is sent). Requests from other origins are rejected.

| Method | Route | Purpose |
|---|---|---|
| GET | `/widget/session` | Visitor, open conversation, unread count, start-form config, `form_token`. Never sets a cookie |
| POST | `/widget/conversations` | Start: `name`, `email`, `phone`, `page_url`, `form_token`, `website` (honeypot, must be empty) → visitor + open conversation |
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

### Agent management

Administrators only (`manage_options`). Agents are never deleted, only deactivated, so past conversations keep their author. A deactivated agent loses API and panel access immediately. Nobody can deactivate themselves.

| Method | Route | Purpose |
|---|---|---|
| GET | `/agent/agents` | All agents with their WordPress account |
| POST | `/agent/agents` | Add: `user_id`, `display_name` (2–100 characters), optional `is_active` |
| POST/PATCH | `/agent/agents/{id}` | Update `display_name`, `avatar_id` (0 removes the picture), `is_active` |
| POST | `/agent/agents/{id}/avatar` | Upload a picture (multipart field `file`; JPG, PNG, GIF or WebP, checked by content). Also requires `upload_files` |
| GET | `/agent/users` | WordPress users who are not agents yet: `search` (login, email, display name), up to 20 |

### Settings

Administrators only (`manage_options`).

| Method | Route | Purpose |
|---|---|---|
| GET | `/agent/settings` | Settings, the default welcome message, limits, available page types, and the chosen items with titles |
| POST/PATCH | `/agent/settings` | Save any of `primary_color` (`#RRGGBB`, `#RGB`; empty = default), `widget_position` (`left` / `right`), `widget_title` (≤ 60 characters), `widget_subtitle` (≤ 100; empty = translated default), `proxy_mode` (`direct` / `cloudflare` / `custom`), `proxy_header`, `trusted_proxies` (IPs or CIDR ranges, ≤ 100), `welcome_enabled`, `welcome_message` (≤ 500 characters; empty = default), `display_mode` (`all` / `include` / `exclude`), `display_targets` (`home`, `pages`, `posts`, `products`), `display_ids` (published pages, posts or products, ≤ 100). Invalid fields → `400 chatchi_invalid_settings` with `data.fields` |
| GET | `/agent/content` | Published pages, posts and products by title: `search`, up to 20 |

## Agent panel

`wp-admin/admin.php?page=chatchi` serves a standalone, full-screen app (no WordPress admin chrome). Access requires the virtual `chatchi_access_panel` capability, granted to site administrators and active agents. Administrators get an agent record automatically on their first visit.

Administrators also get an **Agents** screen (`#/agents`) to add agents from existing WordPress users, rename them, upload a profile picture and switch them on or off. Visitors only ever see an agent's display name and picture, never their login or email.

Administrators also get a **Settings** screen with six sections, each saved on its own (unsaved sections are marked in the sub-menu). Every setting only sets the default a filter receives, so code can still override it:

- **General** (`#/settings/general`): master switch for the widget (off hides it everywhere, shortcodes included; the panel keeps working), how many days returning visitors are remembered (1–400), and whether deleting the plugin deletes all data. **By default data is kept** on delete; only temporary data (rate-limit counters, cron, locks) is removed.
- **Start form** (`#/settings/form`): name / email / mobile each off, optional or required, and the text above the form. With every field off, visitors chat straight away.
- **Spam & limits** (`#/settings/limits`): bot protection on/off, messages per minute and new chats per hour / per 10 minutes, per visitor and per IP address, and the longest allowed message (100–10000 characters).

- **Appearance** (`#/settings/appearance`): brand color (presets, color picker or hex code, with a warning for colors that are hard to see on white), bottom-left or bottom-right corner, and the widget title and subtitle, with a live preview. The agent panel uses the same brand color.
- **Welcome & display** (`#/settings/display`) and **IP & security** (`#/settings/security`), described below.

- **Welcome message**: shown in the widget as the first bubble from support, above the start form and at the top of every conversation. It is not stored as a message, so agents never see it in the inbox. On by default with a translated default text.
- **Where the widget appears**: on every page, only on chosen page types and items, or everywhere except them. Page types are the home page, all pages, all blog posts, and WooCommerce products (single products, the shop, product categories and tags).
- **Shortcode**: `[chatchi]` (alias `[hamyarchat]`) shows the floating widget on that page even where the rules hide it. It prints nothing itself and also works in widget areas and page builders (the widget then loads in the footer).

## Troubleshooting

- **"Not sent" in the widget:** when a site administrator is logged in, the widget shows the technical cause under a failed message (HTTP status, error code, and the server's explanation). Visitors never see it. The same message is always written to the server's PHP error log as `[ChatChi] … error: …`.
- **Unfinished database upgrades and leftover tables** repair themselves: an error about a missing table or column, a NOT NULL column without a default, or a duplicate on a unique index that is not ChatChi's (for example left by an older or different plugin that used the same table name) runs the installer right away, and the widget retries once. After `dbDelta`, the installer drops unique indexes on ChatChi's tables that are not its own and makes unknown NOT NULL columns without a default nullable (MySQL/MariaDB; no data is removed). The installer also checks that required columns exist, and shows an admin notice when it cannot fix them (for example when the database user may not `ALTER` tables).
- **Other plugins printing into responses** (PHP notices, stray whitespace) no longer break the chat: that output is removed from ChatChi's REST responses, and the widget can read the JSON even when something was printed before it.

## Security

- **Rate limits** (sliding window, per visitor and per IP; IPv6 per /64): starting conversations 5/hour per visitor and 10/10 min per IP; messages 20/min per visitor and 60/min per IP; other writes 60/min and 180/min. Reads (polling) and agents are not limited. Refused requests get `429 chatchi_rate_limited` with `data.retry_after` and a `Retry-After` header; the widget shows "try again in N seconds/minutes". Counting is atomic, so parallel requests cannot get past a limit. If the counter table fails, requests are allowed.
- **Visitor IP**: the connection address by default. Behind Cloudflare, `CF-Connecting-IP` is used only on connections from Cloudflare's published ranges; behind another CDN or proxy (e.g. ArvanCloud), the configured header is used only from the listed proxy addresses (`X-Forwarded-For` is read right to left, skipping trusted proxies).
- **Bot checks on the start form**: a honeypot field, and a signed timestamp from `/widget/session` that must be at least 2 seconds and at most 1 day old. The widget retries too-early and expired starts by itself, so people never see these errors.
- **Agent panel**: Content-Security-Policy with a per-view nonce (no injected scripts, `frame-ancestors 'none'`), `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: same-origin`, not cacheable.
