# EventBridge Meta CAPI forensic audit

Date: 2026-09-10; reconciled 2026-09-18; staging-confirmed 2026-09-19
Scope: current EventBridge checkout at `12cdd0f1bb6f4436e0a9235ad6dee40307b26efe`, with the pre-existing local UI and integration-test changes preserved.

## Verdict

The live dump contains evidence of materially reduced campaign-linking context: the two stored manual-conversion occurrences, one for `ninovegeboekt` and one for `sessieGeboektAlle`, were delivered successfully with 2xx responses but contain neither click attribution nor a browser identifier. In practical Meta CAPI terms, their final stored event context lacks `fbc` and `fbp`.

This is a plausible contributor to weak campaign association, but local data cannot prove Meta's external matching or attribution decision. The local schema-7 implementation already carried `fbc` and `fbp` in an immutable booking snapshot. The remediation completed on 2026-09-10 additionally captures the web visitor IP and user agent at booking time and projects those stored values into the later manual Meta event. It never substitutes the administrator request context.

The final focused contract passed on PHP 7.4 and PHP 8.3 with 13 Core tests/52 assertions and one Fluent end-to-end test/73 assertions per version. After all independent review fixes, the final exact-source isolated regression matrix passed on both runtimes with 321 tests, 1,525 assertions, 17 skipped tests, zero failures, zero errors, and no PHPUnit warnings per runtime.

The isolated audit itself did not prove Meta's external processing. After the final website-event correction was deployed by the user, a fresh synthetic staging booking was recognized by Meta Test Events on 2026-09-19. That user-observed result supplies the previously missing external confirmation without adding raw payloads, tokens, click identifiers, or customer data to this report.

## Post-audit website-event correction

The original audit preserved manual conversions as non-web events and an intermediate staging hypothesis used `phone_call`. The staging tests showed that neither represented the actual business event: the visitor had booked the session on the website, while the later admin click merely confirmed it.

The final release-candidate contract therefore uses `action_source=website`, a canonical first-party booking `source_url` stored in the immutable attribution snapshot, and the original booking-time client context. For older snapshots without a stored booking URL, the canonical selected-touch landing URL is the only allowed fallback. A new occurrence is blocked when no canonical source URL or booking-time user agent is available. Existing already-persisted `other`, `phone_call`, and legacy website occurrences remain unchanged for idempotent retry compatibility.

No order or store semantics were added: the configured session-booking event name remains intact, and the payload does not invent `Purchase`, `order_id`, `contents`, `value`, `currency`, `store_data`, or `physical_store` fields.

## Findings

### Browser and Fluent attribution

- Browser identifiers are validated, stored in the allowlisted browser-context namespace, and carried into later conversion projection when available.
- A Fluent booking snapshots first touch, last touch, and browser context when the conversion opportunity is created. Manual conversion therefore uses the booking snapshot rather than later live profile state.
- The Meta destination prefers stored browser identifiers and only derives an `fbc` fallback from stored click attribution when no stored `fbc` is present.
- The browser/multitrigger forensic matrix passed under PHP 7.4 and PHP 8.3, including route-specific deduplication and the configured-versus-unconfigured Advanced Matching cases.

The historical live records do not meet that available-context condition. The sanitized dump aggregate reports two manual-conversion occurrences with person fields present but click attribution and browser identifier both absent. The dump is database schema 6; the audited 2.0.1 checkout is schema 7, whose migration adds the attribution-snapshot and outbound-diagnostics columns. Therefore the old records could not persist the new immutable context and were prepared without these additional campaign-linking signals. The dump cannot prove whether schema 7 has since migrated on the live site or how Meta processed those events.

### Client IP address and user agent

The historical live implementation prevented the wrong context from being sent, but did not satisfy the desired historical-context contract:

- Website events can use request IP and user agent from the current visitor request.
- WooCommerce lifecycle events use the customer IP and user agent stored on the order, rather than a later webhook request context.
- A manual Fluent conversion is emitted later, but the final contract represents the original website booking and deliberately does not add the IP address or user agent of the administrator who clicks Convert.

