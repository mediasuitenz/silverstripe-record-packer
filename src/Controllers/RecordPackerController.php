<?php

namespace MadeCurious\RecordPacker\Controllers;

use MadeCurious\RecordPacker\Extensions\PackableExtension;
use MadeCurious\RecordPacker\Jobs\RecordExportJob;
use MadeCurious\RecordPacker\Jobs\RecordImportJob;
use MadeCurious\RecordPacker\Security\ImportExportPermissions;
use MadeCurious\RecordPacker\Support\ExportQueuer;
use MadeCurious\RecordPacker\Serialization\AssetBundler;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\File;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Throwable;

class RecordPackerController extends Controller
{
    private static $url_segment = 'record-packer';

    private static $allowed_actions = [
        'ExportModalForm',
        'doExport',
        'ImportModalForm',
        'doImport',
        'importPreview',
    ];

    public function Link($action = null)
    {
        return Controller::join_links(static::config()->get('url_segment'), $action);
    }

    public function ExportModalForm(): Form
    {
        $fields = FieldList::create(
            HiddenField::create('RecordClassName'),
            HiddenField::create('RecordID'),
            HiddenField::create('BackURL'),
            CheckboxField::create(
                'IncludeAssets',
                _t(self::class . '.INCLUDE_ASSETS', 'Include referenced files/images'),
                true
            ),
            TextField::create('Description', _t(self::class . '.DESCRIPTION', 'Description (optional)'))
        );

        $actions = FieldList::create(
            FormAction::create('doExport', _t(self::class . '.EXPORT_BUTTON', 'Export'))
                ->addExtraClass('btn-primary')
                ->setUseButtonTag(true)
        );

        $form = Form::create($this, 'ExportModalForm', $fields, $actions);
        $form->setFormAction($this->Link('ExportModalForm'));
        $form->setValidationExemptActions(['doExport']);
        $form->addExtraClass('record-packer-modal-form');

        return $form;
    }

    public function doExport(array $data, Form $form): HTTPResponse
    {
        $class = $this->requirePackableClass($data);

        if ($class instanceof HTTPResponse) {
            return $class;
        }

        $id = (int) ($data['RecordID'] ?? 0);
        /** @var DataObject|null $record */
        $record = $id ? $class::get()->byID($id) : null;

        if (!$record || !$record->exists() || !$record->canView()) {
            return Security::permissionFailure($this);
        }

        $includeAssets = !empty($data['IncludeAssets']);
        $description = trim((string) ($data['Description'] ?? ''));

        ExportQueuer::queue($record, RecordExportJob::class, $includeAssets, $description);

        return $this->redirectToReferer((string) ($data['BackURL'] ?? ''));
    }

    public function ImportModalForm(): Form
    {
        $fields = FieldList::create(
            HiddenField::create('RecordClassName'),
            HiddenField::create('BackURL'),
            HiddenField::create('GridFieldLink'),
            UploadField::create(
                'ImportFile',
                _t(self::class . '.IMPORT_FILE', 'Import a previously exported record (.zip)')
            )->setAllowedExtensions(['zip'])
            ->setAllowedMaxFileNumber(1)
            ->setFolderName('record-packer-uploads')
        );

        $actions = FieldList::create(
            FormAction::create('doImport', _t(self::class . '.IMPORT_BUTTON', 'Import'))
                ->addExtraClass('btn-primary')
                ->setUseButtonTag(true)
        );

        $form = Form::create($this, 'ImportModalForm', $fields, $actions);
        $form->setFormAction($this->Link('ImportModalForm'));
        $form->setValidationExemptActions(['doImport']);
        $form->addExtraClass('record-packer-modal-form');

        return $form;
    }

