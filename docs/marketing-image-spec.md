## Marketing Screenshot Spec

Use these specs when replacing marketing screenshots to avoid cutoffs and inconsistent quality.

### Capture settings

- Browser zoom: `100%`
- Desktop capture viewport: `1920x1200`
- Theme: same dark theme and chart scaling across all shots
- Save a lossless master first (`PNG`)

### Export settings

- Publish format: `WebP` (`quality 90-95`) for most images
- Keep `PNG` when chart text/lines look soft in WebP
- Export at `2x` of display size

### Slot specs used by current UI

- Home hero tiles (`HeroTile`): `16:9`
  - Recommended file size: `1800x1013` or `1920x1080`
- Home shot cards (`ShotCard`): `2:1`
  - Recommended file size: `2400x1200`
- Features hero preview: `16:9`
  - Recommended file size: `1920x1080`
- Feature rows/cards: `16:9`
  - Recommended file size: `1920x1080`

### Naming convention

Use predictable names so replacements are easy:

- `home-live-flow-16x9.webp`
- `home-net-gex-16x9.webp`
- `home-shot-live-flow-2x1.webp`
- `features-live-flow-16x9.webp`
- `features-dex-16x9.webp`

### Notes

- All image containers now use fixed aspect-ratio boxes with `object-contain` to prevent cropping.
- Keep UI chrome visible in every screenshot (top metrics + axes + legend) for consistency.
