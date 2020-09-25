<?php

namespace WebentwicklerAt\NewsCalImporter\Service\Migration;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\DatabaseConnection;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class CalToNewsContentElementMigrationService implements SingletonInterface
{
    /**
     * @var array
     */
    protected $calCategoryUidToSysCategoryUidCache = [];

    /**
     * @var \TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools
     */
    protected $flexFormTools;

    /**
     * @var \GeorgRinger\News\Domain\Repository\CategoryRepository
     */
    protected $categoryRepository;

    /**
     * @param \TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools $flexFormTools
     */
    public function injectFlexFormTools(\TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools $flexFormTools)
    {
        $this->flexFormTools = $flexFormTools;
    }

    /**
     * @param \GeorgRinger\News\Domain\Repository\CategoryRepository $categoryRepository
     */
    public function injectCategoryRepository(\GeorgRinger\News\Domain\Repository\CategoryRepository $categoryRepository)
    {
        $this->categoryRepository = $categoryRepository;
    }

    /**
     * CalToNewsContentElementMigrationService constructor.
     */
    public function __construct()
    {

    }

    /**
     * @return array
     */
    public function migrate()
    {
        $errors = [];
        $contentElements = $this->getCalContentElements();
        foreach ($contentElements as $contentElement) {
            try {
                $flexFormNews = $this->convertCalToNewsFlexForm($contentElement);
                $this->getDatabaseConnection()->exec_UPDATEquery(
                    'tt_content',
                    'uid = ' . (int)$contentElement['uid'],
                    [
                        'list_type' => 'eventnewsplugin_pi1',
                        'pi_flexform' => $flexFormNews,
                        'newscalimporter_pi_flexform' => $contentElement['pi_flexform'],
                    ]
                );
            } catch (\Exception $e) {
                $errors[] = 'migration failed: tt_content/' . (int)$contentElement['uid'] . ' with error: ' . $e->getMessage();
            }
        }
        return $errors;
    }

    /**
     * @return array
     */
    protected function getCalContentElements()
    {
        $contentElements = $this->getDatabaseConnection()->exec_SELECTgetRows(
            'c.*',
            'pages p, tt_content c',
            "p.uid = c.pid AND p.deleted = 0 AND c.deleted = 0 AND c.CType = 'list' AND c.list_type = 'cal_controller'"
        );
        return ($contentElements ?: []);
    }

