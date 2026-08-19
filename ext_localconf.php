<?php

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// Register YAML for frontend + backend so ZohoFinisher is a known preset.
// TYPO3 14 also auto-discovers Configuration/Form/NsZoho/config.yaml; this
// TypoScript path remains for TYPO3 13 and as a fallback if DI cache is stale.
ExtensionManagementUtility::addTypoScriptSetup('
    plugin.tx_form {
        settings {
            yamlConfigurations {
                1732785702 = EXT:ns_zoho/Configuration/Form/setup.yaml
            }
        }
    }
    module.tx_form {
        settings {
            yamlConfigurations {
                1732785702 = EXT:ns_zoho/Configuration/Form/setup.yaml
            }
        }
    }
');