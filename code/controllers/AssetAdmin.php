<?php

use SilverStripe\Filesystem\Storage\AssetNameGenerator;
use SilverStripe\Forms\AssetGalleryField;
use SilverStripe\Forms\DropzoneUploadField;

/**
 * AssetAdmin is the 'file store' section of the CMS.
 * It provides an interface for manipulating the File and Folder objects in the system.
 *
 * @package cms
 * @subpackage assets
 */
class AssetAdmin extends LeftAndMain implements PermissionProvider
{
    private static $url_segment = 'assets';

    private static $url_rule = '/$Action/$ID';

    private static $menu_title = 'Files';

    private static $tree_class = 'Folder';

    /**
     * Amount of results showing on a single page.
     *
     * @config
     * @var int
     */
    private static $page_length = 15;

    /**
     * @config
     * @see Upload->allowedMaxFileSize
     * @var int
     */
    private static $allowed_max_file_size;

    private static $allowed_actions = array(
        'addfolder',
        'delete',
        'AddForm',
        'SearchForm',
    );

    /**
     * Return fake-ID "root" if no ID is found (needed to upload files into the root-folder)
     */
    public function currentPageID()
    {
        if (is_numeric($this->getRequest()->requestVar('ID'))) {
            return $this->getRequest()->requestVar('ID');
        } elseif (is_numeric($this->urlParams['ID'])) {
            return $this->urlParams['ID'];
        } elseif (Session::get("{$this->class}.currentPage")) {
            return Session::get("{$this->class}.currentPage");
        } else {
            return 0;
        }
    }

    /**
     * Gets the ID of the folder being requested.
     *
     * @return int
     */
    public function getCurrentFolderID()
    {
        $currentFolderID = 0;

        if ($this->urlParams['Action'] == 'show' && is_numeric($this->urlParams['ID'])) {
            $currentFolderID = $this->urlParams['ID'];
        }

        return $currentFolderID;
    }

    /**
     * Set up the controller
     */
    public function init()
    {
        parent::init();

        Requirements::javascript(FRAMEWORK_DIR . "/admin/javascript/dist/bundle-lib.js");
        Requirements::javascript(ASSET_ADMIN_DIR . "/javascript/AssetAdmin.js");
        Requirements::add_i18n_javascript(ASSET_ADMIN_DIR . '/javascript/lang', false, true);
        Requirements::css(ASSET_ADMIN_DIR . "/javascript/dist/main.css");

        CMSBatchActionHandler::register('delete', 'AssetAdmin_DeleteBatchAction', 'Folder');
    }

    /**
     * Returns the files and subfolders contained in the currently selected folder,
     * defaulting to the root node. Doubles as search results, if any search parameters
     * are set through {@link SearchForm()}.
     *
     * @return SS_List
     */
    public function getList()
    {
        $folder = $this->currentPage();
        $context = $this->getSearchContext();
        // Overwrite name filter to search both Name and Title attributes
        $context->removeFilterByName('Name');
        $params = $this->getRequest()->requestVar('q');
        $list = $context->getResults($params);

        // Don't filter list when a detail view is requested,
        // to avoid edge cases where the filtered list wouldn't contain the requested
        // record due to faulty session state (current folder not always encoded in URL, see #7408).
        if(!$folder->ID
            && $this->getRequest()->requestVar('ID') === null
            && ($this->getRequest()->param('ID') == 'field')
        ) {
            return $list;
        }

        // Re-add previously removed "Name" filter as combined filter
        // TODO Replace with composite SearchFilter once that API exists
        if (!empty($params['Name'])) {
            $list = $list->filterAny(array(
                'Name:PartialMatch' => $params['Name'],
                'Title:PartialMatch' => $params['Name']
            ));
        }

        // Always show folders at the top
        $list = $list->sort('(CASE WHEN "File"."ClassName" = \'Folder\' THEN 0 ELSE 1 END), "Name"');

        // If a search is conducted, check for the "current folder" limitation.
        // Otherwise limit by the current folder as denoted by the URL.
        if (empty($params) || !empty($params['CurrentFolderOnly'])) {
            $list = $list->filter('ParentID', $folder->ID);
        }

        // Category filter
        if(!empty($params['AppCategory'])
            && !empty(File::config()->app_categories[$params['AppCategory']])
        ) {
            $exts = File::config()->app_categories[$params['AppCategory']];
            $list = $list->filter('Name:PartialMatch', $exts);
        }

        // Date filter
        if (!empty($params['CreatedFrom'])) {
            $fromDate = new DateField(null, null, $params['CreatedFrom']);
            $list = $list->filter("Created:GreaterThanOrEqual", $fromDate->dataValue().' 00:00:00');
        }
        if (!empty($params['CreatedTo'])) {
            $toDate = new DateField(null, null, $params['CreatedTo']);
            $list = $list->filter("Created:LessThanOrEqual", $toDate->dataValue().' 23:59:59');
        }

        return $list;
    }

