# MagDyn — Custom ATS APIs (Integration Guide)

Three JSON endpoints that let an external system (the billing side) drive
inventory stock movements for the ATS workflow.

- **Base path:** `/Custom/` on the MagDyn host
  (e.g. `http://<magdyn-host>/Custom/ats_applied.php`)
- **Methods:** `GET` **or** `POST` (parameters read from either)
- **Response:** `application/json`
- **Encoding:** UTF-8

| Endpoint | Purpose | Stock effect |
|----------|---------|--------------|
| `ats_applied.php`  | ATS applied  | Move `Qty` **MAGDYN → ATS** |
| `ats_reject.php`   | ATS rejected | Move `Qty` **ATS → MAGDYN** |
| `ats_shipment.php` | ATS shipped  | Ship `Qty` out of **ATS** to **Misc Vendor** |

---

## Authentication

Token auth is **off by default** so the endpoints work with only the
documented parameters. To require a shared secret, open each file and set:

```php
const ATS_API_TOKEN = 'your-long-random-secret';
```

When set, every request must include the secret as a `token` parameter:

```
.../ats_applied.php?inventory_model_id=1217&Qty=10&ats_no=ATS-00042&token=your-long-random-secret
```

A wrong/missing token returns **HTTP 403** `{"ok":false,"error":"Unauthorized."}`.

---

## Common parameters

| Name | Required | Description |
|------|----------|-------------|
| `inventory_model_id` | yes | **Inventory model code** — matches `inv_items.code` in MagDyn. |
| `Qty` | yes | Quantity to move/ship. Must be a positive number. |
| `ats_no` | yes | ATS number. Written into the transaction notes. |
| `inv_no` | shipment only | Invoice number for the shipment. |
| `token` | only if enabled | Shared secret (see Authentication). |

> Note: the quantity parameter is spelled **`Qty`** (capital Q). `qty` is also
> accepted as a fallback.

---

## 1. `ats_applied.php` — move MAGDYN → ATS

Posts a paired `move` transaction: `-Qty` at MAGDYN, `+Qty` at ATS. The notes
read `ATS applied — <ats_no>`.

**Request**
```
GET /Custom/ats_applied.php?inventory_model_id=1217&Qty=10&ats_no=ATS-00042
```

**Success — HTTP 200**
```json
{
  "ok": true,
  "message": "Moved 10 of 1217 from MAGDYN to ATS.",
  "item_code": "1217",
  "qty": 10,
  "ats_no": "ATS-00042",
  "from": "Magdyn",
  "to": "ATS",
  "out_txn_id": 44802,
  "in_txn_id": 44803,
  "ats_balance": 10
}
```

---

## 2. `ats_reject.php` — move ATS → MAGDYN

Posts a paired `move` transaction: `-Qty` at ATS, `+Qty` at MAGDYN. The notes
read `ATS rejected — <ats_no>`.

**Request**
```
GET /Custom/ats_reject.php?inventory_model_id=1217&Qty=10&ats_no=ATS-00042
```

**Success — HTTP 200**
```json
{
  "ok": true,
  "message": "Moved 10 of 1217 from ATS back to MAGDYN.",
  "item_code": "1217",
  "qty": 10,
  "ats_no": "ATS-00042",
  "from": "ATS",
  "to": "Magdyn",
  "out_txn_id": 44804,
  "in_txn_id": 44805,
  "magdyn_balance": 47018.4
}
```

---

## 3. `ats_shipment.php` — ship out of ATS to Misc Vendor

In a single DB transaction it: creates a shipment header (vendor = **Misc
Vendor**, `reference`/`ref_doc` = `inv_no`), adds one ship line sourced from
the **ATS** location, approves the shipment, posts the `ship_out` ledger
transaction (`-Qty` at ATS), and marks the shipment `shipped`. The notes read
`ATS Shipped — <ats_no>`.

**Request**
```
GET /Custom/ats_shipment.php?inventory_model_id=1217&Qty=4&ats_no=ATS-00042&inv_no=INV-2026-0099
```

**Success — HTTP 200**
```json
{
  "ok": true,
  "message": "Shipped 4 of 1217 to Misc Vendor (SH-260629-001).",
  "item_code": "1217",
  "qty": 4,
  "ats_no": "ATS-00042",
  "inv_no": "INV-2026-0099",
  "ship_no": "SH-260629-001",
  "shipment_id": 3762,
  "ship_txn_id": 44808,
  "vendor": "Misc Vendor",
  "shipped_from": "ATS",
  "ats_balance": 0,
  "status": "shipped"
}
```

---

## Errors

All errors return `{"ok": false, "error": "<message>"}` with a non-2xx status.

| HTTP | When |
|------|------|
| `403` | Token enabled and missing/incorrect. |
| `404` | `inventory_model_id` does not match any `inv_items.code`. |
| `409` | **Insufficient stock** at the source location (the move/ship is rejected and fully rolled back), or any other transactional failure. |
| `422` | A required parameter is missing or `Qty` is not positive. |
| `500` | A required MAGDYN/ATS location or the Misc Vendor record is missing. |

**Insufficient-stock example — HTTP 409**
```json
{
  "ok": false,
  "error": "Insufficient stock for \"Widget\" at \"ATS\": have 10.000, need 50.000."
}
```

Every operation is atomic: on any error nothing is persisted — no partial
move, no orphan shipment.

---

## Caller checklist

1. Send `inventory_model_id` exactly as the MagDyn item **code** (`inv_items.code`).
2. Always treat **`ok === true`** as the only success signal; otherwise read `error`.
3. Treat **HTTP 409** as a business failure (e.g. not enough stock) and surface `error` to the user — it is safe to retry after stock is corrected.
4. Keep `ats_no` consistent across applied → reject/shipment so the MagDyn ledger notes stay traceable.

---

## Assumptions (change on the MagDyn side if needed)

- **Shipments are sourced from the ATS location** — the natural continuation of
  *applied → ATS → shipped*. To ship from MAGDYN instead, change the source
  location in `ats_shipment.php`.
- Ledger rows / shipments are attributed to the MagDyn **`admin`** user.
- Location codes used: `Magdyn`, `ATS`. Vendor: `Misc Vendor` (`V-01856`).
