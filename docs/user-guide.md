# RecordPacker — user guide

RecordPacker adds two things to the CMS for a record your site's developer has enabled it
for: an **Export** action, and an **Import** option for creating a new record from a 
previously exported file. Together these let you copy a record from one
environment to another (e.g. from a staging site to production) without needing a
developer.

Both actions require the **"Export/import packable records"** permission. If you don't see
the options described below, ask an administrator to grant your Security group the
`RECORD_IMPORT_EXPORT` permission under **Security → Groups** in the CMS.

> Exactly which records this appears on depends on what your developer has enabled it for —
> this guide describes the CMS experience once it's turned on for a given record type.

## Exporting a record

1. Open the record you want to export (its own edit form, or its detail view inside a
   GridField).
2. Click the **Export** button, alongside Save/Publish/Delete.

_[screenshot: export button]_

3. A dialog opens with two options:
   - **Include referenced files/images** — on by default. Bundles any files or images the
     record uses (including ones embedded inline in text via TinyMCE) into the exported
     file, so it looks right as soon as it's imported elsewhere. Turn this off for a smaller
     file if you know the target environment already has the same assets.
   - **Description** — an optional short note (e.g. "before Christmas campaign edits") to
     help you tell exports apart later.

_[screenshot: export modal]_

4. Click **Export**. The export runs in the background — it doesn't happen instantly. You
   land back where you started, and the record's **Export history** tab picks up a new
   `Queued` row once the job starts.

> **Note:** if the record supports draft/published versions, exporting always packages its
> **published (live)** content, not unsaved draft changes — publish it first if you want
> your latest edits included. If it doesn't have that distinction (most GridField-managed
> records don't), its current saved content is what gets exported.

The record is locked for editing while the export is queued and running — this is to
prevent new changes from being included partway through.

_[screenshot: locked warning banner]_

### The Export history tab

Every record that has been exported (or was itself created by importing a file) has an
**Export history** tab alongside its other fields.

_[screenshot: export history tab]_

This tab lists every export and import for the record, most recent first, showing:

| Column | Meaning |
|---|---|
| Created | When the export/import was requested |
| Description | The note you added, if any |
| Origin | `Export` (created here) or `Import` (the file this record was created from) |
| Status | `Queued`, `Complete`, or `Failed` |
| Requested by | Who triggered it |
| Assets included | Whether files/images were bundled |
| Fresh / Stale | See below |
| File | A download link, once complete |

You can delete old entries from this list once you no longer need them.

**Fresh vs. Stale:** an export is marked **Fresh** if nothing on the record (or anything it
owns) has changed since that export was taken, and **Stale** once it has. A stale badge
doesn't stop you downloading the file — it's just a hint that it no longer reflects the
record's current content, so you may want to re-export before using it.

## Importing a record

Imports happen from inside a GridField, not from the record's own edit form.

1. Open the GridField the record type is managed in.
2. Click the **Import** button in the GridField's toolbar.

_[screenshot: GridField import button]_

3. Upload the exported file. Once it finishes uploading, a preview panel appears showing:
   - The record type it will create
   - Its title, if it has one
   - How many assets (files/images) are bundled
   - A warning if that record type isn't installed on this site — importing will still
     create a bare record in that case, but its specific fields/relations won't be
     recreated

_[screenshot: import preview]_

4. Check the preview, then click **Import**. You're taken straight to the new record's edit
   view, which is locked while the import runs in the background.
5. Once the import finishes, the lock is lifted and the record is ready to review. If the
   record supports draft/published versions, it starts life as a **draft** — check it over
   and publish it yourself when you're happy with it. If it doesn't have that distinction,
   the imported content is simply there as its current saved state.

   Its Export history tab will already show the import file as history.

If an import fails, the new record is kept (retitled to flag the failure, where it has a
title field) rather than disappearing, and the Export history tab records a **Failed** entry
with a status message — pass this on to your developer if you need help diagnosing it.

Some GridFields also add a one-click **Export** action per row, alongside the usual delete
button — an alternative to opening a record's detail view just to click its own Export
button.

_[screenshot: GridField row export action]_

## While an export or import is running

A record shows a banner at the top of its edit form while it has an export or import
actively in progress, and can't be edited or published until that job finishes. This is
normal — it prevents the background job and an editor from changing the record at the same
time. Wait for the job to complete (or refresh the page after a minute) and the banner will
clear.

## Frequently asked questions

**Does exporting publish or change my record?**
No. Exporting only reads the record's current content — it never modifies the record
itself.

**Can I import into an existing record rather than creating a new one?**
No — importing always creates a brand-new record. If you want to replace an existing
record's content, delete or unpublish the old one after checking the new import looks
right, or ask a developer for help if you need a more surgical merge.

**Why is there a delay before my export/import finishes?**
Exports and imports run as background jobs rather than happening instantly, so that large
records with lots of assets don't time out a normal web request. Processing time depends on
how often background jobs run on your environment — check with your developer if a job
seems stuck in "Queued" for an unusually long time.
