# AI-assisted development record

AI assistance was used for repository inspection, identifying failure cases in the original implementation, drafting/refactoring PHP and frontend code, creating tests, and writing documentation. This file describes the workflow used for this revision; it does not claim that AI-generated code is automatically correct or that a human review has already occurred.

## How changes were checked

1. Inspect existing behavior and state concrete failure cases before changing code: public administrator selection, cross-account article edits, false publication success, repeated vote rewards, and non-atomic membership upgrades.
2. Implement shared service boundaries and make the failure cases observable through HTTP status codes and database assertions.
3. Run the same requests against actual PHP and MariaDB, including concurrent requests from independent sessions and a deliberately failed ledger insert.
4. Check that retries preserve identity and balance, failed operations leave no partial state, and each fixture wallet reconciles to its entries.
5. Review the rendered UI at desktop and mobile sizes, and document known scope limits rather than adding unverified claims.

The operational follow-up reviewed the actual NVIDIA role description, traced the existing wallet read path, and identified independent balance/history queries as a concurrency gap. AI assistance helped implement snapshot reads and the reconciliation CLI. Validation used a fresh, isolated MariaDB 11.8 database with case-sensitive table names: tests injected several forms of drift, restored fixtures, verified exit codes, and paused a read transaction while an independent HTTP writer committed. This follow-up did not repeat the earlier browser screenshot capture; the validation record separates those results.

No credentials or private applicant information were included in documentation. Temporary test passwords are generated in memory. No job application is submitted by this repository or its scripts.

## Human review before using the project in an interview

Trace a purchase from `api.php` to `membership_order()`, explain the lock order and failure paths, run the suite yourself, and review the English documents. Validate the product policy for rewards and membership. Report actual test results and remaining work; do not replace measurements with AI-generated performance estimates.
