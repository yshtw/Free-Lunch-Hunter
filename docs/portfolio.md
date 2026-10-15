# APAC Ecommerce Backend Intern: interview walkthrough

Target role: NVIDIA APAC Ecommerce Backend Intern – 2027 (JR2027030), based on the job description supplied during this project revision.

## Five-minute demonstration

1. **Product context, 45 seconds.** Explain the campus event community and its points-based Premium upgrade. Show the responsive activity feed, a personal calendar action, and a review. State that points are not money and no payment gateway is connected.
2. **Commerce behavior, 90 seconds.** Open the wallet, earn or explicitly add demo points, and redeem 30 points. Show the resulting receipt and ledger debit. Explain the shared service used by the form and API.
3. **A hard problem, 90 seconds.** Run the integration suite. Explain why four separate sessions are needed to demonstrate concurrent requests rather than accidental session serialization. Show same-key retries returning the same order and competing keys producing a single successful upgrade.
4. **Failure handling, 45 seconds.** Explain the test-only database trigger that rejects a ledger insert and how the transaction prevents a partial purchase. Point to the correlated, sanitized error response.
5. **Collaboration and tradeoffs, 30 seconds.** Open the English architecture document and API contract. Explain the decision to use one relational transaction rather than a distributed service, and identify the additional work needed for real payments and multiple markets.

## Match the job with evidence

| Job expectation | Repository evidence | What to explain |
| --- | --- | --- |
| Ecommerce backend understanding | Membership order, entitlement, idempotency, wallet ledger | Why an order and a balance update must commit together; why this is not a payment gateway |
| Hands-on coding and maintenance | Shared service functions replacing duplicated page logic | Ownership checks, fixed products/prices, validation boundaries, and backward-compatible entry URLs |
| Reliable platform execution | Concurrent and fault-injection regression tests | Retry semantics, row locks, rollback behavior, and limitations of current evidence |
| Clear English collaboration | README, architecture decisions, OpenAPI contract | Requirements, failure cases, scope, and how another engineer reproduces the workflow |
| Modern AI development tools | Documented AI-assisted workflow with executable checks | How generated changes were validated and what still needs human review |
| Cross-functional communication | Explicit points policy, demo flag, receipts, and operational limits | Separate product policy, implementation details, and dependencies that require a decision |

## Resume bullets to adapt after reviewing the implementation

- Refactored a PHP/MariaDB community platform to enforce role and resource ownership boundaries, validate mutations, and share backend services across server-rendered pages and a JSON API.
- Implemented transactional points-based membership ordering with account-scoped idempotency keys, wallet event deduplication, and row locking to prevent duplicate debits under concurrent requests.
- Built real HTTP/MariaDB regression tests covering concurrent purchases, injected storage failures, authorization, CSRF, and wallet reconciliation; documented architecture and API behavior in English.

Use only claims you can demonstrate and explain. Preserve the original course-team attribution, and distinguish this revision from your earlier contributions. No throughput, business revenue, production scale, or hosted CI result has been invented.

## Questions to practice

- Why lock the wallet before looking up the request key?
- Why can `200 replayed` be correct after a network retry, while a new request key gets `409`?
- How could PHP session locking hide a concurrency bug in an otherwise passing test?
- Which operations are idempotent, and which would still need an explicit request key before public deployment?
- What happens if the image upload succeeds but the database transaction fails?
- How would you add payment-provider callbacks without crediting the wallet twice?
- Which timezone should define an APAC event date, and why is a single-market assumption insufficient?
- What monitoring would catch a mismatch between the balance and ledger?

## Handoff format for a global collaborator

**Requirement:** a regular member can redeem 30 community points exactly once for Premium. Retried requests must not debit again.

**Acceptance evidence:** a committed order has one membership ledger debit and an active entitlement; four concurrent same-key requests return one order; a ledger-write failure leaves all three unchanged.

**Remaining decisions:** production payment scope, market-specific time/currency rules, anti-abuse policy, observability, and hosting architecture. These need product/operations input rather than implicit assumptions in code.