    /**
     * @param array $contentElement
     * @return string
     * @throws \Exception
     */
    protected function convertCalToNewsFlexForm($contentElement)
    {
        $flexFormCalArray = GeneralUtility::xml2array($contentElement['pi_flexform']);

        $flexFormNewsArray = [];
        $flexFormNewsPaths = $this->extractFlexFormNewsPaths($contentElement);
        foreach ($flexFormNewsPaths as $flexFormNewsPath) {
            switch ($flexFormNewsPath) {
                case 'data/sDEF/lDEF/switchableControllerActions/vDEF':
                    $this->mapFlexFormValue(
                        $flexFormNewsPath,
                        $flexFormNewsArray,
                        [
                            'month' => 'News->month',
                            'event' => 'News->detail',
                            'list' => 'News->list',
                        ],
                        $this->flexFormTools->getArrayValueByPath('data/sDEF/lDEF/allowedViews/vDEF', $flexFormCalArray),
                        false
                    );
                    break;
                case 'data/sDEF/lDEF/settings.categoryConjunction/vDEF':
                    $this->mapFlexFormValue(
                        $flexFormNewsPath,
                        $flexFormNewsArray,
                        [
                            0 => '',
                            1 => 'and',
                            2 => 'nor',
                            3 => 'or',
                        ],
                        $this->flexFormTools->getArrayValueByPath('data/s_Cat/lDEF/categoryMode/vDEF', $flexFormCalArray)
                    );
                    break;
                case 'data/sDEF/lDEF/settings.categories/vDEF':
                    $value = $this->flexFormTools->getArrayValueByPath('data/s_Cat/lDEF/categorySelection/vDEF', $flexFormCalArray);
                    if(isset($value)) {
                        $calCategoryUids = GeneralUtility::intExplode(',', $value, true);
                        $newsCategoryUids = [];
                        foreach ($calCategoryUids as $calCategoryUid) {
                            $newsCategoryUids[] = $this->convertCalCategoryUidToSysCategoryUid($calCategoryUid);
                        }
                        $value = implode(',', $newsCategoryUids);
                        $this->flexFormTools->setArrayValueByPath($flexFormNewsPath, $flexFormNewsArray, $value);
                    }
                    break;
                case 'data/additional/lDEF/settings.listPid/vDEF':
                    $value = $this->flexFormTools->getArrayValueByPath('data/s_List_View/lDEF/listViewPid/vDEF', $flexFormCalArray);
                    if(isset($value)) {
                        $this->flexFormTools->setArrayValueByPath($flexFormNewsPath, $flexFormNewsArray, $value);
                    }
                    break;
                case 'data/sDEF/lDEF/settings.timeRestriction/vDEF':
                    $this->mapFlexFormValue(
                        $flexFormNewsPath,
                        $flexFormNewsArray,
                        [
                            'cal:yesterday' => 'yesterday',
                            'cal:today' => 'today',
                            'cal:weekstart' => 'midnight this week',
                            'cal:monthstart' => 'midnight first day of this month',
                            //'cal:quarterstart' => '',
                            'cal:yearstart' => 'midnight first day of January this year'
                        ],
                        $this->flexFormTools->getArrayValueByPath('data/s_List_View/lDEF/starttime/vDEF', $flexFormCalArray)
                    );
                    break;
                case 'data/sDEF/lDEF/settings.timeRestrictionHigh/vDEF':
                    $this->mapFlexFormValue(
                        $flexFormNewsPath,
                        $flexFormNewsArray,
                        [
                            'cal:today' => 'tomorrow',
                            'cal:tomorrow' => 'tomorrow +1 day',
                            'cal:weekend' => 'midnight next week',
                            'cal:monthend' => 'midnight first day of next month',
                            //'cal:quarterend' => '',
                            'cal:yearend' => 'midnight first day of January next year'
                        ],
                        $this->flexFormTools->getArrayValueByPath('data/s_List_View/lDEF/endtime/vDEF', $flexFormCalArray)
                    );
                    break;
                case 'data/additional/lDEF/settings.limit/vDEF':
                    $value = $this->flexFormTools->getArrayValueByPath('data/s_List_View/lDEF/maxEvents/vDEF', $flexFormCalArray);
                    if(isset($value)) {
                        $this->flexFormTools->setArrayValueByPath($flexFormNewsPath, $flexFormNewsArray, $value);
                    }
                    break;
                case 'data/additional/lDEF/settings.hidePagination/vDEF':
                    $value = $this->flexFormTools->getArrayValueByPath('data/s_List_View/lDEF/usePageBrowser/vDEF', $flexFormCalArray);
                    if(isset($value)) {
                        $value = ($value === '0') ? 1 : 0;
                        $this->flexFormTools->setArrayValueByPath($flexFormNewsPath, $flexFormNewsArray, $value);
                    }
                    break;
                case 'data/additional/lDEF/settings.list.paginate.itemsPerPage/vDEF':
                    $value = $this->flexFormTools->getArrayValueByPath('data/s_List_View/lDEF/recordsPerPage/vDEF', $flexFormCalArray);
                    if(isset($value)) {
                        $this->flexFormTools->setArrayValueByPath($flexFormNewsPath, $flexFormNewsArray, $value);
                    }
                    break;
                case 'data/additional/lDEF/settings.detailPid/vDEF':
                    $value = $this->flexFormTools->getArrayValueByPath('data/s_Event_View/lDEF/eventViewPid/vDEF', $flexFormCalArray);
                    if(isset($value)) {
                        $this->flexFormTools->setArrayValueByPath($flexFormNewsPath, $flexFormNewsArray, $value);
                    }
                    break;
                case 'data/sDEF/lDEF/settings.includeSubCategories/vDEF':
                case 'data/sDEF/lDEF/settings.topNewsFirst/vDEF':
                case 'data/sDEF/lDEF/settings.excludeAlreadyDisplayedNews/vDEF':
                case 'data/sDEF/lDEF/settings.disableOverrideDemand/vDEF':
                    // some defaults
//                    $this->flexFormTools->setArrayValueByPath($flexFormNewsPath, $flexFormNewsArray, 0);
                    break;
                default:
//                    $this->flexFormTools->setArrayValueByPath($flexFormNewsPath, $flexFormNewsArray, '');
            }
        }

        return $this->flexFormTools->flexArray2Xml($flexFormNewsArray, true);
    }

    /**
     * @param array $contentElement
     * @return array
     */
    protected function extractFlexFormNewsPaths($contentElement)
    {
        $contentElement['list_type'] = 'news_pi1'; // manipulate list_type to get required flex form ds
        $dataStructArray = BackendUtility::getFlexFormDS(
            $GLOBALS['TCA']['tt_content']['columns']['pi_flexform']['config'],
            $contentElement,
            'tt_content',
            'pi_flexform'
        );

        $paths = [];
        foreach ($dataStructArray['sheets'] as $sheetKey => $sheet) {
            foreach ($sheet['ROOT']['el'] as $elementKey => $_) {
                $paths[] = 'data/' . $sheetKey . '/lDEF/' . $elementKey . '/vDEF';
            }
        }
        return $paths;
    }

    /**
     * @param string $path
     * @param array $array
     * @param array $map
     * @param mixed $key
     * @param bool $throwException
     * @throws \Exception
     */
    protected function mapFlexFormValue($path, array &$array, array $map, $key, $throwException = false)
    {
        if(!$key) {
        }elseif (array_key_exists($key, $map)) {
            $value = $map[$key];
            $this->flexFormTools->setArrayValueByPath($path, $array, $value);
        } else if ($throwException) {
            throw new \Exception('unknown source value "' . $key . '" for path "' . $path . '"', 1532283015);
        }
    }

    /**
     * @param int $calCategoryUid
     * @return int
     */
    protected function convertCalCategoryUidToSysCategoryUid($calCategoryUid)
    {
        if (array_key_exists($calCategoryUid, $this->calCategoryUidToSysCategoryUidCache)) {
            return $this->calCategoryUidToSysCategoryUidCache[$calCategoryUid];
        }
        $sysCategory = $this->categoryRepository->findOneByImportSourceAndImportId(
            'tx_cal_category',
            (int)$calCategoryUid
        );
        if ($sysCategory) {
            $this->calCategoryUidToSysCategoryUidCache[$calCategoryUid] = (int)$sysCategory->getUid();
            return $this->calCategoryUidToSysCategoryUidCache[$calCategoryUid];
        }
        return null;
    }

    /**
     * @return DatabaseConnection
     */
    protected function getDatabaseConnection()
    {
        return $GLOBALS['TYPO3_DB'];
    }
}