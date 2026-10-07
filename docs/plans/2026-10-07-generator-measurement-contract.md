# Scheduled completeness and actual-window performance contract

Continue PR93 at merged `b1584276af14e93fce3df3eda0e1ff9b27a41914`.
Use the already approved supplied-report and actual load-script output seams,
with external clock/k6/HTTP boundaries. Apply TDD and independent review before
exact-head CI and merge. No new timing run belongs to this contract increment.

Version the contract explicitly: `scheduled-completeness-actual-window-v1`.
Historical cells lacking this contract remain ineligible; do not reinterpret
previous short diagnostics or change their recorded outcomes.

1. Keep zero drops, unfinished work, errors, parity failures, false negatives,
   unknown membership and early controlled responses across all phases.
2. Require a single local constant-arrival executor and explicit builtin
   iteration/HTTP totals matching positive custom totals and independent receiver
   counts. Under these conditions only, index cohorts support completeness.
3. Apply the existing ±1 nominal count tolerance to the scheduled measurement
   cohort (288,000) and all-run total (432,000). Require cohort completion equality.
   This changes the quantity checked, not the numerical tolerance; it is an
   explicit methodology correction, not a new clock tolerance.
4. Keep actual-time selection for measured start/completion equality, throughput
   including drain (at least 4,560 RPS) and latency envelope. Require exact cohort
   crossing reconciliation and feasible counts. Missing audit/builtin evidence
   must fail closed; no unexplained actual-window discrepancy is admissible.
5. Prepare default qualification collection for three blocks containing all
   0/25/100/150-ms cells. Admit aggregate PASS only for all 12 distinct valid
   cells. Preserve negative-control rejection, identity checks, coverage and
   resource budgets. Stop on the first failure; do not retry until PASS.

Retain contract RED/GREEN and quality evidence outside .build. No production
backend/runtime change, private data, full timing matrix, SQL calibration or
application benchmark is included. A later run must freeze the reviewed source.
