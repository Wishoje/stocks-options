# Live wall tracking

The dashboard uses one near-term expiry basket: 1W, covering the current date through five weekdays ahead. It shows the current New York calendar session. Earlier sessions and alternative expiry scopes are retained for explicit API review, but are not selectable in the live panel. An empty current session never substitutes an earlier day's price or walls.

The short expiry basket includes daily options where available and weekly options for stocks. This is a product scope choice, not a validated claim that one horizon predicts intraday price reactions better than another.

## Collection

`walls:capture-intraday` checks the known options universe every five minutes. Symbol discovery does not depend on a fresh quote or a user visiting a page. Each capture independently requires a recent regular-feed quote from the current market session. Calculator prices cannot replace that quote.

Scheduled wall and price-evidence commands run in the background with overlap protection. Wall capture retains its 90-second round-robin budget. Price evidence retains its 60-second budget, provider backoff and one request per symbol per five minutes. Live evidence processing uses the 1W scope only. A symbol exception does not prevent the remaining symbols from being checked. These commands do not add calculator jobs or consume the intraday options worker queue.

Only the mounted, visible tracker polls its stored timeline once per minute. Opening the page does not fetch provider data or request collection for other scopes. A page opened before the market starts polling when the server's next collection window begins. Changing symbols cancels the prior request. JSON export and AI Export use the same current-session 1W scope independently of the EOD scope.

## Input policy

The model is `frozen-reference-iv-bs.v2`. Open interest comes from the previous completed trading session. Every selected expiration must have that session's chain rows. A stale expiration is not silently mixed into the basket.

Recorded IV for the same expiry, strike and option side can be used from at most three completed sessions, including the OI date. The most recent valid exact-contract value is selected. Current-session repairs of an older chain date are excluded from the prior-session IV reference. No neighboring strike, opposite side, current-session IV or inferred IV is substituted. OI is not changed by reference selection.

The capture gate requires 95% of known open interest to have usable model inputs. This is input coverage, not a percentage of true GEX captured or a probability of a wall holding. Unknown OI remains separately bounded to 10% of contract rows. Counting every missing-IV row equally no longer rejects a basket whose small inactive tails have little OI. Audit counters, each contract's reference date and aggregate IV dates remain in append-only evidence.

Input changes still start separate comparison windows. Existing model records are preserved. The setup evaluator retains its own stricter qualification rules; making a wall chart available does not automatically qualify a trade scenario.

## Validation

- 84 focused PHP tests passed with 445 assertions, covering exact-contract references, date bounds, 95% acceptance, stale quote rejection, current-session isolation, collection, events, setups and setup reviews.
- 16 export tests passed with 115 assertions against a disposable local MySQL database. Monthly EOD exports can include the independent 1W intraday timeline.
- 53 frontend tests passed across wall tracking, events, setups, setup history, dashboard navigation and exports. The production frontend build passed.
- A read-only production calculation checked 105 symbols in about four seconds. 104 qualified against the new input policy. SPY covered over 99.99% of known OI. DRAM retained a stale September 22 expiry slice and correctly remained pending.

## Production verification

Check the web and worker release commits, cached wall configuration and the served dashboard asset. Verify today's `7d` captures for SPY, QQQ, TSLA and representative watchlist names. Observe at least two scheduled cycles to confirm advancing quote times, appended readings and price evidence. Check ready, reserved and delayed queues separately. Do not purge queues solely because a timeline is empty.

No migration, provider subscription or new environment variable is required. Existing worker collection flags and quote delay remain in effect. Refresh Laravel configuration and gracefully restart long-running queue workers through the deployment process.
