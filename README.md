# silverstripe-record-packer

Serialises and deserialises objects from within the CMS to facilitate transfer between environments.

Zips up a json record of the object and its relations, alongside an optional assets bundle.

## Installation

```bash
composer require madecurious/silverstripe-record-packer
```

## Requirements

- PHP `^8.1` 
- `ext-zip`
- `silverstripe/framework` `^5.4`
- `silverstripe/assets` `^2.3`, 
- `silverstripe/asset-admin` `^2.4`
- `silverstripe/versioned` `^2.4`
- `symbiote/silverstripe-queuedjobs` `^5.3`

## Workflow

- open record detail view
- hit "Export" action button
- give the item a description, choose whether or not to include assets
- lock the object while the export is pending
- use Queued Job to export Live version of record
- visit CMS tab to download file
- open relevant Gridfield in target site
- use Import gridfield action to upload file
- check preview to ensure the right content
- get taken to stub record while import is pending
- use Queued Job to import a Draft version of the record
- save and publish once ready

## Documentation

See [docs/](docs/README.md) for the full picture:

- [Developer guide](docs/developer-guide.md) — how to add export/import to your own
  DataObject, configuration, architecture, and testing.
- [User guide](docs/user-guide.md) — the CMS experience for editors: exporting, importing,
  the Export history tab, stale/fresh, permissions.