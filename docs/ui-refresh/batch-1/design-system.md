# Shared Positioning UI

Use `resources/css/ui-foundations.css` inside a `.gex-ui` wrapper. The stylesheet is opt-in and does not restyle existing pages. `ui-preview/Gallery.vue` is a working composition of the shared components, not a replacement dashboard route.

## Visual rules

Neutral graphite cards establish hierarchy. A small violet accent appears in the brand mark and keyboard focus ring. Periwinkle-blue identifies links, actions, and selected controls; a clearer cyan-blue identifies neutral data series. Green and coral identify positive and negative signed values. Amber identifies stale or caution states. Signs, labels, baselines, and named statuses carry the same meaning without color.

The base type size is 14 px with 1.55 line height; section titles are 17 px and page titles 28 px. Secondary labels are 12 px. Numbers use tabular alignment. Card radius is 14 px. Comfortable spacing is 20 px and compact spacing 14 px. Narrow screens stack cards and preserve horizontal scrolling for full tables.

| Token | Light | Dark |
| --- | --- | --- |
| Background | #f5f6f8 | #0d1218 |
| Card | #ffffff | #171e26 |
| Raised surface | #edf0f3 | #202936 |
| Main text | #20262d | #f2f4f7 |
| Secondary text | #5f6b78 | #aeb7c2 |
| Selected surface | #e6f0f6 | #183242 |
| Keyboard focus | #6657a8 | #aa9ee8 |
| Action/link | #3e5fa6 | #91a7f2 |
| Neutral data | #26749d | #68b3dc |
| Positive | #28745e | #86c6ae |
| Negative | #a44f45 | #e3a399 |
| Stale warning | #795c13 | #e1c878 |

Theme selection uses `data-theme="light|dark|system"`. The tokens use CSS `light-dark()` and `color-scheme`; confirm compatibility with the supported browser versions before broad rollout. This first implementation targets current browsers. Density uses `data-density="comfortable|compact"`.

## Trading-product references

The refresh uses current trading products as behavioral references rather than visual templates:

