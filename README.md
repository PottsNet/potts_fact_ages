# Potts Fact Ages for webtrees

Potts Fact Ages is a standalone custom module for **webtrees 2.2.x**.

It displays a person’s calculated age at dated facts and events. It can show ages in a separate tab, place ages directly on the existing Facts and events tiles, or do both.

This module began as part of Jason Potts’ family-history website and is now published as part of the Potts webtrees module family.

## Features

- Adds a standalone **Potts Fact Ages** tab on individual pages.
- Can add age labels to existing fact/event title tiles on the individual page.
- Provides a setting to show the tab, show age labels on event title tiles, or both.
- Hides the separate tab when administrators choose the title-tile-only display option.
- Works without Vesta or any other custom module dependency.
- Watches for AJAX-loaded tab content so labels can be added after the standard webtrees Facts and events tab opens.
- Calculates ages from the person’s first recorded birth date.
- Supports personal facts such as birth, baptism, residence, occupation, education, census, immigration and emigration.
- Supports spouse-family facts such as marriage and divorce.
- Supports close-relative, associate and historical facts when webtrees provides them.
- Handles common GEDCOM date forms including full dates, month/year dates, year-only dates, approximate dates and simple date ranges.
- Provides module settings for choosing which categories to show.
- Allows administrators to choose which GEDCOM fact and event tags are included.
- Provides timeline-card and compact-table display styles.
- Provides simple, detailed or combined age display.

## Requirements

- webtrees 2.2.x
- PHP 8.3 or later

## Potts Biography compatibility

Version 1.0.2 retains the 1.0.1 change that excludes `.potts-life-story` from title-tile scanning so Biography's own age wording is not duplicated. The standard Facts and events tab remains supported.

## Installation

1. Download the release zip file, for example `potts_fact_ages-1.0.2.zip`.
2. Extract the zip file.
3. Upload the `potts_fact_ages` folder to your webtrees `modules_v4` directory.
4. In webtrees, go to **Control panel > Modules > All modules**.
5. Enable **Potts Fact Ages**.
6. Go to **Control panel > Modules > Tabs** and choose where the tab should appear.
7. Open the module settings and choose how ages should be displayed. The settings page includes a direct **Tabs settings** link for confirming that the tab is enabled and for changing the tab order.

The final folder path should be:

```text
modules_v4/potts_fact_ages/module.php
```

## Upgrading from the early `fact_ages` beta

Early development betas used the folder name `fact_ages` and the user-facing title **Fact Ages**.

For the public GitHub release, the module was renamed to **Potts Fact Ages** and the folder was renamed to `potts_fact_ages`.

Recommended upgrade process:

1. Disable the old **Fact Ages** module in webtrees.
2. Remove or rename the old `modules_v4/fact_ages` folder.
3. Upload the new `modules_v4/potts_fact_ages` folder.
4. Enable **Potts Fact Ages**.
5. Re-check the module settings.
6. Clear the webtrees cache.

## Settings

The module configuration page lets administrators choose where ages are shown:

- Separate **Potts Fact Ages** tab
- Age labels on the existing fact/event title tiles
- Both the tab and title labels

The settings page also includes a direct **Tabs settings** link. Use this to make sure the **Potts Fact Ages** tab is enabled in webtrees and to set its position among the other individual-page tabs.

Administrators can also choose whether to display:

- Personal facts
- Family facts
- Close relative events
- Associate events
- Historical facts
- Category labels

Administrators can also choose the GEDCOM tags that are included. The default list covers common personal, family, relative, associate and historical events.

Display style options:

- Timeline cards grouped by year
- Compact table

Age display style options:

- Simple, for example `35 years`
- Detailed, for example `35 years, 2 months, 4 days`
- Both, for example `35 years (35 years, 2 months, 4 days)`

### About historical-facts ages

Potts Fact Ages controls its own **Potts Fact Ages** tab and the optional age labels it adds to fact/event title tiles. If webtrees or another historical-facts module displays its own ages inside its own historical-events tab, those ages are separate and are not changed by this module.

## Troubleshooting

The event-title labels are added using a front-end matcher because the standard webtrees facts tab is often loaded dynamically. The age values themselves are calculated on the server and the browser only matches those calculated rows to the rendered fact/event tiles.

For troubleshooting, open your browser console and run:

```javascript
window.pottsFactAgesStatus
```

The status output includes the module version, candidate and match counts and whether server-side individual detection worked. Version 1.0.2 reports `domFallback: false` because browser-side age calculation has been removed.

## Known limitations

- The event-title labels are matched against the rendered webtrees page. Unusual custom themes may need extra testing.
- Ages are calculated from the first recorded birth date only.
- Unsupported or malformed GEDCOM dates are skipped silently.
- BCE dates and phrase-only dates are not currently supported.
- Custom tag filtering is based on GEDCOM tags, not translated fact labels.
- This is a stable public release. As with any webtrees module, test upgrades on a staging copy first where possible.

## GitHub release checklist

For each public release:

1. Update the version number in `module.php`.
2. Update `CHANGELOG.md`.
3. Update `RELEASE_NOTES.md`.
4. Create an installable zip containing the `potts_fact_ages` folder.
5. Attach the installable zip to the GitHub release.
6. Do not upload GitHub’s automatically generated `Source code.zip` as the installable webtrees module.

## Licence

GPL-3.0-or-later.

See `LICENSE` for details.

The settings page includes navigation buttons back to the module list, the webtrees Tabs settings page, the control panel and the home page.


## Custom Module Manager

This release includes `latest-version.txt` and update-service metadata so webtrees and Custom Module Manager can detect future updates.
