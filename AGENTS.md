# AGENTS.md — SendPortal Core

## Project Overview

SendPortal Core is a Laravel package (`mettle/sendportal-core`) that provides self-hosted email marketing functionality. It can be embedded into any Laravel 10+ application or used via the standalone [SendPortal](https://github.com/mettle/sendportal) wrapper.

- Namespace: `Sendportal\Base`
- License: MIT
- PHP: 8.2+
- Laravel: 10+
- Databases: MySQL (≥ 5.7) or PostgreSQL (≥ 9.4)

## Architecture

This is a Laravel service provider package. The entry point is `SendportalBaseServiceProvider`, which registers routes, views, migrations, events, config, and the `sp:campaigns:dispatch` scheduled command.

### Directory Structure

```
src/
├── Adapters/          # Mail provider adapters (SES, Sendgrid, Mailgun, Postmark, Mailjet, Postal, SMTP)
├── Console/Commands/  # Artisan commands (CampaignDispatchCommand)
├── Events/            # Domain events (MessageDispatch, SubscriberAdded, webhook events)
├── Exceptions/        # Exception handler
├── Facades/           # Sendportal and Helper facades
├── Factories/         # MailAdapterFactory
├── Http/
│   ├── Controllers/   # Web controllers (Campaigns, Subscribers, Tags, Messages, Templates, EmailServices)
│   │   └── Api/       # REST API controllers + webhook handlers
│   ├── Requests/      # Form request validation
│   └── Resources/     # API resources
├── Interfaces/        # Contracts (MailAdapterInterface, BaseTenantInterface, BaseEloquentInterface)
├── Listeners/         # Event listeners (message dispatch, webhook handlers)
├── Models/            # Eloquent models (Campaign, Subscriber, Tag, Message, Template, EmailService, etc.)
├── Pipelines/         # Campaign processing pipelines
├── Presenters/        # View presenters (CampaignReportPresenter)
├── Providers/         # Service providers (App, Event, Form, Route, Resolver)
├── Repositories/      # Data access layer (tenant-scoped and base repositories)
├── Routes/            # Route definitions (WebRoutes, ApiRoutes)
├── Rules/             # Validation rules
├── Services/          # Business logic (Campaigns, Messages, Subscribers, Tags, Templates, Webhooks, Content)
├── Traits/            # Shared traits (Uuid, ThrottlesSending, ScheduledAt, etc.)
└── View/Components/   # Blade components
```

### Key Patterns

- **Multi-tenancy**: Repositories are tenant-scoped via `BaseTenantRepository`. Workspace ID is resolved through `ResolverService`.
- **Mail Adapters**: `MailAdapterInterface` defines the contract. `MailAdapterFactory` resolves the correct adapter based on `EmailService` model config. Supported: SES, Sendgrid, Mailgun, Postmark, Mailjet, Postal, SMTP.
- **Campaign Dispatch Flow**: `sp:campaigns:dispatch` (runs every minute) → `CampaignDispatchService` → `MessageDispatchEvent` → `MessageDispatchHandler` → `DispatchMessage` → mail adapter `send()`.
- **Webhook Processing**: Each provider has a dedicated webhook controller, event, and listener under `Api/Webhooks`, `Events/Webhooks`, and `Listeners/Webhooks`.

### Models

Campaign, CampaignStatus, Subscriber, Tag, Subscription, Template, Message, MessageUrl, MessageFailure, EmailService, EmailServiceType, UnsubscribeEventType.

## Routes

- Web routes: `WebRoutes::sendportalWebRoutes()` (authenticated) and `sendportalPublicWebRoutes()` (subscriptions, webview).
- API routes: `ApiRoutes::sendportalApiRoutes()` (v1, authenticated) and `sendportalPublicApiRoutes()` (webhooks, ping).
- Routes are registered via the `Sendportal` facade in the host application.

## Testing

- Framework: PHPUnit 11
- Test base: `Tests\TestCase` extends Orchestra Testbench
- Run: `composer test`
- CI tests against both MySQL and PostgreSQL on PHP 8.2 and 8.3
- Test directories: `tests/Feature/` (Campaigns, Subscribers, Tags, Messages, Templates, EmailServices, Webhooks, Content, API) and `tests/Unit/` (Http, Factories, Services, Repositories, Models)

## Configuration

Published as `config/sendportal.php`. Keys:
- `command_output_path` — output path for command logs
- `list_unsubscribe.email` / `list_unsubscribe.url` — List-Unsubscribe header values

## Common Tasks

- **Add a new mail adapter**: Implement `MailAdapterInterface`, extend `BaseMailAdapter`, register in `MailAdapterFactory`, add `EmailServiceType` migration.
- **Add a new webhook provider**: Create webhook controller in `Api/Webhooks`, event in `Events/Webhooks`, listener in `Listeners/Webhooks`, register in `EventServiceProvider`, add route in `ApiRoutes`.
- **Modify campaign logic**: Business logic lives in `Services/Campaigns/`. The dispatch pipeline is in `Pipelines/Campaigns/`.
- **Add subscriber features**: Controllers in `Http/Controllers/Subscribers/`, services in `Services/Subscribers/`, repository in `Repositories/Subscribers/`.

## Git Commit Messages

Use the format: `<type>: <short description in lowercase>`

Types:
- `feat:` — new feature or enhancement
- `fix:` — bug fix
- `optimize:` — performance improvement

Examples:
```
feat: add API endpoint to send test email
fix: detach tags before deleting campaign
feat: cache campaigns stats
fix: campaign stats API pagination
feat: return unsubscribed datetime and event for subscribers API
```

Keep messages concise and descriptive of what changed, not how.
