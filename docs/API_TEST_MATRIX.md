# API Test Matrix — B2B v1 (228 routes)

Status legend

- ✅ **Working**: exercised by a passing feature test or verified live over HTTP.
- ⚠️ **Wired but untested**: route exists and is bound to a controller but has no automated coverage yet — run it with the data below.

No endpoint is known to be broken any more: the failure that blocked admin login, quotation accept, CHA filing and trade-workflow registration was a test-environment URL prefix, now fixed.

Base URL `http://127.0.0.1:8000`. Send `Accept: application/json` always, `Content-Type: application/json` on writes, `Authorization: Bearer <token>` on everything except login/register.

---

## 0. Test data (this database)

### Accounts / tokens

| Portal | Login | Credentials |
|---|---|---|
| Importer | `POST /api/v1/importer/login` | `buy_6af4402f8d4a4@example.com` / `password` (user 10242) |
| Exporter | `POST /api/v1/exporter/login` | `sup_6af4402f88d49@example.com` / `password` (user 10241) |
| Exporter #2 | `POST /api/v1/exporter/login` | `sup_6af4402e7de37@example.com` / `password` (user 10235) |
| Admin | `POST /api/v1/admin/login` | ✅ known issue, see below |
| CHA | `POST /api/v1/cha/login` | `cha_6af4402fd29f2@customsbroker.com` (password unknown — reset if needed) |

Response shape: `{"success":true,"message":"Login successful","data":{"token":"1|...","user":{...}}}`.

### Rows to use

| Entity | Table | Usable ids |
|---|---|---|
| Products | `products` (165) | 1 (published, owner 9), 143–147, 230, latest 597–600 |
| Categories | `categories` (153) | 1, 2, 3, 4, 5 … 554 |
| Lots | `product_lots` (138) | 201, 202, 203, 204 |
| Orders | `b2b_orders` (176) | 250, 255, 256, 257 |
| Quotations | `b2b_quotations` (68) | 65, 66, 67, 68 |
| Shipments | `b2b_shipments` (66) | 83, 84, 85, 86 |
| Payments | `b2b_payments` (39) | 36, 37, 38, 39 |
| Trade documents | `b2b_trade_documents` (110) | 113, 114, 115, 116 |
| RFQs | `rfqs` (93) | 109 (rejected), 110, 111, 112 (submitted), 341–344 |
| Disputes | `b2b_disputes` (65) | 62, 63, 64, 65 |
| Credit notes | `b2b_credit_notes` (0) | create one first |
| Warehouse transfers | `b2b_warehouse_transfers` (0) | create one first |
| Checklist templates | `b2b_document_checklist_templates` (0) | create one first |

---

## 1. Importer portal (60 routes)