    public function getEditForm($id = null, $fields = null)
    {
        $form = parent::getEditForm($id, $fields);
        $folder = ($id && is_numeric($id)) ? DataObject::get_by_id('Folder', $id, false) : $this->currentPage();
        $title = ($folder && $folder->exists()) ? $folder->Title : _t('AssetAdmin.FILES', 'Files');

        // Override fields, ignore any details from Folder->getCMSFields() since they'll
        // be handled by a detail form instead.
        $fields = FieldList::create(
          HiddenField::create('ID', false, $folder ? $folder->ID : null)
        );
        $form->setFields($fields);

        // TODO Re-enable once GridField works with React
        // // File listing
        // $gridFieldConfig = GridFieldConfig::create()->addComponents(
        //     new GridFieldToolbarHeader(),
        //     new GridFieldSortableHeader(),
        //     new GridFieldFilterHeader(),
        //     new GridFieldDataColumns(),
        //     new GridFieldPaginator(self::config()->page_length),
        //     new GridFieldEditButton(),
        //     new GridFieldDeleteAction(),
        //     new GridFieldDetailForm(),
        //     GridFieldLevelup::create($folder->ID)->setLinkSpec('admin/assets/show/%d')
        // );
        // $gridField = GridField::create('File', $title, $this->getList(), $gridFieldConfig);
        // $columns = $gridField->getConfig()->getComponentByType('GridFieldDataColumns');
        // $columns->setDisplayFields(array(
        //     'StripThumbnail' => '',
        //     'Title' => _t('File.Title', 'Title'),
        //     'Created' => _t('AssetAdmin.CREATED', 'Date'),
        //     'Size' => _t('AssetAdmin.SIZE', 'Size'),
        // ));
        // $columns->setFieldCasting(array(
        //     'Created' => 'SS_Datetime->Nice'
        // ));
        // $gridField->setAttribute(
        //     'data-url-folder-template',
        //     Controller::join_links($this->Link('show'), '%s')
        // );

        // Move existing fields to a "details" tab, unless they've already been tabbed out through extensions.
        // Required to keep Folder->getCMSFields() simple and reuseable,
        // without any dependencies into AssetAdmin (e.g. useful for "add folder" views).
        if (!$fields->hasTabset()) {
            $tabs = new TabSet('Root',
                $tabList = new Tab('ListView', _t('AssetAdmin.ListView', 'List View'))
            );
            $tabList->addExtraClass("content-listview cms-tabset-icon list");
            if ($fields->Count() && $folder->exists()) {
                $tabs->push($tabDetails = new Tab('DetailsView', _t('AssetAdmin.DetailsView', 'Details')));
                $tabDetails->addExtraClass("content-galleryview cms-tabset-icon edit");
                foreach ($fields as $field) {
                    $fields->removeByName($field->getName());
                    $tabDetails->push($field);
                }
            }
            $fields->push($tabs);
        }

        // Add the upload field for new media
        if ($currentPageID = $this->currentPageID()) {
            Session::set("{$this->class}.currentPage", $currentPageID);
        }

        $folder = $this->currentPage();

        if ($folder->exists()) {
            $path = $folder->getFilename();
        }

        $galleryField = AssetGalleryField::create('Files')
            ->setCurrentFolder($this->getCurrentFolderID())
            ->setLimit(15);

        // List view
        $fields->addFieldsToTab('Root.ListView', array(
            $galleryField,
            new DropzoneUploadField('Upload')
        ));

        // Move actions to "details" tab (they don't make sense on list view)
        $actions = $form->Actions();
        $saveBtn = $actions->fieldByName('action_save');
        $deleteBtn = $actions->fieldByName('action_delete');
        $actions->removeByName('action_save');
        $actions->removeByName('action_delete');
        if (($saveBtn || $deleteBtn) && $fields->fieldByName('Root.DetailsView')) {
            $fields->addFieldToTab(
                'Root.DetailsView',
                CompositeField::create($saveBtn,$deleteBtn)->addExtraClass('Actions')
            );
        }

        $fields->setForm($form);
        $form->setTemplate($this->getTemplatesWithSuffix('_EditForm'));
        // TODO Can't merge $FormAttributes in template at the moment
        $form->addExtraClass('cms-edit-form ' . $this->BaseCSSClasses());
        $form->setAttribute('data-pjax-fragment', 'CurrentForm');
        $form->Fields()->findOrMakeTab('Root')->setTemplate('CMSTabSet');

        $this->extend('updateEditForm', $form);

        return $form;
    }

