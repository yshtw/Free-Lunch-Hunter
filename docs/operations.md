# Wallet and membership operations

`scripts/reconcile.php` checks the committed state of this points system. It is a CLI operator tool, outside the web document root, and makes no data changes. Use the same `DB_DSN`, `DB_USERNAME`, and `DB_PASSWORD` configuration as the application; a dedicated SELECT-only database account can run the check.

## Run a check

```bash
docker compose exec -T app php scripts/reconcile.php
docker compose exec -T app php scripts/reconcile.php --user-id=123
```

Without Docker, run `php scripts/reconcile.php` with the database environment set. The optional argument accepts one positive internal account ID. An unknown ID is an unavailable check, not a clean result. A completely empty installation can be clean with `accounts_checked: 0`; check the counts to confirm the intended database was selected.

| Exit code | Meaning | Operator response |
| --- | --- | --- |
| `0` | All selected accounts/orders satisfy the checks | Record the result and timestamp |
| `1` | One or more inconsistencies detected | Investigate the reported account/order IDs |
| `2` | Invalid arguments, unknown account, or database/check failure | Restore configuration/connectivity and rerun; no clean result was established |

Standard output is JSON for completed checks, with `status`, `checked_at_utc`, `accounts_checked`, `orders_checked`, and `issues`. Invalid or unavailable checks write a brief diagnostic to standard error and produce no JSON. Database errors are reduced to their exception class, without SQL or connection details. Reports use internal IDs and decimal strings; they omit usernames, emails, passwords, and request payloads.

For example, a completed check of one account and one order has this shape:

```json
{
  "status": "ok",
  "checked_at_utc": "2026-10-15T18:00:00Z",
  "accounts_checked": 1,
  "orders_checked": 1,
  "issues": []
}
```

The timestamp above is illustrative. The command emits the actual UTC time when a report is generated.

## What each issue means

| `check` | Invariant |
| --- | --- |
| `missing_wallet` | Every account has a `premium` wallet/role row |
| `wallet_balance` | Stored balance equals the sum of **all** wallet entries, using MariaDB DECIMAL arithmetic |
| `membership_debit` | Every order has exactly one membership debit belonging to the same account, equal to the negative order amount |
| `orphan_membership_debit` | Every membership debit references an order belonging to that same account |
| `membership_entitlement` | A regular member has no order; a Premium member has exactly one; no account has multiple orders |

Administrators can have zero or one historical order: granting administrator access does not create or erase a purchase. An opening balance, if imported through a future migration, must have a corresponding ledger entry. The wallet API only returns the latest 50 entries, so summing that response is not a valid lifetime reconciliation.

All checks run in one read-only, repeatable-read InnoDB snapshot. A purchase or reward committed during the scan is either visible throughout the report or outside that snapshot. The scan does not lock wallet rows or include uncommitted changes.

## Investigate drift

1. Save the JSON, UTC timestamp, and exit code. Restrict stored reports to operators; internal account/order IDs are still operational data.
2. Rerun with the reported `--user-id` and inspect the related order, ledger entries, and membership row with read-only queries. Correlate a reported application failure using its `X-Request-ID` and application logs.
3. Establish the intended state from the original operation and product policy. Matching balance totals alone do not establish that an order was correctly debited.
4. Prepare a reviewed correction or migration with an audit record, then rerun reconciliation. The command deliberately provides no automatic repair action: a mismatched balance alone does not identify which record is wrong.

## Scope

The existing regression suite tests real storage rollback, concurrent orders, 0.01-point drift, balanced but incorrect order debits, orphan references, missing wallets, and entitlement drift. It verifies that checks do not repair corrupted data and that a failed connection returns exit code 2. A separate test demonstrates snapshot isolation during a committed HTTP write.

This tool checks internal records, not payment-provider settlements. Points have no cash value. Scheduling, alert delivery, external payment reconciliation, and large-ledger incremental scans are not implemented. Long-running snapshots retain older InnoDB row versions, so a larger deployment needs measured scan duration and an appropriate batching design.
