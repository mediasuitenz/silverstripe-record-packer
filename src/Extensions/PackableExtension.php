<?php

namespace MadeCurious\RecordPacker\Extensions;

use MadeCurious\RecordPacker\Model\ExportRequest;
use MadeCurious\RecordPacker\Support\ExportHistoryField;
use MadeCurious\RecordPacker\Support\ModalMarkup;
use MadeCurious\RecordPacker\Support\PackingPolicy;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use SilverStripe\View\Requirements;

/**
 * Apply this (plus {@see RecordLockExtension}) to a project DataObject to get an "Export"
 * button + export history.
 *
 * Two hosting contexts are supported:
 * - A record with its own LeftAndMain-style getCMSActions() via updateCMSActions() below.
 * - A record edited through an ordinary GridField gets it via {@see GridFieldRecordActionsExtension},
 *   which calls addExportTrigger() directly
 */
class PackableExtension extends Extension
{
    private static $has_many = [
        'ExportRequests' => ExportRequest::class,
    ];

    private PackingPolicy $policy;

    public function __construct(?PackingPolicy $policy = null)
    {
        parent::__construct();

        $this->policy = $policy ?? Injector::inst()->get(PackingPolicy::class);
    }

    /**
     *
     * @param DataObject|string $classOrRecord A record instance, or a class name
     */
    public static function appliesTo($classOrRecord): bool
    {
        if ($classOrRecord instanceof DataObject) {
            return $classOrRecord->hasExtension(self::class);
        }

        $class = (string) $classOrRecord;

        return $class !== ''
            && class_exists($class)
            && is_a($class, DataObject::class, true)
            && DataObject::singleton($class)->hasExtension(self::class);
    }

    /**
     * Resolves $classOrRecord's own PackingPolicy variant via its PackableExtension instance,
     * falling back to the default policy if the class/record no longer has PackableExtension
     * applied at all
     *
     * @param DataObject|string $classOrRecord
     */
    public static function policyFor($classOrRecord): PackingPolicy
    {
        $class = $classOrRecord instanceof DataObject ? get_class($classOrRecord) : (string) $classOrRecord;

        if ($class !== '' && class_exists($class) && is_a($class, DataObject::class, true)) {
            $extension = DataObject::singleton($class)->getExtensionInstance(self::class);

            if ($extension) {
                return $extension->policy();
            }
        }

        return Injector::inst()->get(PackingPolicy::class);
    }

    public function policy(): PackingPolicy
    {
        return $this->policy;
    }

    public function updateCMSFields(FieldList $fields): void
    {
        $fields->removeByName('ExportRequests');

        if (!$this->policy->showsHistoryFieldInline()) {
            return;
        }

        if (!$this->owner->exists() || !Permission::check($this->policy->permissionCode())) {
            return;
        }

        $historyField = ExportHistoryField::create($this->owner);

        if ($fields->hasTabSet()) {
            $fields->findOrMakeTab('Root.ExportHistory', _t(self::class . '.EXPORT_HISTORY_TAB', 'Export history'));
            $fields->addFieldToTab('Root.ExportHistory', $historyField);
        } else {
            $fields->push($historyField);
        }
    }

    public function updateCMSActions(FieldList $actions): void
    {
        $this->addExportTrigger($actions);
    }

    /**
     * Builds the "Export" trigger button and places it onto $actions
     */
    public function addExportTrigger(FieldList $actions): void
    {
        if (!Permission::check($this->policy->permissionCode())) {
            return;
        }

        if (!$this->owner->exists()) {
            return;
        }

        $locked = $this->owner->hasExtension(RecordLockExtension::class)
            && $this->owner->pendingJobExists([$this->policy->exportJobClass()]);

        if ($locked) {
            return;
        }

        $form = $this->policy->getExportModalForm($this->owner);

        if (!$form) {
            return;
        }

        Requirements::javascript('madecurious/silverstripe-record-packer: client/dist/js/export-modal.js');

        $modalId = 'PackerExportModal' . $this->owner->ID;
        $modalHtml = ModalMarkup::modal(
            $modalId,
            (string) _t(self::class . '.MODAL_TITLE', 'Export record'),
            $form->forTemplate()
        );
        $trigger = LiteralField::create(
            'PackerExportModalTrigger',
            ModalMarkup::trigger(
                $modalId,
                (string) _t(self::class . '.EXPORT_BUTTON', 'Export'),
                'font-icon-share',
                $modalHtml
            )
        );

        $this->policy->placeExportTrigger($actions, $trigger);
    }
}