    public function doImport(array $data, Form $form): HTTPResponse
    {
        $class = $this->requirePackableClass($data);

        if ($class instanceof HTTPResponse) {
            return $class;
        }

        $singleton = DataObject::singleton($class);

        if ($singleton->hasMethod('canCreate') && !$singleton->canCreate()) {
            return Security::permissionFailure($this);
        }

        $backURL = (string) ($data['BackURL'] ?? '');

        $uploadField = $form->Fields()->dataFieldByName('ImportFile');
        $items = $uploadField ? $uploadField->getItems() : null;
        $uploadedFile = $items ? $items->first() : null;

        if (!$uploadedFile instanceof File) {
            $form->sessionMessage(
                _t(self::class . '.NO_FILE', 'Please choose a file to import.'),
                'bad'
            );

            return $this->redirectToReferer($backURL);
        }

        $stub = $class::create();

        // Placeholder so the edit view isn't just blank while the queued job runs
        if ($stub->hasField('Title')) {
            $stub->Title = _t(self::class . '.IMPORTING_TITLE', 'Importing…');
        }

        $stub->write();

        $job = new RecordImportJob($stub, $uploadedFile);
        QueuedJobService::singleton()->queueJob($job);

        $itemLink = $this->itemEditLink((string) ($data['GridFieldLink'] ?? ''), $stub);

        if ($itemLink) {
            return $this->redirect($itemLink);
        }

        // Fallback for when GridFieldLink isn't usable
        return $this->redirectToReferer($backURL);
    }

    /**
     * Ensures this is a Packable thing and we have permission
     */
    private function requirePackableClass(array $data): string|HTTPResponse
    {
        if (!Permission::check(ImportExportPermissions::RECORD_IMPORT_EXPORT)) {
            return Security::permissionFailure($this);
        }

        $class = (string) ($data['RecordClassName'] ?? '');

        if (!PackableExtension::appliesTo($class)) {
            return HTTPResponse::create('Not a packable record type.', 400);
        }

        return $class;
    }

    private function itemEditLink(string $gridFieldLink, DataObject $stub): ?string
    {
        if (!$gridFieldLink || !Director::is_site_url($gridFieldLink)) {
            return null;
        }

        return Controller::join_links($gridFieldLink, 'item', (string) $stub->ID);
    }

    /**
     * Reads a just-uploaded file's manifest and returns the meta block as JSON, so the editor
     * can see what they're about to import before committing
     */
    public function importPreview(HTTPRequest $request): HTTPResponse
    {
        $response = HTTPResponse::create()->addHeader('Content-Type', 'application/json');

        if (!Permission::check(ImportExportPermissions::RECORD_IMPORT_EXPORT)) {
            return $response->setStatusCode(403)->setBody(json_encode(['error' => 'Permission denied.']));
        }

        $fileID = (int) $request->getVar('FileID');
        $file = $fileID ? File::get()->byID($fileID) : null;

        if (!$file || !$file->exists()) {
            return $response->setStatusCode(404)->setBody(json_encode(['error' => 'File not found.']));
        }

        try {
            $manifest = Injector::inst()->create(AssetBundler::class)->readZip($file);
        } catch (Throwable $e) {
            return $response->setStatusCode(422)->setBody(json_encode(['error' => $e->getMessage()]));
        }

        $meta = $manifest['meta'] ?? null;

        if (!$meta) {
            $rootLocalId = (string) ($manifest['rootLocalId'] ?? '0');
            $rootNode = $manifest['nodes'][$rootLocalId] ?? null;
            $meta = $rootNode ? [
                'className' => $rootNode['className'] ?? null,
                'title' => $rootNode['fields']['Title'] ?? null,
                'urlSegment' => $rootNode['fields']['URLSegment'] ?? null,
            ] : null;
        }

        if (!$meta || !$meta['className']) {
            return $response->setStatusCode(422)->setBody(json_encode([
                'error' => 'This file does not look like a valid export — no record metadata found.',
            ]));
        }

        $meta['classExists'] = PackableExtension::appliesTo((string) $meta['className']);
        // include "referenced" assets as per the exporter
        $meta['assetCount'] = count($manifest['assets'] ?? []);

        return $response->setBody(json_encode($meta));
    }

    /**
     * Redirects to wherever the modal's form was submitted from
     */
    private function redirectToReferer(?string $backURL = null): HTTPResponse
    {
        $link = ($backURL && Director::is_site_url($backURL)) ? $backURL : null;

        if (!$link) {
            $referer = $this->getRequest()->getHeader('Referer');
            $link = ($referer && Director::is_site_url($referer)) ? $referer : Director::absoluteBaseURL();
        }

        return $this->redirect($link);
    }
}
