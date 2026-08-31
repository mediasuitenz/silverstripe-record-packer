# RecordPacker — developer guide

`madecurious/silverstripe-record-packer` (namespace `MadeCurious\RecordPacker`) lets any
project `DataObject` — typically one edited through an ordinary GridField — get an "Export"
button, an "Import" button, export history, and in-flight locking, entirely through config
and two opt-in GridField components. No template overrides, no subclassing, no CLI/task
entry point.

## Requirements

- PHP `^8.1` (`ext-zip` too — exports/imports are always a zip)
- `silverstripe/framework` `^5.4`
- `silverstripe/assets` `^2.3`, `silverstripe/asset-admin` `^2.4` (the import upload field)
- `silverstripe/versioned` `^2.4` (only actually engaged for a target class that has the
  extension — see "Versioning" below)
- `symbiote/silverstripe-queuedjobs` `^5.3`, and its queue must actually be processed (via
  its cron-driven `ProcessJobQueueTask` or an equivalent scheduled runner) on every
  environment this runs on — export and import both run as queued jobs, so they'll sit in
  `Queued` forever on an environment where the queue isn't being processed.

## Installation

```bash
composer require madecurious/silverstripe-record-packer
```

## Adding export/import to your own DataObject

Apply two extensions via YAML:

```yaml
App\Model\MyObject:
  extensions:
    - MadeCurious\RecordPacker\Extensions\RecordLockExtension
    - MadeCurious\RecordPacker\Extensions\PackableExtension
```

That's it. That gives you:

- **`PackableExtension`** adds the `ExportRequests` has_many (the export/import history),
  builds the "Export" trigger button
- **`RecordLockExtension`** overrides `canEdit()`/`canPublish()` while an export
  or import job for that record is in flight, and injects a "currently being
  exported/imported" warning banner into the edit form.

Both extensions delegate to an injected `Support\PackingPolicy`. A default
implementation ships: `RecordPackingPolicy`. Implement your own only if you need a
record type hosted somewhere other than a plain edit form/GridField 
(see: `madecurious/silverstripe-page-packer` for the `SiteTree` version).

### GridField wiring

A GridField-edited record already gets its "Export" button automatically — this module
applies `GridFieldRecordActionsExtension` globally to `GridFieldDetailForm_ItemRequest`

Two further components are opt-in per `GridFieldConfig` — add either or both:

```php
use MadeCurious\RecordPacker\Forms\GridField\GridFieldRecordExportAction;
use MadeCurious\RecordPacker\Forms\GridField\GridFieldRecordImportButton;

GridFieldConfig_RecordEditor::create()
    // Toolbar "Import" button — creates a new record in this GridField from an uploaded
    // export file, with a live preview of what it contains before you commit.
    ->addComponent(new GridFieldRecordImportButton())
    // One-click "Export" per row, alongside GridFieldDeleteAction etc. — an alternative to
    // opening a record's detail view just to click its own Export button.
    ->addComponent(new GridFieldRecordExportAction());
```

Both no-op (return no HTML / hide the row action) for a model class that doesn't have
`PackableExtension` applied, so they're safe to add to a `GridFieldConfig` shared across
different tabs/model classes.

### Versioning is automatic, not required

`RecordExportJob`/`RecordImportJob` check `hasExtension(Versioned::class)` on the record's
class before deciding whether to engage `Versioned::withVersionedMode()`/`set_stage()` at
all. A `Versioned` class exports its **published (live)** content and imports onto a
**draft**; a plain, unversioned `DataObject` simply exports its current content.

## Configuration

The one setting intended for project-level overriding is `mismatch_behaviour` on
`RecordSerializer` — the shared serialization engine — controlling what happens when an
export/import encounters a relation shape, class, or field that doesn't match what's
expected on the target site:

```yaml
MadeCurious\RecordPacker\Serialization\RecordSerializer:
  mismatch_behaviour: fail       # or 'best_effort'
```

- `fail` (default) — abort with a clear error the moment a mismatch is encountered.
- `best_effort` — skip what doesn't match and record a warning instead letting the rest 
  of the import/export complete.

One exception either way: an **unresolvable root class**, or a root class that isn't the
stub's own class or a subclass of it, is always fatal on import — see `RecordImportJob`'s
own checks.

Two further sets of config on `RelationSchema` are overridable per project:

- `$excluded_system_fields`, `$excluded_relation_classes`, `$excluded_relation_names` — lists
  of fields/classes/relation names this module will never attempt to walk. Extend these via
  YAML if you have custom relations that shouldn't be included in exports.
- `AssetBundler::$import_folder` (`src/Serialization/AssetBundler.php`) — the assets folder
  (`record-packer-imports`) used both to store outgoing export zips and to materialise
  incoming imported assets. The import upload field itself writes to a separate, hard-coded
  folder, `record-packer-uploads`.

## What actually gets exported

Only **owned** content travels with the record: `$has_one` relations also listed in
`$owns`, every `$has_many`, and `$many_many`/`belongs_many_many` (a `many_many` using
`many_many_extraFields` or a `through` join object is reported as an unsupported relation
rather than silently dropped. A plain `$has_one` that isn't in `$owns` is not walked into 
at all. File/Image `$has_one` relations are handled separately by `AssetBundler` rather 
than as graph nodes, including ones only referenced from inside HTML content as a shortcode

## Security

- `RECORD_IMPORT_EXPORT` — gates every part of this module; the Export trigger, the
  GridField Import button/row action, and `RecordPackerController`'s own routes.

## Known limitations / gotchas

- **Import always creates a new record** — there's no "import into an existing record" path.
  If you want to replace an existing record's content, delete or unpublish the old one after
  checking the new import looks right.
- **A GridField import is scoped to that GridField's own model class** — the uploaded
  file's root class must be the GridField's model class or a subclass of it; there's no
  "import creates whatever class the file says" behaviour.
