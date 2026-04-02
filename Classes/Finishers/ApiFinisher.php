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

use Doctrine\DBAL\Driver\Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Domain\Model\FileReference;
use TYPO3\CMS\Form\Domain\Finishers\AbstractFinisher;
use TYPO3\CMS\Form\Domain\Model\FormElements\FileUpload;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

class ApiFinisher extends AbstractFinisher
{
    protected const INDEX_TABLE = 'zoho_crm_authentication';

    /**
     * @var array
     */
    protected $defaultOptions = [
        'recipientName' => '',
        'senderName'    => '',
        'addHtmlPart'   => true,
        'attachUploads' => true,
    ];

    /**
     * Executes this finisher
     *
     * @throws Exception
     * @see AbstractFinisher::execute()
     */
    protected function executeInternal(): void
    {
        $formRuntime            = $this->finisherContext->getFormRuntime();
        $refToken               = '';
        $constant               = $this->getConstants();
        $availableFields        = $formRuntime->getFormState()->getFormValues();
        $resultExtensProperties = [];
        $array                  = [];
        $newArray               = [];
        $fileName               = null;
        $folderName             = null;

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable(self::INDEX_TABLE);

        $tokenResult = $this->tokenContent($constant, $queryBuilder);

        if (count($tokenResult) === 0) {
            $refreshTokenGenerate = $this->generateRefreshToken($constant);
            if (isset($refreshTokenGenerate->refresh_token)) {
                $refToken    = $refreshTokenGenerate->refresh_token;
                $zohoContent = [
                    'pid'           => 0,
                    'client_id'     => $constant['client_id'],
                    'client_secret' => $constant['client_secret'],
                    'authtoken'     => $refToken,
                ];
                // Use a fresh queryBuilder for insert
                $insertQb = GeneralUtility::makeInstance(ConnectionPool::class)
                    ->getQueryBuilderForTable(self::INDEX_TABLE);
                $insertQb
                    ->insert(self::INDEX_TABLE)
                    ->values($zohoContent)
                    ->executeStatement();
            }
        } else {
            foreach ($tokenResult as $zoho) {
                $refToken = $zoho['authtoken'];
            }
        }

        $refreshToken = $this->generateNewAccessToken($constant, $refToken);
        $auth         = $refreshToken->access_token ?? '';

        foreach ($formRuntime->getFormDefinition()->getRenderablesRecursively() as $element) {
            $skipTypes = ['Page', 'GridRow', 'Fieldset', 'Checkbox', 'StaticText', 'Recaptcha', 'Honeypot'];
            if (in_array($element->getType(), $skipTypes, true)) {
                continue;
            }

            foreach ($element->getRenderingOptions() as $key => $newProperties) {
                if ($key !== 'zohoValue') {
                    continue;
                }

                if (is_string($newProperties) && !empty($newProperties)) {
                    $array[]                  = $element->getIdentifier();
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

                $folderName = $file->getParentFolder()->getName();
                $fileName   = $file->getName();
            }

            foreach ($availableFields as $key => $values) {
                if ($key === $element->getIdentifier()) {
                    if (is_array($values)) {
                        $values = implode(', ', $values);
                    }
                    $newArray[$key] = $values;
                }
            }
        }

        $matchingFormValues = [];
        foreach ($array as $key) {
            $matchingFormValues[$key] = $newArray[$key] ?? '';
        }
        $finalResult = array_combine($resultExtensProperties, $matchingFormValues) ?: [];

        if ($fileName !== null) {
            $finalResult = array_replace($finalResult, ['Record_Image' => $fileName]);
        }

        $zohoModule = $formRuntime->getFormDefinition()->getRenderingOptions()['zohomodule'] ?? 'Leads';
        $result = $this->postData($auth, $finalResult, $zohoModule);

        if (isset($result['data'][0]['status']) && $result['data'][0]['status'] === 'error') {
            $message = $result['data'][0]['code'] . ': ' . $result['data'][0]['message'];
            if (!empty($result['data'][0]['details'])) {
                $message .= ' (' . implode(', ', array_map(
                    fn($k, $v) => "$k=$v",
                    array_keys($result['data'][0]['details']),
                    $result['data'][0]['details']
                )) . ')';
            }

            $redirectUri = $this->getRedirectUri($this->getCurrentPageId());
            echo '<script>
                alert("' . addslashes($message) . '");
                setTimeout(function() {
                    window.location.href = "' . $redirectUri . '";
                }, 100);
            </script>';
            exit;
        }

        if ($fileName !== null && $folderName !== null) {
            $recordId = $result['data'][0]['details']['id'] ?? null;
            if ($recordId) {
                $this->uploadFile(
                    'Attachments',
                    $auth,
                    $zohoModule,
                    $folderName,
                    $recordId,
                    $finalResult['Record_Image']
                );
            }
        }
    }

    /**
     * Returns current frontend page ID.
     * Compatible with TYPO3 13 and 14.
     */
    protected function getCurrentPageId(): int
    {
        return (int)$GLOBALS['TYPO3_REQUEST']
            ->getAttribute('frontend.page.information')
            ->getId();
    }

    /**
     * Get token data from the database table.
     */
    public function tokenContent(array $constant, $queryBuilder): array
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

    /**
     * Post data to Zoho CRM module.
     */
    public function postData(string $auth, array $finalResult, string $zohoModule): array
    {
        $constant = $this->getConstants();
        $json     = json_encode(['data' => [$finalResult]]);

        return $this->getCurl($constant, $json, $auth, $zohoModule);
    }

    /**
     * Upload file attachment to Zoho CRM module.
     */
    public function uploadFile(
        string $attachType,
        string $auth,
        string $zohoModule,
        string $folderName,
        string $recordId,
        string $sendyourdetail
    ): array {
        $targetDir = GeneralUtility::getFileAbsFileName('fileadmin/user_upload/');
        $filename  = $targetDir . $folderName . '/' . $sendyourdetail;
        $cfile     = '';

        if (function_exists('curl_file_create')) {
            $cfile = curl_file_create($filename, '', basename($filename));
        }

        $constant = $this->getConstants();
        $url      = $constant['zohoURL'] . '/crm/v2/' . $zohoModule . '/' . $recordId . '/' . $attachType;

        return $this->getCurlForAttachment($url, ['file' => $cfile], $auth);
    }

    /**
     * Send file via Guzzle multipart request.
     *
     * @throws GuzzleException
     */
    public function getCurlForAttachment(string $url, array $json, string $auth): array
    {
        $client = new Client();

        try {
            $response = $client->request('POST', $url, [
                'headers'   => [
                    'Authorization' => "Zoho-oauthtoken $auth",
                ],
                'multipart' => [
                    [
                        'name'     => 'file',
                        'contents' => fopen($json['file']->name, 'r'),
                    ],
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true) ?? [];

        } catch (RequestException $e) {
            $status = json_decode($e->getResponse()->getBody()->getContents(), true);
            if (($status['status'] ?? '') === 'error') {
                $redirectUri = $this->getRedirectUri($this->getCurrentPageId());
                echo '<script>
                    alert("' . addslashes($status['code'] . ': ' . $status['message']) . '");
                    setTimeout(function() {
                        window.location.href = "' . $redirectUri . '";
                    }, 100);
                </script>';
                die;
            }

            return [
                'error' => $e->getMessage(),
                'code'  => $e->getCode(),
            ];
        }
    }

    /**
     * Send JSON data via Guzzle POST request.
     */
    public function getCurl(array $constant, string $data, string $auth, string $zohoModule): array
    {
        $apiURL  = $constant['zohoURL'] . '/crm/v2/' . $zohoModule;
        $client  = new Client();
        $headers = [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Zoho-oauthtoken ' . $auth,
        ];

        try {
            $response = $client->post($apiURL, [
                'headers' => $headers,
                'body'    => $data,
            ]);

            return json_decode($response->getBody()->getContents(), true) ?? [];

        } catch (RequestException $e) {
            $status = json_decode($e->getResponse()->getBody()->getContents(), true);
            if (($status['status'] ?? '') === 'error') {
                $redirectUri = $this->getRedirectUri($this->getCurrentPageId());
                echo '<script>
                    alert("' . addslashes($status['code'] . ': ' . $status['message']) . '");
                    setTimeout(function() {
                        window.location.href = "' . $redirectUri . '";
                    }, 100);
                </script>';
                die;
            }

            return [
                'error' => $e->getMessage(),
                'code'  => $e->getCode(),
            ];
        }
    }

    /**
     * Generate Zoho refresh token from authorization code.
     */
    public function generateRefreshToken(array $constant): object
    {
        $url = $constant['zohoAccountURL']
            . '/oauth/v2/token?code=' . $constant['authtoken']
            . '&client_id=' . $constant['client_id']
            . '&client_secret=' . $constant['client_secret']
            . '&grant_type=authorization_code';

        $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
        $response       = json_decode(
            $requestFactory->request($url, 'POST')->getBody()->getContents()
        );

        if (isset($response->error)) {
            echo '<script>alert("Please review your Zoho configuration to ensure it\'s set up correctly.");</script>';
        }

        return $response;
    }

    /**
     * Generate a new Zoho access token using the stored refresh token.
     */
    public function generateNewAccessToken(array $constant, string $newRefreshToken): ?object
    {
        try {
            $url = $constant['zohoAccountURL']
                . '/oauth/v2/token?refresh_token=' . $newRefreshToken
                . '&client_id=' . $constant['client_id']
                . '&client_secret=' . $constant['client_secret']
                . '&grant_type=refresh_token';

            $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);

            return json_decode(
                $requestFactory->request($url, 'POST')->getBody()->getContents()
            );
        } catch (RequestException $e) {
            return null;
        }
    }

    /**
     * Get extension TypoScript settings.
     * Compatible with TYPO3 13 and 14.
     */
    public function getConstants(): array
    {
        $configurationManager = GeneralUtility::makeInstance(ConfigurationManagerInterface::class);
        $typoScriptSetup      = $configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT
        );

        return $typoScriptSetup['plugin.']['tx_nszoho.']['settings.'] ?? [];
    }

    /**
     * Build a redirect URI from a page UID.
     * Compatible with TYPO3 13 and 14.
     */
    public function getRedirectUri(int $uid): string
    {
        $contentObject = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $url           = $contentObject->typoLink_URL(['parameter' => $uid]);

        return GeneralUtility::locationHeaderUrl($url);
    }
}