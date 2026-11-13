# Indexing validation

Completed checks and limitations for plan 04; see [the design](indexing-design.md). Further benchmarking was stopped at the owner's request. The private scratch harness is not included in the deliverable.

## Application checks performed

- Static checks passed: Composer validation, CS Fixer check, PHPStan and Symfony LSP.
- Existing headless browser suite passed: **five journeys / 43 assertions**, with exactly **one Projects journey** covering registration, discovery, indexing, search, ordinary source-content update, rebinding and removal. No extra application suites were added.
- Isolated generated migration/schema validation and production image build passed.
- Independent browser verification confirmed dark native EasyAdmin styling and Bootstrap 5 search-form controls without a public Tailwind collision or console errors.
- Selected Chroma/client/bridge capability checks exercised explicit cosine with non-unit vectors, create/upsert/update/delete, native project/date filters, result budgets, and document/vector/metadata inclusion. Selected CodeRank and BGE endpoints were exercised.
- Populated lexical retrieval exercised project/generation constraints, a seven-result budget and a canonical-date window. Graceful restart of the owned Chroma service preserved **26,393** vectors, cosine metadata and the checked result order. This is not crash-recovery proof.

## Completed populated-corpus observations

One full build completed at revision **6,624**: **26,284 canonical parents / 26,393 chunks**. It ran over two sequential CLI processes because the first reached the diagnostic timeout. Recorded processing time totaled **23m 03.9s**, excluding the interruption and setup. PHP peak allocation was **20 MiB** in each process; settled live memory was about **18.4 MiB**. Five unchanged polls took **30 ms cold**, then **2.25–2.43 ms warm**.

Chroma's settled RSS was **225.9 MiB**; container current/lifetime-peak accounting was **380.0/394.6 MiB**. These are separate from PHP, and container accounting includes more than process RSS. Concurrency was one indexing/retrieval CLI at a time, with one Chroma server plus init. External model memory was unavailable, not zero. No multi-client memory claim is made.

All frozen50 questions and the original five identifier questions were requested on the same consistent corpus: **38 present positives / 12 intentional negatives**, with no missing-positive exclusions.

| Measure | Original CodeRank/Vektor hybrid+BGE | Chroma hybrid+BGE |
|---|---:|---:|
| Positive target @5 | 20/38 | 20/38 |
| Positive target @10 | 24/38 | 24/38 |
| Positive target @20 | 26/38 | 26/38 |
| Negatives returning any candidate | 9/12 | 9/12 |
| Identifier questions with previously judged work evidence @5 | 5/5 | 5/5 |
| Unjudged identifier candidate slots, top20 | 39 | 40 |
| Frozen50 median query time | 623 ms | 691 ms |

Two positive targets moved one rank lower, **7→8** and **3→4**; the other 36 matched. Aggregate cutoffs match, not every result. Unjudged candidates are not counted as work evidence, and nearest candidates on negative queries do not establish that the topic exists. No model/budget/floor tuning was performed. These sequential timings do not establish a latency improvement.

New query-process peak allocation was **22 MiB**, versus **54 MiB** for the standalone original-component reference. The new process boots the application kernel; the reference does not. The old full indexing operation was not re-run, so no reproduced 8.5 GB-to-20 MiB claim is made.

Original snapshot/vector/graph hashes remained unchanged. All private source/settings copies, questions, vectors, payloads and scratch code remain ignored under `var/`. No evaluation or Vektor fallback is part of production code.

## Manual-review follow-up

At `91a0766`, `cs:fix`, `qa:static`, production Compose configuration validation, and the existing Projects journey passed (**1 test / 19 assertions**). Owner re-review confirmed four fixes (production storage, first-batch clearing, scan progress and error recovery), but found shared chunk IDs, unrecorded new-parent attempts and quadratic cleanup work. The metadata-based cleanup from that commit is superseded below.

The parent-cleanup follow-up includes the full parent signature in chunk identity, records one pending parent attempt before external writes, and retires exact IDs not retained by a committed manifest. Unchanged parents make no backend cleanup requests. `CHUNK_ID_VERSION=2` changes the generation signature so old-format indexes rebuild. No new entities or queue/coordinator were added; a generated nullable JSON column on `index_generations` holds the single pending attempt under the existing execution lock.

The generated migration `Version20261113145408` was inspected before application: its forward change only adds nullable `pending_parent`. Generation, application and `doctrine:schema:validate` ran through Castor against newly created task-owned storage (`var/browser/pr3-pending-parent/application.db`). Latest `cs:fix`, `qa:static`, the existing Projects journey (**1 test / 19 assertions**, 10.9 seconds in PHPUnit; 20.7 seconds including preparation), and `git diff --check` passed.

No new scenarios, benchmarks, injected failures, or production lifecycle operations were run. Failure-path reasoning is source inspection, not executed fault coverage. Earlier populated-corpus measurements predate chunk-identity format version 2 and were not repeated.

## Unfinished or unverified

- Populated 1/5/20-parent incremental performance measurements and the second full build were not completed. The private writer initially lacked journal-directory permissions; its cleanup removed its own derived index, not original sources. The subsequent rebuild was cancelled. Ordinary content updates were verified in the existing browser journey.
- Autonomous periodic pickup of newly written content is not browser-proven: the journey explicitly requests scan/index after mutation.
- No fault-injection, deterministic duplicate-delivery concurrency, multi-project browser isolation, broad date/security, restore/signature-switch or production-cutover matrix was run.
- In-place restores preserving filesystem identity and canonical revision are not reliably detected. Generic file/WAL witness drift is diagnostic only, because ordinary Hatfield activity changes it too. Chroma/SQLite have no shared transaction; pending updates may temporarily remove candidates. Incompatible source/embedding generations fail explicitly.
