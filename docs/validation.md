# Validation record

Validated on 2026-10-16 (Asia/Taipei). Results below describe this workspace; no hosted deployment or GitHub Actions run is claimed.

| Check | Observed result |
| --- | --- |
| PHP syntax | All 27 PHP files passed |
| JavaScript syntax | `node --check final_project/assets/app.js` passed |
| Diff whitespace | `git diff --check` passed |
| Native PHP + MariaDB regression suite | 15 tests passed |
| Same suite against container MariaDB with `lower_case_table_names=0` | 15 tests passed |
| Fresh Docker build | Succeeded with PDO MySQL installed |
| Compose startup | Database healthy, migration completed, non-root app healthy |
| Repeated migration and seed | Schema unchanged; second seed created zero duplicate events |
| Chromium desktop/mobile | Registration, login, calendar AJAX, review preview/publication, membership receipt, and responsive layout passed |
| Default sandbox credit policy | HTTP 403 when disabled; no credit control shown in the wallet |

The 15 regression tests include independently authenticated concurrent sessions, same-key and different-key purchases, account ownership boundaries, CSRF, invalid uploads, relative image retrieval, and an intentionally failed ledger insert. The failure leaves no order, debit, or entitlement. Wallet fixtures reconcile to their ledger totals.

Browser checks used a 1440×1100 desktop viewport and a 390×844 mobile viewport. The mobile document did not overflow horizontally, and there were no uncaught JavaScript errors. Screenshots contain clearly labeled demo events/posts, a temporary example-domain account, and a genuine points redemption created through the application. They are not evidence of live users or actual payments.

The browser exercised the container's default, disabled demo-credit setting. Remaining points for its sample receipt came from clearly labeled demo posts using the same publication/reward service. Temporary passwords were not printed or committed.

The automated suite removes its unique fixture accounts and associated content. The isolated Docker demo database and its screenshots are separate from the original onboarding database, which was not migrated or reset.
