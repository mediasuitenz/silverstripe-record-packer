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
 * export/import attempts — a plain list + delete
 */
class ExportHistoryField
{
    public static function create(DataObject $owner): GridField
    {
        $config = GridFieldConfig_Base::create();
        $config->addComponent(GridFieldDeleteAction::create());

        // Every row in this GridField shares the same owning record, so latestRecordTimestamp()'s
        // relation-graph walk only needs to run once per distinct RecordClass/RecordID
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
