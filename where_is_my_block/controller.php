<?php      
defined('C5_EXECUTE') or die(_('Access Denied.'));

class WhereIsMyBlockPackage extends Package{
	protected $pkgHandle = 'where_is_my_block';
	protected $appVersionRequired = '5.6.0.2';
	protected $pkgVersion = '1.0';
	
	
	/**
	 * Returns the package description
	 * 
	 * @return string
	 *
	 * @author Brad Beebe
	 * @since v0.9.0
	 */
	public function getPackageDescription(){
		return t('Lists the pages that contain a specific block type.');
	}
	
	
	/**
	 * Returns the package name
	 *
	 * @return string
	 *
	 * @author Brad Beebe
	 * @since v0.9.0
	 */	
	public function getPackageName(){
		// It took a LOT of willpower not to name this "Duuuude, where's my block?"
		return t('Where Is My Block?');
	}
	
	
	/**
	 * Installs package and dashboard page
	 *
	 * @return void
	 *
	 * @author Brad Beebe
	 * @since v0.9.0
	 */		
	public function install(){
		Loader::model('single_page');
		
		$objPkg = parent::install();
		
		$objPage = SinglePage::add('/dashboard/blocks/where-is-my-block', $objPkg);
		$objPage->setAttribute('icon_dashboard', 'icon-search');
	}
}