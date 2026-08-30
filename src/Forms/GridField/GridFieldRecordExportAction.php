<?php

namespace MadeCurious\RecordPacker\Forms\GridField;

use MadeCurious\RecordPacker\Extensions\PackableExtension;
use MadeCurious\RecordPacker\Extensions\RecordLockExtension;
use MadeCurious\RecordPacker\Jobs\RecordExportJob;
use MadeCurious\RecordPacker\Security\ImportExportPermissions;
use MadeCurious\RecordPacker\Support\ExportQueuer;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_ActionProvider;
use SilverStripe\Forms\GridField\GridField_ColumnProvider;
use SilverStripe\Forms\GridField\GridField_FormAction;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Security\Permission;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * An optional, opt-in GridField per-row action
 */
class GridFieldRecordExportAction implements GridField_ColumnProvider, GridField_ActionProvider
{
    private const ACTION_NAME = 'recordpackerexport';

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

    public function getActions($gridField)
    {
        return [self::ACTION_NAME];
    }

    public function getColumnContent($gridField, $record, $columnName)
    {
        $field = $this->getExportAction($gridField, $record);

        return $field ? $field->Field() : null;
    }

    public function handleAction(GridField $gridField, $actionName, $arguments, $data)
    {
        if ($actionName !== self::ACTION_NAME) {
            return;
        }

        $record = $gridField->getList()->byID($arguments['RecordID']);

        if (!$record) {
            return;
        }

        if (!$this->canExport($gridField, $record)) {
            throw new ValidationException(
                _t(self::class . '.EXPORT_PERMISSION_FAILURE', 'No permission to export this record')
            );
        }

        ExportQueuer::queue($record, RecordExportJob::class, true);
    }

    private function getExportAction(GridField $gridField, $record): ?GridField_FormAction
    {
        if (!$this->canExport($gridField, $record)) {
            return null;
        }

        $title = _t(self::class . '.EXPORT', 'Export');

        return GridField_FormAction::create(
            $gridField,
            'RecordPackerExport' . $record->ID,
            false,
            self::ACTION_NAME,
            ['RecordID' => $record->ID]
        )
            ->addExtraClass('btn--icon-md font-icon-share btn--no-text grid-field__icon-action action-menu--handled')
            ->setAttribute('classNames', 'font-icon-share')
            ->setDescription($title)
            ->setAttribute('aria-label', $title);
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