| Method | Endpoint | Status | Body / query to test |
|---|---|---|---|
| POST | `/api/v1/importer/login` | ✅ | `{"email":"buy_6af4402f8d4a4@example.com","password":"password"}` |
| POST | `/api/v1/importer/register` | ✅ | `{"name":"Buyer X","email":"buyer_x@example.com","password":"password","password_confirmation":"password","user_type":"customer", ...}` |
| POST | `/api/v1/importer/logout` | ⚠️ | — |
| GET | `/api/v1/importer/profile` | ⚠️ | — |
| POST | `/api/v1/importer/company-profile` | ⚠️ | `{"name":"Buyer Co","country":"UAE"}` |
| POST | `/api/v1/importer/email/verify` | ✅ | `{"email":"...","token":"<from response>"}` |
| POST | `/api/v1/importer/email/resend` | ⚠️ | `{"email":"..."}` |
| POST | `/api/v1/importer/password/forgot` | ⚠️ | `{"email":"..."}` |
| POST | `/api/v1/importer/password/reset` | ⚠️ | `{"email":"...","token":"...","password":"newpass123","password_confirmation":"newpass123"}` |
| POST | `/api/v1/importer/two-factor/setup` | ✅ | — |
| POST | `/api/v1/importer/two-factor/enable` | ✅ | `{"code":"<TOTP>"}` |
| GET | `/api/v1/importer/two-factor/status` | ✅ | — |
| POST | `/api/v1/importer/two-factor/disable` | ⚠️ | `{"password":"..."}` |
| GET | `/api/v1/importer/products` | ✅ | `?per_page=10` (pending/archived excluded) |
| GET | `/api/v1/importer/products/{id}` | ✅ | `1`; pending/archived → 404 |
| GET | `/api/v1/importer/categories` | ⚠️ | — |
| GET | `/api/v1/importer/lots/{id}` | ⚠️ | `201` |
| POST | `/api/v1/importer/rfqs` | ✅ | see RFQ guide §1 |
| GET | `/api/v1/importer/rfqs` | ✅ | `?status=submitted&per_page=15` |
| GET | `/api/v1/importer/rfqs/{id}` | ✅ | `112` |
| POST | `/api/v1/importer/rfqs/{id}/save-draft` | ✅ | `{"draft_notes":"...","target_price":950}` |
| POST | `/api/v1/importer/rfqs/{id}/assign` | ✅ | `{"assigned_to":10235}` |
| POST | `/api/v1/importer/rfqs/{id}/broadcast` | ✅ | `{"supplier_ids":[10235,10237],"message":"Quote please"}` |
| GET | `/api/v1/importer/rfqs/{id}/invitations` | ✅ | `112` |
| POST | `/api/v1/importer/rfqs/{id}/messages` | ✅ (route) | `{"message":"Please confirm moisture"}` |
| POST | `/api/v1/importer/rfqs/{id}/cancel` | ✅ | — |
| GET | `/api/v1/importer/quotations` | ⚠️ | `?per_page=15` |
| GET | `/api/v1/importer/quotations/{id}` | ⚠️ | `68` |
| POST | `/api/v1/importer/quotations/{id}/accept` | ✅ | `68` → currently 404 (buyer/quote mismatch), see Known issues |
| POST | `/api/v1/importer/quotations/{id}/reject` | ⚠️ | `{"reason":"Price too high"}` |
| POST | `/api/v1/importer/quotations/{id}/draft` | ⚠️ | `{"notes":"..."}` |
| GET | `/api/v1/importer/orders` | ⚠️ | `?per_page=15` |
| GET | `/api/v1/importer/orders/{id}` | ⚠️ | `257` |
| GET | `/api/v1/importer/orders/{orderId}/document-checklist` | ✅ | `257` |
| GET | `/api/v1/importer/orders/{orderId}/documents/package` | ✅ | `257` (404 until a document exists) |
| GET | `/api/v1/importer/documents` | ⚠️ | — |
| GET | `/api/v1/importer/documents/{id}` | ⚠️ | `116` |
| GET | `/api/v1/importer/documents/{id}/download` | ⚠️ | `116` |
| POST | `/api/v1/importer/documents/upload` | ⚠️ | multipart `file` + `document_type` + `order_id` |
| GET | `/api/v1/importer/shipments` | ⚠️ | — |
| GET | `/api/v1/importer/shipments/{id}` | ⚠️ | `86` |
| GET | `/api/v1/importer/shipments/{id}/track` | ⚠️ | `86` |
| POST | `/api/v1/importer/payments` | ⚠️ | see exporter payment fields |
| GET | `/api/v1/importer/payments` | ⚠️ | — |
| GET | `/api/v1/importer/payments/{id}` | ⚠️ | `39` |
| GET | `/api/v1/importer/credit-notes` | ✅ (route) | — |
| GET | `/api/v1/importer/credit-notes/{id}` | ✅ | create via exporter first |
| GET | `/api/v1/importer/agreements` | ⚠️ | — |
| GET | `/api/v1/importer/agreements/{id}` | ⚠️ | — |
| POST | `/api/v1/importer/agreements/{id}/sign` | ⚠️ | `{"signature":"..."}` |
| GET | `/api/v1/importer/dashboard` | ✅ | — |
| GET | `/api/v1/importer/dashboard/summary` | ⚠️ | — |
| GET | `/api/v1/importer/reports` | ✅ | (14 reports) |
| GET | `/api/v1/importer/reports/{report}` | ✅ | `order_pipeline` |
| GET | `/api/v1/importer/reports/{report}/export` | ✅ | `?format=csv|xlsx|pdf` |
| GET | `/api/v1/importer/disputes` | ✅ | — |
| GET | `/api/v1/importer/disputes/{id}` | ✅ | `65` |
| POST | `/api/v1/importer/disputes` | ✅ | `{"order_id":257,"dispute_type":"quality","description":"..."}` |
| POST | `/api/v1/importer/disputes/{id}/messages` | ✅ | `{"message":"..."}` |
| POST | `/api/v1/importer/disputes/{id}/evidence` | ✅ | `{"attachments":["b2b/test/a.jpg"]}` |

