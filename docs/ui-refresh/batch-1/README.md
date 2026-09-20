# Batch 1: UI foundations

Batch 1 provides the source inventory, acquisition measurement audit, and shared Positioning components. The dashboard integration starts in Batch 2. Existing pages and their data fetching remain unchanged.

## Run locally

From the repository root, use Node 20 as declared in `.nvmrc` and run:

```powershell
npm ci
npm run ui:preview
```

If dependencies are already installed, only the second command is needed. Open **http://127.0.0.1:4173/**. This preview needs no Herd, PHP, database, `.env`, login, production credentials, or external data feed. The server binds only to loopback. Stop it with Ctrl+C.

For a built preview, run `npm run ui:build` followed by `npm run ui:serve`. Generated assets go to ignored `storage/app/ui-preview`, separate from Laravel's production build. `npm run build` continues to build the normal application.

## Deliverables and completion

| Card | Deliverable | Remaining review |
| --- | --- | --- |
| UI-01 | [Screen and field map](inventory.md), [generated source inventory](source-inventory.json), recorded and synthetic fixtures | Complete field-level expansion, compare the deployed application with the declared source snapshot, and capture missing live screen references. Source coverage alone does not establish deployed parity. |
| UI-02 | [Event dictionary, funnel audit, baseline collection plan](measurement.md) | GA property access, real baseline values, DebugView verification, and confirmed activation instrumentation remain outstanding. No conversion numbers are inferred. |
| UI-03 | Shared Vue components, CSS tokens, clickable local gallery, [interaction rules](design-system.md) | Human visual review at desktop, tablet, and mobile widths; compare against the approved Positioning reference and save durable approved captures. |

## Manual review

1. Open the local preview at widths of approximately 1440, 768, and 390 pixels. Check reading order, clipped text, card widths, table scrolling, and the visible zero line. Switch Dark, Light, and System appearance and Comfortable/Compact density.
2. In the recorded example, confirm **40 expiry rows**, including expired dates, positive and negative readings, and the zero on September 23. The symmetric scale, center baseline, signs, and legend should explain direction without relying on color. Tab into the chart once, then use arrow keys and Home/End to inspect rows. Compare the full selected value with the sortable table. Sorting must retain all rows.
3. Switch between 0DTE, 1W, and 1M with arrow keys and Home/End. Each bucket must have **30 history readings**. The chart must name its scope, available count, units, zero baseline, selected date, and selected value without hover. Move the date slider with arrow keys and compare its value to the full table. Missing readings form gaps but isolated valid points remain visible. Keep the chart visible while the daily readings, calculation details, and complete field history start collapsed.
4. Try every Data example. Missing values must say Unavailable, zero must stay 0, sparse history must have a gap, and empty/loading/preparing/error cases must not show the recorded summary as current. Retry the error case and confirm readings return.
5. Use Tab to move through the page. Check focus visibility, readable selected states, and accessible table controls. Graphite should dominate; cyan-blue marks neutral data, periwinkle-blue marks interaction, green/coral mark signed values, amber marks caution, and violet is limited to the brand/focus accent. Enable Reduce motion and the operating system's reduced-motion preference. Numbers and charts should never animate into place.
6. Review the inventory before starting each later tab. Check API fields, tooltip precision, filter scope, histories, export actions, and educational copy against the current production screen. Resolve the named gaps instead of assuming the gallery covers the whole product.

The gallery's values are historical display samples and labeled synthetic cases. They are not current market data. No production database copy is necessary for Batch 1.
