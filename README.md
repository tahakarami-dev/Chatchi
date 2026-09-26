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
| 3 | User / Visitor Identity | ⏳ |

## Architecture

```text
chatchi.php              Bootstrap: constants, requirements check, autoloader, hooks
uninstall.php            Removes all plugin data on delete
includes/                PSR-4 root for the ChatChi\ namespace
├── Plugin.php           Kernel: service container + module boot
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
└── Models/              Visitor, Agent, Conversation, Message
languages/               Translation files (text domain: chatchi)
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

## Database

| Table | Purpose | Key indexes |
|---|---|---|
| `{prefix}chatchi_visitors` | Visitor identity (hashed cookie token) and contact info | `UNIQUE token_hash`, `user_id` |
| `{prefix}chatchi_agents` | Agents, one per WordPress user | `UNIQUE user_id`, `is_active` |
| `{prefix}chatchi_conversations` | Conversation state and latest activity | `(visitor_id,status)`, `(status,last_message_at)`, `last_message_at`, `agent_id` |
| `{prefix}chatchi_messages` | Messages and read state | `(conversation_id,id)`, `UNIQUE (conversation_id,client_id)` |

All datetimes are stored in UTC. Relations are enforced in the application layer.
