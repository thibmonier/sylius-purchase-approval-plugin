# SPEC: tmonier/sylius-purchase-approval-plugin (v1b)

## 1. Sources

- Decision memo `docs/2026-10-11-memo-b2b.md` (sylius_plugins workspace), §2 (dependencies, order-hold
  kernel), §3.2 "v1b" and §5 (verifier corrections), and the wave 3 brief.
- Sylius 2.3 sources: `UnpaidOrdersStateUpdater` (5-day cancellation, a guard on `cancel` makes
  the command loop), `UpdatePaymentStateExtension` (Payum skips a guarded payment transition
  silently: payment guards do not stop a capture), shop `OrderCompleteListener`
  (`sylius.order.post_complete`) and API `SendOrderConfirmationHandler` (both call
  `OrderEmailManager::sendConfirmationEmail()` → `sylius.email_sender`), invoicing plugin
  `OrderPlacedProducer` (invoice as soon as the checkout completes).
- Legal (memo §5.20): an acknowledgement of receipt is due when the order is received (art. 11
  dir. 2000/31/EC, French C. civ. 1127-2), not silence; an invoice issued then cancelled needs a
  credit note (CGI 289), hence the invoicing bridge.
- Positioning: MIT alternative, native Sylius 2.2/2.3, for merchants without Sylius Plus. Never
  claim that Plus or Elesto lacks a feature; no reuse of their names or screens.

## 2. Scope

v1b exactly: `OrderApproval` (one per order), workflow `tmonier_order_approval`
(pending → approved / rejected / expired), order limit and approval limit with a currency per
member, no self-approval, one step, blocking through the order-hold kernel, shipment guard, no
guard on `sylius_order.cancel`, acknowledgement e-mail on both confirmation paths, optional
invoicing bridge, rejection / expiry → `sylius_order.cancel`, outbox `order.approved`,
`tmonier:approval:expire`. Shop approvals page, buyer status, admin status / filter / limits,
API for both.

Out of scope (memo): subsidiaries, payment restrictions per company, accountant role,
invitations, periodic budgets, multi-level approval, shared catalogues, multi-company customers.

## 3. Design

### 3.1 Data

- `tmonier_purchase_approval_order_approval`: order (unique, cascade), company (set null),
  state, order total and currency at request time, the buyer's order limit at request time
  (snapshot; null when the limit was in another currency), decision (type member /
  administrator / system, customer, name snapshot, comment, date). Index (state, created_at)
  for the expiry.
- `tmonier_purchase_approval_member_limit`: company member (unique, cascade), order limit,
  approval limit (minor units, nullable), currency. company-accounts is not modified: limits
  live in this plugin's table, edited through a form type extension of its member form.

### 3.2 Request

`OrderApprovalRequestListener` (onFlush, priority 100, before the outbox capture) creates the
pending approval for the orders whose checkout becomes `completed` in the flush (inserted
completed or updated), when `ApprovalRequirementChecker` says so: enabled member of an enabled
company (`isActive()`), order eligible for company-accounts (`CompanyOrderEligibilityCheckerInterface`:
signed-in account holder, or a staff order of admin-order-creation; never a guest checkout),
order limit set, and total above it or currency different. Same flush: nothing observes a placed
order without its approval. `requested` events are dispatched in postFlush and dropped when the
flush fails.

### 3.3 Decision

`ApprovalDecisionMaker`: transaction, `PESSIMISTIC_WRITE` refresh of the approval (and of the
deciding member), `ApprovalPolicy` for members (active, role in `approver_roles`, same company,
not the buyer, approval limit for approvals), workflow transition, decision fields; on reject /
expire the core `sylius_order.cancel` (when possible). Events after the commit. Store
administrators use `Decider::administrator()` and are not bound by the policy.

### 3.4 Holding the order (order-hold kernel)

`ApprovalHold` implements:
- `PaymentEntryBlockerInterface`: block while the approval is not approved (pay page, Payum
  capture / authorize routes for tokens created earlier, shop API payment requests).
- `UnpaidOrderCancellationExclusionInterface`: pending → indefinitely; approved →
  `since(decidedAt)` (the 5-day unpaid delay starts at the approval); rejected / expired → none
  (already cancelled).

