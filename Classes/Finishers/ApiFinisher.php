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

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Driver\Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Form\Domain\Finishers\Exception\FinisherException;
use TYPO3\CMS\Form\Domain\Finishers\AbstractFinisher;
use TYPO3\CMS\Extbase\Domain\Model\FileReference;
use TYPO3\CMS\Form\Domain\Model\FormElements\FileUpload;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Database\Connection;
/**
 * This finisher sends an email to one recipient
 *
 * Options:
 *
 * - templateName (mandatory): Template name for the mail body
 * - templateRootPaths: root paths for the templates
 * - layoutRootPaths: root paths for the layouts
 * - partialRootPaths: root paths for the partials
 * - variables: associative array of variables which are available inside the Fluid template
 *
 * The following options control the mail sending. In all of them, placeholders in the form
 * of {...} are replaced with the corresponding form value; i.e. {email} as senderAddress
 * makes the recipient address configurable.
 *
 * - subject (mandatory): Subject of the email
 * - recipients (mandatory): Email addresses and human-readable names of the recipients
 * - senderAddress (mandatory): Email address of the sender
 * - senderName: Human-readable name of the sender
 * - replyToRecipients: Email addresses and human-readable names of the reply-to recipients
 * - carbonCopyRecipients: Email addresses and human-readable names of the copy recipients
 * - blindCarbonCopyRecipients: Email addresses and human-readable names of the blind copy recipients
 * - title: The title of the email - If not set "subject" is used by default
 *
 * Scope: frontend
 */
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
     * @throws FinisherException*@throws GuzzleException
     * @throws GuzzleException
     * @throws DBALException
     * @throws Exception
     * @see AbstractFinisher::execute()
     *
     */
    protected function executeInternal()
    {
        $formRuntime = $this->finisherContext->getFormRuntime();
        $refToken = '';

        // get configuration from extension manager
        $constant = $GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_nszoho.']['settings.'];
        $availablefileds = $formRuntime->getFormState()->getFormValues();
        $resultExtensProperties = [];

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable(self::INDEX_TABLE);

        $zohoAuthData = $queryBuilder
            ->select('*')
            ->from(self::INDEX_TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'client_id', $queryBuilder->createNamedParameter($constant['client_id'], Connection::PARAM_STR)
                ),
                $queryBuilder->expr()->eq(
                    'client_secret', $queryBuilder->createNamedParameter($constant['client_secret'], Connection::PARAM_STR)
                )
            )
            ->execute()
            ->fetchAll();

        if(count($zohoAuthData) == 0){
            $refreshTokenGenerate = $this->generateRefreshToken($constant);
            if($refreshTokenGenerate->refresh_token){
                $refToken = $refreshTokenGenerate->refresh_token;
                $zohoContent = [
                    'pid' => 0,
                    'client_id' => $constant['client_id'],
                    'client_secret' => $constant['client_secret'],
                    'authtoken' => $refToken,
                ];

                $queryBuilder
                    ->insert(self::INDEX_TABLE)
                    ->values($zohoContent)
                    ->execute();

            }
        }
        else {
            foreach ($zohoAuthData as $zoho) {
                $refToken = $zoho['authtoken'];
            }
        }

        $refreshToken = $this->generateNewAccessToken($constant, $refToken);
        $auth = $refreshToken->access_token;

        foreach ($formRuntime->getFormDefinition()->getRenderablesRecursively() as $element) {
            if($element->getType() != 'Page' && $element->getType() != 'GridRow' && $element->getType() != 'Fieldset' && $element->getType() != 'Checkbox' && $element->getType() != 'StaticText' && $element->getType() != 'Recaptcha' && $element->getType() != 'Honeypot'){
                foreach($element->getRenderingOptions() as $key => $newProperties){

                    if($key == 'zohoValue'){

                        if(is_string($newProperties)){
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
                foreach($availablefileds as $key => $values){
                    if($key == $element->getIdentifier()){
                        if(is_array($values)){
                            $values = implode(', ', $values);
                        }
                        $matchingFormValues[$key] = $values;
                    }
                }
            }
        }

        $finalResult = array_combine($resultExtensProperties, $matchingFormValues);

        $replaceImgValue = array("Record_Image" => $fileName);
        $finalResult = array_replace($finalResult,$replaceImgValue);
        $zohoModule = 'Leads';

        $result = $this->postData($auth, $finalResult);

        if($fileName) {
            $responseData = $result['data'][0];
            $details = $responseData['details'];
            $recordId = $details['id'];

            // UploadFiles to CRM Module
            $this->uploadFile('Attachments', $auth, $zohoModule, $folderName, $recordId, $finalResult['Record_Image']);
        }

    }

    /**
     * postData to CRM Module
     *
     * @throws GuzzleException
     */
    public function postData($auth, $finalResult)
    {
        $constant = $GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_nszoho.']['settings.'];

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
     * @return void
     * @throws GuzzleException
     */
    public function uploadFile($attachType, $auth, $zohoModule, $folderName, $recordId, $sendyourdetail)
    {

        // Upload file to CRM module
        $target_dir = GeneralUtility::getFileAbsFileName('fileadmin/user_upload/');
        $filename = $target_dir . $folderName . '/' . $sendyourdetail;

        if (function_exists('curl_file_create')) {
            $cfile = curl_file_create($filename, '', basename($filename));
        }

        // Path for uploadFiles to CRM module
        $constant = $GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_nszoho.']['settings.'];
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

        $responseAttachment = $client->request('POST', $url, [
            'headers' => [
                'Authorization' => "Zoho-oauthtoken $auth",
            ],
            'multipart' => [
                [
                    'name'     => 'file',
                    'contents' => fopen($json['file']->name, 'r'),
                ],
            ],
        ]);

        $responseAttachmentBody = $responseAttachment->getBody()->getContents();

        return json_decode($responseAttachmentBody, true);
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
                echo $e->getResponse()->getBody()->getContents();
            }
            return [
                'error' => $errorMessage,
                'code' => $errorCode,
            ];
        }
    }

    public function generateRefreshToken($constant)
    {
        $url = $constant['zohoAccountURL'] . "/oauth/v2/token?code=" . $constant['authtoken'] . "&client_id=" . $constant['client_id'] . "&client_secret=" . $constant['client_secret'] ."&grant_type=authorization_code";

        $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
        $finalRequest = $requestFactory->request($url, 'POST');
        return json_decode($finalRequest->getBody()->getContents());
    }

    /**
     */
    public function generateNewAccessToken($constant, $newRefreshToken)
    {
        $url = $constant['zohoAccountURL'] . "/oauth/v2/token?refresh_token=" . $newRefreshToken ."&client_id=" . $constant['client_id'] . "&client_secret=" . $constant['client_secret'] ."&grant_type=refresh_token";

        $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
        $finalRequest = $requestFactory->request($url, 'POST');
        return json_decode($finalRequest->getBody()->getContents());

    }

}
