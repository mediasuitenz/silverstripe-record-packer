<?php

namespace MadeCurious\RecordPacker\Extensions;

use MadeCurious\RecordPacker\Support\PackingPolicy;
use SilverStripe\Control\Director;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Locks a DataObject (one with {@see PackableExtension} applied) while an
 * export or import job for it is in flight.
 */
class RecordLockExtension extends Extension
{
    private PackingPolicy $policy;

    public function __construct(?PackingPolicy $policy = null)
    {
        parent::__construct();

        $this->policy = $policy ?? Injector::inst()->get(PackingPolicy::class);
    }

    public function canEdit($member = null)
    {
        if (!Director::is_cli() && $this->pendingJobExists()) {
            return false;
        }

        return null;
    }

    public function canPublish($member = null)
    {
        if (!Director::is_cli() && $this->pendingJobExists()) {
            return false;
        }

        return null;
    }

    public function updateCMSFields(FieldList $fields): void
    {
        if (!$this->pendingJobExists()) {
            return;
        }

        $warning = LiteralField::create(
            'RecordPackerLockedWarning',
            '<div class="alert alert-warning">' . nl2br($this->policy->lockedWarningMessage()) . '</div>'
        );

        if ($fields->hasTabSet()) {
            $fields->addFieldToTab('Root.Main', $warning);
        } else {
            $fields->unshift($warning);
        }
    }

    /**
     * @param string[] $jobClasses Defaults to both of the policy's job classes
     */
    public function pendingJobExists(?array $jobClasses = null): bool
    {
        $jobClasses ??= [$this->policy->exportJobClass(), $this->policy->importJobClass()];

        if (!$this->owner->exists()) {
            return false;
        }

        $exportJobClass = $this->policy->exportJobClass();
        $importJobClass = $this->policy->importJobClass();

        if (in_array($exportJobClass, $jobClasses, true) && $this->pendingJobMatches(
            [$exportJobClass],
            $exportJobClass::signatureForRecord($this->owner)
        )) {
            return true;
        }

        if (in_array($importJobClass, $jobClasses, true) && $this->pendingJobMatches(
            [$importJobClass],
            $importJobClass::signatureForRecordId((int) $this->owner->ID)
        )) {
            return true;
        }

        return false;
    }

    private function pendingJobMatches(array $jobClasses, string $signature): bool
    {
        return QueuedJobDescriptor::get()->filter([
            'Implementation' => $jobClasses,
            'Signature' => $signature,
            'JobStatus' => [
                QueuedJob::STATUS_NEW,
                QueuedJob::STATUS_INIT,
                QueuedJob::STATUS_RUN,
                QueuedJob::STATUS_WAIT,
            ],
        ])->exists();
    }
}
