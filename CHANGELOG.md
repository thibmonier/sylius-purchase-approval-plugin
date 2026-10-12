# Changelog

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
