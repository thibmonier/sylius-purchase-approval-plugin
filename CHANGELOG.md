# Changelog

## [1.3.0] - Unreleased

- Shop header: a click on the "Approvals" entry opens a panel with the 5 oldest approvals the
  member may decide (the ones that expire first): order number, buyer, amount, how long they have
  waited, "Expires in N days" when the expiry is within 3 days (`expire_after_days`), and a
  "See all (N)" link to the full list. The panel is fetched at the first click
  (`GET /account/company/approvals-preview`: one query for the rows, buyers and orders joined, plus
  the count), never with the page; it only navigates, decisions stay on the order page.
- Accessibility: the entry becomes a button with `aria-expanded`, the panel follows it in the tab
  order, Escape closes it and returns the focus to the button; without JavaScript the entry stays
  a link to the list.
- Same permission and same scope as the list (`APPROVAL_LIST`, the member's company, not their own
  orders, within their approval limit): 403 for members who do not approve.
- Translations of the new labels in en, fr, de, es, it, nl and pl.
- CI: PHP 8.5 (Sylius 2.3, Symfony 8) in the matrix, actions pinned by commit SHA,
  `permissions: contents: read`, Dependabot for GitHub Actions.

## 1.2.0 - 2026-10-22

- Shop header: an "Approvals" entry next to "My account" (desktop and mobile account menu) for
  members who can approve, with a badge counting the pending approvals they can decide (not their
  own orders, none above their approval limit; "99+" beyond 99). The count is announced as text
  ("3 approvals pending", "No approval pending"), takes one `COUNT` query per page and none for
  visitors, buyers or members who do not approve. Disable it with `header_badge: false`.
- Approvals list: `?decidable=1` lists exactly what the header counts.
- `approver_roles: [approver, admin]` documented: company admins can approve like any approver.
- Translations of the new labels in en, fr, de, es, it, nl and pl.

## 1.1.0

- Requires tmonier/sylius-company-accounts-plugin ^1.1: the Approvals and Purchase limits tabs
  are registered in its "My company" tabs hook, so they appear in the company tab bar on every
  company page; the approval pages show that same tab bar (no duplicated tabs, no extra links
  under the company tabs).

## 1.0.1 (2026-10-12)

- Shop: the approval pages show the company tabs (Overview, Members, Company orders) followed by
  Approvals and Purchase limits; the Approvals tab stays active on an approval page.
- Admin: approval column in the orders grid (one query per page).

## 1.0.0

First release: purchase approval for tmonier/sylius-company-accounts-plugin (order and approval
limits, approvals created at checkout, approve / reject / expire, payment and shipment held
through tmonier/sylius-order-hold-plugin, acknowledgement e-mail, invoicing and webhook outbox
bridges, shop, admin and API).
