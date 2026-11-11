# RFQ API Test Guide (P2-2)

Base URL: `http://127.0.0.1:8000`
All endpoints expect `Accept: application/json`; write calls need `Content-Type: application/json`.
Every `POST`/`GET` below needs `Authorization: Bearer <token>` for the right portal.

---

## 0) Tokens you need

| Portal | Login endpoint | Body |
|---|---|---|
| Importer (buyer) | `POST /api/v1/importer/login` | `{"email":"buy_6af4402f8d4a4@example.com","password":"password"}` (user 10242) |
| Exporter (supplier) | `POST /api/v1/exporter/login` | `{"email":"sup_6af4402f88d49@example.com","password":"password"}` (user 10241) |
| Admin | `POST /api/v1/admin/login` | admin credentials |

Response: `data.token` → use as `Authorization: Bearer <token>`.

### Real RFQ rows in this database

| RFQ id | buyer | supplier | status |
|---|---|---|---|
| 110 | 10238 | 10237 | submitted |
| 111 | 10240 | 10239 | submitted |
| 112 | 10242 | 10241 | submitted |
| 109 | 10236 | 10235 | rejected (consumed by a reject test) |

Buyer passwords are `password`; suppliers created alongside them also use `password`.

---

## 1) Importer (buyer) endpoints — 9

| # | Method | Path | Body | Expected |
|---|---|---|---|---|
| 1 | POST | `/api/v1/importer/rfqs` | see below | 201, `data.status = submitted` |
| 2 | GET | `/api/v1/importer/rfqs?status=draft&per_page=15` | — | 200 paginated |
| 3 | GET | `/api/v1/importer/rfqs/{id}` | — | 200, `data` + product/quotations/messages |
| 4 | POST | `/api/v1/importer/rfqs/{id}/save-draft` | `{"draft_notes":"...","notes":"...","requested_quantity":20,"unit":"MT","target_price":950}` | 200, `status=draft`, `draft_saved_at` set |
| 5 | POST | `/api/v1/importer/rfqs/{id}/assign` | `{"assigned_to":<user id>}` | 200, `assigned_to/assigned_by/assigned_at` |
| 6 | POST | `/api/v1/importer/rfqs/{id}/broadcast` | `{"supplier_ids":[10241,10235],"message":"Please quote"}` | 200, `invited_supplier_ids`, `invitation_count` |
| 7 | GET | `/api/v1/importer/rfqs/{id}/invitations` | — | 200, `summary{total,invited,viewed,responded,declined}` |
| 8 | POST | `/api/v1/importer/rfqs/{id}/messages` | `{"message":"Please confirm moisture"}` | 201; status → `clarification_needed` |
| 9 | POST | `/api/v1/importer/rfqs/{id}/cancel` | — | 200, `data.status = closed` (400 if converted/closed) |

Create body (required fields marked):

```json
{
  "product_id": 260,
  "requested_quantity": 10,
  "unit": "MT",
  "trade_type": "international",
  "destination_country": "UAE",
  "destination_port_or_address": "Jebel Ali Port",
  "target_price": 900,
  "quality_specification": "Grade A Premium",
  "notes": "Testing RFQ creation"
}
```

Rules worth testing:

- Another seller's product is fine (buyer may raise an RFQ on any product).
- `supplier_id` defaults to the product owner; sending your own id → 422.
- save-draft on a `closed` RFQ → 400; on another buyer's RFQ → 404.
- broadcast on `closed`/`rejected`/`cancelled`/`converted` → 400; duplicate ids are de-duplicated.
- invitations of another buyer → 404.

---

## 2) Exporter (supplier) endpoints — 7

