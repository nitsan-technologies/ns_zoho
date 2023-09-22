<?php

$EM_CONF[$_EXTKEY] = [
    'title' => '[NITSAN] Nitsan Zoho',
    'description' => 'Easily install and configure your typo3 form with zoho CRM',
    'category' => 'plugin',
    'author' => 'T3D: Pradeepsinh Masani, Nilesh Malankiya',
    'author_email' => 'sanjay@nitsan.in',
    'author_company' => 'NITSAN Technologies Pvt Ltd',
    'state' => 'stable',
    'clearCacheOnLoad' => 0,
    'version' => '1.0.1',
    'constraints' => [
        'depends' => [
            'typo3' => '10.4.0-12.4.99',
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
