<?php
defined('C5_EXECUTE') or die(_('Access Denied.'));

Loader::model('page_list');
$objJh = Loader::helper('json');
$objVh = Loader::helper('validation/token');
$objNh = Loader::helper('navigation');
$objController = Loader::controller('/dashboard/blocks/where-is-my-block');
$objUser = new User();
$blnCacheEnabled = (defined('ENABLE_CACHE') && ENABLE_CACHE);

// Check form token
if(!$objVh->validate('wimb_page_block_search')){
	$objResp = new stdClass();
	$objResp->status = 'error';
	$objResp->alert = $objController->getAlert($objVh->getErrorMessage(), 'error');
	$objResp->message = t('There was an error with your request');
	
	header('Content-type: application/json');
	echo $objJh->encode($objResp);
	exit;
}

// Extract search parameters
$intSearchBtId = (int) $_GET['btid'];

$intSearchIpp = (int) $_GET['ipp'];
if(!$objController->isValidItemsPerPage($intSearchIpp)){
	$intSearchIpp = 10;
}

$strSearchSort = strtolower((string) $_GET['sort_by']);
if(!$objController->isValidSortableCol($strSearchSort)){
	$strSearchSort = 'page_name';
}

$strSearchDir = strtolower((string) $_GET['sort_dir']);
if($strSearchDir != 'desc'){
	$strSearchDir = 'asc';
}

if((isset($_GET['ccm_paging_p'])) && is_numeric($_GET['ccm_paging_p'])){
	$_GET['ccm_paging_p'] = (int) abs($_GET['ccm_paging_p']);
}else{
	$_GET['ccm_paging_p'] = 1;
}

$blnRefresh = isset($_GET['refresh']) ? (bool) $_GET['refresh'] : FALSE;

// Record the options to make the form sticky
$_SESSION['wimb_form_options'] = array(
	'btid' => $intSearchBtId,
	'ipp' => $intSearchIpp,
	'sort_by' => $strSearchSort,
	'sort_dir' => $strSearchDir,
	'ccm_paging_p' => $_GET['ccm_paging_p']
);

// Check for a valid block type ID
$htmError = FALSE;
if(!is_numeric($intSearchBtId) || $intSearchBtId < 0){
	$htmError = $objController->getAlert(t('...Really?'), 'error');
}elseif($intSearchBtId == 0){
	$htmError = $objController->getAlert(t('You need to select a block type to search for'), 'warning');
}elseif(!$objController->isAllowedBlockTypeId($intSearchBtId)){
	$htmError = $objController->getAlert(t('You cannot search for that block type'), 'error');
}

// Return any errors
if($htmError !== FALSE){
	$objResp = new stdClass();
	$objResp->status = 'error';
	$objResp->alert = $htmError;
	$objResp->message = t('There was an error with your request');
	
	header('Content-type: application/json');
	echo $objJh->encode($objResp);
	exit;
}

// Check for cached data (if we're not refreshing it)
$arrPageBlockInfo = array();
$arrPageIds = array();

$keyPgBlkInfo = 'pageBlockInfo_' . $objUser->uID;
$keyPgIds = 'pageIds_' . $objUser->uID;

if(!$blnCacheEnabled){
	Cache::enableCache();
}

if($blnRefresh === TRUE){
	Cache::delete('wimb', $keyPgBlkInfo);
	Cache::delete('wimb', $keyPgIds);
}else{
	$arrPageBlockInfo = ($cachePgBlkInfo = Cache::get('wimb', $keyPgBlkInfo, FALSE)) ? $cachePgBlkInfo : array();
	$arrPageIds = ($cachePageIds = Cache::get('wimb', $keyPgIds, FALSE)) ? $cachePageIds : array();
}

if(!$blnCacheEnabled){
	Cache::disableCache();
}

