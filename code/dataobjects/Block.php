<?php
/**
 * @package silverstipe blocks
 * @author Shea Dawson <shea@silverstripe.com.au>
 */
class Block extends DataObject implements PermissionProvider{

	/**
	 * @var array
	 */
	private static $db = array(
		'Title' => 'Varchar(255)',
		'Area' => 'Varchar', // will be removed in future versions (moved to m_m_extrafields on page)
		'Weight' => 'Int', // will be removed in future versions (moved to m_m_extrafields on page)
		"CanViewType" => "Enum('Anyone, LoggedInUsers, OnlyTheseUsers', 'Anyone')",
		'ExtraCSSClasses' => 'Varchar'
	);

	/**
	 * @var array
	 */
	private static $many_many = array(
		"ViewerGroups" => "Group"
	);

	/**
	 * @var array
	 */
	private static $belongs_many_many = array(
		'Pages' => 'SiteTree',
		'BlockSets' => 'BlockSet'
	);

	private static $extensions = array(
		"Versioned('Stage', 'Live')"
	);

	/**
	 * @var array
	 */
	private static $default_sort = array('Title' => 'ASC');

	/**
	 * @var array
	 */
	private static $dependencies = array(
        'blockManager' => '%$blockManager',
    );

	/**
	 * @var BlockManager
	 */
    public $blockManager;

    /**
	 * @var BlockController
	 */
    protected $controller;

	public function getCMSFields(){
		// this line is a temporary patch until I can work out why this dependency isn't being
		// loaded in some cases...
		if(!$this->blockManager) $this->blockManager = singleton('BlockManager');

		$fields = parent::getCMSFields();
		
		$classes = ArrayLib::valuekey(ClassInfo::subclassesFor('Block'));
		unset($classes['Block']);
		$classField = DropdownField::create('ClassName', 'Block Type', $classes);

		$fields->removeFieldFromTab('Root', 'BlockSets');
		$fields->removeByName('Area'); // legacy
		$fields->removeByName('Weight'); // legacy
		
		// Sort Fields: Type, Title, Published, Exta Classes
		$fields->addFieldToTab('Root.Main', $classField, 'Title');
		
		if($this->blockManager->getUseExtraCSSClasses()){
			$fields->addFieldToTab('Root.Main', $fields->dataFieldByName('ExtraCSSClasses'), 'Title');	
		}else{
			$fields->removeByName('ExtraCSSClasses');
		}

		// Viewer groups
		$fields->removeFieldFromTab('Root', 'ViewerGroups');
		$groupsMap = Group::get()->map('ID', 'Breadcrumbs')->toArray();
		asort($groupsMap);
		$viewersOptionsField = new OptionsetField(
			"CanViewType", 
			_t('SiteTree.ACCESSHEADER', "Who can view this page?")
		);
		$viewerGroupsField = ListboxField::create("ViewerGroups", _t('SiteTree.VIEWERGROUPS', "Viewer Groups"))
			->setMultiple(true)
			->setSource($groupsMap)
			->setAttribute(
				'data-placeholder', 
				_t('SiteTree.GroupPlaceholder', 'Click to select group')
		);
		$viewersOptionsSource = array();
		$viewersOptionsSource["Anyone"] = _t('SiteTree.ACCESSANYONE', "Anyone");
		$viewersOptionsSource["LoggedInUsers"] = _t('SiteTree.ACCESSLOGGEDIN', "Logged-in users");
		$viewersOptionsSource["OnlyTheseUsers"] = _t('SiteTree.ACCESSONLYTHESE', "Only these people (choose from list)");
		$viewersOptionsField->setSource($viewersOptionsSource);

		$fields->addFieldsToTab('Root.ViewerGroups', array(
			$viewersOptionsField,
			$viewerGroupsField,
		));
		
		// Show a GridField (list only) with pages which this block is used on
		$fields->removeFieldFromTab('Root.Pages', 'Pages');
		$fields->addFieldsToTab('Root.Pages', 
				new GridField(
						'Pages', 
						'Used on pages', 
						$this->Pages(), 
						$gconf = GridFieldConfig_Base::create()));
		// enhance gridfield with edit links to pages if GFEditSiteTreeItemButtons is available
		// a GFRecordEditor (default) combined with BetterButtons already gives the possibility to 
		// edit versioned records (Pages), but STbutton loads them in their own interface instead 
		// of GFdetailform
		if(class_exists('GridFieldEditSiteTreeItemButton')){
			$gconf->addComponent(new GridFieldEditSiteTreeItemButton());
		}

		return $fields;
		
		
	}
	
	/*
	 * Provide a fallback mechanism for replacing Area (global) with BlockArea (on n:n relation)
	 */
	public function BlockArea(){
		return ( $this->BlockArea ? $this->BlockArea : $this->Area );
	}

	public function validate() {
		$result = parent::validate();

		if(!$this->Title){
			$result->error('Block Title is required');
		}
		return $result;
	}
	
	
	/**
	 * Copybutton extra cleanup: Duplicate for use in ModelAdmin
	 * mainly removing all links to pages and blocksets that may have been duplicated
	 */
	public function onAfterDuplicate() {
		// remove relations to pages & blocksets duplicated from the original item
        $this->Pages()->removeAll();
		$this->BlockSets()->removeAll();
    }


