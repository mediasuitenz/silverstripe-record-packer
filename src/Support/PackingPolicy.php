<?php

namespace MadeCurious\RecordPacker\Support;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;

/**
 * Everything {@see \MadeCurious\RecordPacker\Extensions\PackableExtension} and
 * {@see \MadeCurious\RecordPacker\Extensions\RecordLockExtension} need that varies between "a
 * SiteTree page" and "an arbitrary packable DataObject" — injected into both extensions as a
 * constructor-supplied collaborator rather than hard-coded, so the two extension classes serve
 * both cases without a SiteTree-specific subclass of either.
 *
 * Two implementations ship: {@see RecordPackingPolicy} (the default, for any project DataObject)
 * and {@see SiteTreePackingPolicy} (for `SiteTree`, wired up as a named Injector service variant
 * in this module's own `_config/extensions.yml` — the same pattern
 * `silverstripe/versioned`'s own `Versioned` extension uses for its Stage/StagedVersioned modes:
 * one extension class, two named `Injector` service variants with different constructor args,
 * and a default alias).
 */
interface PackingPolicy
{
    /**
     * The Permission code gating this record's export/import.
     */
    public function permissionCode(): string;

    /**
     * The job class actually queued for this record's export — what
     * {@see RecordLockExtension::pendingJobExists()} checks the queue for by default, and what
     * {@see PackableExtension::addExportTrigger()} checks isn't already in flight.
     */
    public function exportJobClass(): string;

    /**
     * The job class actually queued for this record's import.
     */
    public function importJobClass(): string;

    /**
     * Wording for the "this record is currently being exported/imported" banner
     * RecordLockExtension::updateCMSFields() shows.
     */
    public function lockedWarningMessage(): string;

    /**
     * Builds and pre-populates the Export modal's form for $owner, or returns null if there's
     * nowhere to host it right now (e.g. the SiteTree policy needs a CMSMain-derived controller
     * currently rendering the page, and returns null outside of that context).
     */
    public function getExportModalForm(DataObject $owner): ?Form;

    /**
     * Places the built trigger button onto the record's CMS actions — e.g. pushed flat for a
     * generic record, or into `ActionMenus.MoreOptions` for a SiteTree page.
     */
    public function placeExportTrigger(FieldList $actions, LiteralField $trigger): void;

    /**
     * Whether PackableExtension::updateCMSFields() should add a formatted
     * {@see ExportHistoryField} back onto the record's own edit form after removing the raw
     * auto-scaffolded `ExportRequests` relation field.
     *
     * True for a generic record, which has nowhere else to see its export history or download a
     * past export — false for a SiteTree page, which already gets this from a dedicated
     * "Content Export" tab (see CMSPageContentExportController); adding it inline there too
     * would just duplicate it.
     */
    public function showsHistoryFieldInline(): bool;

    /**
     * $record's own human-readable display title, or null if it has none — "Title" is a
     * near-universal convention across both generic DataObjects and SiteTree, but not a
     * guaranteed one; this is the seam an integration whose records use a different convention
     * (e.g. Name, Label, or a computed accessor) can override, rather than the generic engine
     * hardcoding a field name in multiple places (the manifest meta block, export filename slug
     * generation).
     */
    public function displayTitle(DataObject $record): ?string;

    /**
     * Sets $record's display title to $value, returning whether anything was actually changed —
     * a record type with no display title concept at all is a no-op. Used to surface an error
     * message directly on a failed import's stub record (see RecordImportJob::failStub())
     * without that generic engine code needing to know what field, if any, this record type uses.
     */
    public function setDisplayTitle(DataObject $record, string $value): bool;
}
