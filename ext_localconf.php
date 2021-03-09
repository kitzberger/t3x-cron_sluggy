<?php
defined('TYPO3_MODE') || die('Access denied.');

call_user_func(
    function()
    {
        if (TYPO3_MODE === 'BE' || TYPO3_MODE === 'CLI') {
            $updateClass = \Cron\CronSluggy\Updates\MigrateRealUrlPathSegmentField::class;
            $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update'][$updateClass] = $updateClass;
        }
    }
);