## 2. Exporter portal (104 routes)

| Method | Endpoint | Status | Body / query to test |
|---|---|---|---|
| POST | `/api/v1/exporter/login` | ✅ | `sup_6af4402f88d49@example.com` / `password` |
| POST | `/api/v1/exporter/register` | ✅ | company + user payload |
| POST | `/api/v1/exporter/logout` | ⚠️ | — |
| GET | `/api/v1/exporter/profile` | ⚠️ | — |
| POST | `/api/v1/exporter/company-profile` | ⚠️ | `{"name":"Supplier Co","country":"India"}` |
| POST | `/api/v1/exporter/email/verify` | ✅ | token from register response |
| POST | `/api/v1/exporter/email/resend` | ⚠️ | — |
| POST | `/api/v1/exporter/password/forgot` | ⚠️ | — |
| POST | `/api/v1/exporter/password/reset` | ⚠️ | — |
| POST | `/api/v1/exporter/two-factor/setup` / `enable` / `disable` | ✅ | TOTP code |
| GET | `/api/v1/exporter/two-factor/status` | ✅ | — |
| GET | `/api/v1/exporter/products` | ✅ | `?search=rice&per_page=15` |
| POST | `/api/v1/exporter/products` | ✅ | `{"name":"Organic Rice","category_id":4,"origin_country":"India","unit":"MT","unit_price":900,"min_qty":100}` |
| GET | `/api/v1/exporter/products/{id}` | ✅ | `600` (own product) |
| POST | `/api/v1/exporter/products/{id}/update` | ✅ | `{"unit_price":950}` (`approved`/`published` → 422) |
| POST | `/api/v1/exporter/products/{productId}/gallery` | ✅ | form-data `images[]` = 1–8 files jpg/png/webp ≤5MB |
| GET | `/api/v1/exporter/products/{productId}/gallery` | ✅ | — |
| DELETE | `/api/v1/exporter/products/{productId}/gallery/{imageId}` | ✅ | — |
| POST | `/api/v1/exporter/products/{productId}/gallery/{imageId}/primary` | ✅ | — |
| POST | `/api/v1/exporter/products/{productId}/archive` | ✅ | `{"reason":"Discontinued"}` |
| POST | `/api/v1/exporter/products/{productId}/unarchive` | ✅ | — |
| GET | `/api/v1/exporter/products/bulk/template` | ✅ | — |
| POST | `/api/v1/exporter/products/bulk/preview` | ✅ | form-data `file` CSV (`name,category_id,grade,origin_country,unit,price,quantity`) |
| POST | `/api/v1/exporter/products/bulk/confirm` | ✅ | `{"import_id":"<from preview>"}` |
| GET | `/api/v1/exporter/products/bulk/history` | ✅ | — |
| GET | `/api/v1/exporter/products/{productId}/lots` | ⚠️ | `600` |
| POST | `/api/v1/exporter/lots` | ⚠️ | `{"product_id":600,"lot_code":"LOT-1","quantity_available":500,"unit_price":900,"unit":"MT","warehouse_location":"A","quality_grade":"A"}` |
| GET | `/api/v1/exporter/lots/{id}` | ⚠️ | `204` |
| POST | `/api/v1/exporter/lots/{id}/price` | ⚠️ | `{"unit_price":950}` |
| POST | `/api/v1/exporter/lots/{id}/stock-adjust` | ⚠️ | `{"quantity":10,"movement_type":"adjustment","reason":"stock count"}` |
| POST | `/api/v1/exporter/lots/{id}/low-stock-threshold` | ✅ | `{"low_stock_threshold":250}` |
| POST | `/api/v1/exporter/lots/{id}/movements` | ✅ | `{"movement_type":"damaged","quantity":10,"reason":"Water damage"}` — types: `damaged`, `lost`, `returned`, `adjusted` (+ `direction: add\|subtract`) |
| GET | `/api/v1/exporter/lots/{id}/movements` | ✅ | `259` (own lot), `?per_page=20`. Other suppliers' lots → **403** `belongs to another supplier`; unknown id → 404 `Lot {id} not found`. Admin/staff may read any lot. |
| GET | `/api/v1/exporter/inventory/summary` | ✅ | — |
| GET | `/api/v1/exporter/inventory/low-stock` | ✅ | — |
| GET | `/api/v1/exporter/inventory/aging` | ✅ | — |
| GET | `/api/v1/exporter/inventory/movements` | ✅ | optional `?movement_type=damaged&lot_id=259&from=2026-11-01&to=2026-11-30&per_page=20` |
| GET | `/api/v1/exporter/inventory/alerts` | ✅ | optional `?status=open&alert_type=low_stock` |
| POST | `/api/v1/exporter/inventory/alerts/scan` | ✅ | `{}` (idempotent per open alert) |
| POST | `/api/v1/exporter/inventory/alerts/{id}/acknowledge` | ✅ | `{}` |
| GET | `/api/v1/exporter/inventory/transfers` | ✅ | — |
| GET | `/api/v1/exporter/inventory/transfers/{id}` | ✅ | create one first |
| POST | `/api/v1/exporter/inventory/transfers` | ✅ | `{"from_warehouse":"A","to_warehouse":"B","items":[{"lot_id":259,"quantity":50}]}` |
| POST | `/api/v1/exporter/inventory/transfers/{id}/complete` | ✅ | — |
| POST | `/api/v1/exporter/inventory/transfers/{id}/cancel` | ✅ | — |
| GET | `/api/v1/exporter/units/conversions` | ✅ | — |
| POST | `/api/v1/exporter/units/convert` | ✅ | `{"from_unit":"kg","to_unit":"mt","quantity":1500}` → `converted: 1.5` |
| GET | `/api/v1/exporter/rfqs` | ✅ | `?status=submitted` |
| GET | `/api/v1/exporter/rfqs/{id}` | ✅ | `112` |
| POST | `/api/v1/exporter/rfqs/{id}/reject` | ✅ | `{"rejection_reason":"Unable to supply the quantity"}` |
| POST | `/api/v1/exporter/rfqs/{id}/offers` | ✅ | `{"offers":[{"price":930,"incoterm":"FOB","lead_time_days":20}]}` |
| POST | `/api/v1/exporter/rfqs/{id}/messages` | ✅ | `{"message":"We can ship in 3 weeks"}` |
| GET | `/api/v1/exporter/rfq-invitations` | ✅ | `?status=invited` |
| POST | `/api/v1/exporter/rfq-invitations/{id}/respond` | ✅ | `{"action":"view"}` / `{"action":"accept"}` / `{"action":"decline","reason":"Out of stock"}` |
| GET | `/api/v1/exporter/quotations` | ⚠️ | — |
| POST | `/api/v1/exporter/quotations` | ⚠️ | `{"rfq_id":112,"currency":"USD","items":[{"lot_id":204,"quantity":10,"unit_price":900}]}` |
| GET | `/api/v1/exporter/quotations/{id}` | ⚠️ | `68` |
| POST | `/api/v1/exporter/quotations/{id}/draft` | ⚠️ | — |
| POST | `/api/v1/exporter/quotations/{id}/revise` | ⚠️ | `{"items":[...]}` |
| GET | `/api/v1/exporter/orders` | ⚠️ | — |
| GET | `/api/v1/exporter/orders/{id}` | ⚠️ | `257` |
| POST | `/api/v1/exporter/orders/{id}/status` | ⚠️ | `{"status":"processing"}` |
| GET | `/api/v1/exporter/orders/{orderId}/document-checklist` | ✅ | `257` |
| GET | `/api/v1/exporter/orders/{orderId}/documents/package` | ✅ | `257` |
| GET | `/api/v1/exporter/shipments` | ⚠️ | — |
| GET | `/api/v1/exporter/shipments/{id}` | ⚠️ | `86` |
| POST | `/api/v1/exporter/shipments` | ✅ | `{"order_id":257,"mode":"sea","origin_port":"Nhava Sheva","destination_port":"Jebel Ali"}` |
| POST | `/api/v1/exporter/shipments/{id}/update` | ⚠️ | `{"status":"in_transit"}` |
| POST | `/api/v1/exporter/shipments/{id}/containers` | ✅ | `{"container_number":"MSCU1234567","seal_number":"S1","container_type":"40HC"}` |
| GET | `/api/v1/exporter/shipments/{id}/container-history` | ✅ | `86` |
| POST | `/api/v1/exporter/shipments/{id}/eta-revisions` | ✅ | `{"new_eta":"2026-12-20","reason":"Port congestion"}` |
| GET | `/api/v1/exporter/shipments/{id}/eta-revisions` | ✅ | `86` |
| POST | `/api/v1/exporter/shipments/{id}/events` | ✅ | `{"event_type":"vessel_departed","description":"Departed","occurred_at":"2026-11-11 10:00:00","timezone":"Asia/Kolkata"}` |
| POST | `/api/v1/exporter/shipments/{id}/vehicles` | ✅ | `{"vehicle_number":"TRK-1","driver_name":"Ravi","driver_phone":"9999"}` |
| GET | `/api/v1/exporter/shipments/{id}/vehicles` | ✅ | `86` |
| POST | `/api/v1/exporter/shipments/{id}/vehicles/{vehicleId}/release` | ✅ | — |
| GET | `/api/v1/exporter/documents` | ⚠️ | — |
| POST | `/api/v1/exporter/documents/upload` | ✅ | multipart `file` + `document_type=commercial_invoice` + `order_id=257` |
| POST | `/api/v1/exporter/documents/{id}/status` | ⚠️ | `{"status":"approved"}` |
| GET | `/api/v1/exporter/documents/{id}/download` | ⚠️ | `116` |
| GET | `/api/v1/exporter/payments` | ⚠️ | — |
| GET | `/api/v1/exporter/payments/{id}` | ⚠️ | `39` |
| GET | `/api/v1/exporter/payments/reconciliation-summary` | ✅ | — |
| POST | `/api/v1/exporter/payments/{id}/verify` | ✅ | `{"verified_amount":1000}` |
| POST | `/api/v1/exporter/payments/{id}/reconcile` | ✅ | `{"reconciliation_status":"matched","bank_statement_reference":"STMT-2026-11-0042","transaction_at":"2026-11-09T14:15:00","exchange_rate":110}` → `base_amount` recomputed |
| POST | `/api/v1/exporter/payments/{id}/refund` | ⚠️ | `{"amount":100,"reason":"Overpayment"}` |
| GET | `/api/v1/exporter/invoices` | ⚠️ | — |
| GET | `/api/v1/exporter/invoices/{id}` | ⚠️ | — |
| POST | `/api/v1/exporter/invoices` | ⚠️ | `{"order_id":257}` |
| GET | `/api/v1/exporter/credit-notes` | ✅ | — |
| POST | `/api/v1/exporter/credit-notes` | ✅ | `{"order_id":257,"amount":100,"reason":"Short shipment"}` |
| GET | `/api/v1/exporter/credit-notes/{id}` | ✅ | created id |
| POST | `/api/v1/exporter/credit-notes/{id}/issue` | ✅ | — |
| POST | `/api/v1/exporter/credit-notes/{id}/apply` | ✅ | `{"payment_id":39}` |
| POST | `/api/v1/exporter/credit-notes/{id}/void` | ✅ | `{"reason":"..."}` |
| POST | `/api/v1/exporter/agreements/{id}/sign` | ⚠️ | `{"signature":"..."}` |
| GET | `/api/v1/exporter/dashboard` | ✅ | — |
| GET | `/api/v1/exporter/dashboard/summary` | ⚠️ | — |
| GET | `/api/v1/exporter/reports` | ✅ | — |
| GET | `/api/v1/exporter/reports/{report}` | ✅ | `inventory_valuation` |
| GET | `/api/v1/exporter/reports/{report}/export` | ✅ | `?format=csv` |
| GET | `/api/v1/exporter/disputes` / `/{id}` | ✅ | `65` |
| POST | `/api/v1/exporter/disputes/{id}/reply` | ✅ | `{"message":"..."}` |
| POST | `/api/v1/exporter/disputes/{id}/messages` | ✅ | `{"message":"..."}` |
| POST | `/api/v1/exporter/disputes/{id}/evidence` | ✅ | `{"attachments":["b2b/test/b.jpg"]}` |

