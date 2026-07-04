# Potts Fact Ages 1.0.0

Stable release of **Potts Fact Ages** for webtrees 2.2.x.

This release promotes the tested `1.0.0-beta.7` build to a regular stable release.

## Highlights

- Standalone webtrees 2.2.x module.
- No Vesta dependency.
- Adds a **Potts Fact Ages** tab to individual pages when the tab option is selected.
- Can optionally add age labels directly to existing Facts and events title tiles.
- Includes category and GEDCOM tag controls.
- Includes timeline and compact-table display options.
- Includes simple, detailed and combined age-display options.
- Includes update service support for webtrees and Custom Module Manager.

## Changes since beta.7

- Changed the internal version from `1.0.0-beta.7` to `1.0.0`.
- Added `latest-version.txt`.
- Added `customModuleLatestVersionUrl()` so the module should not show `Update service: None`.
- Updated README, changelog and release notes from beta wording to stable release wording.

## Installation

Download `potts_fact_ages-1.0.0.zip`, extract it and upload the `potts_fact_ages` folder to:

```text
modules_v4/potts_fact_ages
```

Then enable **Potts Fact Ages** in the webtrees control panel.

## Upgrade note

Early test builds used the folder name `fact_ages`. This public release uses the folder name `potts_fact_ages`.

Disable the old **Fact Ages** module and remove or rename `modules_v4/fact_ages` before installing this release.
