<?php

namespace MadeCurious\RecordPacker\Model;

use MadeCurious\RecordPacker\Extensions\PackableExtension;
use MadeCurious\RecordPacker\Serialization\ContentTimestampWalker;
use SilverStripe\Assets\File;
use SilverStripe\Control\Controller;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\Controllers\QueuedJobsAdmin;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;

/**
 * Tracks one export bundle for a record
 */
class ExportRequest extends DataObject
{
    private static $table_name = 'RecordPacker_ExportRequest';

    public const STATUS_QUEUED = 'Queued';
    public const STATUS_COMPLETE = 'Complete';
    public const STATUS_FAILED = 'Failed';

    public const ORIGIN_EXPORT = 'Export';
    public const ORIGIN_IMPORT = 'Import';

    private static $db = [
        'Status' => "Enum('Queued,Complete,Failed','Queued')",
        'Origin' => "Enum('Export,Import','Export')",
        'SourceContentTimestamp' => 'Varchar(32)',
        'StatusMessage' => 'Text',
        'Description' => 'Varchar(255)',
        'IncludeAssets' => 'Boolean',
    ];

    private static $has_one = [
        'Record' => DataObject::class,
        'Member' => Member::class,
        'ResultFile' => File::class,
        'QueuedJobDescriptor' => QueuedJobDescriptor::class,
    ];

    private static $owns = [
        'ResultFile',
    ];

    private static $default_sort = 'Created DESC';

    /**
     * RecordClass values (or ancestors thereof) for which the staleness walk is skipped entirely,
     * so a fresh {@see ContentTimestampWalker} is never invoked against dense trees. The record's
     * own LastEdited is used instead, ignoring anything it owns
     *
     * @var string[]
     */
    private static $skip_staleness_walk_for_classes = [];

    private static $summary_fields = [
        'Created' => 'Date',
        'Description' => 'Description',
        'Origin' => 'Origin',
        'StatusLinkHtml' => 'Status',
        'Member.Title' => 'Requested by',
        'IncludeAssets' => 'Assets included',
        'StaleBadge' => 'Stale',
        'DownloadLinkHtml' => 'File',
    ];

    /**
     * Cast so history GridField renders them unescaped
     */
    private static $casting = [
        'StaleBadge' => 'HTMLFragment',
        'DownloadLinkHtml' => 'HTMLFragment',
        'StatusLinkHtml' => 'HTMLFragment',
    ];

    public function canView($member = null)
    {
        return Permission::checkMember($member, $this->permissionCode());
    }

    public function canCreate($member = null, $context = [])
    {
        return $this->canView($member);
    }

    public function canEdit($member = null)
    {
        return $this->canView($member);
    }

    public function canDelete($member = null)
    {
        return $this->canView($member);
    }

    private function permissionCode(): string
    {
        return PackableExtension::policyFor((string) $this->RecordClass)->permissionCode();
    }

    /**
     * Compares SourceContentTimestamp against a fresh walk of the record's current content.
     */
    public function isStale(): bool
    {
        return $this->isStaleForTimestamp($this->latestRecordTimestamp());
    }

    public function isStaleForTimestamp(?string $currentTimestamp): bool
    {
        if (!$this->RecordID || !$this->RecordClass) {
            return false;
        }

        if ($currentTimestamp === null) {
            // Never published/created (or since removed)
            return false;
        }

        if ($this->SourceContentTimestamp === '' || $this->SourceContentTimestamp === null) {
            // Origin=Import: anything existing now means a publish/edit has happened since
            return true;
        }

        return $currentTimestamp > $this->SourceContentTimestamp;
    }

    /**
     * The most recent LastEdited found across the record and everything it owns, or — for a
     * class listed in {@see $skip_staleness_walk_for_classes} — just the record's own LastEdited
     */
    public function latestRecordTimestamp(): ?string
    {
        $class = $this->RecordClass;

        if (!$class || !class_exists($class) || !is_a($class, DataObject::class, true)) {
            return null;
        }

        $skipWalk = $this->skipsStalenessWalk($class);
        $recordID = (int) $this->RecordID;
        $walk = function () use ($class, $recordID, $skipWalk): ?string {
            $record = $class::get()->byID($recordID);

            if (!$record) {
                return null;
            }

            return $skipWalk ? $record->LastEdited : ContentTimestampWalker::create()->latestTimestamp($record);
        };

        if (!DataObject::singleton($class)->hasExtension(Versioned::class)) {
            return $walk();
        }

        return Versioned::withVersionedMode(function () use ($walk) {
            Versioned::set_stage(Versioned::LIVE);

            return $walk();
        });
    }

    /**
     * Whether $class (or an ancestor of it) is configured to skip the staleness walk
     */
    private function skipsStalenessWalk(string $class): bool
    {
        foreach ((array) $this->config()->get('skip_staleness_walk_for_classes') as $skipped) {
            if (class_exists($skipped) && is_a($class, $skipped, true)) {
                return true;
            }
        }

        return false;
    }

    public function getDownloadLink(): ?string
    {
        if ($this->Status !== self::STATUS_COMPLETE || !$this->ResultFileID) {
            return null;
        }

        // Guard explicitly against calling via CLI or tests or whatever
        if (!Controller::curr()) {
            return null;
        }

        $file = $this->ResultFile();

        return $file && $file->exists() ? $file->getAbsoluteURL() : null;
    }

    public function getStaleBadge(): string
    {
        return $this->staleBadgeForTimestamp($this->latestRecordTimestamp());
    }

    public function staleBadgeForTimestamp(?string $currentTimestamp): string
    {
        return $this->isStaleForTimestamp($currentTimestamp)
            ? '<span class="badge badge-warning">' . _t(self::class . '.STALE', 'Stale') . '</span>'
            : '<span class="badge badge-success">' . _t(self::class . '.FRESH', 'Fresh') . '</span>';
    }

    /**
     * The Status column's value, linked through to the relevant QueuedJob
     */
    public function getStatusLinkHtml(): string
    {
        $status = htmlspecialchars((string) $this->Status);

        if (!$this->QueuedJobDescriptorID) {
            return $status;
        }

        if (!Permission::check(QueuedJobsAdmin::getRequiredPermissions())) {
            return $status;
        }

        $descriptor = $this->QueuedJobDescriptor();

        if (!$descriptor || !$descriptor->exists()) {
            return $status;
        }

        $link = $this->queuedJobEditLink($descriptor);

        return '<a href="' . htmlspecialchars($link) . '">' . $status . '</a>';
    }

    /**
     * Built manually because it resolves wrongly otherwise
     */
    private function queuedJobEditLink(QueuedJobDescriptor $descriptor): string
    {
        $admin = QueuedJobsAdmin::singleton();

        return Controller::join_links(
            $admin->getLinkForModelClass(QueuedJobDescriptor::class),
            'EditForm/field/QueuedJobDescriptor/item',
            (string) $descriptor->ID,
            'edit'
        );
    }

    public function getDownloadLinkHtml(): string
    {
        $link = $this->getDownloadLink();

        if (!$link) {
            return '';
        }

        $label = _t(self::class . '.DOWNLOAD', 'Download');
        $size = $this->getFormattedFileSize();

        if ($size !== null) {
            $label .= ' (' . $size . ')';
        }

        return '<a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($label) . '</a>';
    }

    private function getFormattedFileSize(): ?string
    {
        $file = $this->ResultFileID ? $this->ResultFile() : null;

        if (!$file || !$file->exists()) {
            return null;
        }

        return $file->getSize() ?: null;
    }
}
