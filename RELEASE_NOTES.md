# Potts Fact Ages 1.0.0-beta.7

This is the seventh public GitHub beta release of **Potts Fact Ages**.

## Highlights

- Standalone webtrees 2.2.x module.
- No Vesta dependency.
- Adds a **Potts Fact Ages** tab to individual pages when the tab option is selected.
- Can optionally add age labels directly to existing Facts and events title tiles.
- Adds a direct **Tabs settings** link from the Potts Fact Ages settings page using the webtrees `/admin/tabs` route.
- Adds a helper panel reminding administrators to enable and order the separate tab in webtrees **Control panel > Modules > Tabs**.
- Keeps the display-location fixes from beta 5.
- Includes category and GEDCOM tag controls.
- Includes timeline and compact-table display options.

## 1.0.0-beta.7 fix

This release fixes the **Tabs settings** link so it points to the webtrees `/admin/tabs` route relative to the current webtrees installation, rather than falling back to My Page or another routed page.

Historical age text shown by another historical-facts tab or by webtrees itself is not changed by this module.

## Installation

Download `potts_fact_ages-1.0.0-beta.7.zip`, extract it and upload the `potts_fact_ages` folder to:

```text
modules_v4/potts_fact_ages
```

Then enable **Potts Fact Ages** in the webtrees control panel.

## Upgrade note

Early test builds used the folder name `fact_ages`. This public beta uses the folder name `potts_fact_ages`.

Disable the old **Fact Ages** module and remove or rename `modules_v4/fact_ages` before installing this release.

## Beta warning

This is a beta release. Test it on a staging copy of your webtrees site before using it on a production site.
