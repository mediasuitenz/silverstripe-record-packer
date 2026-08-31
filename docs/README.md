# RecordPacker documentation

RecordPacker lets a CMS editor pack a single `DataObject` — its content, owned relations
(including nested owned records), and any referenced files/images (including ones embedded
as TinyMCE shortcodes) — into a downloadable file, and unpack it elsewhere to recreate the
record as a new draft. It's built for moving content between environments (e.g. dev → UAT →
production) entirely through the CMS UI, with no developer or CLI access required.

This `docs/` folder covers two audiences:

- **[Developer guide](developer-guide.md)** — for developers wiring export/import onto a
  project `DataObject`, configuring mismatch handling, and running the test suite.
- **[User guide](user-guide.md)** — for CMS editors/authors who want to export or import a
  record, understand the "stale" badge, and know what permission they need.


For a quick summary and the installation one-liner, see the [module README](../README.md) in
the repository root.