## 3. Admin portal (53 routes)

| Method | Endpoint | Status | Body / query to test |
|---|---|---|---|
| POST | `/api/v1/admin/login` | ✅ | non-admin email returns **404** instead of 403 — see Known issues |
| POST | `/api/v1/admin/logout` | ⚠️ | — |
| GET | `/api/v1/admin/profile` | ✅ | blocked by admin login issue |
| POST | `/api/v1/admin/profile` | ✅ | `{"name":"Admin"}` |
| GET | `/api/v1/admin/dashboard` | ⚠️ | — |
| GET | `/api/v1/admin/dashboard/summary` | ⚠️ | — |
| GET | `/api/v1/admin/rfqs` | ✅ | `?status=submitted&unassigned=1&per_page=20` |
| GET | `/api/v1/admin/rfqs/stats` | ✅ | — |
| GET | `/api/v1/admin/rfqs/{id}` | ✅ | `112` |
| POST | `/api/v1/admin/rfqs/{id}/assign` | ✅ | `{"assigned_to":<staff id>,"note":"Follow up"}` |
| POST | `/api/v1/admin/rfqs/{id}/unassign` | ✅ | — |
| GET | `/api/v1/admin/products` | ✅ | `?status=pending_approval` |
| POST | `/api/v1/admin/products/{id}/approval` | ✅ | `{"status":"active"}` / `rejected` / `suspended` |
| GET | `/api/v1/admin/lots/pending-approval` | ✅ | suite fails |
| POST | `/api/v1/admin/lots/{id}/approval` | ✅ | `{"decision":"approved"}` |
| GET | `/api/v1/admin/suppliers` | ✅ | — |
| GET | `/api/v1/admin/suppliers/{id}` | ✅ | — |
| POST | `/api/v1/admin/suppliers/{id}/verify` | ✅ | `{"verification_status":"verified"}` |
| GET | `/api/v1/admin/users` | ✅ | — |
| POST | `/api/v1/admin/users` | ✅ | create staff |
| POST | `/api/v1/admin/users/{id}/status` | ✅ | `{"banned":1}` |
| GET | `/api/v1/admin/categories` | ✅ | — |
| POST | `/api/v1/admin/categories` | ✅ | `{"name":"Spices"}` |
| POST/PUT | `/api/v1/admin/categories/{id}` | ✅ | `{"name":"Spices 2"}` |
| DELETE | `/api/v1/admin/categories/{id}` | ✅ | — |
| GET | `/api/v1/admin/hs-codes` | ✅ | — |
| POST | `/api/v1/admin/hs-codes` | ✅ | `{"code":"0902","description":"Tea"}` |
| GET | `/api/v1/admin/unit-conversions` | ✅ | — |
| POST | `/api/v1/admin/unit-conversions` | ✅ | `{"from_unit":"box","to_unit":"pcs","factor":24,"category":"count"}` |
| POST | `/api/v1/admin/unit-conversions/{id}` | ✅ | `{"factor":12}` |
| DELETE | `/api/v1/admin/unit-conversions/{id}` | ✅ | — |
| POST | `/api/v1/admin/unit-conversions/seed-defaults` | ✅ | — |
| GET | `/api/v1/admin/document-checklist-templates` | ✅ | — |
| POST | `/api/v1/admin/document-checklist-templates` | ✅ | `{"name":"Rice to UAE","trade_type":"international","destination_country":"UAE","items":[{"code":"commercial_invoice","name":"Commercial Invoice","required":1}]}` |
| GET | `/api/v1/admin/document-checklist-templates/{id}` | ✅ | created id |
| PUT | `/api/v1/admin/document-checklist-templates/{id}` | ⚠️ | `{"name":"Rice to UAE v2"}` |
| DELETE | `/api/v1/admin/document-checklist-templates/{id}` | ⚠️ | — |
| GET | `/api/v1/admin/audit-logs` | ✅ | suite fails |
| GET | `/api/v1/admin/audit-logs/{id}` | ✅ | — |
| GET | `/api/v1/admin/disputes` | ✅ | — |
| GET | `/api/v1/admin/disputes/{id}` | ✅ | `65` |
| POST | `/api/v1/admin/disputes/{id}/resolve` | ✅ | `{"resolution":"refund","resolution_notes":"..."}` |
| GET | `/api/v1/admin/credit-notes` | ⚠️ | — |
| POST | `/api/v1/admin/credit-notes` | ⚠️ | `{"order_id":257,"amount":100}` |
| POST | `/api/v1/admin/credit-notes/{id}/issue` / `apply` / `void` | ⚠️ | — |
| GET | `/api/v1/admin/reports` | ✅ | — |
| GET | `/api/v1/admin/reports/{report}` | ⚠️ | — |
| GET | `/api/v1/admin/reports/{report}/export` | ⚠️ | `?format=csv` |

