# Validation record

Validated on 2026-10-16 (Asia/Taipei). This record describes local checks; hosted CI results are reported separately on the pull request. No hosted deployment is claimed.

## Wallet operations follow-up

| Check | Observed result |
| --- | --- |
| PHP syntax | All 29 PHP files passed |
| JavaScript / Python syntax | `node --check final_project/assets/app.js` and `python3 -m py_compile tests/integration.py` passed |
| Diff whitespace | `git diff --check` passed |
| Real HTTP + MariaDB regression suite | All 21 tests passed in 7.439 seconds |
| Database used for this run | MariaDB 11.8.9, `lower_case_table_names=0`, newly created isolated `_test` database |
| Updated Docker image build | Succeeded; existing PHP/PDO build layer reused, current application/scripts copied |
| Container migration and reconciliation | Succeeded as UID 33; a SELECT-only database account reported one clean fixture account |

The six added tests cover clean/scoped reports, exact 0.01-point balance drift, an incorrect membership debit despite matching wallet totals, orphan references, missing wallets, unearned entitlements, valid administrator purchase history, unavailable-check exit codes, and a deterministic snapshot/read-write interleaving. The original 15 tests also passed. All temporary test databases and users from these checks were removed; the retained demo and onboarding databases were not migrated or reset.

The snapshot test starts with a connection whose default isolation is READ COMMITTED, pauses a read-only snapshot after its balance query, then commits a credit through an independently authenticated HTTP session. The original snapshot's ledger total still matches its original balance, while the subsequent wallet API request observes the credit.

This follow-up changes backend reads and operator tooling without changing the visual layout. The wallet page is exercised by the HTTP suite; browser screenshot capture was not repeated. The earlier screenshots and browser evidence are listed below with their original scope. Test duration is an observed regression-run duration, not a throughput benchmark.

## Earlier baseline: commit `441bf88`

The following results were already recorded in the baseline revision and are retained as historical evidence:

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
