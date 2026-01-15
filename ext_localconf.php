<?php

if (!defined('TYPO3')) {
    die('Access denied.');
}

$versionInformation = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Information\Typo3Version::class);
if ($versionInformation->getMajorVersion() < 12) {
    
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_userauthgroup.php']['backendUserLogin'][] =
        \Nitsan\NsZoho\Hooks\BackendUserLogin::class . '->dispatch';
}
