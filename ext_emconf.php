<?php

$EM_CONF[$_EXTKEY] = [
    'title' => '[NITSAN] Nitsan Zoho',
    'description' => 'Easily install and configure your typo3 form with zoho CRM',
    'category' => 'plugin',
    'author' => 'Nitsan Team',
    'author_email' => 'sanjay@nitsan.in',
    'state' => 'stable',
    'clearCacheOnLoad' => 0,
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '11.5.0-12.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
    'autoload' => [
        'psr-4' => [
            'Nitsan\\NsZoho\\' => 'Classes'
        ],
    ],
];
