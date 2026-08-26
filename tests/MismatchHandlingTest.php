<?php

namespace MadeCurious\RecordPacker\Tests;

use MadeCurious\RecordPacker\Serialization\AssetBundler;
use MadeCurious\RecordPacker\Serialization\RecordSerializer;
use MadeCurious\RecordPacker\Tests\Fixtures\TestCatalogue;
use RuntimeException;
use SilverStripe\Dev\SapphireTest;

/**
 * Covers what happens importing a file whose manifest references something this site doesn't
 * have — a class that doesn't exist here, or a field that's been removed/renamed since the file
 * was exported. Both are really the same two mechanisms (RecordSerializer::createNode() and
 * ::applyScalarFields(), both funnelled through flagMismatch()) that also cover "exported from a
 * different version of this site/module" — there's no separate "check the module version" step;
 * drift between versions only ever shows up as one of these two structural mismatches. The one
 * exception is an unresolvable ROOT class specifically, which RecordImportJob treats as fatal
 * unconditionally — see RecordImportJobTest for that.
 *
 * Deliberately hand-builds manifests rather than round-tripping a real export: nodes are only
 * ever discovered by RecordSerializer::import() by appearing in the manifest's own `nodes` array
 * (see its pass-1 loop, which iterates every node unconditionally) — no real has_many/many_many
 * relation needs to actually reference a node to exercise the "unknown class" path, so a minimal,
 * explicit manifest is both sufficient and clearer than manufacturing a real relation just to
 * trigger it.
 */
class MismatchHandlingTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        TestCatalogue::class,
    ];

    private function importer(string $mismatchBehaviour): RecordSerializer
    {
        RecordSerializer::config()->set('mismatch_behaviour', $mismatchBehaviour);

        return new RecordSerializer(new AssetBundler(), true);
    }

    private function stub(): TestCatalogue
    {
        $stub = TestCatalogue::create();
        $stub->write();

        return $stub;
    }

    public function testUnknownChildNodeClassUnderFailModeThrows(): void
    {
        $manifest = [
            'format' => 1,
            'rootLocalId' => '0',
            'nodes' => [
                '0' => [
                    'className' => TestCatalogue::class,
                    'fields' => ['Title' => 'A catalogue'],
                    'hasOne' => [],
                    'assetHasOne' => [],
                    'manyMany' => [],
                    'shortcodeAssets' => [],
                ],
                '1' => [
                    // Plausible stand-in for "a block type only some sites have installed" or
                    // "a class that existed in an older version of this module/site" — either
                    // way, RecordSerializer has no way to distinguish those causes, and doesn't
                    // need to.
                    'className' => 'DNADesign\\Elemental\\Models\\ElementNoLongerInstalled',
                    'fields' => [],
                    'hasOne' => [],
                    'assetHasOne' => [],
                    'manyMany' => [],
                    'shortcodeAssets' => [],
                ],
            ],
            'assets' => [],
            'warnings' => [],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist on this site');

        $this->importer(RecordSerializer::MISMATCH_FAIL)->import($this->stub(), $manifest);
    }

    public function testUnknownChildNodeClassUnderBestEffortModeSkipsAndWarns(): void
    {
        $manifest = [
            'format' => 1,
            'rootLocalId' => '0',
            'nodes' => [
                '0' => [
                    'className' => TestCatalogue::class,
                    'fields' => ['Title' => 'A catalogue'],
                    'hasOne' => [],
                    'assetHasOne' => [],
                    'manyMany' => [],
                    'shortcodeAssets' => [],
                ],
                '1' => [
                    'className' => 'DNADesign\\Elemental\\Models\\ElementNoLongerInstalled',
                    'fields' => [],
                    'hasOne' => [],
                    'assetHasOne' => [],
                    'manyMany' => [],
                    'shortcodeAssets' => [],
                ],
            ],
            'assets' => [],
            'warnings' => [],
        ];

        $importer = $this->importer(RecordSerializer::MISMATCH_BEST_EFFORT);
        $imported = $importer->import($this->stub(), $manifest);

        // The root record itself still imports successfully — only the unresolvable child was
        // dropped, not the whole file.
        $this->assertSame('A catalogue', $imported->Title);
        $this->assertStringContainsString(
            'ElementNoLongerInstalled" does not exist on this site',
            implode(' ', $importer->warnings())
        );
    }

    public function testUnknownFieldUnderFailModeThrows(): void
    {
        $manifest = [
            'format' => 1,
            'rootLocalId' => '0',
            'nodes' => [
                '0' => [
                    'className' => TestCatalogue::class,
                    'fields' => [
                        'Title' => 'A catalogue',
                        // Stands in for a field removed/renamed since this file was exported —
                        // e.g. by a newer/older version of this module, or a genuinely different
                        // installed version of the record's own class.
                        'SomeFieldRemovedInANewerVersion' => 'a value',
                    ],
                    'hasOne' => [],
                    'assetHasOne' => [],
                    'manyMany' => [],
                    'shortcodeAssets' => [],
                ],
            ],
            'assets' => [],
            'warnings' => [],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no longer exists on this site');

        $this->importer(RecordSerializer::MISMATCH_FAIL)->import($this->stub(), $manifest);
    }

    public function testUnknownFieldUnderBestEffortModeSkipsAndWarns(): void
    {
        $manifest = [
            'format' => 1,
            'rootLocalId' => '0',
            'nodes' => [
                '0' => [
                    'className' => TestCatalogue::class,
                    'fields' => [
                        'Title' => 'A catalogue',
                        'SomeFieldRemovedInANewerVersion' => 'a value',
                    ],
                    'hasOne' => [],
                    'assetHasOne' => [],
                    'manyMany' => [],
                    'shortcodeAssets' => [],
                ],
            ],
            'assets' => [],
            'warnings' => [],
        ];

        $importer = $this->importer(RecordSerializer::MISMATCH_BEST_EFFORT);
        $imported = $importer->import($this->stub(), $manifest);

        // Every OTHER field on the same node still applies — one unknown field doesn't sink the
        // rest of the record's own content.
        $this->assertSame('A catalogue', $imported->Title);
        $this->assertStringContainsString(
            'SomeFieldRemovedInANewerVersion" no longer exists on this site',
            implode(' ', $importer->warnings())
        );
    }
}