    public function addfolder($request)
    {
        $obj = $this->customise(array(
            'EditForm' => $this->AddForm()
        ));

        if ($request->isAjax()) {
            // Rendering is handled by template, which will call EditForm() eventually
            $content = $obj->renderWith($this->getTemplatesWithSuffix('_Content'));
        } else {
            $content = $obj->renderWith($this->getViewer('show'));
        }

        return $content;
    }

    public function delete($data, $form)
    {
        $className = $this->stat('tree_class');

        $record = DataObject::get_by_id($className, $data['ID']);
        if ($record && !$record->canDelete()) {
            return Security::permissionFailure();
        }
        if (!$record || !$record->ID) {
            throw new SS_HTTPResponse_Exception("Bad record ID #" . (int) $data['ID'], 404);
        }
        $parentID = $record->ParentID;
        $record->delete();
        $this->setCurrentPageID(null);

        $this->getResponse()->addHeader('X-Status', rawurlencode(_t('LeftAndMain.DELETED', 'Deleted.')));
        $this->getResponse()->addHeader('X-Pjax', 'Content');

        return $this->redirect(Controller::join_links($this->Link('show'), $parentID ? $parentID : 0));
    }

    /**
     * Get the search context
     *
     * @return SearchContext
     */
    public function getSearchContext()
    {
        $context = singleton('File')->getDefaultSearchContext();

        // Namespace fields, for easier detection if a search is present
        foreach ($context->getFields() as $field) {
            $field->setName(sprintf('q[%s]', $field->getName()));
        }
        foreach ($context->getFilters() as $filter) {
            $filter->setFullName(sprintf('q[%s]', $filter->getFullName()));
        }

        // Customize fields
        $dateHeader = HeaderField::create('q[Date]', _t('CMSSearch.FILTERDATEHEADING', 'Date'), 4);
        $dateFrom = DateField::create('q[CreatedFrom]', _t('CMSSearch.FILTERDATEFROM', 'From'))
        ->setConfig('showcalendar', true);
        $dateTo = DateField::create('q[CreatedTo]',_t('CMSSearch.FILTERDATETO', 'To'))
        ->setConfig('showcalendar', true);
        $dateGroup = FieldGroup::create(
            $dateHeader,
            $dateFrom,
            $dateTo
        );
        $context->addField($dateGroup);
        $appCategories = array(
            'archive' => _t('AssetAdmin.AppCategoryArchive', 'Archive', 'A collection of files'),
            'audio' => _t('AssetAdmin.AppCategoryAudio', 'Audio'),
            'document' => _t('AssetAdmin.AppCategoryDocument', 'Document'),
            'flash' => _t('AssetAdmin.AppCategoryFlash', 'Flash', 'The fileformat'),
            'image' => _t('AssetAdmin.AppCategoryImage', 'Image'),
            'video' => _t('AssetAdmin.AppCategoryVideo', 'Video'),
        );
        $context->addField(
            $typeDropdown = new DropdownField(
                'q[AppCategory]',
                _t('AssetAdmin.Filetype', 'File type'),
                $appCategories
            )
        );

        $typeDropdown->setEmptyString(' ');

        $context->addField(
            new CheckboxField('q[CurrentFolderOnly]', _t('AssetAdmin.CurrentFolderOnly', 'Limit to current folder?'))
        );
        $context->getFields()->removeByName('q[Title]');

        return $context;
    }