| # | Method | Path | Body | Expected |
|---|---|---|---|---|
| 10 | GET | `/api/v1/exporter/rfqs?status=submitted&per_page=15` | — | 200 (own + invited + own-product RFQs) |
| 11 | GET | `/api/v1/exporter/rfqs/{id}` | — | 200; 403 if RFQ exists but you are not a party; 404 if id missing |
| 12 | POST | `/api/v1/exporter/rfqs/{id}/reject` | `{"rejection_reason":"Unable to supply the requested quantity"}` | 200, `status=rejected`, `rejection_reason`, `rejection_code=other` |
| 13 | POST | `/api/v1/exporter/rfqs/{id}/offers` | `{"offers":[{"price":930,"incoterm":"FOB","lead_time_days":20,"validity_date":"2026-12-31"}]}` | 200, offer appended with `supplier_id` |
| 14 | POST | `/api/v1/exporter/rfqs/{id}/messages` | `{"message":"We can ship in 3 weeks"}` | 201 |
| 15 | GET | `/api/v1/exporter/rfq-invitations?status=invited` | — | 200 paginated invitations |
| 16 | POST | `/api/v1/exporter/rfq-invitations/{id}/respond` | `{"action":"view"}` / `{"action":"accept"}` / `{"action":"decline","reason":"Out of stock"}` | 200; re-answer → 409 |

Reject also accepts `{"reason_code":"out_of_stock","reason":"..."}`; `reason_code` defaults to `other`.
On a multi-supplier broadcast one supplier's reject only declines its own invitation (RFQ stays open); a single-supplier RFQ becomes `rejected`.
Repeat reject → 400 (`RFQ cannot be rejected while its status is rejected`); missing reason → 422.

---

## 3) Admin endpoints — 5

| # | Method | Path | Body | Expected |
|---|---|---|---|---|
| 17 | GET | `/api/v1/admin/rfqs?status=submitted&unassigned=1&search=RFQ&per_page=20` | — | 200 paginated queue |
| 18 | GET | `/api/v1/admin/rfqs/stats` | — | 200 `{total,unassigned,open,rejected,converted}` |
| 19 | GET | `/api/v1/admin/rfqs/{id}` | — | 200 with buyer/supplier/product/messages/invitations/quotations |
| 20 | POST | `/api/v1/admin/rfqs/{id}/assign` | `{"assigned_to":<staff id>,"note":"Follow up"}` | 200, `assigned_by` = acting admin, status unchanged unless `status` sent |
| 21 | POST | `/api/v1/admin/rfqs/{id}/unassign` | — | 200, `assigned_to = null` |

Assignee must be an `admin` or `staff` user (else 422); non-admin caller → 403.
`assign` also accepts optional `status` (`submitted|under_review|supplier_responding`) and `note` (stored as an RFQ message).

---

## 4) Suggested end-to-end order

1. Supplier login → get exporter token. Buyer login → get importer token. Admin login → admin token.
2. Buyer: `POST /importer/rfqs` → note `data.id` (call it R).
3. Buyer: `POST /importer/rfqs/R/save-draft` → expect 200 `status=draft`.
4. Buyer: `POST /importer/rfqs/R/broadcast` with two supplier ids → expect `invitation_count=2`.
5. Buyer: `GET /importer/rfqs/R/invitations` → summary counts.
6. Supplier B login: `GET /exporter/rfqs` (RFQ R must appear), `GET /exporter/rfqs/R`, then `POST /exporter/rfq-invitations/{id}/respond {"action":"view"}`.
7. Supplier B: `POST /exporter/rfqs/R/offers` → 200; buyer's invitations summary should show `responded=1`.
8. Supplier A: `POST /exporter/rfqs/R/reject {"rejection_reason":"..."}` → 200 `RFQ declined by supplier` (R stays open because B still has an invitation).
9. Admin: `POST /admin/rfqs/R/assign {"assigned_to":<staff id>}` → 200; then `GET /admin/rfqs/R`, then `POST /admin/rfqs/R/unassign`.
10. Buyer: `POST /importer/rfqs/R/messages`, `GET /importer/rfqs/R`, `POST /importer/rfqs/R/cancel` → `status=closed`.
11. Buyer: `POST /importer/rfqs/R/broadcast` again → 400 (closed).

---

## 5) Status codes used

| Code | Meaning in these APIs |
|---|---|
| 200 | OK (draft saved, reject/decline, broadcast, assign, invitations) |
| 201 | RFQ created, message sent |
| 400 | state conflict (`closed`, `converted`, `rejected`, already rejected) |
| 403 | authenticated but not a party to the RFQ |
| 404 | no such RFQ / not your resource |
| 409 | invitation already answered / already declined |
| 422 | validation failure (missing body fields, non back-office assignee) |
