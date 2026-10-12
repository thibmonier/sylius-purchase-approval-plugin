# CLAUDE.md

Sylius 2 plugin: purchase approval for B2B company accounts (v1b of the company accounts memo).
Order limits and approval limits per company member, pending approvals created in the checkout
flush, approve / reject / expire (workflow `tmonier_order_approval`), payment and shipment
blocked through tmonier/sylius-order-hold-plugin, acknowledgement e-mail, invoicing and webhook
outbox bridges, shop / admin / API. Research and decisions: `SPEC.md`. Public docs: `README.md`.

## Dependencies

- `require`: tmonier/sylius-company-accounts-plugin ^1.0 (members, roles, eligibility of company
  orders, current member resolver) and tmonier/sylius-order-hold-plugin ^1.0 (Packagist). Never
  modify them from here: if an extension point is missing, report it.
- `require-dev` (bridges and staff orders tested): sylius/invoicing-plugin (classes only, bundle
  not enabled), tmonier/sylius-webhook-outbox-plugin (enabled), tmonier/sylius-admin-order-creation-plugin
  (enabled).

## Commands

PHP needs more than 128 MB to compile the test application container: prefix console and
PHPStan calls with `php -d memory_limit=2G` when running locally.

```bash
docker compose up -d mysql mailhog        # ports come from compose.override.yml (gitignored)
vendor/bin/phpunit                        # unit + integration + functional (test DB + fixtures)
vendor/bin/phpstan analyse                # level max, src + tests
vendor/bin/ecs check
vendor/bin/console lint:container
vendor/bin/console tmonier:approval:expire --dry-run
```

Local env overrides (gitignored): `compose.override.yml` (MySQL on 3331, MailHog on 8050/1050),
`tests/TestApplication/.env.local` (DATABASE_URL + MAILER_DSN) and `.env.test.local`
(DATABASE_URL). Databases `tmonier_sylius_purchase_approval_dev` / `_test`, loaded with the
default fixture suite: company-accounts demo company (`admin@`, `buyer@`, `approver@acme-industries.example.com`,
password `sylius`, verified), then the `tmonier_purchase_approval` fixture (buyer order limit
50.00, approver approval limit 100000.00, two pending orders of the buyer). Dev server:
`symfony server:start -d --port=8098 --no-tls`.

## Architecture

- `Entity/OrderApproval`, `Entity/MemberLimit` (XML mapped superclasses, Schema API migration).
- `Approval/`: `ApprovalRequirementChecker` (who needs an approval), `ApprovalPolicy` (who
  decides), `ApprovalDecisionMaker` (lock, workflow, cancel, events), `MemberLimitUpdater` (shop
  limits), `LimitAmount` (major ↔ minor units), `Decider`.
- `EventListener/OrderApprovalRequestListener` (onFlush priority 100 + postFlush),
  `ShipmentApprovalGuardListener` (`workflow.sylius_shipment.guard.ship`),
  `PaymentCompletionAlertListener` (warning only).
- `OrderHold/ApprovalHold`: payment entry blocker + unpaid order cancellation exclusion (tags of
  the kernel set explicitly in `config/services.php`).
- `Mailer/`: `AcknowledgementEmailSender` (decorates `sylius.email_sender`), `ApprovalEmailListener`.
- `Bridge/Invoicing`, `Bridge/WebhookOutbox` (`config/services/*.php`, loaded when the bundle is
  enabled; flags set at prepend time).
- Shop `Controller/Shop/ApprovalController` + `Security/ApprovalVoter`; admin
  `Controller/Admin/ApprovalDecisionController`, `Grid/Filter/ApprovalStateFilter`
  (`config/grids/order.yaml`), `Form/Extension/CompanyMemberLimitsTypeExtension`; API in
  `src/Api` (attributes) and `config/api_platform` (limits).
- `Command/ExpireApprovalsCommand` (`tmonier:approval:expire`).

## Rules

- No core entity change, no change to the company-accounts or order-hold plugins.
- Never guard `sylius_order.cancel`; never guard payments to block them (kernel entry points).
- DQL: `member` is a reserved word, never use it as an alias.
- Tests never depend on random fixtures: `tests/TestCatalog.php` (`orderAgainstLimit()` reads the
  cart total before setting the limit), `tests/Functional/ApprovalScenario.php` for the access
  matrices. No `markTestSkipped`.
- Labels in `messages`, refusals in `validators`, shop flashes in `flashes`; every key in en, fr,
  de, es, it, nl, pl (`TranslationsTest`). Clear the caches after adding a translation file.
- Positioning: MIT alternative for merchants without Sylius Plus; never claim Plus or Elesto
  lacks a feature.
