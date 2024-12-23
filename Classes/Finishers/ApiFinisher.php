<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace Nitsan\NsZoho\Finishers;

use GuzzleHttp\Client;
use Doctrine\DBAL\Driver\Exception;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Http\RequestFactory;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Extbase\Domain\Model\FileReference;
use TYPO3\CMS\Form\Domain\Finishers\AbstractFinisher;
use TYPO3\CMS\Form\Domain\Model\FormElements\FileUpload;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

class ApiFinisher extends AbstractFinisher
{
    protected const INDEX_TABLE = 'zoho_crm_authentication';

    /**
     * @var array
     */
    protected $defaultOptions = [
        'recipientName' => '',
        'senderName' => '',
        'addHtmlPart' => true,
        'attachUploads' => true,
    ];

    /**
     * Executes this finisher
     * @throws GuzzleException
     * @throws Exception
     * @see AbstractFinisher::execute()
     *
     */
    protected function executeInternal(): void
    {
        $formRuntime = $this->finisherContext->getFormRuntime();
        $refToken = '';
        $matchingFormValues = [];
        // get configuration from extension manager
        $constant = $this->getConstants();
        $availablefileds = $formRuntime->getFormState()->getFormValues();
        $resultExtensProperties = [];

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable(self::INDEX_TABLE);

        if ((GeneralUtility::makeInstance(Typo3Version::class))->getMajorVersion() > 10) {
            $tokenResult = $this->tokenContent($constant, $queryBuilder);
        } else {
            $tokenResult = $this->tokenContentV10($constant, $queryBuilder);
        }

        if(count($tokenResult) == 0) {
            $refreshTokenGenerate = $this->generateRefreshToken($constant);
            if(isset($refreshTokenGenerate->refresh_token)) {
                $refToken = $refreshTokenGenerate->refresh_token;
                $zohoContent = [
                    'pid' => 0,
                    'client_id' => $constant['client_id'],
                    'client_secret' => $constant['client_secret'],
                    'authtoken' => $refToken,
                ];

                if ((GeneralUtility::makeInstance(Typo3Version::class))->getMajorVersion() > 10) {
                    $queryBuilder
                        ->insert(self::INDEX_TABLE)
                        ->values($zohoContent)
                        ->executeStatement();
                } else {
                    $queryBuilder
                        ->insert(self::INDEX_TABLE)
                        ->values($zohoContent)
                        ->execute();
                }

            }
        } else {
            foreach ($tokenResult as $zoho) {
                $refToken = $zoho['authtoken'];
            }
        }

        $refreshToken = $this->generateNewAccessToken($constant, $refToken);
        $auth = $refreshToken->access_token ?? '';

        foreach ($formRuntime->getFormDefinition()->getRenderablesRecursively() as $element) {
            if($element->getType() != 'Page' && $element->getType() != 'GridRow' && $element->getType() != 'Fieldset' && $element->getType() != 'Checkbox' && $element->getType() != 'StaticText' && $element->getType() != 'Recaptcha' && $element->getType() != 'Honeypot') {
                foreach($element->getRenderingOptions() as $key => $newProperties) {

                    if($key == 'zohoValue') {

                        if(is_string($newProperties) && !empty($newProperties)) {
                            $array[] = $element->getIdentifier();
                            $resultExtensProperties[] = $newProperties;
                        }

                        if (!$element instanceof FileUpload) {
                            continue;
                        }
                        $file = $formRuntime[$element->getIdentifier()];

                        if (!$file) {
                            continue;
                        }

                        if ($file instanceof FileReference) {
                            $file = $file->getOriginalResource();
                        }

                        $folder = $file->getParentFolder();
                        $folderName = $folder->getName();
                        $fileName = $file->getName();
                    }
                }
                foreach($availablefileds as $key => $values) {
                    if($key == $element->getIdentifier()) {
                        if(is_array($values)) {
                            $values = implode(', ', $values);
                        }
                        $newArray[$key] = $values;
                    }
                }
            }
        }

        foreach ($array as $key) {
            $matchingFormValues[$key] = $newArray[$key];
        }

        $finalResult = array_combine($resultExtensProperties, $matchingFormValues);
        if (isset($fileName)) {
            $replaceImgValue = array("Record_Image" => $fileName);
            $finalResult = array_replace($finalResult, $replaceImgValue);
            $zohoModule = 'Leads';
        }

        $result = $this->postData($auth, $finalResult);
        if (isset($result['data'][0]['status']) and $result['data'][0]['status'] == 'error') {
            $message = $result['data'][0]['code'].': '.$result['data'][0]['message'];
            if (!empty($result['data'][0]['details'])){
                $message = $message.' ('.implode(', ', array_map(
                        fn($key, $value) => "$key=$value",
                        array_keys($result['data'][0]['details']),
                        $result['data'][0]['details']
                    )).' )';
            }
            
            $uid = $GLOBALS['TSFE']->id;
            $redirectUri = $this->getRedirectUri($uid);
            echo '<script>
                alert("' . $message . '");
                setTimeout(function() {
                    window.location.href = "' . $redirectUri . '";
                }, 100); // Delay for 100ms
            </script>';
            die;
        }
        if(isset($fileName) && isset($folderName) && isset($zohoModule)) {
            $responseData = $result['data'][0];
            $details = $responseData['details'];
            $recordId = $details['id'];

            // UploadFiles to CRM Module
            $this->uploadFile('Attachments', $auth, $zohoModule, $folderName, $recordId, $finalResult['Record_Image']);
        }

    }

