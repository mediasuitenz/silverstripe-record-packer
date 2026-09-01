<?php

namespace MadeCurious\RecordPacker\Forms\GridField;

use MadeCurious\RecordPacker\Extensions\PackableExtension;
use MadeCurious\RecordPacker\Extensions\RecordLockExtension;
use MadeCurious\RecordPacker\Jobs\RecordExportJob;
use MadeCurious\RecordPacker\Security\ImportExportPermissions;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_ActionMenuItem;
use SilverStripe\Forms\GridField\GridField_ActionMenuLink;
use SilverStripe\Forms\GridField\GridField_ColumnProvider;
use SilverStripe\Security\Permission;
use SilverStripe\View\HTML;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * An optional, opt-in GridField per-row action
 * Links straight into the edit view and opens the export modal
 */
class GridFieldRecordExportAction implements GridField_ColumnProvider, GridField_ActionMenuLink
{
    /**
     * Set of RecordExportJob signatures currently in flight, lazily built once per render
     *
     * @var array<string, true>|null
     */
    private ?array $pendingExportSignatures = null;

    public function augmentColumns($gridField, &$columns)
    {
        if (!in_array('Actions', $columns, true)) {
            $columns[] = 'Actions';
        }
    }

    public function getColumnAttributes($gridField, $record, $columnName)
    {
        return ['class' => 'grid-field__col-compact'];
    }

    public function getColumnMetadata($gridField, $columnName)
    {
        return $columnName === 'Actions' ? ['title' => ''] : [];
    }

    public function getColumnsHandled($gridField)
    {
        return ['Actions'];
    }

    /**
     * Fallback link for when the "..." menu isn't there
     */
    public function getColumnContent($gridField, $record, $columnName)
    {
        if (!$this->canExport($gridField, $record)) {
            return null;
        }

        $title = (string) _t(self::class . '.EXPORT', 'Export');

        return HTML::createTag('a', [
            'href' => $this->getUrl($gridField, $record, $columnName),
            'class' => 'btn--icon-md btn--no-text grid-field__icon-action action-menu--handled font-icon-share',
            'title' => $title,
            'aria-label' => $title,
        ]);
    }

    public function getTitle($gridField, $record, $columnName)
    {
        return (string) _t(self::class . '.EXPORT', 'Export');
    }

    public function getGroup($gridField, $record, $columnName)
    {
        return $this->canExport($gridField, $record) ? GridField_ActionMenuItem::DEFAULT_GROUP : null;
    }

    public function getExtraData($gridField, $record, $columnName)
    {
        return ['classNames' => 'font-icon-share action-detail'];
    }

    public function getUrl($gridField, $record, $columnName)
    {
        $link = Controller::join_links($gridField->Link('item'), $record->ID, 'edit');

        return $gridField->addAllStateToUrl($link) . '#recordpacker-export';
    }

    private function canExport(GridField $gridField, $record): bool
    {
        if (!PackableExtension::appliesTo($record)) {
            return false;
        }

        if (!Permission::check(ImportExportPermissions::RECORD_IMPORT_EXPORT)) {
            return false;
        }

        if (!$record->hasMethod('canView') || !$record->canView()) {
            return false;
        }

        if ($record->hasExtension(RecordLockExtension::class) && $this->hasPendingExport($gridField, $record)) {
            return false;
        }

        return true;
    }

    /**
     * Whether $record already has a queued/running RecordExportJob
     */
    private function hasPendingExport(GridField $gridField, $record): bool
    {
        if ($this->pendingExportSignatures === null) {
            $this->pendingExportSignatures = $this->fetchPendingExportSignatures($gridField);
        }

        return isset($this->pendingExportSignatures[RecordExportJob::signatureForRecord($record)]);
    }

    /**
     * One query covering every row on the current page: map(ID, ClassName), then one
     * QueuedJobDescriptor lookup for all of those signatures at once.
     *
     * @return array<string, true>
     */
    private function fetchPendingExportSignatures(GridField $gridField): array
    {
        $classNamesByID = $gridField->getManipulatedList()->map('ID', 'ClassName');

        if (!count($classNamesByID)) {
            return [];
        }

        $signatures = [];

        foreach ($classNamesByID as $id => $className) {
            $signatures[] = RecordExportJob::signatureForIdAndClass((int) $id, $className);
        }

        $pendingSignatures = QueuedJobDescriptor::get()->filter([
            'Implementation' => RecordExportJob::class,
            'Signature' => $signatures,
            'JobStatus' => [
                QueuedJob::STATUS_NEW,
                QueuedJob::STATUS_INIT,
                QueuedJob::STATUS_RUN,
                QueuedJob::STATUS_WAIT,
            ],
        ])->column('Signature');

        return array_fill_keys($pendingSignatures, true);
    }
}
