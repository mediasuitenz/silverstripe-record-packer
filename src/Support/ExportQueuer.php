<?php

namespace MadeCurious\RecordPacker\Support;

use MadeCurious\RecordPacker\Model\ExportRequest;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Security;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Creates the ExportRequest history row and queues the export job for it
 */
class ExportQueuer
{
    public static function queue(
        DataObject $record,
        string $jobClass,
        bool $includeAssets = true,
        string $description = ''
    ): ExportRequest {
        $exportRequest = ExportRequest::create();
        $exportRequest->RecordID = $record->ID;
        $exportRequest->RecordClass = get_class($record);
        $exportRequest->MemberID = Security::getCurrentUser() ? Security::getCurrentUser()->ID : null;
        $exportRequest->Status = ExportRequest::STATUS_QUEUED;
        $exportRequest->Origin = ExportRequest::ORIGIN_EXPORT;
        $exportRequest->Description = $description;
        $exportRequest->IncludeAssets = $includeAssets;
        $exportRequest->write();

        $job = new $jobClass($record, $includeAssets, $exportRequest->ID);
        $exportRequest->QueuedJobDescriptorID = QueuedJobService::singleton()->queueJob($job);
        $exportRequest->write();

        return $exportRequest;
    }
}
