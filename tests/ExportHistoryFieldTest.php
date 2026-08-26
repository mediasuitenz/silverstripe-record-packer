<?php

namespace MadeCurious\RecordPacker\Tests;

use MadeCurious\RecordPacker\Model\ExportRequest;
use MadeCurious\RecordPacker\Support\ExportHistoryField;
use MadeCurious\RecordPacker\Tests\Fixtures\TestCatalogue;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use Symbiote\QueuedJobs\Controllers\QueuedJobsAdmin;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;

/**
 * Covers the History GridField's column formatting specifically. StaleBadge/StatusLinkHtml/
 * DownloadLinkHtml are all cast HTMLFragment at the ExportRequest model level, but
 * GridFieldDataColumns doesn't consult model casting on its own — each must ALSO be listed in
 * setFieldFormatting() (see ExportHistoryField's own doc comment) or it renders HTML-escaped
 * (a literal "&lt;a href=...&gt;" string) despite the casting declaration. Regression guard for
 * exactly that gap: StatusLinkHtml was added to the model with the casting declaration alone,
 * which alone wasn't enough to make it render as an actual link in this GridField.
 */
class ExportHistoryFieldTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        TestCatalogue::class,
    ];

    public function testStatusColumnRendersAsAClickableLinkNotEscapedMarkup(): void
    {
        $this->logInWithPermission(QueuedJobsAdmin::getRequiredPermissions());

        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $descriptor = QueuedJobDescriptor::create([
            'JobTitle' => 'Export TestCatalogue (#' . $catalogue->ID . ')',
            'Signature' => 'export-history-field-test',
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

        $gridField = ExportHistoryField::create($catalogue);
        $columns = $gridField->getConfig()->getComponentByType(GridFieldDataColumns::class);
        $content = (string) $columns->getColumnContent($gridField, $request, 'StatusLinkHtml');

        $this->assertStringContainsString(
            '<a href=',
            $content,
            'Must render as an actual clickable link, not markup escaped into literal "&lt;a href=" text.'
        );
        $this->assertStringNotContainsString('&lt;a', $content);
    }

    public function testStatusColumnFallsBackToPlainTextWithoutADescriptor(): void
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

        $gridField = ExportHistoryField::create($catalogue);
        $columns = $gridField->getConfig()->getComponentByType(GridFieldDataColumns::class);
        $content = (string) $columns->getColumnContent($gridField, $request, 'StatusLinkHtml');

        // No QueuedJobDescriptor on this row, so it should fall back to plain (escaped) status
        // text rather than a link — but still routed through the same formatter, not left
        // unformatted/double-escaped.
        $this->assertSame('Queued', $content);
    }

    public function testDownloadColumnStillRendersAsUnescapedHtml(): void
    {
        // Regression guard against breaking the sibling column this fix is modelled on — the two
        // must be formatted the same way (see ExportHistoryField's own doc comment).
        $catalogue = TestCatalogue::create(['Title' => 'A catalogue']);
        $catalogue->write();

        $request = ExportRequest::create([
            'RecordID' => $catalogue->ID,
            'RecordClass' => TestCatalogue::class,
            'Origin' => ExportRequest::ORIGIN_EXPORT,
            'Status' => ExportRequest::STATUS_QUEUED,
        ]);
        $request->write();

        $gridField = ExportHistoryField::create($catalogue);
        $columns = $gridField->getConfig()->getComponentByType(GridFieldDataColumns::class);
        $content = (string) $columns->getColumnContent($gridField, $request, 'DownloadLinkHtml');

        // No ResultFile/not Complete, so nothing to download — but this must still come back as
        // an empty string via the formatter, not an escaped/unformatted value.
        $this->assertSame('', $content);
    }
}