	/**
	 * Renders this block with appropriate templates
	 * looks for templates that match BlockClassName_AreaName 
	 * falls back to BlockClassName
	 **/
	public function forTemplate(){
		if($this->Area){
			$template[] = $this->class . '_' . $this->Area;
			if(SSViewer::hasTemplate($template)){
				return $this->renderWith($template);
			}
		}

		return $this->renderWith($this->ClassName);
	}


	public function BlockHTML(){
		return $this->forTemplate();
	}


	public function PagesCount(){
		return $this->Pages()->count();
	}

	
	/* 
	 * Deleting can be done from BlockAdmin 
	 */
	public function onBeforeDelete(){
		parent::onBeforeDelete();
		$this->Pages()->removeAll();
		$this->BlockSets()->removeAll();
	}

	
	/* 
	 * Base permissions
	 */
	public function canView($member = null){
		if(!$member || !(is_a($member, 'Member')) || is_numeric($member)) {
			$member = Member::currentUserID();
		}

		// admin override
		if($member && Permission::checkMember($member, array("ADMIN", "SITETREE_VIEW_ALL"))) return true;

		// Standard mechanism for accepting permission changes from extensions
		$extended = $this->extendedCan('canView', $member);
		if($extended !== null) return $extended;

		// check for empty spec
		if(!$this->CanViewType || $this->CanViewType == 'Anyone') return true;

		// check for any logged-in users
		if($this->CanViewType == 'LoggedInUsers' && $member) {
			return true;
		}

		// check for specific groups
		if($member && is_numeric($member)) $member = DataObject::get_by_id('Member', $member);
		if($this->CanViewType == 'OnlyTheseUsers' && $member && $member->inGroups($this->ViewerGroups())){
			return true;	
		} 
		
		return false;
	}

	public function canEdit($member = null) {
		return Permission::check('ADMIN') || Permission::check('BLOCK_EDIT');
	}

	public function canDelete($member = null) {
		return Permission::check('ADMIN') || Permission::check('BLOCK_DELETE');
	}

	public function canCreate($member = null) {
		return Permission::check('ADMIN') || Permission::check('BLOCK_CREATE');
	}

	public function canPublish($member = null) {
		return Permission::check('ADMIN') || Permission::check('BLOCK_PUBLISH');
	}

	public function providePermissions() {
		return array(
			'BLOCK_EDIT' => array(
				'name' => 'Edit a Block',
				'category' => 'Blocks',
			),
			'BLOCK_DELETE' => array(
				'name' => 'Delete a Block',
				'category' => 'Blocks',
			),
			'BLOCK_CREATE' => array(
				'name' => 'Create a Block',
				'category' => 'Blocks'
			)
		);
	}

	public function onAfterWrite(){
		parent::onAfterWrite();
		if($this->hasExtension('FilesystemPublisher')){
			$this->republish($this);
		}
	}


	/**
     * Get a list of URL's to republish when this block changes
     * if using StaticPublisher module
     */
    public function pagesAffectedByChanges() {
        $pages = $this->Pages();
        $urls = array();
        foreach ($pages as $page) {
        	$urls[] = $page->Link();
        }
        return $urls;
    }
	
	/*
	 * Get a list of Page and Blockset titles this block is used on
	 */
	public function UsageListAsString() {
		$pages = implode(", ", $this->Pages()->column('URLSegment'));
		$sets = implode(", ", $this->BlockSets()->column('Title'));
		if($pages && $sets) return "Pages: $pages<br />Block Sets: $sets";	
		if($pages) return "Pages: $pages";
		if($sets) return "Block Sets: $sets";
	}

	/**
	 * Check if this block has been published.
	 *
	 * @return boolean True if this page has been published.
	 */
	public function isPublished() {
		if(!$this->exists())
			return false;
		// var_dump($this->ID);

		// $result = DB::query("SELECT \"ID\" FROM \"Block_Live\" WHERE \"ID\" = $this->ID")->value();
		// return $result;

		return (DB::query("SELECT \"ID\" FROM \"Block_Live\" WHERE \"ID\" = $this->ID")->value())
			? 1
			: 0;
	}


	/**
	 * Check if this block has been published.
	 *
	 * @return boolean True if this page has been published.
	 */
	public function isPublishedField() {
		$field = Boolean::create('isPublished');
		$field->setValue($this->isPublished());
		return $field;
	}


    /**
     * CSS Classes to apply to block element in template
     * @return string $classes
     */
    public function CSSClasses($stopAtClass = 'DataObject') {
		$classes = strtolower(parent::CSSClasses($stopAtClass));
		if($this->blockManager->getUseExtraCSSClasses()){
			$classes = $this->ExtraCSSClasses ? $classes . " $this->ExtraCSSClasses" : $classes;	
		}
		return $classes;
	}

	/**
	 * @throws Exception
	 *
	 * @return BlockController
	 */
	public function getController() {
		if($this->controller) {
			return $this->controller;
		}
		foreach(array_reverse(ClassInfo::ancestry($this->class)) as $blockClass) {
			$controllerClass = "{$blockClass}_Controller";
			if(class_exists($controllerClass)) break;
		}
		if(!class_exists($controllerClass)) {
			throw new Exception("Could not find controller class for $this->classname");
		}
		$this->controller = Injector::inst()->create($controllerClass, $this);
		return $this->controller;
	}

}