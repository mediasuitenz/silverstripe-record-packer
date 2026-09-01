<?php

namespace MadeCurious\RecordPacker\Tests;

use MadeCurious\RecordPacker\Controllers\RecordPackerController;
use MadeCurious\RecordPacker\Forms\GridField\GridFieldRecordExportAction;
use MadeCurious\RecordPacker\Jobs\RecordExportJob;
use MadeCurious\RecordPacker\Security\ImportExportPermissions;
use MadeCurious\RecordPacker\Tests\Fixtures\TestCatalogue;
use MadeCurious\RecordPacker\Tests\Fixtures\TestProduct;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridField;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Covers the optional, opt-in GridField row action — a real entry in the "..." action menu
 * (alongside Edit/Archive) that links into the record's own edit view and fires its Export modal
 * there (see PackableExtension::addExportTrigger()) via the `#recordpacker-export` marker read by
 * client/dist/js/export-modal.js. That queuing behaviour itself is covered by
 * RecordPackerControllerTest.
 */
class GridFieldRecordExportActionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        TestCatalogue::class,
        TestProduct::class,
    ];

    private function gridFieldFor(string $modelClass): GridField
    {
        $gridField = GridField::create('Records', 'Records', $modelClass::get());
        // getUrl()/getColumnContent() call $gridField->Link() — a bare GridField (as used
        // everywhere else in this test file) has no hosting Form by default, and FormField::Link()
        // requires one.
        Form::create(RecordPackerController::create(), 'TestForm', FieldList::create($gridField), FieldList::create());

        return $gridField;
    }

    public function testColumnContentRendersForAPackableRecordWithPermission(): void
    {
        $this->logInWithPermission(ImportExportPermissions::RECORD_IMPORT_EXPORT);

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $gridField = $this->gridFieldFor(TestCatalogue::class);
        $component = new GridFieldRecordExportAction();
        $content = $component->getColumnContent($gridField, $catalogue, 'Actions');

        $this->assertNotNull($content);
        $this->assertStringContainsString('href="' . $component->getUrl($gridField, $catalogue, 'Actions') . '"', $content);

        // `action-menu--handled` is deliberate here — unlike a data-modal trigger, this is a
        // plain link, so GridField_ActionMenu's JS can safely fold it into the "..." dropdown via
        // the matching getGroup()/getExtraData() schema entry below.
        $this->assertStringContainsString('action-menu--handled', $content);
    }

    public function testGetUrlPointsAtTheRecordsOwnEditViewWithTheAutoFireMarker(): void
    {
        $this->logInWithPermission(ImportExportPermissions::RECORD_IMPORT_EXPORT);

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $gridField = $this->gridFieldFor(TestCatalogue::class);
        $component = new GridFieldRecordExportAction();
        $url = $component->getUrl($gridField, $catalogue, 'Actions');

        $this->assertStringContainsString('item/' . $catalogue->ID . '/edit', $url);
        $this->assertStringEndsWith('#recordpacker-export', $url);
    }

    /**
     * getGroup()/getExtraData() participate in GridField_ActionMenu's JSON schema so this action
     * shows up in the "..." dropdown like Edit/Archive do. A prior attempt tried to put a
     * data-modal trigger there instead, which needed a non-null group but had no real extra data
     * to give it — that crashed the CMS's GridFieldActions React component (reading
     * `data.classNames` off a null `data`) for the *whole row*, not just this action. A plain
     * `link`-type entry (the same kind GridFieldEditButton uses) doesn't have that problem.
     */
    public function testGetGroupAndExtraDataParticipateInTheActionMenuSchema(): void
    {
        $this->logInWithPermission(ImportExportPermissions::RECORD_IMPORT_EXPORT);

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $component = new GridFieldRecordExportAction();
        $gridField = $this->gridFieldFor(TestCatalogue::class);

        $this->assertSame('Export', $component->getTitle($gridField, $catalogue, 'Actions'));
        $this->assertNotNull($component->getGroup($gridField, $catalogue, 'Actions'));
        $this->assertIsArray($component->getExtraData($gridField, $catalogue, 'Actions'));
    }

    public function testGetGroupIsNullForANonPackableRecord(): void
    {
        $this->logInWithPermission(ImportExportPermissions::RECORD_IMPORT_EXPORT);

        $product = TestProduct::create(['Title' => 'A widget']);
        $product->write();

        $component = new GridFieldRecordExportAction();
        $gridField = $this->gridFieldFor(TestProduct::class);

        $this->assertNull($component->getGroup($gridField, $product, 'Actions'));
    }

    public function testColumnContentIsAbsentForANonPackableRecord(): void
    {
        $this->logInWithPermission(ImportExportPermissions::RECORD_IMPORT_EXPORT);

        $product = TestProduct::create(['Title' => 'A widget']);
        $product->write();

        $component = new GridFieldRecordExportAction();
        $content = $component->getColumnContent($this->gridFieldFor(TestProduct::class), $product, 'Actions');

        $this->assertNull($content);
    }

    public function testColumnContentIsAbsentWithoutPermission(): void
    {
        $this->logOut();

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $component = new GridFieldRecordExportAction();
        $content = $component->getColumnContent($this->gridFieldFor(TestCatalogue::class), $catalogue, 'Actions');

        $this->assertNull($content);
    }

    public function testColumnContentIsAbsentWhileAnExportIsInFlight(): void
    {
        $this->logInWithPermission(ImportExportPermissions::RECORD_IMPORT_EXPORT);

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        QueuedJobDescriptor::create([
            'Implementation' => RecordExportJob::class,
            'Signature' => RecordExportJob::signatureForRecord($catalogue),
            'JobStatus' => QueuedJob::STATUS_RUN,
        ])->write();

        $component = new GridFieldRecordExportAction();
        $content = $component->getColumnContent($this->gridFieldFor(TestCatalogue::class), $catalogue, 'Actions');

        $this->assertNull($content);
    }
}
