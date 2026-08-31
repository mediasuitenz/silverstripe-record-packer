<?php

namespace MadeCurious\RecordPacker\Support;

use MadeCurious\RecordPacker\Controllers\RecordPackerController;
use MadeCurious\RecordPacker\Jobs\RecordExportJob;
use MadeCurious\RecordPacker\Jobs\RecordImportJob;
use MadeCurious\RecordPacker\Security\ImportExportPermissions;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;

/**
 * The default DataObject PackingPolicy - apply your own via config if needed
 */
class RecordPackingPolicy implements PackingPolicy
{
    public function permissionCode(): string
    {
        return ImportExportPermissions::RECORD_IMPORT_EXPORT;
    }

    public function exportJobClass(): string
    {
        return RecordExportJob::class;
    }

    public function importJobClass(): string
    {
        return RecordImportJob::class;
    }

    public function lockedWarningMessage(): string
    {
        return (string) _t(
            self::class . '.LOCKED_WARNING',
            'This record is currently being exported/imported by Record Packer.'
            . ' Please try again in a minute or so.'
        );
    }

    public function getExportModalForm(DataObject $owner): ?Form
    {
        $form = RecordPackerController::singleton()->ExportModalForm();
        $form->Fields()->dataFieldByName('RecordClassName')->setValue(get_class($owner));
        $form->Fields()->dataFieldByName('RecordID')->setValue($owner->ID);
        $form->Fields()->dataFieldByName('BackURL')->setValue(CurrentBackUrl::capture());

        return $form;
    }

    public function placeExportTrigger(FieldList $actions, LiteralField $trigger): void
    {
        $actions->insertBefore('action_doDelete', $trigger);
    }

    public function showsHistoryFieldInline(): bool
    {
        return true;
    }

    public function displayTitle(DataObject $record): ?string
    {
        return $record->hasField('Title') ? (string) $record->Title : null;
    }

    public function setDisplayTitle(DataObject $record, string $value): bool
    {
        if (!$record->hasField('Title')) {
            return false;
        }

        $record->Title = $value;

        return true;
    }
}
