<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Zoho',
    'description' => 'The TYPO3 Zoho Extension offers seamless integration between TYPO3 forms and Zoho CRM, streamlining the lead management process. By automatically capturing leads from your website, this innovative integration ensures no opportunity is missed. 
    
    *** Live Demo: https://demo.t3planet.com/t3-extensions/typo3-zoho-crm *** Premium Version, Documentation & Free Support: https://t3planet.com/typo3-zoho-extension',
    'category' => 'plugin',
    'author' => 'T3: Nilesh Malankiya, T3: Pradeepsinh Masani, QA: Krishna Dhapa',
    'author_email' => 'sanjay@nitsan.in',
    'author_company' => 'T3Planet // NITSAN',
    'state' => 'stable',
    'uploadfolder' => 0,
    'createDirs' => '',
    'version' => '1.0.3',
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
