<?php

if (!defined ('TYPO3_MODE')) {
    die ('Access denied.');
}

(static function () {

    try {
        $config = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
            \TYPO3\CMS\Core\Configuration\ExtensionConfiguration::class
        )->get('cron_sluggy');
    } catch (\TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException $e) {
    } catch (\TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException $e) {
    }

    // Remove "/" from page slugs to be compatible on how it was in RealURL times
    if ((bool)$config['slash_remove'] && !isset($GLOBALS['TCA']['pages']['columns']['slug']['config']['generatorOptions']['replacements']['/'])) {
        $GLOBALS['TCA']['pages']['columns']['slug']['config']['generatorOptions']['replacements']['/'] = '';
    }

    $pagesFieldsForSlug = explode(',', (string)$config['pages_slugfields']);
    if (!empty($pagesFieldsForSlug)) {
        $GLOBALS['TCA']['pages']['columns']['slug']['config']['generatorOptions']['fields'] = [
            $pagesFieldsForSlug
        ];
    }

    $fields = [
        'tx_cronsluggy_pathsegment' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:cron_sluggy/Resources/Private/Language/locallang.xlf:pages.tx_cronsluggy_pathsegment',
            'description' => 'LLL:EXT:cron_sluggy/Resources/Private/Language/locallang.xlf:pages.tx_cronsluggy_pathsegment.description',
            'config' => [
                'type' => 'input',
                'eval' => 'trim'
            ]
        ],
    ];

    $showItems = ['--linebreak--', 'tx_cronsluggy_pathsegment'];

    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('pages', $fields);
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addFieldsToPalette('pages', 'title', implode(',', $showItems), 'after:slug');
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addFieldsToPalette('pages', 'titleonly', implode(',', $showItems), 'after:slug');

})();
