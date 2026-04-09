<?php

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// Register YAML configuration for backend form editor
ExtensionManagementUtility::addTypoScriptSetup('
    module.tx_form {
        settings {
            yamlConfigurations {
                1732785702 = EXT:ns_zoho/Configuration/Form/setup.yaml
            }
        }
    }
');