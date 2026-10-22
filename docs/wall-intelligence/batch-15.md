# Intraday gamma regime and flip

Dashboard → Intraday → Wall tracking includes Gamma context between the wall/price cards and the timeline. It shows the modeled aggregate gamma regime at the selected price, the nearest gamma flip, distance from that flip and its movement within consecutive comparable readings. The existing timeline slider selects the observation. No tab, expiry selector or extra chart request is added.

The guide explains that walls are strike concentrations, while flips are sign changes in aggregate gamma across hypothetical underlying prices. Multiple crossings are labeled explicitly and listed in the guide. A profile without a crossing says No flip in range and displays the tested bounds. These outputs add context; they do not modify setup confirmation, targets, evidence grades or alerts.

## Calculation and identity

`intraday-fixed-reference-iv-gamma-profile.v1` reuses the EOD gamma profile's Black-Scholes scenario engine. Intraday uses the exact wall observation's frozen contract basket, prior-session OI, recorded reference IV, underlying price and provider quote timestamp. Each expiry uses its remaining seconds to the regular-session close, including early closes. Intraday has no EOD one-minute floor and excludes expired contracts. The original EOD calculation retains its existing grid, coverage policy, time floor and version.

Contracts use the existing call-minus-put inventory convention, 100-share multiplier, zero interest and dividends, and USD per 1% underlying move. Independently calculated net gamma at the recorded price must reconcile with that wall observation's net GEX before the context is accepted. Context retains the observation time, reference price, basis key, scope key, wall-model version and profile version. It cannot borrow the current quote for an older reading.

The profile samples 201 regular prices across ±10% of the recorded price. Short-lived narrow distributions add their strike centers and one-volatility-time shoulders, with a 601-point total limit. Sign-change brackets are refined by bisection; near-zero gross exposure cannot create an underflow crossing. More than 16 crossings makes the compact profile unavailable. Crossings are limited to this sampled range; a narrow crossing between samples may not be found. A nearest crossing among multiple roots is not treated as one unique flip.

The intraday profile follows the existing 95% known-OI capture gate and 10% maximum unknown-OI row proportion. Input coverage is not a measurement of the percentage of true GEX captured. Audit details remain in the API/export rather than the main panel. Historical outcome eligibility remains false.

Movement uses consecutive profiles within the selected wall comparison window. A missing profile, changed input identity, model change, multiple roots or a no-crossing reading starts a new flip comparison. Earlier inspection never uses a later reading to establish its starting value. Old observations without gamma context remain unchanged and do not display a substitute EOD profile.

## Collection, storage and export

Capture calculates context only after accepting the wall basis and determining that the observation is new. Existing five-minute observations and existing quote revisions return without recalculation. One compact `gamma_context` object is saved inside the append-only observation. The complete scenario curve and contract inputs are not duplicated in every observation. No rows, tables, migrations, provider requests, worker processes or additional collection schedules are added.

Gamma scenario work has a 250 ms time budget checked between grid/refinement evaluations, with a 30,000-contract limit. An over-budget profile stays unavailable without preventing the wall reading from being recorded. The pre-existing 90-second collector budget and rotating symbol cursor remain in force. This adds CPU work and roughly 1–2 KB per ordinary observation; historical retention is unchanged.

Normal read requests return saved context only. The local demonstration calculates its labeled synthetic examples on request. The response's top-level `gamma_context` matches the latest observation, without carrying forward an earlier profile when the latest lacks one. Export timeline JSON preserves each recorded context. Optional Intraday walls & setups in AI Export includes the payload and `summary.intraday_gamma_context`, independently of the EOD selection.

## Local review

The local server runs at `http://127.0.0.1:8000`. Open `/dashboard?symbol=SPY&mode=intraday&tab=walls` using the existing local review account, then select Preview local demonstration. The example is clearly synthetic and production cannot enable it. Local review data has no current-session wall captures.

1. Check the four values in Gamma context, above Wall levels & price.
2. Move Inspect reading backward. Context time and values follow the selected wall/price reading. The earliest gamma reading does not claim movement from a future baseline.
3. Open How to use gamma context. Review the range limitation and the distinction between a wall, gamma flip and entry confirmation.
4. Select another saved symbol and open its local demonstration. The prior symbol's context must clear.
5. Export timeline JSON and inspect `gamma_context` on individual observations and at the top level. The latest summary must match the final reading.
6. Review at a narrow viewport. The four values wrap to two columns without horizontal page overflow.

Release requires a normal PHP/frontend deployment. Existing capture automatically includes profiles on subsequent new readings. No environment settings or external subscriptions change. This batch is local for review; production is unchanged.

## Validation

Sixty-seven focused PHP tests passed: 51 model, capture and gamma-profile checks, plus 16 export tests under the guarded MySQL test configuration. The default SQLite run of the export suite encountered an existing MySQL-specific migration; the successful rerun used an isolated local `*_test` database. No migration was changed for this feature. Thirty-five frontend tests passed across gamma context, the wall tracker and the existing EOD gamma panel. The frontend build and scoped PHP formatting checks passed.

A local SPY chain with 2,192 contracts took 59 ms to calculate one compact profile of 1,084 JSON bytes. A synthetic 5,000-contract basket took 174 ms and produced 1,251 bytes. These are single local observations, not production throughput guarantees. No extra market-data request was made.

Browser review confirmed the selected-time context, earliest-reading behavior, the guide, and a 390-pixel mobile viewport without horizontal page overflow. The local server uses the existing review account, whose expired local trial was extended for manual review. No production account or billing state changed.
