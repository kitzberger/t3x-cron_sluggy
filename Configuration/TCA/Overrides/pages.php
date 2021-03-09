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

})();
