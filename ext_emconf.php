<?php

$EM_CONF[$_EXTKEY] = [
    'title' => '[NITSAN] Zoho TYPO3 Extension',
    'description' => 'Easily install and configure your TYPO3 form with zoho CRM. Demo: https://demo.t3planet.com/t3-extensions/typo3-zoho-crm You can download PRO version for more-features & free-support at https://t3planet.com/typo3-zoho-extension',
    'category' => 'plugin',
    'author' => 'T3D: Pradeepsinh Masani, Nilesh Malankiya',
    'author_email' => 'sanjay@nitsan.in',
    'author_company' => 'NITSAN Technologies Pvt Ltd',
    'state' => 'stable',
    'uploadfolder' => 0,
    'createDirs' => '',
    'version' => '1.0.2',
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
