<?php

namespace MadeCurious\RecordPacker\Tests;

use MadeCurious\RecordPacker\Model\ExportRequest;
use MadeCurious\RecordPacker\Security\ImportExportPermissions;
use MadeCurious\RecordPacker\Tests\Fixtures\TestCatalogue;
use MadeCurious\RecordPacker\Tests\Fixtures\TestProduct;
use MadeCurious\RecordPacker\Tests\Fixtures\TestVersionedRecord;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use Symbiote\QueuedJobs\Controllers\QueuedJobsAdmin;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;

/**
 * Covers the one shared history model — `Record` is a polymorphic has_one (see the class's own
 * doc comment), so this one model/table serves any packable DataObject, versioned or not.
 * TestVersionedRecord (Versioned applied, draft/live staging) exercises isStale()'s "read
 * through the LIVE stage" branch; TestCatalogue (deliberately unversioned) exercises the "no
 * stage at all" branch. The page-tree integration module has its own equivalent coverage for the
 * SITETREE_IMPORT_EXPORT-specific permission gating and the Elemental-nested-block staleness
 * case, both of which only make sense against a real page.
 */
class ExportRequestTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        TestCatalogue::class,
        TestProduct::class,
        TestVersionedRecord::class,
    ];

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();

        parent::tearDown();
    }

    public function testNeverPublishedRecordIsNeverStale(): void
    {
        $record = TestVersionedRecord::create(['Title' => 'Draft only']);
        $record->write();

        // Origin=Export normally always has a real SourceContentTimestamp, but even an
        // (unrealistic) Export-origin entry against a since-unpublished record must not be
        // treated as stale — there's no newer live content to be behind.
        $request = ExportRequest::create([
            'RecordID' => $record->ID,
            'RecordClass' => TestVersionedRecord::class,
            'Origin' => ExportRequest::ORIGIN_EXPORT,
            'SourceContentTimestamp' => '2020-01-01 00:00:00',
        ]);
        $request->write();

        $this->assertFalse($request->isStale());
    }

    public function testImportOriginEntryIsStaleOnceTheRecordIsPublished(): void
    {
        $record = TestVersionedRecord::create(['Title' => 'Imported record']);
        $record->write();

        $request = ExportRequest::create([
            'RecordID' => $record->ID,
            'RecordClass' => TestVersionedRecord::class,
            'Origin' => ExportRequest::ORIGIN_IMPORT,
            // Deliberately left at its default ('') — see ExportRequest::isStale()'s doc comment.
        ]);
        $request->write();

        $this->assertFalse($request->isStale(), 'Not stale before the record has ever been published.');

        $record->publishRecursive();

        $this->assertTrue($request->isStale(), 'Stale as soon as the record is published at all.');
    }

    public function testExportOriginEntryIsStaleOnlyAfterANewerPublish(): void
    {
        DBDatetime::set_mock_now('2024-01-01 12:00:00');

        $record = TestVersionedRecord::create(['Title' => 'Published record']);
        $record->write();
        $record->publishRecursive();

        $request = ExportRequest::create([
            'RecordID' => $record->ID,
            'RecordClass' => TestVersionedRecord::class,
            'Origin' => ExportRequest::ORIGIN_EXPORT,
            'SourceContentTimestamp' => $record->LastEdited,
        ]);
        $request->write();

        $this->assertFalse($request->isStale(), 'Not stale immediately after capturing the current live content.');

        DBDatetime::set_mock_now('2024-01-01 12:05:00');
        $record->Title = 'Published record, edited';
        $record->write();
        $record->publishRecursive();

        $this->assertTrue($request->isStale(), 'Stale after a newer publish of the record itself.');
    }

    public function testDeletePermissionIsGatedByTheRecordPermissionForAGenericDataObject(): void
    {
        $catalogue = TestCatalogue::create(['Title' => 'Owner of an export']);
        $catalogue->write();
        $request = ExportRequest::create([
            'RecordID' => $catalogue->ID,
            'RecordClass' => TestCatalogue::class,
            'Origin' => ExportRequest::ORIGIN_EXPORT,
        ]);
        $request->write();

        $this->logOut();
        $this->assertFalse(
            (bool) $request->canDelete(),
            'A visitor with no permission at all must not be able to delete an export.'
        );

        // Some OTHER, unrelated permission code must NOT be enough — this has to be gated on
        // RECORD_IMPORT_EXPORT specifically, not just "is logged in with some permission".
        $this->logInWithPermission('SOME_OTHER_PERMISSION');
        $this->assertFalse((bool) $request->canDelete());

        $this->logInWithPermission(ImportExportPermissions::RECORD_IMPORT_EXPORT);
        $this->assertTrue(
            (bool) $request->canDelete(),
            'A member with the module\'s permission must be able to delete an export — this is'
            . ' exactly what GridFieldDeleteAction checks before allowing the history'
            . ' GridField\'s per-row delete button to do anything.'
        );
    }

    public function testDeletingAnExportRequestRemovesItFromTheRecordsHistory(): void
    {
        $this->logInWithPermission(ImportExportPermissions::RECORD_IMPORT_EXPORT);

        $catalogue = TestCatalogue::create(['Title' => 'Owner of two exports']);
        $catalogue->write();

        $keep = ExportRequest::create([
            'RecordID' => $catalogue->ID,
            'RecordClass' => TestCatalogue::class,
            'Origin' => ExportRequest::ORIGIN_EXPORT,
        ]);
        $keep->write();
        $delete = ExportRequest::create([
            'RecordID' => $catalogue->ID,
            'RecordClass' => TestCatalogue::class,
            'Origin' => ExportRequest::ORIGIN_EXPORT,
        ]);
        $delete->write();

        $this->assertSame(2, $catalogue->ExportRequests()->count());

        // Mirrors exactly what GridFieldDeleteAction::handleAction() does server-side for the
        // 'deleterecord' action: check canDelete(), then delete() outright (not a mere
        // remove-from-relation) — see the has_many wiring on PackableExtension.
        $this->assertTrue((bool) $delete->canDelete());
        $delete->delete();

        $remaining = $catalogue->ExportRequests();
        $this->assertSame(1, $remaining->count());
        $this->assertSame($keep->ID, $remaining->first()->ID);
    }

    public function testDescriptionIsPersistedAndShownInSummary(): void
    {
        $catalogue = TestCatalogue::create(['Title' => 'Described export']);
        $catalogue->write();

        $request = ExportRequest::create([
            'RecordID' => $catalogue->ID,
            'RecordClass' => TestCatalogue::class,
            'Origin' => ExportRequest::ORIGIN_EXPORT,
            'Description' => 'Before the redesign',
        ]);
        $request->write();

        $reloaded = ExportRequest::get()->byID($request->ID);
        $this->assertSame('Before the redesign', $reloaded->Description);
    }

    public function testNeverTouchedGenericRecordIsNeverStaleForAnExportOriginEntry(): void
    {
        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $request = ExportRequest::create([
            'RecordID' => $catalogue->ID,
            'RecordClass' => TestCatalogue::class,
            'Origin' => ExportRequest::ORIGIN_EXPORT,
            'SourceContentTimestamp' => $catalogue->LastEdited,
        ]);
        $request->write();

        $this->assertFalse($request->isStale());
    }

    public function testImportOriginEntryIsStaleAsSoonAsAGenericRecordHasAnyContent(): void
    {
        DBDatetime::set_mock_now('2024-01-01 12:00:00');

        $catalogue = TestCatalogue::create(['Title' => 'Imported catalogue']);
        $catalogue->write();

        $request = ExportRequest::create([
            'RecordID' => $catalogue->ID,
            'RecordClass' => TestCatalogue::class,
            'Origin' => ExportRequest::ORIGIN_IMPORT,
        ]);
        $request->write();

        // TestCatalogue is deliberately unversioned — its "current" content is its live content
        // by definition, so the record already existing at all makes this stale immediately.
        $this->assertTrue($request->isStale());
    }

    public function testExportOriginEntryForAGenericRecordIsStaleOnlyAfterALaterEdit(): void
    {
        DBDatetime::set_mock_now('2024-01-01 12:00:00');

        $catalogue = TestCatalogue::create(['Title' => 'Original catalogue']);
        $catalogue->write();

        $request = ExportRequest::create([
            'RecordID' => $catalogue->ID,
            'RecordClass' => TestCatalogue::class,
            'Origin' => ExportRequest::ORIGIN_EXPORT,
            'SourceContentTimestamp' => $catalogue->LastEdited,
        ]);
        $request->write();

        $this->assertFalse($request->isStale(), 'Not stale immediately after capturing the current content.');

        DBDatetime::set_mock_now('2024-01-01 12:05:00');
        $catalogue->Title = 'Edited catalogue';
        $catalogue->write();

        $this->assertTrue($request->isStale(), 'Stale after a later edit to the record itself.');
    }

    /**
     * Mirrors the nested-Elemental-block case the page-tree integration module covers, against a
     * genuinely unrelated owned has_many (TestCatalogue -> TestProduct) with no page/versioning
     * semantics at all.
     */
    public function testStaleAfterEditingAnOwnedChildOfAGenericRecordEvenWhenTheParentIsUntouched(): void
    {
        DBDatetime::set_mock_now('2024-01-01 12:00:00');

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $product = TestProduct::create(['Title' => 'Widget']);
        $product->CatalogueID = $catalogue->ID;
        $product->write();

        $request = ExportRequest::create([
            'RecordID' => $catalogue->ID,
            'RecordClass' => TestCatalogue::class,
            'Origin' => ExportRequest::ORIGIN_EXPORT,
            'SourceContentTimestamp' => $product->LastEdited,
        ]);
        $request->write();

        $this->assertFalse($request->isStale(), 'Not stale immediately after capturing.');

        DBDatetime::set_mock_now('2024-01-01 12:05:00');
        $product->Title = 'Updated widget';
        $product->write();

        $this->assertTrue(
            $request->isStale(),
            'Stale after editing an owned child, even though the parent was not re-saved.'
        );
    }

    public function testStatusLinkHtmlIsPlainTextWithoutAJobDescriptor(): void
    {
        $this->logInWithPermission(QueuedJobsAdmin::getRequiredPermissions());

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $request = ExportRequest::create([
            'RecordID' => $catalogue->ID,
            'RecordClass' => TestCatalogue::class,
            'Origin' => ExportRequest::ORIGIN_EXPORT,
            'Status' => ExportRequest::STATUS_QUEUED,
        ]);
        $request->write();

        $this->assertSame('Queued', $request->getStatusLinkHtml());
    }

    public function testStatusLinkHtmlLinksThroughToTheJobsAdminWithPermission(): void
    {
        $this->logInWithPermission(QueuedJobsAdmin::getRequiredPermissions());

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $descriptor = QueuedJobDescriptor::create([
            'JobTitle' => 'Export TestCatalogue (#' . $catalogue->ID . ')',
            'Signature' => 'test-signature',
        ]);
        $descriptor->write();

        $request = ExportRequest::create([
            'RecordID' => $catalogue->ID,
            'RecordClass' => TestCatalogue::class,
            'Origin' => ExportRequest::ORIGIN_EXPORT,
            'Status' => ExportRequest::STATUS_QUEUED,
            'QueuedJobDescriptorID' => $descriptor->ID,
        ]);
        $request->write();

        $html = $request->getStatusLinkHtml();
        $expectedLink = QueuedJobsAdmin::singleton()->getCMSEditLinkForManagedDataObject($descriptor);

        $this->assertStringContainsString('<a href="' . htmlspecialchars($expectedLink) . '"', $html);
        $this->assertStringContainsString('>Queued<', $html);
    }

    public function testStatusLinkHtmlFallsBackToPlainTextWithoutPermission(): void
    {
        // Deliberately logged out rather than merely "not given QueuedJobsAdmin's permission" —
        // an editor who can see this history GridField (RECORD_IMPORT_EXPORT) doesn't
        // automatically also have access to admin/queuedjobs.
        $this->logOut();

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $descriptor = QueuedJobDescriptor::create([
            'JobTitle' => 'Export TestCatalogue (#' . $catalogue->ID . ')',
            'Signature' => 'test-signature-no-permission',
        ]);
        $descriptor->write();

        $request = ExportRequest::create([
            'RecordID' => $catalogue->ID,
            'RecordClass' => TestCatalogue::class,
            'Origin' => ExportRequest::ORIGIN_EXPORT,
            'Status' => ExportRequest::STATUS_QUEUED,
            'QueuedJobDescriptorID' => $descriptor->ID,
        ]);
        $request->write();

        $this->assertSame('Queued', $request->getStatusLinkHtml());
    }

    public function testStatusLinkHtmlFallsBackToPlainTextWhenTheDescriptorHasBeenPurged(): void
    {
        $this->logInWithPermission(QueuedJobsAdmin::getRequiredPermissions());

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $request = ExportRequest::create([
            'RecordID' => $catalogue->ID,
            'RecordClass' => TestCatalogue::class,
            'Origin' => ExportRequest::ORIGIN_EXPORT,
            'Status' => ExportRequest::STATUS_COMPLETE,
            // Points at a descriptor ID that doesn't (or no longer) exists.
            'QueuedJobDescriptorID' => 999999,
        ]);
        $request->write();

        $this->assertSame('Complete', $request->getStatusLinkHtml());
    }
}
