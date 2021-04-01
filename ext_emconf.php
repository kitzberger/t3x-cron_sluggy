<?php
$EM_CONF['cron_sluggy'] = [
    'title' => 'Slug regenerator',
    'description' => 'Regenerate Slugs',
    'category' => 'module',
    'author' => 'Ernesto Baschny',
    'author_email' => 'eb@cron.eu',
    'state' => 'stable',
    'uploadfolder' => 0,
    'createDirs' => '',
    'clearCacheOnLoad' => 0,
    'version' => '1.1.1',
    'constraints' => [
        'depends' => [
            'typo3' => '9.5.0-10.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
