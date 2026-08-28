<?php

namespace MadeCurious\RecordPacker\Support;

use MadeCurious\RecordPacker\Model\ExportRequest;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_Base;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\ORM\DataObject;

/**
 * Builds the read-only "Export history" GridField shown for a packable record's past
 * export/import attempts — a plain list + delete (no add/edit, since a history row is never
 * hand-authored), with {@see ExportRequest}'s `StaleBadge`/`StatusLinkHtml`/`DownloadLinkHtml`
 * explicitly formatted so they render as HTML rather than being escaped as plain text.
 *
 * Shared by {@see \MadeCurious\RecordPacker\Controllers\CMSPageContentExportController} (the
 * SiteTree/CMSMain "Content Export" tab) and {@see \MadeCurious\RecordPacker\Extensions\PackableExtension}
 * (added inline to a generic packable DataObject's own edit form, in place of the raw
 * auto-scaffolded relation field it removes — see that extension's own doc comment for why a
 * generic record needs this at all when SiteTree gets it from a dedicated tab instead).
 */
final class ExportHistoryField
{
    public static function create(DataObject $owner): GridField
    {
        $config = GridFieldConfig_Base::create();
        $config->addComponent(GridFieldDeleteAction::create());

        // Every row in this GridField shares the same owning record, so latestRecordTimestamp()'s
        // relation-graph walk only needs to run once per distinct RecordClass/RecordID — not once
        // per row — even though this list can (via a shared table/polymorphic Record relation, in
        // principle) span more than one. Scoped to this closure's own lifetime rather than a
        // static cache, so it never outlives this one GridField render.
        $timestampCache = [];

        // using setFieldFormatting() to ensure we get rendered HTML
        $config->getComponentByType(GridFieldDataColumns::class)->setFieldFormatting([
            'StaleBadge' => function ($value, $item) use (&$timestampCache) {
                $key = $item->RecordClass . ':' . $item->RecordID;

                if (!array_key_exists($key, $timestampCache)) {
                    $timestampCache[$key] = $item->latestRecordTimestamp();
                }

                return $item->staleBadgeForTimestamp($timestampCache[$key]);
            },
            'StatusLinkHtml' => fn ($value, $item) => $item->StatusLinkHtml,
            'DownloadLinkHtml' => fn ($value, $item) => $item->DownloadLinkHtml,
            'IncludeAssets' => fn ($value, $item) => $item->IncludeAssets ? 'Yes' : 'No',
        ]);

        return GridField::create(
            'ExportRequests',
            _t(self::class . '.HISTORY_TITLE', 'Export history'),
            $owner->ExportRequests(),
            $config
        );
    }
}
