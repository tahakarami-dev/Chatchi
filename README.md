# ChatChi | چت‌چی

A lightweight, secure live chat plugin for WordPress with a standalone, SaaS-style agent panel.

## Requirements

- WordPress 6.0+
- PHP 7.4+

## License check (RTL-Theme)

The plugin starts only when its RTL-Theme purchase is active. `chatchi.php` loads `license/license.php`, which verifies RTL-Theme's license class (`license/RTL_License_d4a7d3cf49c760e7.php`, ionCube-encoded, checked by its SHA-1) and hooks `chatchi()->boot()` only when `isActive()` returns true; otherwise administrators see a notice asking them to activate the license.

- **Release build**: replace `license/license.php` with the copy encoded by RTL-Theme's tool. Never change `RTL_License_d4a7d3cf49c760e7.php`: its hash is checked.
- **ionCube Loader** is required on the server. Without it, the encoded files would stop the whole site, so `chatchi.php` does not load them and shows administrators a notice instead.
- **Development**: without the loader (or an active license) the plugin does not boot.

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
├── Ai/                  AI client (OpenAI-compatible: GapGPT, AvalAI, OpenAI or any other; plain HTTP), prompts, bot and reply suggestions
├── Commerce/           WooCommerce customer context for agents (current page, cart, orders)
├── Conversations/       Conversation service, access policy (Actor), inbox summaries, ratings
├── Messages/            Message service, text rules, paging
├── Reports/             Report figures for administrators
├── Rest/                chatchi/v1 controllers (widget, agent, agent management, settings), presenter, CSRF guard
├── Frontend/            Widget loader, display rules, [chatchi] shortcode, color tokens, font face, interface strings
├── Admin/               Standalone agent panel page, access capability, menu entry, per-agent theme
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
├── Notifications/       Offline email, Telegram / Bale bots, transcripts, SMS (Kavenegar, Melipayamak)
├── Settings/            Settings store (one autoloaded option), validation, content lookup for display rules, invitations, contact channels, FAQ
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
| `chatchi_rate_limits` | filter | Limits per action (`start`, `message`, `typing`, `upload`, `write`) and scope (`visitor`, `ip`): `[max hits, window seconds]`; receives the Settings values |
| `chatchi_rate_limited` | action | A request was refused by a rate limit |
| `chatchi_rate_limit_error` | action | The counter table failed; the request was allowed |
| `chatchi_client_ip` | filter | The visitor IP used for rate limiting |
| `chatchi_spam_protection` | filter | Turn the start-form bot checks on/off; receives the Settings switch |
| `chatchi_spam_min_seconds` | filter | Minimum seconds between loading the widget and starting (default 2, 0–60) |
| `chatchi_spam_blocked` | action | A bot filled in the honeypot |
| `chatchi_stray_output` | action | Output printed by other code (PHP notices, stray whitespace) was removed from a ChatChi REST response |
| `chatchi_panel_csp` | filter | Content-Security-Policy of the agent panel (empty string: none) |
| `chatchi_avatar_max_bytes` | filter | Largest agent picture upload in bytes (default 2 MB, capped by the server upload limit) |
| `chatchi_availability` | filter | The availability sent to the widget (`mode`, `agents_online`, `in_hours`, `opens_at`, `offline_form`, `offline_message`) |
| `chatchi_offline_fields` | filter | Fields of the leave-a-message form (email is required by default) |
| `chatchi_offline_email` | filter | The email about an unanswered message (`to`, `subject`, `message`, `headers`); return false to send nothing |
| `chatchi_attachment_types` | filter | Allowed file types, extension pattern => MIME type. Scriptable types (SVG, HTML, XML, JS, PHP…) are always removed |
| `chatchi_agent_status_changed` | action | An agent switched between online and away |
| `chatchi_note_added` | action | An agent added an internal note |
| `chatchi_conversation_assigned` | action | A conversation was taken, transferred or unassigned |
| `chatchi_bot_message` | filter | Text of the Telegram / Bale message about a new chat; empty sends nothing |
| `chatchi_bot_api_url` | filter | Bot API address per service (e.g. a relay) |
| `chatchi_transcript_email` | filter | The transcript email (`to`, `subject`, HTML `message`, `headers`); falsy cancels |
| `chatchi_canned_responses_updated` | action | The saved replies changed |
| `chatchi_agent_can_see_orders` | filter | Whether the current agent sees the visitor's WooCommerce orders (default: `manage_options` or `edit_shop_orders`) |
| `chatchi_conversation_rated` | action | A visitor rated (or re-rated) an ended conversation |
| `chatchi_widget_theme` | filter | `light`, `dark` or `auto` (follows the visitor's device); receives the Settings choice (default `light`) |
| `chatchi_sms_message` | filter | Text of the SMS to a visitor; empty sends nothing |
| `chatchi_sms_api_url` | filter | SMS service address per provider (e.g. a relay); Kavenegar's contains `%s` for the API key |
| `chatchi_sms_sent` | action | An SMS told a visitor about a reply |
| `chatchi_bot_answer` | filter | The AI bot's answer before it is sent; empty sends nothing |
| `chatchi_bot_replied` | action | The AI bot answered a visitor |
| `chatchi_bot_handoff` | action | The AI bot handed a conversation to the team |

## Database

| Table | Purpose | Key indexes |
|---|---|---|
| `{prefix}chatchi_visitors` | Visitor identity (hashed cookie token), contact info, and the page they are on with a summary of their WooCommerce cart (for agents) | `UNIQUE token_hash`, `user_id` |
| `{prefix}chatchi_agents` | Agents, one per WordPress user, with their chosen status (online / away) and when their panel last talked to the server | `UNIQUE user_id`, `is_active` |
| `{prefix}chatchi_conversations` | Conversation state, latest activity, when each side last reported typing, the visitor's rating (1–5) and comment, and when the AI bot handed it to the team | `UNIQUE open_visitor_id` (one open conversation per visitor), `(visitor_id,status)`, `(status,last_message_at)`, `last_message_at`, `agent_id`, `created_at` |
| `{prefix}chatchi_messages` | Messages and read state; `attachment_id` links a sent file; `internal` marks agent-only notes and events, never sent to visitors | `(conversation_id,id)`, `UNIQUE (conversation_id,client_id)` |
| `{prefix}chatchi_attachments` | Files sent in conversations: original name, type, size, picture size, and the path of the stored file | `conversation_id`, `message_id` |
| `{prefix}chatchi_conversation_tags` | Tags agents put on conversations | `(conversation_id,tag)` primary, `tag` |
| `{prefix}chatchi_rate_limits` | Rate-limit counters; buckets are salted hashes, no IPs stored. Purged hourly | `bucket` (primary), `expires_at` |

All datetimes are stored in UTC. Relations are enforced in the application layer.

## REST API (`chatchi/v1`)

All responses are JSON with `Cache-Control: no-store`. Errors use the standard WordPress shape `{code, message, data: {status}}`. Message `body` is plain text and must be rendered as text, never as HTML. Dates are ISO 8601 UTC.

### Widget (visitors)

Identified by the HttpOnly `chatchi_visitor` cookie (logged-in users: by account, when `X-WP-Nonce` is sent). Requests from other origins are rejected.

| Method | Route | Purpose |
|---|---|---|
| GET | `/widget/session` | Optional `url` and `title` of the current page (recorded, with the WooCommerce cart, only for visitors who already chatted). Visitor, open conversation, unread count, start-form config (and the leave-a-message fields), `form_token`, `availability` (`mode`: online / away / offline, `opens_at`, `offline_form`, `offline_message`) and `attachments` (`enabled`, `max_bytes`, `accept`). Never sets a cookie |
| POST | `/widget/conversations` | Start: `name`, `email`, `phone`, `page_url`, `form_token`, `website` (honeypot, must be empty), `offline` (leave-a-message form: email required) → visitor + open conversation |
| GET | `/widget/conversations/{id}/messages` | `after_id` (polling), `before_id` (history), `limit` 1–50. Also returns `typing`: whether the other side is typing |
| POST | `/widget/conversations/{id}/messages` | `body`, optional `client_id` (UUID, makes retries idempotent) |
| POST | `/widget/conversations/{id}/read` | Mark agent messages read, optional `up_to_id` |
| POST | `/widget/conversations/{id}/typing` | `typing` (default `true`): the visitor is typing, or stopped. Lasts 6 seconds unless repeated; sending a message clears it. Ignored for closed conversations |
| POST | `/widget/conversations/{id}/attachments` | Send a file: multipart field `file`, optional `client_id`. Pictures (JPG, PNG, GIF, WebP), PDF, Word, Excel, text, CSV and ZIP, checked by content; SVG, HTML and scripts are always refused. Returns the new message with `attachment` |
| GET | `/widget/conversations/{id}/attachments/{attachment_id}` | The file, after an access check. Pictures inline, everything else as a download (`download=1` forces it); sandboxing CSP and `nosniff` always |
| POST | `/widget/conversations/{id}/close` | Close own conversation |
| POST | `/widget/conversations/{id}/bot` | The AI bot answers the visitor's latest message when it looks after the conversation → `{answered, handoff}`. Rate limited (12/min per visitor) |
| POST | `/widget/conversations/{id}/handoff` | The visitor asks for a person; the bot stops for this conversation |
| POST | `/widget/conversations/{id}/rating` | `score` 1–5, optional `comment` (≤ 500 characters): rate own ended conversation. Can be changed for 7 days after it ends; `403 chatchi_rating_disabled` when rating is off, `409` while open or once too old |

### Agent panel

Logged-in user with `X-WP-Nonce` and an active agent record.

| Method | Route | Purpose |
|---|---|---|
| GET | `/agent/me` | Current agent, with `status` and the `attachments` limits |
| POST/PATCH | `/agent/me` | `status`: `online` or `away`, `theme`: `light`, `dark` or `auto` (the panel's theme, saved per user), `display_name` (2–100 characters) and/or `avatar_id: 0` (removes the picture) |
| POST | `/agent/me/avatar` | Multipart `file`: the agent's own picture (JPG, PNG, GIF or WebP); returns the agent |
| GET | `/agent/conversations` | Inbox: `status`, `search`, `assigned` (`mine` / `unassigned`), `tag`, `page`, `per_page` (≤100); `X-WP-Total` headers. Items carry `tags` |
| GET | `/agent/conversations/{id}` | Conversation with visitor, assigned agent, last message, unread count |
| GET/POST | `/agent/conversations/{id}/messages` | Same as widget |
| POST | `/agent/conversations/{id}/read` | Mark visitor messages read |
| POST | `/agent/conversations/{id}/typing` | Same as widget: the agent is typing |
| POST, GET | `/agent/conversations/{id}/attachments[/{attachment_id}]` | Same as widget; agents may send files even when visitors may not |
| POST | `/agent/conversations/{id}/notes` | Internal note (`body`, optional `client_id`): agents only; never in the visitor's messages, unread count or the inbox preview |
| POST | `/agent/conversations/{id}/assign` | `agent_id` (0 unassigns): hand the conversation to an active agent; an internal event line records it |
| PUT/PATCH/POST | `/agent/conversations/{id}/tags` | `tags`: replaces the tags (≤ 10; cleaned: one line, no `#`, ≤ 40 characters, Latin in lower case) |
| POST | `/agent/conversations/{id}/transcript` | Email the visitor a copy of the conversation (notes excluded) |
| GET | `/agent/conversations/{id}/customer` | What the visitor is viewing now and, on WooCommerce shops, their cart and (for agents allowed by `chatchi_agent_can_see_orders`) their recent orders and total spent |
| POST | `/agent/conversations/{id}/suggest` | An AI draft of the next reply → `{text}` (when suggestions are on) |
| GET | `/agent/tags` | The 50 most used tags with counts |
| GET | `/agent/team` | Active agents (`id`, `name`, `avatar_url`, `online`, `is_me`), for transfers |
| GET | `/agent/canned` | Saved replies (`id`, `title`, `shortcut`, `body`); any active agent |
| POST | `/agent/canned` | Add a saved reply (administrators): `title` (≤ 100), `shortcut` (optional, `a-z 0-9 - _`, unique), `body` (≤ 2000) |
| POST/PATCH, DELETE | `/agent/canned/{id}` | Change or delete a saved reply (administrators) |
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
| GET | `/agent/reports` | `range` 7, 30 or 90 days (site time zone): KPIs with the previous period (`conversations`, `answered` %, median `first_response` in seconds, `satisfaction`), `daily`, `ratings`, `agents`, `heatmap` (weekday × hour, 0 = Sunday), `tags`, `comments` |
| POST | `/agent/settings/ai-test` | `provider` (`gapgpt` / `avalai` / `openai` / `custom`), `api_key`, `base_url` (empty: the preset's address), `model`: a tiny request with these values; returns the model's answer or the service's error |
| POST | `/agent/settings/sms-test` | `provider` (`kavenegar` / `melipayamak`), `api_key` or `username` + `password`, `sender`, `phone`: sends a test SMS with these values and reports the service's answer |
| POST | `/agent/settings/bot-test` | `service` (`telegram` / `bale`), `token`, `chat_id`, `api_url`: sends a test message with these values (saved or not) and reports the service's answer |

## Agent panel

`wp-admin/admin.php?page=chatchi` serves a standalone, full-screen app (no WordPress admin chrome). Access requires the virtual `chatchi_access_panel` capability, granted to site administrators and active agents. Administrators get an agent record automatically on their first visit.

Administrators also get an **Agents** screen (`#/agents`) to add agents from existing WordPress users (with an optional picture), rename them, upload a profile picture and switch them on or off. Visitors only ever see an agent's display name and picture, never their login or email.

Every agent, administrator or not, edits their own name and picture from **My profile** (the person icon in the rail). Pictures are JPG, PNG, GIF or WebP; before upload the panel scales photos so the shorter side is at most 512px (turned the right way up), so ordinary phone photos fit the size limit, and the server makes the square thumbnail. Uploading pictures for agents does not need the WordPress `upload_files` capability (some security plugins remove it), since only checked images of limited size are accepted.

Administrators also get a **Settings** screen with twelve sections in four groups (Widget, Answering, Alerts, Protection), each saved on its own. Every setting only sets the default a filter receives, so code can still override it.

- **Finding a setting**: the search box above the sections finds any section, heading or field by name (Arabic ي/ك match Persian ی/ک); Enter or a click opens its section and highlights it.
- **Saving**: a bar at the bottom of each section says whether it has unsaved changes and offers *Discard* and *Save* (also Ctrl+S / Cmd+S, with any keyboard layout). Sections with unsaved changes are marked in the menu, and leaving the panel with unsaved changes asks first.
- **Switchable features** (welcome message, business hours, email, Bale and Telegram bots, SMS, AI bot, contact channels, FAQ, visitor files) show their settings only while they are on.
- **Business hours** are picked from 24-hour menus in the site's digits, every half hour, with "end of day" (24:00) for closing times; one click copies the first open day's hours to every open day.
- **Setup guide**: with no conversation open, administrators see the steps that make the chat useful (color and title, team, hours, phone alerts, FAQ, and the optional AI assistant), which are done, and a link to the site. It can be hidden (remembered in that browser) and shown again.


- **Channels & FAQ** (`#/settings/contact`): WhatsApp, Telegram, Bale and phone numbers (up to 6, reorderable, each with an optional label) shown in a menu the chat button opens, and up to 30 frequently asked questions visitors see before their first message. Numbers are stored normalized (WhatsApp `0912…` becomes `98912…`) and links are built only from them (`wa.me`, `t.me`, `ble.ir`, `tel:`).
- **AI** (`#/settings/ai`): the service (GapGPT — the default —, AvalAI, OpenAI, or another OpenAI-compatible service), key, model, and the API address (each preset has its own; required for another service), "test the connection"; the bot (on/off, only when nobody is online or always first, name, extra instructions, up to 20 pages, posts or products it knows, most replies per conversation); reply suggestions for agents; and the most AI requests per day.
- **SMS** (`#/settings/sms`): Kavenegar (API key, optional sender line) or Melipayamak (username, password, sender line), the text (`{site}`, `{link}`), how long to wait for the visitor to read the reply (1–60 minutes), and "send a test SMS".
- **Sales** (`#/settings/sales`): chat invitations (up to 10) and the post-chat rating. Each invitation has a message (≤ 300 characters), where it shows (all pages, home, product pages, shop and categories, cart, checkout, or pages whose address contains a text), a delay (0–600 seconds) and how often (once per visit, once a day, every page view). The first matching invitation appears as a bubble by the launcher; clicking it opens the chat with the invitation as support's first message. Invitations never show to a visitor who is already chatting.
- **Notifications** (`#/settings/notifications`): the email about unanswered messages, Telegram and Bale bots (token, chat ID, an optional relay address for Telegram, "send a test message"), whether bots write for every new chat or only when nobody is online, and automatic transcripts for visitors when a conversation ends.
- **Hours & offline** (`#/settings/hours`): weekly business hours (Saturday first on Persian sites; in the site time zone), what offline visitors see (a leave-a-message form, or the normal chat with a notice), whether "no agent online" also counts as offline, and the offline text.
- **General** (`#/settings/general`): master switch for the widget (off hides it everywhere, shortcodes included; the panel keeps working), the notification sound for new replies, how many days returning visitors are remembered (1–400), and whether deleting the plugin deletes all data. **By default data is kept** on delete; only temporary data (rate-limit counters, cron, locks) is removed.
- **Start form** (`#/settings/form`): name / email / mobile each off, optional or required, and the text above the form. With every field off, visitors chat straight away.
- **Spam & limits** (`#/settings/limits`): bot protection on/off, messages per minute and new chats per hour / per 10 minutes, per visitor and per IP address, the longest allowed message (100–10000 characters), and files: whether visitors may send them and the largest size (1–50 MB, capped by the server's upload limit).

- **Appearance** (`#/settings/appearance`): light, dark or automatic (the visitor's device decides) widget theme, brand color (presets, color picker or hex code, with a warning for colors that are hard to see on white), bottom-left or bottom-right corner, and the widget title and subtitle, with a live preview. The agent panel uses the same brand color.
- **Welcome & display** (`#/settings/display`) and **IP & security** (`#/settings/security`), described below.

- **Welcome message**: shown in the widget as the first bubble from support, above the start form and at the top of every conversation. It is not stored as a message, so agents never see it in the inbox. On by default with a translated default text.
- **Where the widget appears**: on every page, only on chosen page types and items, or everywhere except them. Page types are the home page, all pages, all blog posts, and WooCommerce products (single products, the shop, product categories and tags).
- **Shortcode**: `[chatchi]` (alias `[hamyarchat]`) shows the floating widget on that page even where the rules hide it. It prints nothing itself and also works in widget areas and page builders (the widget then loads in the footer).

## Availability, saved replies and files

- **Agent status**: every request from an open panel counts as presence (written at most every 30 seconds). An agent is *available* when active, set to **online** (the dot on their avatar in the panel's rail; one click switches to away) and seen in the last 90 seconds.
- **What visitors see**: a green dot and the usual subtitle when someone is available; amber and "we'll reply as soon as we can" when not; gray, "back Saturday 09:00" and the leave-a-message form (or a notice, per settings) outside business hours.
- **Offline email**: the first visitor message of a conversation sent while nobody is available is emailed, with the visitor's details, the page, a link to the conversation and `Reply-To` set to the visitor. Filter `chatchi_offline_email` to change or cancel it.
- **Saved replies** (`#/canned`, administrators): in the composer, type `/` (e.g. `/ship`) or press ⚡ to pick one; arrows move, Enter or Tab inserts. `{name}` and `{agent}` are filled in.
- **Files**: the 📎 button, pasting a screenshot, or dropping files on the chat send them (up to 5 at a time) with a progress bar; pictures show as thumbnails. Files are stored in `uploads/chatchi-files` under random names without an extension (the folder also refuses direct access on Apache) and are only served by the REST API to the conversation's visitor and to agents. Deleting the plugin with "delete all data" on removes them.

## Team tools

- **Internal notes**: switch the composer from *Reply* to *Internal note* (🔒). Notes show as yellow cards to agents only; the visitor's API never returns them.
- **Transfer**: the button in the conversation header lists active agents (green dot = online). Taking, transferring or unassigning adds an internal event line ("Sara transferred the conversation to Reza.").
- **Inbox filters**: *All / Mine / Unassigned*, and a tag filter; tags show under each conversation and are edited in the details pane (Enter or comma adds, suggestions come from tags in use).
- **Alerts**: the 🔔 button in the rail turns new-message alerts on or off for the agent's browser: a chime and, with the browser's permission, a desktop notification that opens the conversation. Alerts skip the conversation the agent is looking at.
- **Telegram / Bale**: one message per new conversation (its first visitor message), sent without delaying the visitor; filters `chatchi_bot_message` and `chatchi_bot_api_url`. Bale's bot API is Telegram-compatible (`tapi.bale.ai`). Many servers in Iran cannot reach `api.telegram.org`; use Bale or a relay address.
- **Transcripts**: plain, escaped RTL HTML email of the visitor-facing messages; sent on close (setting) or from the details pane. Filter `chatchi_transcript_email`.

## Sales tools

- **Customer context**: the details pane shows the page the visitor is on now and, with WooCommerce, their cart (items, quantities, total) and recent orders with status and total spent. The cart is read on the server from the visitor's own WooCommerce session when the widget loads; it is never put into the page's HTML, so page caches cannot leak one shopper's cart to another. Orders are found by the visitor's account or email address and are only shown to agents allowed by `chatchi_agent_can_see_orders`.
- **Chat invitations**: see Settings → Sales above.
- **Ratings**: when a conversation ends, the widget asks "How was your chat?" with five stars and an optional comment. Agents see the rating as an internal event line, in the inbox (★) and in the details pane.
- **Reports** (`#/reports`, administrators): conversations, answer rate, median first response time and satisfaction, each compared with the previous period; conversations per day, rating distribution, each agent's conversations, replies, response time and rating, the busiest hours, the most used tags and the latest comments.

## Contact channels, FAQ, dark mode and SMS

- **Dark mode**: the widget follows the Appearance setting; each agent picks light, dark or their device's setting for the panel with the button in the rail (saved on their account, applied before the page draws). All colors come from design tokens, and the brand's soft tint is recomputed for dark surfaces.
- **Contact menu**: with channels set, the chat button opens a menu (chat first, then the channels); arrow keys move, Escape or a click elsewhere closes it. Visitors with unread replies go straight to the chat. External links open in a new tab with `noopener`.
- **FAQ**: shown above the start form and in the empty chat until the first message. Answers are plain text with clickable links; one is open at a time; with more than five questions there is a search box (Persian and Arabic ی/ک match).
- **SMS**: when an agent replies, a check runs after the set delay (WP-Cron). If the reply is still unread, the visitor has not been on the site for a minute, and they gave a mobile number, one SMS is sent with a link to the page they chatted on (`#chatchi` opens the chat), at most once per conversation every 6 hours. Agents see an event line saying it was sent or why it failed. The service's credentials never appear in logs or errors.

## AI bot and reply suggestions

- **Services**: every service is spoken to in the OpenAI Chat Completions format — `POST {base}/chat/completions` with a Bearer key — over plain HTTP (no SDK, PHP 7.4). Presets: GapGPT (`https://api.gapgpt.app/v1`), AvalAI (`https://api.avalai.ir/v1`), OpenAI (`https://api.openai.com/v1`); an address set in the settings replaces the preset's, and "another service" needs one. Addresses must be https; private and local addresses are refused when the request is made. A `content_filter` finish hands the chat to the team.
- **When the bot answers**: the widget asks after each visitor message. It answers only while the conversation is open, not taken by an agent and not handed over; in "only when nobody is online" mode, only while no agent is available. One answer at a time per conversation; each answer is stored as an agent message with sender ID 0 and shown with the bot's name and a ✨ avatar.
- **Handoff**: when the model ends its answer with `[[HANDOFF]]` (it doesn't know, the visitor wants a person, an order or payment problem), when the visitor presses "Talk to a person", when the AI fails, or after the per-conversation reply limit. Agents see an event line with the reason; the bot then stays quiet in that conversation, and also as soon as an agent replies.
- **Knowledge**: the FAQ, the chosen pages/posts/products (price and stock read from WooCommerce), the owner's extra instructions, and the visitor's current page and cart, sent as the system message. Only published content the administrator picked is sent; the key and the model never reach the browser.
- **Cost control**: a daily limit (bot answers and suggestions together) and a per-conversation limit. The visitor endpoint is rate limited.
- **Reply suggestions**: the ✨ button in the composer drafts a reply for the agent to edit; unknown facts come back as `[placeholders]`.

## Chat widget

- **Composer**: one rounded box with an emoji picker, a text area that grows up to about five lines, and a round send button. Enter sends and Shift+Enter starts a new line (shown as a hint on devices with a keyboard). The character counter counts emoji as one character, as the server does, and blocks sending past the limit.
- **Typing indicators**: visitors see three dots and "typing…" in the header while an agent writes; agents see "typing…" above their composer while the visitor writes. Each side signals at most every 3 seconds while typing.
- **Notifications**: a soft two-note chime (Web Audio, no sound file) when a reply arrives while the chat is closed or the tab is in the background, and the count in the tab title until the visitor comes back. The sound can be turned off in Settings → General.
- A message of one to three emoji is shown large without a bubble, and a "latest message" button appears when the visitor scrolls up, with a dot when new messages arrive below.

## Troubleshooting

- **"Not sent" in the widget:** when a site administrator is logged in, the widget shows the technical cause under a failed message (HTTP status, error code, and the server's explanation). Visitors never see it. The same message is always written to the server's PHP error log as `[ChatChi] … error: …`.
- **Unfinished database upgrades and leftover tables** repair themselves: an error about a missing table or column, a NOT NULL column without a default, or a duplicate on a unique index that is not ChatChi's (for example left by an older or different plugin that used the same table name) runs the installer right away, and the widget retries once. After `dbDelta`, the installer drops unique indexes on ChatChi's tables that are not its own and makes unknown NOT NULL columns without a default nullable (MySQL/MariaDB; no data is removed). The installer also checks that required columns exist, and shows an admin notice when it cannot fix them (for example when the database user may not `ALTER` tables).
- **Other plugins printing into responses** (PHP notices, stray whitespace) no longer break the chat: that output is removed from ChatChi's REST responses, and the widget can read the JSON even when something was printed before it.

## Security

- **Rate limits** (sliding window, per visitor and per IP; IPv6 per /64): starting conversations 5/hour per visitor and 10/10 min per IP; messages 20/min per visitor and 60/min per IP; typing signals 40/min and 240/min; files 10 and 40 per 10 minutes; other writes 60/min and 180/min. Reads (polling) and agents are not limited. Refused requests get `429 chatchi_rate_limited` with `data.retry_after` and a `Retry-After` header; the widget shows "try again in N seconds/minutes". Counting is atomic, so parallel requests cannot get past a limit. If the counter table fails, requests are allowed.
- **Visitor IP**: the connection address by default. Behind Cloudflare, `CF-Connecting-IP` is used only on connections from Cloudflare's published ranges; behind another CDN or proxy (e.g. ArvanCloud), the configured header is used only from the listed proxy addresses (`X-Forwarded-For` is read right to left, skipping trusted proxies).
- **Bot checks on the start form**: a honeypot field, and a signed timestamp from `/widget/session` that must be at least 2 seconds and at most 1 day old. The widget retries too-early and expired starts by itself, so people never see these errors.
- **Agent panel**: Content-Security-Policy with a per-view nonce (no injected scripts, `frame-ancestors 'none'`), `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: same-origin`, not cacheable.
