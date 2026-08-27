<?php

namespace MadeCurious\RecordPacker\Tests\Fixtures;

use MadeCurious\RecordPacker\Extensions\PackableExtension;
use MadeCurious\RecordPacker\Extensions\RecordLockExtension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A deliberately plain, unversioned, non-SiteTree DataObject — stands in for a real project
 * model (e.g. this module's own README example, a "Catalogue" edited via an ordinary GridField)
 * to prove PackableExtension/RecordLockExtension/RecordExportJob/RecordImportJob work on
 * something that is emphatically not a page.
 */
class TestCatalogue extends DataObject implements TestOnly
{
    private static $table_name = 'RecordPacker_Test_Catalogue';

    private static $db = [
        'Title' => 'Varchar(255)',
        'Description' => 'Text',
        // HTMLText specifically (not just Text, like Description above) so tests can exercise
        // ContentShortcodeScanner's embedded-asset rewriting against a genuinely non-page
        // DataObject — see RecordSerializerTest::testShortcodeEmbeddedImageIsExportedAndRewrittenOnImport().
        'HTMLContent' => 'HTMLText',
    ];

    private static $has_many = [
        'Products' => TestProduct::class,
    ];

    private static $extensions = [
        RecordLockExtension::class,
        PackableExtension::class,
    ];

    /**
     * Deliberately open so tests can isolate PackableExtension's own RECORD_IMPORT_EXPORT gate
     * (and RecordLockExtension's canEdit()/canPublish() veto) from DataObject's own default
     * canView()/canEdit(), which otherwise requires ADMIN — and ADMIN implies every other
     * permission by default, which would make a "no permission" test pass for the wrong reason.
     */
    public function canView($member = null)
    {
        return true;
    }
}
