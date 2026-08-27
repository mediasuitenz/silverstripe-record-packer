<?php

namespace MadeCurious\RecordPacker\Security;

use SilverStripe\Security\PermissionProvider;

/**
 * Gates PackableExtension/RecordPackerController for a plain project DataObject —
 * madecurious/silverstripe-page-packer registers its own SiteTreeImportExportPermissions
 * alongside this one, so a group can be granted export/import on generic records without also
 * being granted it on pages (or vice versa).
 */
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
