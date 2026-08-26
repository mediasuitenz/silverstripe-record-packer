<?php

namespace MadeCurious\RecordPacker\Tests\Fixtures;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * A plain, non-page DataObject with the Versioned extension applied (draft/live staging,
 * STAGEDVERSIONED being Versioned::class's own default mode) — used to prove
 * ExportRequest::isStale()'s "read through the LIVE stage for a versioned record" branch works
 * for any Versioned DataObject, not just SiteTree specifically (see the page-tree integration
 * module's own equivalent test, which covers the same branch against a real page).
 */
class TestVersionedRecord extends DataObject implements TestOnly
{
    private static $table_name = 'PagePacker_Test_VersionedRecord';

    private static $db = [
        'Title' => 'Varchar(255)',
    ];

    private static $extensions = [
        Versioned::class,
    ];
}