- Keep symbol, interval/timeframe, chart state and auxiliary panels in predictable places. TradingView documents a top toolbar for symbol and interval, a separate watchlist/details panel, and chart-level time and timezone controls in [its Supercharts guide](https://www.tradingview.com/support/solutions/43000746464-getting-started-with-supercharts/). GEX Options should use fewer controls and show only the scope that affects the active dataset.
- Let the watchlist collapse and change density without losing the active symbol. Koyfin exposes watchlists in a right sidebar and supports ticker-only or ticker-plus-name views, as described in [its sidebar guide](https://www.koyfin.com/help/right-sidebar/). For GEX Options, search/add and selection should remain one clear workflow.
- Preserve filtering power while keeping defaults understandable. Unusual Whales separates feed navigation, filters, settings, charts and search in [its options-flow guide](https://docs.unusualwhales.com/features/2-options-flow/). GEX Options should group advanced filters behind a clearly summarized filter control and always show active filters.
- Pair visual summaries with complete tables and explanations. OptionStrat presents outputs in chart and table views and explains the effect of price, time and volatility in [its strategy tutorial](https://optionstrat.com/tutorials/options-builder). GEX Options should follow the order summary, chart, selected value, collapsed full readings, then collapsed calculation details.
- Allow focused chart inspection without hiding provenance. SpotGamma's TRACE documentation includes full-screen heatmap controls and explicit scale/lookback settings in its [TRACE manual](https://spotgamma.com/wp-content/uploads/2025/07/TRACE-User-Manual-Final-Version.pdf). GEX Options should keep symbol, snapshot time, unit and scope visible when a chart expands.

The distinguishing choice is a strict color budget and clear data scope. Graphite carries structure; periwinkle-blue carries interaction; cyan-blue carries neutral series; green/coral carry signed meaning; amber carries stale or caution states; violet appears only in the brand mark and keyboard focus. Product navigation, EOD/intraday tabs, timeframe controls and chart-local controls must remain visually distinct.

## Component contract

| Component | Behavior and preservation rule |
| --- | --- |
| UiPanel | Visible section title, subtitle for source/scope, actions slot, body. Titles remain present when data is empty. |
| UiMetric | Separate label, value, unit and context. Null is unavailable; numeric zero is valid. `prominence="primary"` strengthens only the metrics that anchor a scan. Format presentation without replacing the source value in component state. |
| UiTabs | Arrow keys wrap enabled tabs; Home/End select the endpoints. The caller supplies a matching tabpanel and label. Do not use these controls interchangeably with page navigation. |
| UiSelect / UiButton | Native controls with labels, disabled state and visible focus. Select emits the original option value, retaining numeric types, and forwards native attributes to the control. Preserve actions and keyboard semantics. |
| UiStatus | Named loading, preparing, partial, stale, empty, closed-market and error states. Retry is an explicit action. Failure is announced as an alert. |
| UiExposureChart | Every supplied expiry is retained. Symmetric positive/negative scale and zero line. Exact values are in each row's accessible label. Callers compose selected-item details and a full table, as the gallery demonstrates. Keyboard and touch do not depend on a hover tooltip. |
| UiDataTable | Full row set, caption and count, numeric alignment, sortable headers, missing values last in either sort direction. Sorting does not mutate the source array. |
| UiHistory | All supplied dates are plotted; missing values produce a gap and the latest available point remains marked. Date slider provides keyboard/touch access and a collapsed table retains every row. Refreshes retain an inspected date when present; callers change `scopeKey` to select the latest date for a new bucket. The chart stays visible while readings and calculation details start collapsed. |
| UiTooltip | Short explanatory help opens on hover or focus and can be pinned by mouse, keyboard or touch. Escape closes it. Keep interactive workflows outside tooltips and repeat chart values in selected details or tables. |
| UiHelpDialog | Reading guides open in a viewport-fixed dialog with focus containment, Escape and backdrop close, scroll lock, and focus restoration. Use it for multi-paragraph guidance rather than expanding content at the bottom of a long panel. |
| UiBadge | Compact symbol, scope, date, and status context. Text carries the meaning; the optional semantic dot reinforces it. Keep badges out of dense data cells. |

Consumer code owns request cancellation, stale response suppression, filters, errors and data provenance. The components do not fetch data or infer unavailable measurements. Preserve every call/put series and every raw field during integration; rounded presentation and collapsed tables do not replace the source payload held in state.

## Chart contract

Every chart states what it measures, its symbol and dataset scope, timeframe or bucket, snapshot time or comparison date, and units without requiring hover. Signed charts show zero. Ratio charts show the meaningful reference value, such as 1.0. Strike charts identify spot when the verified source provides it. Legends name the fields or calculation. Keyboard and touch users can inspect each source row; visible labels use consistent, readable precision while accessible descriptions and component state retain the underlying values.

Focus, bucketing, and zoom are presentation states. When a view limits or groups rows, show “displaying X of Y,” name the rule, preserve the unmodified input, and provide a reset action. Additive buckets must match source totals within stated rounding. Recompute ratios from their numerator and denominator unless the existing verified calculation says otherwise. Sorting, selection, and resizing must not mutate, discard, or refetch the dataset.

Use color only for verified meaning. Put/call identity also needs a label or line treatment. Do not label a complex metric bullish or bearish unless that interpretation is part of the verified calculation. Charts do not tween, count up, or animate from zero. Hover, selection, resize, and zoom must not issue network requests. Reuse observers and chart instances, and avoid repeated blur or glow effects in scrolling data surfaces. Do not add a general chart package unless a missing requirement justifies its measured bundle and dense-render cost.

Hover and selection transitions are 150 ms with one shared easing curve. Buttons rise by 1 px, selected rows and tabs change surface or marker color, and explanatory tooltips fade by 2 px. Cards, numbers, chart bars and history lines remain stable. There are no animated number counters, pulsing states, entrance choreography or delayed data. Both `prefers-reduced-motion` and `data-motion="reduced"` set transition duration to zero. Touch controls have a 44 px minimum height where configured. Verify visual focus and all target sizes in the target browser before release.

## Public-page motion

Home and Features may use user-controlled product-preview tabs, accessible chart callouts, expandable current screenshots, brief card/CTA feedback, and an optional one-time reveal of no more than 4 px for below-fold product media. Content must remain available before the enhancement runs. Reduced motion presents the same final content immediately. Do not autoplay market movement, rotate carousels, delay hero copy, scroll-jack, or run continuous decorative motion.

Pricing may animate only the selected border, marker, or short content transition after the user changes interval or plan. Prices and billing terms update immediately without count-up, pulsing recommendations, countdowns, false urgency, or layout movement. Authentication, contact, and account pages use the same brief feedback for verified loading, success, and error states without moving focus.

Moving product assets are user-controlled, use an efficient video or CSS treatment instead of GIF, and include a poster and static reduced-motion fallback. Below-fold media is lazy-loaded with explicit dimensions; the LCP asset is protected from optional motion code. Preview engagement remains diagnostic and must not count as a CTA, trial, paid activation, or first useful dashboard session.

## Reference and fixtures

The gallery follows the approved Positioning hierarchy: snapshot context, concise summaries, signed exposure by expiry, selected expiry detail, bucket selection, visible skew history, and collapsed calculation and full-reading disclosures. It preserves every recorded row while keeping secondary detail out of the default reading path. It is a subset used to validate shared components, not a claim that all Positioning fields have already been implemented.

`ui-preview/fixtures.js` contains 40 rounded expiry readings and 30 daily skew readings for each of three buckets, transcribed from the approved reference. Snapshot date: September 9, 2026. These are recorded display values, not raw API payloads. Reported net DEX is retained separately rather than recomputed from rounded rows. Rounded put/call IV can differ from the reported skew; summary and rolling history are independent samples.

Synthetic cases cover sparse/missing, all zero, positive only, negative only, empty, loading, preparing, stale, closed-market and failed requests. They operate on copies of the recorded arrays. Full API fixtures for other screens belong to their implementation cards.
