# silverstripe-record-packer

Serialises and deserialises objects from within the CMS to facilitate transfer between environments.

Zips up a json record of the object and its relations, alongside an optional assets bundle.

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
