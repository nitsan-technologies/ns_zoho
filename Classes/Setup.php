<?php

namespace Nitsan\NsZoho;

use NITSAN\NsLicense\Service\LicenseService;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Setup
 */
class Setup
{
    public function executeOnSignal($extname = null): void
    {

        if (is_object($extname)) {
            $extname = $extname->getPackageKey();
        }

        if ($extname === 'ns_zoho') {
            //Let's check a license system
            // @extensionScannerIgnoreLine
            $activePackages = GeneralUtility::makeInstance(PackageManager::class)->getActivePackages();
            $isLicenseCheck = false;
            foreach ($activePackages as $key => $value) {
                if ($key == 'ns_license') {
                    $isLicenseCheck = true;
                }
            }
            if($isLicenseCheck) {

                if ((GeneralUtility::makeInstance(Typo3Version::class))->getMajorVersion() >= 12) {
                    $nsLicenseModule = GeneralUtility::makeInstance(LicenseService::class);
                } else {
                    $nsLicenseModule = GeneralUtility::makeInstance(\NITSAN\NsLicense\Controller\NsLicenseModuleController::class);
                }
                $nsLicenseModule->connectToServer($extname, 0);
            }
        }
    }
}
