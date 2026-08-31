<?php

namespace MadeCurious\RecordPacker\Security;

use SilverStripe\Security\PermissionProvider;

class ImportExportPermissions implements PermissionProvider
{
    const RECORD_IMPORT_EXPORT = 'RECORD_IMPORT_EXPORT';

    public function providePermissions()
    {
        return [
            self::RECORD_IMPORT_EXPORT => [
                'name' => _t(
                    __CLASS__ . '.RECORD_PERMISSION_NAME',
                    'Export/import packable records'
                ),
                'category' => _t(
                    __CLASS__ . '.PERMISSION_CATEGORY',
                    'Content'
                ),
                'help' => _t(
                    __CLASS__ . '.RECORD_PERMISSION_HELP',
                    'Allow exporting a packable record (e.g. one edited via a GridField) to a'
                    . ' downloadable file, and importing such a file to create a new record.'
                ),
                'sort' => 101,
            ],
        ];
    }
}