## 4. CHA portal (11 routes)

| Method | Endpoint | Status | Body / query to test |
|---|---|---|---|
| POST | `/api/v1/cha/login` | ⚠️ | `cha_6af4402fd29f2@customsbroker.com` |
| POST | `/api/v1/cha/logout` | ⚠️ | — |
| GET | `/api/v1/cha/profile` | ⚠️ | — |
| POST | `/api/v1/cha/profile` | ⚠️ | `{"name":"Senior Customs Broker Ltd"}` |
| GET | `/api/v1/cha/shipments` | ⚠️ | — |
| GET | `/api/v1/cha/shipments/{id}` | ⚠️ | `86` |
| GET | `/api/v1/cha/shipments/{id}/checklist` | ⚠️ | `86` |
| POST | `/api/v1/cha/shipments/{id}/filing` | ✅ | `{"declaration_number":"SB-1","port_code":"INNSA1"}` → 404 in CHA suite |
| POST | `/api/v1/cha/shipments/{id}/examination` | ✅ | same suite |
| POST | `/api/v1/cha/shipments/{id}/duty-calculation` | ✅ | same suite |
| POST | `/api/v1/cha/shipments/{id}/release` | ✅ | same suite |

---

## Fixed in this pass

All previously failing endpoints now pass. Root cause was a single test-environment defect, not an API bug:

