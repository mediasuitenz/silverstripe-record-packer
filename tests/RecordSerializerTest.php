<?php

namespace MadeCurious\RecordPacker\Tests;

use MadeCurious\RecordPacker\Model\ExportRequest;
use MadeCurious\RecordPacker\Serialization\AssetBundler;
use MadeCurious\RecordPacker\Serialization\RecordSerializer;
use MadeCurious\RecordPacker\Tests\Fixtures\TestCatalogue;
use MadeCurious\RecordPacker\Tests\Fixtures\TestProduct;
use SilverStripe\Assets\Image;
use SilverStripe\Dev\SapphireTest;

/**
 * Round-trip tests for RecordSerializer against a plain, non-page DataObject, calling the
 * service directly and synchronously (bypassing the queue entirely) so these assert field/
 * relation parity independent of queued-jobs timing. GenericDataObjectRoundTripTest covers the
 * core has_many walk itself (and the basic scalar-field round trip); this file covers everything
 * else RecordSerializer does on top of that: the manifest's meta block, keeping its own export
 * history out of the walk, and shortcode-embedded asset rewriting. The page-tree integration
 * module has its own equivalent test covering the SiteTree/Elemental/Userforms-shaped edge cases
 * that only exist there (chosen-parent-on-import, polymorphic/lateral has_one references, and
 * so on).
 */
class RecordSerializerTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        TestCatalogue::class,
        TestProduct::class,
    ];

    private function export(TestCatalogue $catalogue, bool $includeAssets = true): array
    {
        $exporter = new RecordSerializer(new AssetBundler(), $includeAssets);

        return $exporter->export($catalogue);
    }

    private function importAsNew(array $manifest): TestCatalogue
    {
        $stub = TestCatalogue::create();
        $importer = new RecordSerializer(new AssetBundler());

        return $importer->import($stub, $manifest);
    }

    /**
     * The manifest's top-level `meta` block exists so a consumer can answer "what record is
     * this" without needing to know about rootLocalId/node structure at all — must always match
     * the same fields on the root node itself. urlSegment is null here: it's a SiteTree-only
     * concept (see the page-tree integration module's own equivalent test), not something this
     * otherwise-generic class should assume every DataObject has.
     */
    public function testManifestMetaBlockSummarisesTheRootRecord(): void
    {
        $catalogue = TestCatalogue::create(['Title' => 'A catalogue to export']);
        $catalogue->write();

        $manifest = $this->export($catalogue);

        $this->assertSame([
            'className' => TestCatalogue::class,
            'title' => 'A catalogue to export',
            'urlSegment' => null,
        ], $manifest['meta']);
    }

    /**
     * Regression test: PackableExtension declares a real has_many from the record to
     * ExportRequest (so its history GridField can use a genuine RelationList) — without an
     * explicit exclusion, the exporter would treat that as ordinary owned content and try to
     * walk into it, recursing into ExportRequest's own Member/ResultFile relations and failing
     * (reported live: exporting a record that already had export history threw a mismatch error
     * about a Member "outside the exported record").
     */
    public function testOwnExportHistoryIsNeverWalkedAsContent(): void
    {
        $catalogue = TestCatalogue::create(['Title' => 'Catalogue with prior exports']);
        $catalogue->write();

        $previousExport = ExportRequest::create([
            'RecordID' => $catalogue->ID,
            'RecordClass' => TestCatalogue::class,
            'Origin' => ExportRequest::ORIGIN_EXPORT,
            'Description' => 'An earlier export',
        ]);
        $previousExport->write();

        $manifest = $this->export($catalogue);

        foreach ($manifest['nodes'] as $node) {
            $this->assertNotSame(ExportRequest::class, $node['className']);
        }

        // The real point: this must not throw under the default fail-fast mismatch behaviour.
        $imported = $this->importAsNew($manifest);
        $this->assertSame('Catalogue with prior exports', $imported->Title);
    }

    /**
     * A TinyMCE-inserted image isn't a relation at all — it's a `[image id="X" ...]` shortcode
     * embedded directly in an HTML field's raw string, invisible to every relation-based
     * mechanism the exporter otherwise relies on. See ContentShortcodeScanner.
     *
     * The original image is deleted between export and import specifically so
     * AssetBundler::materializeAsset()'s dedupe-by-hash can't just find and reuse it (as it
     * correctly would if the same file already existed on the "target" — these tests import
     * into the very same database) — this is the case where it genuinely has to recreate the
     * file from the embedded bytes and the shortcode's id has to actually change.
     */
    public function testShortcodeEmbeddedImageIsExportedAndRewrittenOnImport(): void
    {
        $image = Image::create();
        $image->setFromString('not-really-a-jpeg', 'photo.jpg');
        $image->write();
        $originalImageID = $image->ID;

        $catalogue = TestCatalogue::create([
            'Title' => 'Catalogue with an inline image',
            'HTMLContent' => '<p>Before</p>[image id="' . $originalImageID . '" alt="A photo"]<p>After</p>',
        ]);
        $catalogue->write();

        // Go through a real zip write+read cycle rather than handing the importer the same
        // in-memory manifest/AssetBundler instance the exporter used — otherwise
        // materializeAsset() would have no embedded bytes to fall back on at all (its
        // openZipPath is only ever set by readZip()), the one thing this test needs to exercise.
        $exportAssetBundler = new AssetBundler();
        $exporterForZip = new RecordSerializer($exportAssetBundler, true);
        $manifest = $exporterForZip->export($catalogue);
        $zipFile = $exportAssetBundler->writeZip($manifest, 'shortcode-test.zip');

        $image->delete();

        $importAssetBundler = new AssetBundler();
        $manifestFromZip = $importAssetBundler->readZip($zipFile);

        $stub = TestCatalogue::create();
        $importer = new RecordSerializer($importAssetBundler);
        $imported = $importer->import($stub, $manifestFromZip);

        $this->assertStringNotContainsString(
            'id="' . $originalImageID . '"',
            $imported->HTMLContent,
            'The shortcode must be rewritten to the newly recreated image, not the original (now-deleted) ID.'
        );
        $this->assertMatchesRegularExpression('/\[image\b[^\]]*\bid="\d+"/', $imported->HTMLContent);
        $this->assertStringContainsString('<p>Before</p>', $imported->HTMLContent);
        $this->assertStringContainsString('<p>After</p>', $imported->HTMLContent);

        preg_match('/\[image\b[^\]]*\bid="(\d+)"/', $imported->HTMLContent, $match);
        $newImage = Image::get()->byID((int) $match[1]);
        $this->assertNotNull($newImage);
        $this->assertSame('not-really-a-jpeg', $newImage->getString());
    }
}
