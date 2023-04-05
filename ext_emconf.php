<?php
$EM_CONF['cron_sluggy'] = [
    'title' => 'Slug regenerator',
    'description' => 'Regenerate Slugs',
    'category' => 'module',
    'author' => 'Ernesto Baschny',
    'author_email' => 'eb@cron.eu',
    'state' => 'stable',
    'createDirs' => '',
    'version' => '1.2.0',
    'constraints' => [
        'depends' => [
            'typo3' => '9.5.0-11.5.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