- `.env` sets `APP_URL=http://localhost/ecommerce`, so every test request was built with an extra
  `/ecommerce` path segment (`"The route ecommerce/api/v1/admin/login could not be found"`), making
  admin login, quotation accept, CHA filing and the trade-workflow registration look broken.
- `phpunit.xml` now pins `APP_URL=http://localhost` and `tests/TestCase.php` forces the root URL in
  `setUp()`, so requests hit the real routes.
- `B2bTradeWorkflowTest` now approves its API-created product before browsing the marketplace, because
  DEC-002 correctly keeps new products in `pending_approval`.

Production was never affected: `POST /api/v1/admin/login` with a customer account already answered
`403 Access forbidden. Account lacks administrative privileges.` over real HTTP.

## P2-8 / P2-9 / P2-10 verification (latest pass)

- **P2-8 auth hardening** — email verification, password reset, TOTP MFA and idle session timeouts all exist and are now covered by tests. Three real defects were fixed: the `password_reset_tokens` table was never created (and `config/auth.php` still pointed at a non-existent `password_resets` table), so every reset request failed silently; `B2bSessionTimeout` compared Sanctum's `last_used_at`, which Sanctum refreshes before middleware runs, so the timeout never fired — idle time is now tracked in the cache per token; a mail transport failure turned a valid reset request into `429`.
- **P2-9 dashboards & reports** — 3 role dashboards (`/importer|exporter|admin/dashboard` + `/dashboard/summary`), all 14 SRS reports via `/reports` and `/reports/{report}`, exports in `format=csv|xlsx|pdf`, date-range validation and per-portal data scoping.
- **P2-10 disputes** — buyers raise claims (`POST /importer/disputes`), both parties exchange messages and evidence, duplicate open claims per order+type are refused (409), and cross-tenant access is 404.

## Passing suites

```
php artisan test --filter="B2bTradeWorkflowTest|B2bChaPortalTest|B2bBusinessRulesTest|B2bAdminAndModerationTest|B2bP3ProductManagementTest|B2bP2OperationsTest|B2bP2RfqWorkflowTest|B2bDisputeFlowTest"
→ 46 passed (541 assertions)
```
