# Sylius Purchase Approval Plugin

> Français : [README.fr.md](README.fr.md) <!-- vitrine:lang -->

[![Build](https://github.com/thibmonier/sylius-purchase-approval-plugin/actions/workflows/build.yaml/badge.svg)](https://github.com/thibmonier/sylius-purchase-approval-plugin/actions/workflows/build.yaml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**Purchase approval for Sylius 2 B2B company accounts: orders above a buyer's limit wait for an
approver of their company before they can be paid, shipped or invoiced.**

Built on [tmonier/sylius-company-accounts-plugin](https://github.com/thibmonier/sylius-company-accounts-plugin)
(companies, members, roles) and the [tmonier/sylius-order-hold-plugin](https://github.com/thibmonier/sylius-order-hold-plugin)
kernel (orders kept unpaid without being cancelled, payment entry points blocked). MIT-licensed,
native to Sylius 2.2 / 2.3.

## Features

| Need | What the plugin does |
|---|---|
| Cap what each buyer may order alone | **Order limit** per company member (amount and currency, own table linked to the member). An order above it, or in another currency than the limit, waits for an approval. No limit: no approval, the store works as before. |
| Let the company decide | **Approvals** page in the customer account for approvers: pending orders of the company first, then decisions; order detail with items and totals; **approve or reject with a comment**. One step, **never on one's own order**, within the approver's **approval limit**. |
| See what waits, on every page | **"Approvals" entry in the shop header**, next to "My account" (desktop and mobile menu), for members who can approve: a badge with the number of pending approvals **they can decide** (not their own orders, not above their approval limit; "99+" beyond 99), announced as text for screen readers ("3 approvals pending", "No approval pending"). One `COUNT` query per page, none for visitors and buyers. It leads to the list filtered on what the member decides (`?decidable=1`). `header_badge: false` turns it off. **Preview panel** (1.3): a click on the entry opens a panel with the 5 oldest approvals to decide (they expire first): order number, buyer, amount, age, and "Expires in N days" when the expiry is within 3 days, then "See all (N)". It is loaded at the first click (`GET /account/company/approvals-preview`, one query), never with the page, only navigates (decisions stay on the order page), works with the keyboard (`aria-expanded`, Escape closes it and gives the focus back to the button) and falls back to the link without JavaScript. The small script is a static file of the bundle (`bundles/tmoniersyliuspurchaseapprovalplugin/header-approvals.js`, installed by `assets:install`, loaded with `defer`): it works with a strict `script-src 'self'` policy, without a nonce. |
| Let the company set its rules | **Purchase limits** page for company admins (one form per colleague; nobody changes their own limits). Store administrators set them on the member form of the back office or through the API. |
| No payment, shipment or invoice before the approval | The pending approval is created **in the flush that completes the checkout**. Through the order-hold kernel, the pay page, the Payum capture routes and the shop API payment requests are blocked until it is approved (flash / 422 message; the Pay buttons of the order pages are replaced by the approval notice), and `sylius:cancel-unpaid-orders` leaves pending orders alone; **once approved, the unpaid-orders delay starts from the approval date**. A guard stops `sylius_shipment.ship`. With [sylius/invoicing-plugin](https://github.com/Sylius/InvoicingPlugin), the invoice is generated at approval, never for a rejected order (no credit note needed). |
| Tell people what happens | **Acknowledgement of receipt** instead of the order confirmation while the order waits, on both confirmation paths (shop checkout and API, also asynchronous); the regular **confirmation once approved**, sent once; the approvers of the company are notified of a new request; the buyer is told of a rejection or an expiry, with the comment. |
| Do not keep orders waiting forever | `tmonier:approval:expire` expires approvals pending for more than 14 days (configurable) and cancels their orders; it also closes the pending approvals of orders cancelled without the order workflow (raw SQL, imports). A rejected or expired order is cancelled with the core `sylius_order.cancel` transition (stock and promotion usage released). An order cancelled by the store (or the API, or another plugin) while it waits closes its approval (`cancelled`): it can no longer be approved. |
| See and act in the back office | **Approval card on the order page** (state, company, limit, decision, comment) with approve / reject for the store; **approval column and filter in the orders grid** (pending, approved, rejected, expired, cancelled, none; one query per page for the column). |
| Headless | Shop API `/api/v2/shop/company/approvals` (list, item, `approve`, `reject`); admin API `/api/v2/admin/order-approvals` and `/api/v2/admin/company-member-limits`. |
| Feed other tools | Symfony events after each step (`requested`, `approved`, `rejected`, `expired`); with [tmonier/sylius-webhook-outbox-plugin](https://github.com/thibmonier/sylius-webhook-outbox-plugin), webhooks `order.approval_requested`, `order.approved` (the business "order confirmed" event), `order.approval_rejected`, `order.approval_expired`, written in the transaction of the decision. |
| Staff orders | Orders created by staff with [tmonier/sylius-admin-order-creation-plugin](https://github.com/thibmonier/sylius-admin-order-creation-plugin) for a member follow the same rules: above the member's limit, they wait for an approval too, and its "payment link" e-mail is not offered until they are approved. |

Also included: translations in English, French, German, Spanish, Italian, Dutch and Polish
(pages, e-mails, messages); a demo fixture; MySQL, MariaDB and PostgreSQL support (Schema API
migration).

## Screenshots

![Approvals entry in the header, with the number of approvals to decide](docs/images/shop-header-approvals-desktop-en.jpg)

![Preview panel: the 5 oldest approvals to decide, opened from the header entry](docs/images/shop-header-preview-desktop-en.jpg)

![The same panel in the mobile account menu](docs/images/shop-header-preview-mobile-en.jpg)

![Orders awaiting approval on the approver's Approvals page](docs/images/shop-approvals.jpg)

![Approval decision: approve or reject with a comment for the buyer](docs/images/shop-approval-decision.jpg)

![Buyer's order awaiting approval, without the Pay button](docs/images/shop-order-awaiting-approval.jpg)

![Purchase limits managed by the company admin](docs/images/shop-purchase-limits.jpg)

![Approval card on the admin order page](docs/images/admin-order-approval.jpg)

![Admin orders grid filtered on Awaiting approval](docs/images/admin-orders-approval-filter.jpg)

## Requirements

| | Version |
|---|---|
| PHP | 8.2+ (Sylius 2.2), 8.3+ (Sylius 2.3); 8.5 tested with Sylius 2.3 and Symfony 8 |
| Sylius | 2.2.9+, 2.3 |
| Symfony | 6.4, 7.4 (Sylius 2.2); 6.4, 7.4, 8.0 (Sylius 2.3, Symfony 8 needs PHP 8.4) |
| Required | tmonier/sylius-company-accounts-plugin 1.1, tmonier/sylius-order-hold-plugin 1.0 |
| Optional | sylius/invoicing-plugin 2.x, tmonier/sylius-webhook-outbox-plugin 1.0, tmonier/sylius-admin-order-creation-plugin 1.0 |

The `sylius_order_checkout`, `sylius_order`, `sylius_payment` and `sylius_shipment` graphs must
use the Symfony Workflow adapter (the Sylius 2 default).

## Installation

1. Require the package:

    ```bash
    composer require tmonier/sylius-purchase-approval-plugin
    ```

2. Register the bundles in `config/bundles.php` (the two dependencies first):

    ```php
    Tmonier\SyliusOrderHoldPlugin\TmonierSyliusOrderHoldPlugin::class => ['all' => true],
    Tmonier\SyliusCompanyAccountsPlugin\TmonierSyliusCompanyAccountsPlugin::class => ['all' => true],
    Tmonier\SyliusPurchaseApprovalPlugin\TmonierSyliusPurchaseApprovalPlugin::class => ['all' => true],
    ```

3. Import the configuration (and the company-accounts one, see its README) in
   `config/packages/tmonier_sylius_purchase_approval.yaml`:

    ```yaml
    imports:
        - { resource: "@TmonierSyliusPurchaseApprovalPlugin/config/config.yaml" }
    ```

4. Import the routes in `config/routes/tmonier_sylius_purchase_approval.yaml`:

    ```yaml
    tmonier_sylius_purchase_approval:
        resource: "@TmonierSyliusPurchaseApprovalPlugin/config/routes.yaml"
    ```

    The shop pages live under `/{_locale}/account/company/approvals` and
    `/{_locale}/account/company/limits`, behind the Sylius account access control. The API
    operations are registered with the Sylius API (`/api/v2`).

5. Run the migrations (two tables):

    ```bash
    bin/console doctrine:migrations:migrate
    ```

6. Schedule the expiry, for instance daily:

    ```bash
    bin/console tmonier:approval:expire
    ```

## Configuration

```yaml
# config/packages/tmonier_sylius_purchase_approval.yaml
tmonier_sylius_purchase_approval:
    # Member roles that approve the orders of their colleagues (within their approval limit).
    approver_roles: [approver]
    # Pending approvals older than this are expired by tmonier:approval:expire (orders cancelled).
    expire_after_days: 14
    # "Approvals" entry with the pending count in the shop header (members who can approve only).
    header_badge: true
```

The models (`resources.order_approval`, `resources.member_limit`) can be overridden like any
Sylius resource.

### Rules

| | |
|---|---|
| Who needs an approval | An enabled member of an enabled company, signed in (or served by staff with admin-order-creation), whose order total is above their order limit, or whose limit is in another currency than the order. Guest checkouts never need one (company-accounts never treats them as company orders). |
| Who approves | Enabled members of the order's company with a role in `approver_roles`, never for their own order, and only up to their approval limit (same currency as the order; empty: no maximum). Rejecting is not capped by the approval limit. |
| Who sets the limits | Company admins in the shop (not their own), store administrators in the back office and the admin API. |
| Store administrators | Approve or reject from the order page or the admin API; the company rules (limits, own orders) do not apply to them. |
| Two decisions at the same time | The approval row is locked and re-read: the second decision gets "This order is no longer waiting for an approval." |

### E-mails

| Code | To | When |
|---|---|---|
| `tmonier_purchase_approval_acknowledgement` | buyer | instead of `order_confirmation` while the order waits |
| `order_confirmation` (core) | buyer | when the order is approved |
| `tmonier_purchase_approval_requested` | approvers of the company (not the buyer) | when the order is placed |
| `tmonier_purchase_approval_rejected` / `_expired` | buyer | with the comment of the decision |

Override them like any Sylius e-mail (`sylius_mailer.emails`).

An API confirmation (`SendOrderConfirmation`) consumed by a late worker after the decision is
dropped: the buyer gets the confirmation once (at the approval) and no acknowledgement any
more. Confirmations sent on purpose (code, the admin "Resend confirmation email") always go
out.

### Events

Symfony events with an `OrderApprovalEvent`, dispatched after the flush:
`tmonier_purchase_approval.order_approval.requested`, `.approved`, `.rejected`, `.expired`
(a cancellation by the store closes the approval without event: the core order events apply).

### Webhooks

The outbox still emits the core `order.placed` when the checkout of an order waiting for
approval completes: it means "received", not "confirmed". An integration that ships or bills on
orders must wait for `order.approved` when `order.approval_requested` was emitted for the same
order (both carry the order payload, plus an `approval` block), or rely on `order.placed` only
for orders without approval.

## API

| Operation | Who | Notes |
|---|---|---|
| `GET /api/v2/shop/company/approvals` | approvers | `?state=pending`; pending first; buyer first and last name, never their e-mail |
| `GET /api/v2/shop/company/approvals/{orderNumber}` | approvers, the buyer | `canApprove`, `canReject` for the signed-in customer |
| `POST /api/v2/shop/company/approvals/{orderNumber}/approve` / `reject` | approvers | `{"comment": "..."}` (optional, 1000 characters); 422 when refused (own order, limit, already decided) |
| `GET /api/v2/admin/order-approvals` (`?state=`), `GET .../{orderNumber}` | administrators | |
| `POST /api/v2/admin/order-approvals/{orderNumber}/approve` / `reject` | administrators | 422 when not pending |
| `/api/v2/admin/company-member-limits` (`{id}`) | administrators | CRUD; `member` IRI of `/api/v2/admin/company-members/{id}`, amounts in minor units |

Shop errors: 401 without token, 404 without membership or for an approval out of reach, 403 when
the permission is refused.

## Customisation

| Hook | Content |
|---|---|
| `tmonier_purchase_approval_shop.account.{approvals,approval,limits}.index.content.main` → `section` | Body of the pages |
| `sylius_shop.base.header.content.security.logged_in_user.desktop` and `….mobile.menu` → `tmonier_approvals` | "Approvals" entry with the pending count in the header (priority 50, between "My account" and "Log out") |
| `tmonier_company_accounts.shop.account.company.tabs` → `tmonier_approvals`, `tmonier_purchase_limits` | Approvals and Purchase limits tabs of "My company" (company-accounts 1.1 tabs hook) |
| `tmonier_purchase_approval_shop.account.{approvals,approval,limits}.index.content.main.header` → `navigation` | The company-accounts tab bar on the approval pages (`active_tab`: `approvals`, `limits`) |
| `sylius_shop.account.order.show.content.main.header` → `tmonier_approval` | Approval notice on the customer order page |
| `sylius_admin.order.show.content.sections#right` → `tmonier_approval` | Approval card of the admin order page |

Services: `ApprovalPolicy` (rules), `ApprovalDecisionMaker` (decisions),
`ApprovalRequirementChecker`, the voter `tmonier_purchase_approval.voter.approval`
(`APPROVAL_LIST`, `APPROVAL_VIEW`, `APPROVAL_DECIDE`, `APPROVAL_LIMITS_MANAGE`).

## Limitations

- One approval step, one approver; no multi-level approval, budgets per period, delegation or
  approval rules per category.
- Gateways that capture the money during the checkout itself (before the pay page) are not
  stopped: the payment is completed, a warning is logged (`tmonier_purchase_approval` channel)
  and the order still waits for its approval before shipment. Prefer offline or deferred
  capture methods for companies with limits.
- Invoicing tools other than sylius/invoicing-plugin are not bridged: if they invoice at
  checkout, an order rejected later needs a credit note.
- Limits are amounts per order, in one currency per member.
- Company admins do not see the approvals, nor the header entry, unless their role is in `approver_roles` (`approver_roles: [approver, admin]` makes them approvers: they decide like any approver, never on their own orders and within their own approval limit).
- The `requested` event and the e-mails to the approvers are sent after the flush, not after an
  outer transaction opened by the caller: the API checkout (Messenger `doctrine_transaction`
  middleware) and admin-order-creation commit just after; if that outer transaction rolled
  back, the approvers would have been notified of an order that does not exist.

## Development

```bash
cp compose.override.dist.yml compose.override.yml   # publishes MySQL and MailHog (or run `make init`)
docker compose up -d mysql mailhog
composer install
(cd vendor/sylius/test-application && yarn install && yarn build)
vendor/bin/console doctrine:database:create
vendor/bin/console doctrine:migrations:migrate -n
vendor/bin/console sylius:fixtures:load -n     # includes the demo company and approvals
```

Demo: `buyer@acme-industries.example.com` (order limit 50.00, two orders waiting),
`approver@acme-industries.example.com` (*My account > Approvals*), `admin@...` (*Purchase
limits*), password `sylius`.

The functional and integration tests need a **test database with the Sylius fixtures**:

```bash
echo 'DATABASE_URL=mysql://root@127.0.0.1:3306/tmonier_sylius_purchase_approval_%kernel.environment%' > tests/TestApplication/.env.test.local
APP_ENV=test vendor/bin/console doctrine:database:create
APP_ENV=test vendor/bin/console doctrine:migrations:migrate -n
APP_ENV=test vendor/bin/console sylius:fixtures:load -n

vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/ecs check
```

<!-- vitrine:demo-b2b:start -->
## B2B demo

This plugin is used in the [Sylius B2B demo](https://github.com/thibmonier/sylius-b2b-demo): a Sylius 2.3 shop with fictitious sample data where the B2B plugins work together. The source code, setup and fixtures of the demo are in that repository.

- Online demo: [sylius-b2b.thibaut-monier.fr](https://sylius-b2b.thibaut-monier.fr)
- Author: [Thibaut Monier](https://www.linkedin.com/in/thibautmonier/)

The demo is an example, not a production setup.
<!-- vitrine:demo-b2b:end -->

## License

MIT. See [LICENSE](LICENSE).