Sending the latter values would be incorrect attribution. The remediation now:

- accepts IP and user-agent capture only for a conversion-relevant Fluent booking whose stored source is `web`;
- prefers Fluent Booking's stored booking IP and uses the same web request's remote address only when that value is invalid or absent;
- captures the user agent from that web booking request;
- validates and length-limits request-scoped IP and user-agent values without writing them to a shared per-profile namespace;
- hands those values directly from the Fluent booking callback into the immutable booking snapshot, so concurrent bookings cannot read or delete each other's request context;
- includes `client_request` in the immutable snapshot only when that fresh web capture returns values; exception, missing capture service, invalid values, and non-web sources all fail closed;
- projects them into Meta as `client_ip_address` and `client_user_agent`, while diagnostics expose only presence booleans.

Raw booking IP and user-agent values are removed from the snapshot and stored occurrence after confirmed delivery. The daily maintenance task also redacts them from unfinished conversion records after 30 days while retaining touch attribution, browser identifiers and privacy-safe outbound diagnostics.

The focused integration test changes the live profile context and current server request to later administrator values before manual conversion, and proves that the final intercepted request still contains the original booking-time context. No database schema change was required.

### `external_id` and Test Events

No `external_id` is projected from booking identifiers in the audited routes. This keeps an internal booking identifier out of Meta matching data, consistent with the project privacy boundary. A second primary-documentation attempt on 2026-09-18 again returned HTTP 429. Meta-owned SDK and sample repositories corroborate placement inside `user_data` and normalization or hashing, but do not establish that a booking ID is a suitable stable person identifier. This remains an explicit external evidence gap and an optional privacy/architecture decision, not a runtime failure.

The original audit used local HTTP interception rather than Meta Test Events. The later 2026-09-19 user-controlled staging test supplied external confirmation that Meta registered the corrected website conversion. The local artifacts remain the authoritative evidence for the exact privacy-safe payload contract and regression coverage.

## Independent review and dispositions

The read-only review found five blocking privacy/correctness defects. First, an upsert-only recapture could combine an older IP address or user agent with a newer partial browser request. Second, a non-web booking or failed fresh capture could inherit the profile's older `client_request` context. Third, a conversion without a valid booking snapshot could construct a new occurrence from mutable live profile data. Fourth, raw IP and user-agent values lacked a working erasure route. Fifth, a shared per-profile capture/snapshot/delete handoff allowed concurrent bookings to read or delete each other's request context. The final design avoids that shared handoff entirely: validated IP/user-agent values flow directly from the Fluent callback into the immutable snapshot; new occurrences require that snapshot; persisted request context is removed after confirmed delivery and after 30 days for unfinished historical records; cleanup uses an age-conditional delete so it cannot remove fresh shared legacy context.

The review also corrected the causal wording about historical `fbc`/`fbp` absence, required stale PHPUnit warnings to be removed, and required a new full run because the reviewed worktree was not byte-identical to the earlier regression artifact. A timing race then surfaced in the asynchronous WooCommerce browser harness; the harness now waits for observable completion instead of a fixed 20 ms delay. Final review and exact-source matrix results are recorded below.

## Evidence

| Evidence | Result |
| --- | --- |
| Checkpoint 3 isolated browser/multitrigger matrix | PASS, PHP 7.4 and PHP 8.3 |
| Checkpoint 4 isolated Core, Fluent, WooCommerce matrix | PASS, PHP 7.4 and PHP 8.3 |
| Remediation-focused Core contract | PASS, 13 tests / 52 assertions per PHP version |
| Remediation-focused Fluent end-to-end contract | PASS, 1 test / 73 assertions per PHP version |
| Review-fix Checkpoint 4 contract | PASS, Core, Fluent, and WooCommerce on PHP 7.4 and PHP 8.3 |
| Final post-review exact-source isolated regression matrix | PASS, 321 tests / 1,525 assertions / 17 skipped per PHP version; zero failures, zero errors, and no PHPUnit warnings |
| Website replay targeted matrix | PASS, 14 Core tests / 62 assertions and 1 Fluent test / 73 assertions per PHP version |
| Website replay full isolated regression matrix | PASS, 318 tests / 1,498 assertions / 17 skipped / 2 pre-cleanup non-failing warnings per PHP version; zero failures and zero errors |
| Fresh staging Meta Test Events verification | PASS, user-observed registration of the corrected website conversion |
| Browser harnesses | PASS, including asynchronous WooCommerce interactions after deterministic wait fix |
| Source read-only and post-run source-integrity checks | PASS for every run |
| Disposable-stack teardown | PASS for every run |
| Artifact privacy scan | PASS |