    /**
     * Returns a form for filtering of files and assets gridfield.
     * Result filtering takes place in {@link getList()}.
     *
     * @return Form
     *              @see AssetAdmin.js
     */
    public function SearchForm()
    {
        $folder = $this->currentPage();
        $context = $this->getSearchContext();

        $fields = $context->getSearchFields();
        $actions = new FieldList(
            FormAction::create('doSearch',  _t('CMSMain_left_ss.APPLY_FILTER', 'Apply Filter'))
                ->addExtraClass('ss-ui-action-constructive'),
            Object::create('ResetFormAction', 'clear', _t('CMSMain_left_ss.RESET', 'Reset'))
        );

        $form = new Form($this, 'filter', $fields, $actions);
        $form->setFormMethod('GET');
        $form->setFormAction(Controller::join_links($this->Link('show'), $folder->ID));
        $form->addExtraClass('cms-search-form');
        $form->loadDataFrom($this->getRequest()->getVars());
        $form->disableSecurityToken();
        // This have to match data-name attribute on the gridfield so that the javascript selectors work
        $form->setAttribute('data-gridfield', 'File');

        return $form;
    }

    public function AddForm()
    {
        $form = CMSForm::create(
            $this,
            'AddForm',
            new FieldList(
                new TextField("Name", _t('File.Name')),
                new HiddenField('ParentID', false, $this->getRequest()->getVar('ParentID'))
            ),
            new FieldList(
                FormAction::create('doAdd', _t('AssetAdmin_left_ss.GO','Go'))
                    ->addExtraClass('ss-ui-action-constructive')->setAttribute('data-icon', 'accept')
                    ->setTitle(_t('AssetAdmin.ActionAdd', 'Add folder'))
            )
        )->setHTMLID('Form_AddForm');
        $form->setResponseNegotiator($this->getResponseNegotiator());
        $form->setTemplate($this->getTemplatesWithSuffix('_EditForm'));
        // TODO Can't merge $FormAttributes in template at the moment
        $form->addExtraClass('add-form cms-add-form cms-edit-form cms-panel-padded center ' . $this->BaseCSSClasses());

        return $form;
    }

    /**
     * Add a new group and return its details suitable for ajax.
     *
     * @todo Move logic into Folder class, and use LeftAndMain->doAdd() default implementation.
     */
    public function doAdd($data, $form)
    {
        $class = $this->stat('tree_class');

        // check create permissions
        if (!singleton($class)->canCreate()) {
            return Security::permissionFailure($this);
        }

        // check addchildren permissions
        if(
            singleton($class)->hasExtension('Hierarchy')
            && isset($data['ParentID'])
            && is_numeric($data['ParentID'])
            && $data['ParentID']
        ) {
            $parentRecord = DataObject::get_by_id($class, $data['ParentID']);
            if ($parentRecord->hasMethod('canAddChildren') && !$parentRecord->canAddChildren()) {
                return Security::permissionFailure($this);
            }
        } else {
            $parentRecord = null;
        }

        // Check parent
        $parentID = $parentRecord && $parentRecord->ID
            ? (int) $parentRecord->ID
            : 0;
        // Build filename
        $filename = isset($data['Name'])
            ? basename($data['Name'])
            : _t('AssetAdmin.NEWFOLDER',"NewFolder");
        if ($parentRecord && $parentRecord->ID) {
            $filename = $parentRecord->getFilename() . '/' . $filename;
        }

        // Get the folder to be created

        // Ensure name is unique
        foreach ($this->getNameGenerator($filename) as $filename) {
            if (! File::find($filename) ) {
                break;
            }
        }

        // Create record
        $record = Folder::create();
        $record->ParentID = $parentID;
        $record->Name = $record->Title = basename($filename);
        $record->write();

        if ($parentRecord) {
            return $this->redirect(Controller::join_links($this->Link('show'), $parentRecord->ID));
        } else {
            return $this->redirect($this->Link());
        }
    }

