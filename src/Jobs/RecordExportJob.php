<?php

namespace MadeCurious\RecordPacker\Jobs;

use MadeCurious\RecordPacker\Extensions\PackableExtension;
use MadeCurious\RecordPacker\Model\ExportRequest;
use MadeCurious\RecordPacker\Serialization\AssetBundler;
use MadeCurious\RecordPacker\Serialization\ContentTimestampWalker;
use MadeCurious\RecordPacker\Serialization\RecordSerializer;
use RuntimeException;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\Parsers\URLSegmentFilter;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Throwable;

/**
 * Reads a single record's current content and produces a downloadable export zip.
 */
class RecordExportJob extends AbstractQueuedJob implements QueuedJob
{
    public function __construct(?DataObject $record = null, bool $includeAssets = true, ?int $exportRequestID = null)
    {
        if ($record) {
            $this->recordID = $record->ID;
            // Captured so getSignature() can embed it
            $this->recordClass = get_class($record);
            $this->includeAssets = $includeAssets;
            $this->exportRequestID = $exportRequestID;

            $member = Security::getCurrentUser();

            if ($member) {
                $this->memberID = $member->ID;
            }
        }
    }

    public function getJobType(): string
    {
        $this->totalSteps = 1;

        return QueuedJob::QUEUED;
    }

    public function getTitle(): string
    {
        return _t(self::class . '.TITLE', 'Export {class} (#{ID})', [
            'class' => $this->recordClass ? ClassInfo::shortName($this->recordClass) : 'record',
            'ID' => $this->recordID,
        ]);
    }

    /**
     * Must produce the exact value RecordLockExtension::pendingJobExists() queries for
     */
    public function getSignature(): string
    {
        return $this->recordClass !== null
            ? self::signatureForIdAndClass((int) $this->recordID, $this->recordClass)
            : self::signatureForRecordId((int) $this->recordID);
    }

    public static function signatureForRecord(DataObject $record): string
    {
        return static::signatureForIdAndClass((int) $record->ID, get_class($record));
    }

    public static function signatureForRecordId(int $id): string
    {
        // Class-agnostic fallback, used only when no ClassName is available at all
        return md5(sprintf('%s-%s', static::signaturePrefix(), $id));
    }

    public static function signatureForIdAndClass(int $id, string $className): string
    {
        return md5(sprintf('%s-%s-%s', static::signaturePrefix(), $id, $className));
    }

    /**
     * Overridden by SiteTreeExportJob so a page's signature stays namespaced separately from a
     * generic record's, even though both otherwise compute the same way.
     */
    protected static function signaturePrefix(): string
    {
        return 'record-export';
    }

    public function process(): void
    {
        $currentMember = Security::getCurrentUser();

        if ($this->memberID) {
            $member = Member::get()->byID($this->memberID);

            if ($member) {
                Security::setCurrentUser($member);
            }
        }

        $exportRequest = $this->exportRequestID ? ExportRequest::get()->byID($this->exportRequestID) : null;

        try {
            if (!$exportRequest) {
                throw new RuntimeException('No Export Request found for job.');
            }

            $class = $this->recordClass;

            if (!$class || !class_exists($class) || !is_a($class, DataObject::class, true)) {
                throw new RuntimeException("Record #{$this->recordID} has an unresolvable class \"{$class}\".");
            }

            $recordID = (int) $this->recordID;
            $includeAssets = (bool) $this->includeAssets;

            $export = function (DataObject $record) use ($includeAssets) {
                $assetBundler = Injector::inst()->create(AssetBundler::class);
                $serializer = RecordSerializer::create($assetBundler, $includeAssets);
                $manifest = $serializer->export($record);
                $file = $assetBundler->writeZip($manifest, $this->exportFilenameFor($record));
                $sourceContentTimestamp = ContentTimestampWalker::create()->latestTimestamp($record);

                return [$file, $sourceContentTimestamp];
            };

            $record = $class::get()->byID($recordID);

            if (!$record || !$record->exists()) {
                throw new RuntimeException("Record #{$recordID} no longer exists.");
            }

            // Only switch into LIVE mode if the record has been published at least once
            $isPublished = $record->hasExtension(Versioned::class) && $record->isPublished();

            if (!$isPublished) {
                [$file, $sourceContentTimestamp] = $export($record);
            } else {
                // Re-fetch under LIVE mode (withVersionedMode restores the prior reading mode as
                // soon as its callback returns) so every field genuinely reflects the published
                // row, not whatever mode the $record fetched above happened to be read under.
                [$file, $sourceContentTimestamp] = Versioned::withVersionedMode(
                    function () use ($class, $recordID, $export) {
                        Versioned::set_stage(Versioned::LIVE);

                        $liveRecord = $class::get()->byID($recordID);

                        if (!$liveRecord || !$liveRecord->exists()) {
                            throw new RuntimeException("Record #{$recordID} no longer exists.");
                        }

                        return $export($liveRecord);
                    }
                );
            }

            $exportRequest->Status = ExportRequest::STATUS_COMPLETE;
            $exportRequest->ResultFileID = $file->ID;
            $exportRequest->SourceContentTimestamp = (string) $sourceContentTimestamp;
            $exportRequest->write();

            $this->addMessage("Exported record #{$this->recordID} successfully.");
        } catch (Throwable $e) {
            if ($exportRequest) {
                $exportRequest->Status = ExportRequest::STATUS_FAILED;
                $exportRequest->StatusMessage = $e->getMessage();
                $exportRequest->write();
            }

            $this->addMessage('Export failed: ' . $e->getMessage(), 'ERROR');
            $this->isComplete = true;

            throw $e;
        } finally {
            Security::setCurrentUser($currentMember);
        }

        $this->isComplete = true;
    }

    private function exportFilenameFor(DataObject $record): string
    {
        $slug = $record->hasField('URLSegment') ? (string) $record->URLSegment : '';

        if ($slug === '') {
            $title = PackableExtension::policyFor($record)->displayTitle($record) ?? get_class($record);
            // Same filter SiteTree itself uses to derive URLSegment from Title
            $slug = (new URLSegmentFilter())->filter($title);
        }

        return ($slug !== '' ? $slug : 'record') . '-export.zip';
    }
}
