# GEX Options social post formats

Use one observation and one matching product image per post. SPY, QQQ and TSLA can use the same formats with their own data. Include the analysis date and expiry scope. Intraday posts must show the recorded market time and the 15-minute price delay.

Replace every placeholder with a verified value from the matching production view. Do not turn demonstration values or Test alerts into market commentary. Preview the full post and image together before approval.

## Exposure map

Use Net GEX by strike for an EOD preparation post. Show total net GEX and the largest positive and negative levels above the chart.

> $SPY levels to watch for {analysis_session}
>
> Largest positive net GEX: {positive_strike}
> Largest negative net GEX: {negative_strike}
> Total net GEX: {total_net_gex} {units}
>
> {scope} expirations · EOD {data_date}
> Price areas to monitor as the next session develops.
> #Options

Positive and negative GEX describe modeled exposure. Do not automatically describe them as bullish and bearish signals. For Sunday preparation, use the latest completed EOD data and the correct next trading session. Exclude expired contracts according to the preparation view.

## Wall movement and price response

Use the wall timeline with a selected price-event marker. Describe what price did and whether the leading wall moved. A wall moving across price is not a price breakout.

> $QQQ at the {put_or_call} wall
>
> Price {observed_behavior} near {level} at {time_et}.
> The leading wall {wall_movement} during the comparison window.
>
> {session_date} · 1W expirations · Prices delayed 15 min
> #Options

Use an observed event such as a test, touch, bounce or break and retest only when the stored price evidence supports that description. Avoid forecasting a hold or a target from the wall alone.

## Recorded setup with boundaries

Use the setup assessment image only when conditions were met at the recorded time. Keep its entry area, invalidation and target in the image. An expired assessment can be discussed as a dated example, not as an available entry.

> $TSLA recorded setup · {time_et}, {session_date}
>
> {recorded_scenario}
> Entry area: {entry_low}–{entry_high}
> Invalid if a 5-minute close {invalidation_rule}
> Reference target: {target}
>
> Conditions met at the displayed delayed close. Check current price.
> #Options

If the text exceeds the account’s post limit, keep the scenario, recorded time and delay in the post. Retain all three price boundaries in the image. Do not silently remove the invalidation condition.

## Gamma context

Use the gamma-context panel alongside the matching wall chart. Name the current modeled regime and flip without promising a market outcome.

> $SPY gamma context · {session_date}, {time_et}
>
> Modeled regime: {regime}
> Flip: {flip_level}
> Delayed price: {recorded_price}
>
> Context for the wall levels and price reactions—not a directional forecast.
> 1W expirations · Prices delayed 15 min
> #Options

## Options pricing

Use the intraday IV and skew view with the expiration and recorded time visible. Compare one expiration throughout the session.

> $QQQ options pricing · {session_date}
>
> {expiration} near-money IV: {iv}% ({iv_change} pp since {first_time_et})
> Put-minus-call skew: {skew} pp
>
> Positive skew means the 25-delta puts carry higher IV than calls.
> As of {time_et} · Delayed updates
> #Options

## Publication rules

- Keep the current automation: SPY at 8:45 AM Eastern on Sunday, Tuesday and Thursday. QQQ and TSLA require approval and retain Send now.
- These formats do not change the scheduler, account permissions or automatic publication logic.
- Use only fresh production data for the intended analysis session. Skip an automatic post if the required data is not ready.
- Use the symbol cashtag and one relevant hashtag. Avoid a list of unrelated tags.
- Put a product link in selected explanatory posts. The profile and pinned post can explain the complete workflow.
- Check the rendered character count, scope, units, date and image before publishing. Do not combine values from different views or sessions.
