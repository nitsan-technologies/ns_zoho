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

use TYPO3\CMS\Form\Domain\Finishers\Exception\FinisherException;
use TYPO3\CMS\Form\Domain\Finishers\AbstractFinisher;
use TYPO3\CMS\Extbase\Domain\Model\FileReference;
use TYPO3\CMS\Form\Domain\Model\FormElements\FileUpload;

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
     * @see AbstractFinisher::execute()
     *
     * @throws FinisherException
     */
    protected function executeInternal()
    {
        $formRuntime = $this->finisherContext->getFormRuntime();

        // get configuration from extension manager
        $constant = $GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_nszoho.']['settings.'];
        $availablefileds = $formRuntime->getFormState()->getFormValues();
        $resultExtensProperties = [];

        $refreshToken = $this->generateNewAccessToken($constant);
        $auth = $refreshToken['access_token'];

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
        $zohoModule = $formRuntime->getFormDefinition()->getRenderingOptions()['zohomodule'];

        // postData to CRM module
        if(empty($zohoModule)){
            $zohoModule = 'Lead';
        } 
        $result = $this->postData($auth, $finalResult, $zohoModule);
        
        if($fileName) {
            $result2 = json_decode($result);
            $data = get_object_vars($result2);
            $data2 = get_object_vars($data['data'][0]);
            $data3 = get_object_vars($data2['details']);
            $recordId = $data3['id'];

            // UploadFiles to CRM Module
            $this->uploadFile('Attachments', $auth, $zohoModule, $folderName, $recordId, $finalResult['Record_Image']);
        }

    }

    /**
     * postData to CRM Module
     * 
     */
    public function postData($auth, $finalResult, $zohoModule)
    {
        $constant = $GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_nszoho.']['settings.'];
        $url = $constant['zohoURL']."/crm/v2/".$zohoModule;

        $json = '{
                "data":[
                '.
                    json_encode($finalResult)
                .'
            ]
        }';

        // curl configuration for postData
        $response = $this->getCurl($url, $json, $auth, 'application/json');
        return $response;
    }

    /**
     * uploadFiles to CRM Module
     *
     * @return void
     */
    public function uploadFile($attachType, $auth, $zohoModule, $folderName, $recordId, $sendyourdetail)
    {

        // Upload file to CRM module
        $target_dir = 'fileadmin/user_upload/';
        $filename = $_SERVER['DOCUMENT_ROOT'] . dirname($_SERVER['SCRIPT_NAME']) . $target_dir . $folderName . '/' . $sendyourdetail;

        if (function_exists('curl_file_create')) {
            $cfile = curl_file_create($filename, '', basename($filename));
        }

        // Path for uploadFiles to CRM module
        $constant = $GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_nszoho.']['settings.'];
        $url = $constant['zohoURL']."/crm/v2/" . $zohoModule . "/" . $recordId . "/" . $attachType;
        $json = array("file" => $cfile);

        // curl configuration for uploadFiles
        $response = $this->getCurlForAttachment($url, $json, $auth, 'multipart/form-data');
        return $response;
    }

    public function getCurlForAttachment($url, $json, $auth, $contentType)
    {
        $authtoken = array('Authorization: Zoho-oauthtoken '.$auth, 'Content-Type: ' . $contentType);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $authtoken);

        $response = curl_exec($ch);

        return $response;
    }

    public function getCurl($url, $data, $auth, $contentType)
    {
        $authtoken = array('Authorization: Zoho-oauthtoken '.$auth, 'Content-Type: ' . $contentType);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $authtoken);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS,$data);

        $response = curl_exec($ch);

        return $response;
    }

    public function generateRefreshToken($constant)
    {
        $tokenUrl = $constant['zohoAccountURL'] . "/oauth/v2/token";
        $postData = [
            "grant_type" => "authorization_code",
            "client_id" => $constant['client_id'],
            "client_secret" => $constant['client_secret'],
            "code" => $constant['authtoken']
        ];

        $curl = curl_init($tokenUrl);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postData));

        $response = curl_exec($curl);

        if (curl_errno($curl)) {
            $error = curl_error($curl);
            // Handle the error
        } else {
            $jsonResponse = json_decode($response, true);
            $refreshToken = $jsonResponse['refresh_token'];
            // Store the refresh token securely for future use
        }

        $newAccessToken = $this->generateNewAccessToken($refreshToken, $constant);

        return $newAccessToken;
    }

    public function generateNewAccessToken($constant)
    {
        $newAccessTokenUrl = $constant['zohoAccountURL'] . "/oauth/v2/token";
        $postDataForNewAccessToken = [
            "refresh_token" => $constant['authtoken'],
            "grant_type" => "refresh_token",
            "client_id" => $constant['client_id'],
            "client_secret" => $constant['client_secret'],
        ];

        $curl = curl_init($newAccessTokenUrl);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postDataForNewAccessToken));

        $response = curl_exec($curl);

        if (curl_errno($curl)) {
            $error = curl_error($curl);
            // Handle the error
        } else {
            $jsonResponse = json_decode($response, true);
            $refreshToken = $jsonResponse['access_token'];
            // Store the refresh token securely for future use
        }

        return $jsonResponse;
    }

}
