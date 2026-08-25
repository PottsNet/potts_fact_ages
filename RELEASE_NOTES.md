# Potts Fact Ages 1.0.2

Correctness, localisation and performance update for webtrees 2.2.x.

## Fixed

- Birth title tiles now use the same server-calculated age as the Potts Fact Ages tab, including `0 days` for an exact birth date.
- Removed the DOM age-calculation fallback that could add ages beside `_FSFTID`, record-change dates or other non-event metadata.
- Added German translations for approximate-age wording and relevant category labels.
- Prevented the internal `CLOSE_RELATIVE` identifier from appearing as a user-facing label.
- The title-tile **Age** label now follows the active webtrees language.

## Performance

- Calculated age rows are cached for the duration of the page request.
- The browser observer now watches only for added or removed AJAX content.
- Attribute-change and click-triggered rescans have been removed.
- The previous eight delayed rescans have been reduced to one short delayed pass.
- All displayed ages are now supplied by the server-side calculation rather than recalculated in the browser.

## Upgrade

Upload the `potts_fact_ages` folder to `modules_v4`, replacing version 1.0.1, then clear the webtrees cache. Existing settings are retained.
