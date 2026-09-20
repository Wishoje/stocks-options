# Marketing product media

The source of truth is `docs/ui-refresh/batch-9/product-media-manifest.json`. A product image can move from `pending_capture` to `ready` only after the corresponding interface is implemented and reviewed.

## Capture settings

- Capture the delivered application at 100% browser zoom.
- Prefer a 1600 x 1000 desktop viewport and a dedicated 390 x 844 mobile viewport. When a reviewed native screenshot is supplied at another size, publish its declared native dimensions and record any proportional mobile derivative in the manifest.
- Use a local review account with generic symbols. Remove account identity, filenames, tokens, query strings, and notifications.
- Keep the symbol, mode, timeframe or dataset scope, dates, units, source status, and relevant controls visible.
- Use one repeatable recorded example per view. The caption must say that it is a recorded example.
- Never compose a mock product result or reuse an earlier UI capture as current product proof.

## Export settings

- Keep a lossless local master outside the published directory.
- Publish WebP at the dimensions declared in the manifest. Use PNG only when text or fine chart lines are measurably clearer.
- Give every published file a width and height in the media registry to prevent layout shift.
- Use descriptive alt text for the product state shown. Put dates, units, and scope in the nearby caption when repeating them in alt text would be noisy.

## Review gate

Before marking an asset `ready`, compare the capture with the delivered route at desktop and mobile sizes. Confirm that the manifest's required context is visible and that no stale or personal information appears. Add the final file path, dimensions, alt text, caption, capture revision, and review date to both the manifest and `resources/js/Support/marketing-content.js`.