    /**
     * Get Token Data From Table
     *
     */
    public function tokenContent($constant, $queryBuilder): array
    {
        return $queryBuilder
            ->select('*')
            ->from(self::INDEX_TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'client_id',
                    $queryBuilder->createNamedParameter($constant['client_id'], Connection::PARAM_STR)
                ),
                $queryBuilder->expr()->eq(
                    'client_secret',
                    $queryBuilder->createNamedParameter($constant['client_secret'], Connection::PARAM_STR)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }
    public function tokenContentV10($constant, $queryBuilder): array
    {
        return $queryBuilder
            ->select('*')
            ->from(self::INDEX_TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'client_id',
                    $queryBuilder->createNamedParameter($constant['client_id'], Connection::PARAM_STR)
                ),
                $queryBuilder->expr()->eq(
                    'client_secret',
                    $queryBuilder->createNamedParameter($constant['client_secret'], Connection::PARAM_STR)
                )
            )
            ->execute()
            ->fetchAll();
    }

    /**
     * postData to CRM Module
     *
     * @throws GuzzleException
     */
    public function postData($auth, $finalResult)
    {
        $constant = $this->getConstants();
        $json = '{
                "data":[
                '.
            json_encode($finalResult)
            .'
            ]
        }';

        // curl configuration for postData
        return $this->getCurl($constant, $json, $auth);
    }

    /**
     * uploadFiles to CRM Module
     *
     * @throws GuzzleException
     */
    public function uploadFile($attachType, $auth, $zohoModule, $folderName, $recordId, $sendyourdetail)
    {
        $cfile = '';
        // Upload file to CRM module
        $target_dir = GeneralUtility::getFileAbsFileName('fileadmin/user_upload/');
        $filename = $target_dir . $folderName . '/' . $sendyourdetail;

        if (function_exists('curl_file_create')) {
            $cfile = curl_file_create($filename, '', basename($filename));
        }

        // Path for uploadFiles to CRM module
        $constant = $this->getConstants();
        $url = $constant['zohoURL']."/crm/v2/" . $zohoModule . "/" . $recordId . "/" . $attachType;
        $json = array("file" => $cfile);

        // curl configuration for uploadFiles
        return $this->getCurlForAttachment($url, $json, $auth);
    }

    /**
     * @throws GuzzleException
     */
    public function getCurlForAttachment($url, $json, $auth)
    {
        $client = new Client();

        try {

            $responseAttachment = $client->request('POST', $url, [
                'headers' => [
                    'Authorization' => "Zoho-oauthtoken $auth",
                ],
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => fopen($json['file']->name, 'r'),
                    ],
                ],
            ]);

            $responseAttachmentBody = $responseAttachment->getBody()->getContents();

            return json_decode($responseAttachmentBody, true);

        } catch (RequestException $e) {
            $errorMessage = $e->getMessage();
            $errorCode = $e->getCode();

            if ($e->hasResponse()) {
                $uid = $GLOBALS['TSFE']->id;
                $redirectUri = $this->getRedirectUri($uid);
                echo '<script>
                    alert("' . $e->getResponse()->getBody()->getContents() . '");
                    setTimeout(function() {
                        window.location.href = "' . $redirectUri . '";
                    }, 100); // Delay for 100ms
                </script>';
                die;
            }
            return [
                'error' => $errorMessage,
                'code' => $errorCode,
            ];
        }
    }

    /**
     * @throws GuzzleException
     */
    public function getCurl($constant, $data, $auth)
    {

        $apiURL = $constant['zohoURL'] . '/crm/v2/Leads';

        $client = new Client();

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Zoho-oauthtoken ' . $auth,
        ];

        try {

            $response = $client->post($apiURL, [
                'headers' => $headers,
                'body' => $data,
            ]);
            $responseBody = $response->getBody()->getContents();

            return json_decode($responseBody, true);
        } catch (RequestException $e) {
            $errorMessage = $e->getMessage();
            $errorCode = $e->getCode();

            if ($e->hasResponse()) {
                $uid = $GLOBALS['TSFE']->id;
                $redirectUri = $this->getRedirectUri($uid);
                echo '<script>
                    alert("' . $e->getResponse()->getBody()->getContents() . '");
                    setTimeout(function() {
                        window.location.href = "' . $redirectUri . '";
                    }, 100); // Delay for 100ms
                </script>';
                die;
            }
            return [
                'error' => $errorMessage,
                'code' => $errorCode,
            ];
        }
    }

    public function generateRefreshToken($constant)
    {
        $url = "https://accounts.zoho.com/oauth/v2/token?code=" . $constant['authtoken'] . "&client_id=" . $constant['client_id'] . "&client_secret=" . $constant['client_secret'] ."&grant_type=authorization_code";
        $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
        $finalRequest = $requestFactory->request($url, 'POST');
        $response =json_decode($finalRequest->getBody()->getContents());
        if (isset($response->error)){
            $uid = $GLOBALS['TSFE']->id;
            $redirectUri = $this->getRedirectUri($uid);
            echo '<script>
                alert("Zoho Auth Token Expired!");
                setTimeout(function() {
                    window.location.href = "' . $redirectUri . '";
                }, 100); // Delay for 100ms
            </script>';
            die;
        }
        return $response;
    }

    /**
     */
    public function generateNewAccessToken($constant, $newRefreshToken)
    {
        try {
            $url = $constant['zohoAccountURL'] . "/oauth/v2/token?refresh_token=" . $newRefreshToken ."&client_id=" . $constant['client_id'] . "&client_secret=" . $constant['client_secret'] ."&grant_type=refresh_token";

            $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
            $finalRequest = $requestFactory->request($url, 'POST');
            return json_decode($finalRequest->getBody()->getContents());
        } catch (RequestException $e) {
            return false;
        }

    }
    public function getConstants()
    {
        if ((GeneralUtility::makeInstance(Typo3Version::class))->getMajorVersion() > 10) {
            $configurationManager = GeneralUtility::makeInstance(ConfigurationManagerInterface::class);
            $typoScriptSetup = $configurationManager->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT);
            return $typoScriptSetup['plugin.']['tx_nszoho.']['settings.'];
        } else {
            return $GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_nszoho.']['settings.'];
        }
    }
    public function getRedirectUri($uid): string
    {
        $typoScriptFrontendController = $GLOBALS['TSFE'];
        $typoLinkConfig = [
            'parameter' => $uid,
        ];
        $url = $typoScriptFrontendController->cObj->typoLink_URL($typoLinkConfig);
        return GeneralUtility::locationHeaderUrl($url);
    }

}
