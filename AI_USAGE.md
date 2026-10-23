# AI Usage Disclosure

This document describes how AI tools were used while building **PolicyFlow** and how I verified the output. I am responsible for all code in this repository.

---

## 1. Tools Used
- **Google Antigravity IDE** (model: Claude 3.5 Sonnet / Gemini 2.5 Pro): code generation from step-by-step prompts.
- **Claude (claude.ai chat)**: breaking the assignment into small tasks, writing prompts, reviewing the README and design decisions.
- Dates of use: October 2026.
- No other AI tools were used.

---

## 2. How AI Helped
1. **Scaffolding and boilerplate.** Antigravity generated the Laravel project, migrations, models, enums and controllers from small, separate prompts (scaffold, migrations, pricing, intake, state machine, mock insurer, submit workflow, callback, tests, docs). I committed after each step.
2. **Core logic and tests.** Antigravity drafted `PremiumCalculator` (brick/math), `PolicyStateMachine`, `IssuanceService`, the mock insurer, and the unit/feature tests, including the concurrency test. I reviewed each file against the assignment before committing.
3. **Design and documentation review.** Claude helped choose the reliability cases to implement, the in-process mock behind an `InsurerClient` interface (an app calling its own URL under `php artisan serve` can deadlock), and the use of the request UUID as the provider idempotency key. Claude also reviewed the README against the assignment checklist.

---

## 3. Incorrect or Incomplete AI Output That I Corrected

1. **Wrong coverage tier.** Generated documentation listed `1000000` as a supported sum insured. The assignment allows only 100000, 300000 and 500000. I removed it everywhere and added a test that `1000000` returns 422.
2. **Stuck state after timeout.** When the provider timed out and the status lookup found no policy, the request stayed in `NEEDS_RECONCILIATION` with no way to retry. I added a `NEEDS_RECONCILIATION → VALIDATED` transition for the "provider confirms not found" case and a test showing the retry produces exactly one policy with the original premium.
3. **Silent duplicate with different data.** Repeating `agent_id + client_request_id` with a different payload returned the original record without any warning. I changed it to return 409 for a mismatched payload and 200 only for an identical one.
4. **README problems.** The generated README contained a local absolute path, no example responses, and did not clearly state which reliability cases were implemented. I fixed these.

---

## 4. How I Verified the Output
- Ran the full test suite after every change, against a real MySQL test database.
- Ran every script in `examples/` against a running server for each mock scenario and checked the status codes.
- Read `IssuanceService`, the state machine transition map and the callback handler line by line so I can explain them.
- Checked the implementation and README against each requirement and acceptance criterion in the assignment.
- Ran a cleanup audit to remove unused boilerplate, then re-ran the tests.
- Searched the repo for secrets and personal paths, and confirmed `.env` is not tracked.

---

## 5. Data and Licensing
- No real credentials, API keys or customer data were shared with any AI tool. All customer data in tests and examples is fabricated.
- `.env` is not committed. `.env.example` contains placeholders only.
- Third-party packages (Laravel, brick/math and other Composer dependencies) are used under their open-source licenses.
- Private chat transcripts are not included, as the assignment requests.

---

**Author:** Ankit Yadav / 12320498
