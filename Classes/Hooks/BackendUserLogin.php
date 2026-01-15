<?php

namespace Nitsan\NsZoho\Hooks;

use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * BackendUserLogin
 */
class BackendUserLogin
{

    public function dispatch(array $backendUser)
    {
        $isLicenseActivate = GeneralUtility::makeInstance(PackageManager::class)->isPackageActive('ns_license');
        if ($isLicenseActivate) {
            $nsLicenseModule = GeneralUtility::makeInstance(\NITSAN\NsLicense\Controller\NsLicenseModuleController::class);
            $nsLicenseModule->connectToServer('ns_zoho', 0);
        }
    }
}