    /**
     * Get an asset renamer for the given filename.
     *
     * @param  string             $filename Path name
     * @return AssetNameGenerator
     */
    protected function getNameGenerator($filename)
    {
        return Injector::inst()
            ->createWithArgs('AssetNameGenerator', array($filename));
    }

    /**
     * Custom currentPage() method to handle opening the 'root' folder
     */
    public function currentPage()
    {
        $id = $this->currentPageID();
        if ($id && is_numeric($id) && $id > 0) {
            $folder = DataObject::get_by_id('Folder', $id);
            if ($folder && $folder->exists()) {
                return $folder;
            }
        }
        $this->setCurrentPageID(null);

        return new Folder();
    }

    /**
     * @param  bool      $unlinked
     * @return ArrayList
     */
    public function Breadcrumbs($unlinked = false)
    {
        $items = parent::Breadcrumbs($unlinked);

        // The root element should explicitly point to the root node.
        // Uses session state for current record otherwise.
        $items[0]->Link = Controller::join_links(singleton('AssetAdmin')->Link('show'), 0);

        // If a search is in progress, don't show the path
        if ($this->getRequest()->requestVar('q')) {
            $items = $items->limit(1);
            $items->push(new ArrayData(array(
                'Title' => _t('LeftAndMain.SearchResults', 'Search Results'),
                'Link' => Controller::join_links($this->Link(), '?' . http_build_query(array('q' => $this->getRequest()->requestVar('q'))))
            )));
        }

        // If we're adding a folder, note that in breadcrumbs as well
        if ($this->getRequest()->param('Action') == 'addfolder') {
            $items->push(new ArrayData(array(
                'Title' => _t('Folder.AddFolderButton', 'Add folder'),
                'Link' => false
            )));
        }

        return $items;
    }

    public function providePermissions()
    {
        $title = _t("AssetAdmin.MENUTITLE", LeftAndMain::menu_title_for_class($this->class));

        return array(
            "CMS_ACCESS_AssetAdmin" => array(
                'name' => _t('CMSMain.ACCESS', "Access to '{title}' section", array('title' => $title)),
                'category' => _t('Permission.CMS_ACCESS_CATEGORY', 'CMS Access')
            )
        );
    }

}
/**
 * Delete multiple {@link Folder} records (and the associated filesystem nodes).
 * Usually used through the {@link AssetAdmin} interface.
 *
 * @package cms
 * @subpackage batchactions
 */
class AssetAdmin_DeleteBatchAction extends CMSBatchAction
{
    public function getActionTitle()
    {
        // _t('AssetAdmin_left_ss.SELECTTODEL','Select the folders that you want to delete and then click the button below')
        return _t('AssetAdmin_DeleteBatchAction.TITLE', 'Delete folders');
    }

    public function run(SS_List $records)
    {
        $status = array(
            'modified'=>array(),
            'deleted'=>array()
        );

        foreach ($records as $record) {
            $id = $record->ID;

            // Perform the action
            if($record->canDelete()) $record->delete();

            $status['deleted'][$id] = array();

            $record->destroy();
            unset($record);
        }

        return Convert::raw2json($status);
    }
}