Safe artifacts are stored outside the checkout under `C:\Users\LPick\eventbridge-test\artifacts`:

- `20260910T143043Z-923cbe77` (Checkpoint 3)
- `20260910T143304Z-ada4a03c` (Checkpoint 4)
- `20260910T143603Z-1a5a830d` (full regression)
- `20260910T155949Z-4d2e736d` (final focused remediation proof)
- `20260910T160258Z-f1a16b9a` (superseded post-remediation full regression; 311 tests and two warnings per PHP runtime)
- `20260918T112901Z-ed9c3612` (review-fix Checkpoint 4 contract)
- `20260918T114047Z-2d407dc4` (first post-review full attempt; browser harness timing race exposed)
- `20260918T122256Z-fe7fee77` (superseded post-review full regression; all phases passed before final privacy review fixes)
- `20260918T130809Z-90ee2797` (non-web snapshot-policy Checkpoint 4 proof)
- `20260918T131802Z-50851d55` (final fail-closed Checkpoint 4 proof)
- `20260918T132229Z-02d63753` (final reviewed full regression; all phases passed)
- `20260919T113939Z-7231389a` (website replay full regression; all phases passed)
- `20260919T114857Z-1588ba77` (final website replay request-body contract)
- `20260919T165637Z-26667dc0` (final exact-source release-candidate matrix after concurrency and privacy review fixes; all phases passed)
- `20260919T170822Z-a4a59e13` (final committed release-candidate matrix including the version-independent release verifier self-test; all phases passed)

## Implemented remediation

The narrowly scoped R3 remediation is implemented locally:

1. Booking-time browser and client context is captured before the conversion opportunity snapshot is created.
2. Valid `fbc`, `fbp`, click-touch fallback inputs, visitor IP, and visitor user agent are stored in the immutable snapshot.
3. Admin-created bookings are explicitly excluded from request-context capture.
4. Only the immutable historical context is projected into the later manual CAPI event.
5. Transactional namespace replacement prevents old and new partial client fields from being combined, including rollback preservation on database failure.
6. Client request context is snapshotted only after an exact successful fresh web capture; every failure and non-web path excludes the namespace.
7. Focused and full isolated regressions prove payload presence, immutability, idempotency, safe diagnostics, and artifact privacy.

Historical conversions whose occurrences were already persisted without campaign context cannot be reliably backfilled from the stored occurrence alone. Do not resend or alter them without a separate business and Meta-deduplication decision.

## Remaining manual gates

1. Before making a production claim, verify the deployed plugin revision without exposing credentials or event data.
2. Before production promotion, verify the release-candidate package and deployed revision; the fresh staging booking and Meta registration gate has passed.
3. The primary Meta documentation check for `external_id` remains rate-limited after the 2026-09-18 refresh. Do not add `external_id` from a booking identifier without a separate privacy and contract decision.
4. Confirm that the site's privacy notice, lawful basis, access policy, and profile-retention setting cover persisted booking IP and user-agent data; the plugin's default profile retention remains disabled until configured.

## Source boundary

The remediation changed the browser-context capture, transactional profile-context replacement, Fluent booking attribution, conversion-service snapshot policy, attribution-snapshot normalization, Meta destination projection, and the manual website-event contract. Focused unit/integration tests and the WooCommerce browser harness were extended, including the pre-existing Fluent CAPI integration test without removing its earlier assertions. The pre-existing admin-navigation implementation and tests were preserved. The user later deployed the candidate to staging and performed the synthetic external verification; the local agent did not deploy, mutate production data, or send real Meta requests.
