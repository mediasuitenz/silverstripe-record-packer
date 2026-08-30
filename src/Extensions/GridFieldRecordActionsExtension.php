<?php

namespace MadeCurious\RecordPacker\Extensions;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;

/**
 * Adds the packer "Export" button to a GridField-managed record's own edit form, for any
 * DataObject with {@see PackableExtension} applied.
 */
class GridFieldRecordActionsExtension extends Extension
{
    public function updateFormActions(FieldList $actions): void
    {
        $record = $this->owner->getRecord();

        if (!$record instanceof DataObject || !$record->exists() || !PackableExtension::appliesTo($record)) {
            return;
        }

        $record->extend('addExportTrigger', $actions);
    }
}
