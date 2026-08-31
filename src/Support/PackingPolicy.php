<?php

namespace MadeCurious\RecordPacker\Support;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;

/**
 * Everything {@see \MadeCurious\RecordPacker\Extensions\PackableExtension} and
 * {@see \MadeCurious\RecordPacker\Extensions\RecordLockExtension} need
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
     */
    public function lockedWarningMessage(): string;

    /**
     * Builds and pre-populates the Export modal's form for $owner
     */
    public function getExportModalForm(DataObject $owner): ?Form;

    /**
     * Places the built trigger button onto the record's CMS actions
     */
    public function placeExportTrigger(FieldList $actions, LiteralField $trigger): void;

    /**
     * Whether PackableExtension::updateCMSFields() should add a formatted
     * {@see ExportHistoryField} back onto the record's own edit form after removing the raw
     * auto-scaffolded `ExportRequests` relation field.
     */
    public function showsHistoryFieldInline(): bool;


    public function displayTitle(DataObject $record): ?string;

    public function setDisplayTitle(DataObject $record, string $value): bool;
}
