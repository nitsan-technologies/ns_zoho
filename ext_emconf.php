<?php

$EM_CONF['ns_zoho'] = [
    'title' => 'Zoho CRM Integration for TYPO3',
    'description' => 'Seamlessly connect TYPO3 forms with Zoho CRM. Automatically capture and manage leads from your website to streamline your marketing and sales process.',
    'category' => 'plugin',
    'author' => 'Team T3Planet',
    'author_email' => 'info@t3planet.de',
    'author_company' => 'T3Planet',
    'state' => 'stable',
    'uploadfolder' => 0,
    'createDirs' => '',
    'version' => '2.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '10.4.0-13.9.99',
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
