<?php

namespace Nitsan\NsZoho\Event;

use NITSAN\NsLicense\Service\LicenseService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Authentication\Event\AfterUserLoggedInEvent;

final class BackendUserLogin
{
    public function dispatch(AfterUserLoggedInEvent $event): void
    {
        if ($event->getUser() instanceof BackendUserAuthentication) {
            // Let's check license system
            // @extensionScannerIgnoreLine
            $isLicenseActivate = GeneralUtility::makeInstance(PackageManager::class)->isPackageActive('ns_license');

            if ($isLicenseActivate) {

                if ((GeneralUtility::makeInstance(Typo3Version::class))->getMajorVersion() >= 12) {
                    $nsLicenseModule = GeneralUtility::makeInstance(LicenseService::class);
                } else {
                    $nsLicenseModule = GeneralUtility::makeInstance(\NITSAN\NsLicense\Controller\NsLicenseModuleController::class);
                }
                $nsLicenseModule->connectToServer('ns_zoho', 0);
            }
        }
    }
}