No guard on `sylius_order.cancel` (memo §5.8). A guard on `sylius_shipment.ship`
(`ShipmentApprovalGuardListener`). Payments: no guard (it would not stop the PSP);
`PaymentCompletionAlertListener` logs a warning when a payment completes before the approval.

### 3.5 E-mails

`AcknowledgementEmailSender` decorates `sylius.email_sender`: `order_confirmation` of an order
waiting for approval becomes `tmonier_purchase_approval_acknowledgement`; of a rejected or
expired order it is dropped (late asynchronous message). One decorator covers the shop listener
and the Messenger handler. `ApprovalEmailListener`: approvers notified on request (not the
buyer), core confirmation on approval, rejection / expiry e-mail with the comment.

### 3.6 Bridges (loaded only when the bundle is enabled, decided at prepend time)

- sylius/invoicing-plugin: `ApprovalAwareInvoiceCreator` decorates
  `sylius_invoicing.creator.invoice` (skips orders not approved, also the mass generation);
  `InvoiceOnApprovalListener` dispatches the plugin's `OrderPlaced` event at approval.
- tmonier/sylius-webhook-outbox-plugin: `ApprovalChangeDetector` (unit of work, same
  transaction) and the event types. Payload: the core order payload plus approval state, company
  code and dates; never the free-text comment.

### 3.7 UI and API

- Shop: `ApprovalController` under `/account/company` (approvals list, approval page with
  approve / reject and comment, purchase limits), navigation links on the company-accounts pages
  through their header hooks, account menu entries, notice on the customer order page.
  `ApprovalVoter`: `APPROVAL_LIST` / `APPROVAL_LIMITS_MANAGE` on a company, `APPROVAL_VIEW` /
  `APPROVAL_DECIDE` on an approval. No membership 404, refused 403, out of reach 404.
- Admin: order page card with decision form, `tmonier_approval_state` filter of the orders grid
  (EXISTS / NOT EXISTS subquery), limit fields on the member form.
- API: shop and admin read models with attributes (`src/Api`), limits as an admin resource
  (`config/api_platform`).

## 4. Decisions (conservative where the brief and memo were silent)

1. **No limit, no approval**: a member without limits (or with an empty order limit) orders as
   before; installing the plugin changes nothing until limits are set.
2. **Another currency, approval**: an order in another currency than the buyer's limit always
   needs an approval; an approver whose approval limit is in another currency cannot approve it.
3. **Approvers are the `approver` role only** by default (`approver_roles`); company admins
   configure limits but approve only if the role list includes `admin`.
4. **No self-decision**: an approver can neither approve nor reject their own order.
5. **Rejecting is not capped** by the approval limit (the safe direction).
6. **Company admins set limits in the shop, never their own** (no self-escalation); store
   administrators set any limit.
7. **Store administrators can decide** from the order page / admin API, not bound by the company
   rules (e.g. no approver available).
8. **Expiry after 14 days**, configurable; expiry cancels the order like a rejection.
9. **Staff orders** (admin-order-creation) for a member follow the same rules (brief).
10. **Guest checkouts never need an approval** (they are not company orders).
11. **The order total** (items, shipping, taxes, promotions) is compared with the limit; the
    limit and total are snapshotted on the approval; changing a limit later does not change
    pending approvals.
12. **Visibility**: the buyer sees the approval of their order (state, decider name, comment);
    approvers see the company's approvals with buyer names, never e-mails or phones.
13. **Payments captured during checkout** are not reversed: logged for the merchant.
14. A company deleted with pending approvals: the approval keeps the order (company set to
    null); only the store can decide.

## 5. Not verified

- Real payment service providers that capture during the checkout (only offline and a Payum
  capture token of an offline gateway are tested).
- sylius/invoicing-plugin end to end: the bridge is tested with unit tests (decorator and
  listener) and a DI test, not with the invoicing bundle enabled in the test application (it
  needs its PDF and storage setup).
- Sylius 2.2 hook names for the account pages: same names in the 2.2 branch, reproduced in a
  throwaway Sylius 2.2.10 + Symfony 6.4 copy before release.