// Refresh cache (if needed)
if(count($arrPageBlockInfo) == 0 || count($arrPageIds) == 0 || $blnRefresh === TRUE){
	// Get a list of all non-aliased pages viewable by the current user
	$objHome = Page::getByID(HOME_CID);
	$strHomePath = (string) $objHome->getCollectionPath();

	$objPl = new PageList();
	$objPl->filterByPath($strHomePath, TRUE);
	$objPl->ignoreAliases();
	$objPl->includeSystemPages();
	$objPl->displayUnapprovedPages();
	$arrAllowedPages = (array) $objPl->get();

	$objPerm = new Permissions($objHome);
	if($objPerm->canRead()) array_unshift($arrAllowedPages, $objHome);

	// For any page that has at least one of the block type we are searching for, get
	// the page name, path and total number of instances while also recording the page ID
	$arrPageBlockInfo = array();
	$arrPageIds = array();
	foreach($arrAllowedPages as $objPage){
		if((!is_object($objPage)) || !$objPage instanceof Page || $objPage->error){
			continue;
		}
		
		$intPageId = $objPage->getCollectionID();
		$strName = $objPage->getCollectionName();
		$strPath = $objNh->getLinkToCollection($objPage, TRUE);
		if(strlen($strPath) == 0){
			$strPath = BASE_URL;
		}
		
		$arrPageBlocks = (array) $objPage->getBlocks(FALSE);
		
		foreach($arrPageBlocks as $objBlock){
			if((!$objBlock instanceof Block) || $objBlock->btID != $intSearchBtId){
				continue;
			}
			
			$objBlkPerm = new Permissions($objBlock);
			if(!$objBlkPerm->canRead()){
				continue;
			}
			
			if(is_array($arrPageBlockInfo[$strPath])){
				$arrPageBlockInfo[$strPath]['instances']++;

				continue;
			}

			$arrPageBlockInfo[$strPath] = array(
				'page_name' => $strName,
				'page_path' => $strPath,
				'instances' => 1
			);
			
			$arrPageIds[] = $intPageId;
		}
	}

	// Return error message if no pages found that contain the specific block type
	if(count($arrPageIds) == 0){
		$objResp = new stdClass();
		$objResp->status = 'error';
		$objResp->alert = '';
		$objResp->message = t('No pages contain that block type');
		
		header('Content-type: application/json');
		echo $objJh->encode($objResp);
		exit;
	}

	// Cache the results for future sorting/pagination (with a relatively short TTL)
	if(!$blnCacheEnabled){
		Cache::enableCache();
	}

	Cache::set('wimb', $keyPgBlkInfo, $arrPageBlockInfo, (time() + 600));
	Cache::set('wimb', $keyPgIds, $arrPageIds, (time() + 600));	
	
	if(!$blnCacheEnabled){
		Cache::disableCache();
	}
}

// Convert the list of page IDs into a query string
$strFilter = '(p1.cID IN(' . implode(',', $arrPageIds) . '))';

// Get a paginated list of pages using the page IDs from the first PageList request
// so we can use its built-in paginator to easily extract any pagination information
$objPl = new PageList();
$objPl->filter(FALSE, $strFilter);
$objPl->includeSystemPages();
$objPl->displayUnapprovedPages();
$objPl->setItemsPerPage($intSearchIpp);	
(array) $objPl->getPage();

// Apply sorting
$strFirst = $strSearchDir == 'asc' ? '$a' : '$b';
$strSecond = $strFirst == '$a' ? '$b' : '$a';
$strOperator = $strSearchDir == 'asc' ? '>' : '<';

switch($strSearchSort){
	case 'page_name':
		usort($arrPageBlockInfo, create_function('$a, $b', '
			return strnatcmp(strtolower(' . $strFirst . '["page_name"]), strtolower(' . $strSecond . '["page_name"]));
		'));		
		break;
	
	case 'page_path':
		usort($arrPageBlockInfo, create_function('$a, $b', '
			$strRgx = "/[^a-zA-Z0-9]/";
			return strnatcmp(preg_replace($strRgx, "", ' . $strFirst . '["page_path"]), preg_replace($strRgx, "", ' . $strSecond . '["page_path"]));
		'));		
		break;
	
	case 'instances':
		usort($arrPageBlockInfo, create_function('$a, $b', '
			return $a["instances"] ' . $strOperator . ' $b["instances"];
		'));		
		break;
}

// If the results are paginated we get the pagination HTML and slice our custom results array
// to reflect the current offset and items per page parameter
if($objPl->getSummary()->pages > 1){
	$objPgn = $objPl->getPagination();
	
	$arrPageBlockInfo = array_slice($arrPageBlockInfo, $objPgn->result_offset, $objPgn->page_size);
	
	$htmPgn = (string) $objPl->displayPagingV2(FALSE, TRUE);
	$strPgnInfo = t('Viewing ' . $objPgn->result_lower . ' to ' . $objPgn->result_upper . ' (' . $objPgn->result_count . ' Total)');
}else{
	$intCurrentRows = count($arrPageBlockInfo);
	
	$htmPgn = '';
	$strPgnInfo = t('Viewing 1 to ' . $intCurrentRows . ' (' . $intCurrentRows . ' Total)');
}

$objResp = new stdClass();
$objResp->status = 'success';
$objResp->response = new stdClass();
$objResp->response->tblData = $arrPageBlockInfo;
$objResp->response->pgnHtml = $htmPgn;
$objResp->response->pgnInfo = $strPgnInfo;

header('Content-type: application/json');
echo $objJh->encode($objResp);
exit;