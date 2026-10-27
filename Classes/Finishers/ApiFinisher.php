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
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Http\RequestFactory;
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
                $refToken = (string)$refreshTokenGenerate->refresh_token;
                $this->persistRefreshToken($constant, $refToken);
            } elseif (!empty($constant['authtoken'])) {
                // Site setting may already contain a refresh token instead of a one-time grant code.
                $probe = $this->generateNewAccessToken($constant, (string)$constant['authtoken']);
                if (!empty($probe?->access_token)) {
                    $refToken = (string)$constant['authtoken'];
                    $this->persistRefreshToken($constant, $refToken);
                } else {
                    $this->alertAndStop(
                        'Zoho OAuth error: ' . (string)($refreshTokenGenerate->error ?? $probe?->error ?? 'invalid configuration')
                    );
                }
            } else {
                $this->alertAndStop(
                    'Zoho OAuth error: ' . (string)($refreshTokenGenerate->error ?? 'missing configuration')
                );
            }
        } else {
            foreach ($tokenResult as $zoho) {
                $refToken = (string)($zoho['authtoken'] ?? '');
            }
        }

        $refreshToken = $this->generateNewAccessToken($constant, $refToken);
        $auth         = (string)($refreshToken?->access_token ?? '');

        if ($auth === '') {
            $this->alertAndStop(
                'Zoho OAuth error: ' . (string)($refreshToken?->error ?? 'unable to generate access token')
            );
        }

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
                if (!is_object($file) || !method_exists($file, 'getName')) {
                    continue;
                }

                $fileName = $file->getName();
                if (method_exists($file, 'getParentFolder')) {
                    $parentFolder = $file->getParentFolder();
                    if (is_object($parentFolder) && method_exists($parentFolder, 'getName')) {
                        $folderName = $parentFolder->getName();
                    }
                }
            }

            foreach ($availableFields as $key => $values) {
                $safeKey = $key ?? '';
                if ($safeKey !== $element->getIdentifier()) {
                    continue;
                }
                if (is_array($values)) {
                    $values = $this->stringifyList($values);
                }
                $newArray[$safeKey] = $values;
            }
        }

        $finalResult = $this->combineMappedFields($array, $resultExtensProperties, $newArray);

        if ($fileName !== null) {
            $finalResult = array_replace($finalResult, ['Record_Image' => $fileName]);
        }

        $zohoModule = (string)($formRuntime->getFormDefinition()->getRenderingOptions()['zohomodule'] ?? 'Leads');
        if ($zohoModule === '') {
            $zohoModule = 'Leads';
        }
        $result = $this->postData($auth, $finalResult, $zohoModule);

        if (isset($result['data'][0]['status']) && $result['data'][0]['status'] === 'error') {
            $message = (string)($result['data'][0]['code'] ?? '') . ': ' . (string)($result['data'][0]['message'] ?? '');
            if (!empty($result['data'][0]['details']) && is_array($result['data'][0]['details'])) {
                $message .= ' (' . $this->stringifyAssoc($result['data'][0]['details']) . ')';
            }
            $this->alertAndStop($message);
        }

        if ($fileName !== null && $folderName !== null) {
            $recordId = $result['data'][0]['details']['id'] ?? null;
            if ($recordId) {
                $this->uploadFile(
                    'Attachments',
                    $auth,
                    $zohoModule,
                    $folderName,
                    (string)$recordId,
                    (string)$finalResult['Record_Image']
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
        $request = $this->getRequest();
        $pageInformation = $request?->getAttribute('frontend.page.information');
        if ($pageInformation !== null && method_exists($pageInformation, 'getId')) {
            return (int)$pageInformation->getId();
        }

        return 0;
    }

    /**
     * Get token data from the database table.
     */
    public function tokenContent(array $constant, QueryBuilder $queryBuilder): array
    {
        return $queryBuilder
            ->select('*')
            ->from(self::INDEX_TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'client_id',
                    $queryBuilder->createNamedParameter((string)($constant['client_id'] ?? ''), Connection::PARAM_STR)
                ),
                $queryBuilder->expr()->eq(
                    'client_secret',
                    $queryBuilder->createNamedParameter((string)($constant['client_secret'] ?? ''), Connection::PARAM_STR)
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
        $json = json_encode(['data' => [$finalResult]]);
        if ($json === false) {
            $json = '{"data":[{}]}';
        }

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

        $constant = $this->getConstants();
        $url = (string)($constant['zohoURL'] ?? '') . '/crm/v2/' . $zohoModule . '/' . $recordId . '/' . $attachType;

        return $this->getCurlForAttachment($url, ['file' => $filename], $auth);
    }

    /**
     * Send file via Guzzle multipart request.
     */
    public function getCurlForAttachment(string $url, array $json, string $auth): array
    {
        $filename = $this->resolveAttachmentFilename($json['file'] ?? null);
        if ($filename === '' || !is_readable($filename)) {
            return [
                'error' => 'attachment not readable',
                'code'  => 0,
            ];
        }

        $handle = fopen($filename, 'r');
        if ($handle === false) {
            return [
                'error' => 'unable to open attachment',
                'code'  => 0,
            ];
        }

        $client = new Client();

        try {
            $response = $client->request('POST', $url, [
                'headers'   => [
                    'Authorization' => "Zoho-oauthtoken $auth",
                ],
                'multipart' => [
                    [
                        'name'     => 'file',
                        'contents' => $handle,
                        'filename' => basename($filename),
                    ],
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (GuzzleException $e) {
            return $this->handleGuzzleException($e);
        }
    }

    /**
     * Send JSON data via Guzzle POST request.
     */
    public function getCurl(array $constant, string $data, string $auth, string $zohoModule): array
    {
        $apiURL = (string)($constant['zohoURL'] ?? '') . '/crm/v2/' . $zohoModule;
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
        } catch (GuzzleException $e) {
            return $this->handleGuzzleException($e);
        }
    }

    /**
     * Generate Zoho refresh token from authorization code.
     */
    public function generateRefreshToken(array $constant): object
    {
        $params = [
            'code'          => (string)($constant['authtoken'] ?? ''),
            'client_id'     => (string)($constant['client_id'] ?? ''),
            'client_secret' => (string)($constant['client_secret'] ?? ''),
            'grant_type'    => 'authorization_code',
        ];
        if (!empty($constant['redirect_uri'])) {
            $params['redirect_uri'] = (string)$constant['redirect_uri'];
        }

        $url = rtrim((string)($constant['zohoAccountURL'] ?? ''), '/') . '/oauth/v2/token';

        try {
            $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
            $response       = json_decode(
                $requestFactory->request($url, 'POST', ['form_params' => $params])->getBody()->getContents()
            );

            return is_object($response) ? $response : (object)['error' => 'invalid_response'];
        } catch (\Throwable $e) {
            return (object)['error' => $e->getMessage()];
        }
    }

    /**
     * Generate a new Zoho access token using the stored refresh token.
     */
    public function generateNewAccessToken(array $constant, string $newRefreshToken): ?object
    {
        if ($newRefreshToken === '') {
            return (object)['error' => 'missing_refresh_token'];
        }

        try {
            $params = [
                'refresh_token' => $newRefreshToken,
                'client_id'     => (string)($constant['client_id'] ?? ''),
                'client_secret' => (string)($constant['client_secret'] ?? ''),
                'grant_type'    => 'refresh_token',
            ];
            $url = rtrim((string)($constant['zohoAccountURL'] ?? ''), '/') . '/oauth/v2/token';

            $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
            $response       = json_decode(
                $requestFactory->request($url, 'POST', ['form_params' => $params])->getBody()->getContents()
            );

            return is_object($response) ? $response : (object)['error' => 'invalid_response'];
        } catch (\Throwable $e) {
            return (object)['error' => $e->getMessage()];
        }
    }

    /**
     * Store the Zoho refresh token for later form submissions.
     */
    protected function persistRefreshToken(array $constant, string $refreshToken): void
    {
        $insertQb = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable(self::INDEX_TABLE);
        $insertQb
            ->insert(self::INDEX_TABLE)
            ->values([
                'pid'           => 0,
                'client_id'     => (string)($constant['client_id'] ?? ''),
                'client_secret' => (string)($constant['client_secret'] ?? ''),
                'authtoken'     => $refreshToken,
            ])
            ->executeStatement();
    }

    /**
     * Show a frontend alert and stop form processing.
     */
    protected function alertAndStop(string $message): never
    {
        $redirectUri = $this->getRedirectUri($this->getCurrentPageId());
        echo '<script>
            alert("' . addslashes($message) . '");
            setTimeout(function() {
                window.location.href = "' . $redirectUri . '";
            }, 100);
        </script>';
        exit;
    }

    /**
     * Get extension settings.
     * Site Settings are the source of truth for TYPO3 13/14 Site Sets, because
     * TypoScript still contains extension defaults (empty client_id, .com URLs).
     */
    public function getConstants(): array
    {
        $settings = [];

        try {
            $configurationManager = GeneralUtility::makeInstance(ConfigurationManagerInterface::class);
            $typoScriptSetup      = $configurationManager->getConfiguration(
                ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT
            );
            $settings = $typoScriptSetup['plugin.']['tx_nszoho.']['settings.']
                ?? $typoScriptSetup['plugin']['tx_nszoho']['settings']
                ?? [];
        } catch (\Throwable $e) {
            $settings = [];
        }

        $request = $this->getRequest();
        $site    = $request?->getAttribute('site');
        if ($site && method_exists($site, 'getSettings')) {
            $siteSettings = $site->getSettings();
            $map = method_exists($siteSettings, 'getMap')
                ? $siteSettings->getMap()
                : (method_exists($siteSettings, 'getAllFlat') ? $siteSettings->getAllFlat() : []);
            $prefix = 'plugin.tx_nszoho.settings.';
            foreach ($map as $key => $value) {
                if (!is_string($key) || !str_starts_with($key, $prefix) || is_array($value)) {
                    continue;
                }
                $short = substr($key, strlen($prefix));
                if ($short !== '' && $value !== null && $value !== '') {
                    $settings[$short] = $value;
                }
            }
        }

        return $settings;
    }

    /**
     * Build a redirect URI from a page UID.
     * Compatible with TYPO3 13 and 14.
     */
    public function getRedirectUri(int $uid): string
    {
        $contentObject = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $request = $this->getRequest();
        if ($request instanceof ServerRequestInterface) {
            $contentObject->setRequest($request);
        }
        $url = $contentObject->typoLink_URL(['parameter' => $uid]);

        return $this->toAbsoluteUrl($url);
    }

    /**
     * TYPO3 13: locationHeaderUrl(string $path)
     * TYPO3 14.3+: locationHeaderUrl(string $path, ?ServerRequestInterface $request = null)
     * Passing a second argument on v13 throws ArgumentCountError; omitting it on
     * v14.3+ emits E_USER_DEPRECATED.
     */
    protected function toAbsoluteUrl(string $path): string
    {
        $request = $this->getRequest();
        $parameterCount = (new \ReflectionMethod(GeneralUtility::class, 'locationHeaderUrl'))->getNumberOfParameters();
        if ($parameterCount >= 2 && $request instanceof ServerRequestInterface) {
            return GeneralUtility::locationHeaderUrl($path, $request);
        }

        return GeneralUtility::locationHeaderUrl($path);
    }

    /**
     * Map form identifiers onto Zoho CRM field names.
     * Null keys are stored as '' (PHP 8.5 deprecates null array offsets).
     *
     * @param list<int|string|null> $formIdentifiers
     * @param list<string> $zohoFieldNames
     * @param array<int|string, mixed> $formValues
     * @return array<int|string, mixed>
     */
    protected function combineMappedFields(array $formIdentifiers, array $zohoFieldNames, array $formValues): array
    {
        $matchingFormValues = [];
        foreach ($formIdentifiers as $key) {
            $safeKey = $key ?? '';
            $matchingFormValues[$safeKey] = $formValues[$safeKey] ?? '';
        }
        if ($zohoFieldNames === [] || count($zohoFieldNames) !== count($matchingFormValues)) {
            return [];
        }
        $combined = array_combine($zohoFieldNames, $matchingFormValues);

        return is_array($combined) ? $combined : [];
    }

    protected function getRequest(): ?ServerRequestInterface
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;

        return $request instanceof ServerRequestInterface ? $request : null;
    }

    /**
     * @param array<int|string, mixed> $values
     */
    protected function stringifyList(array $values): string
    {
        $parts = [];
        foreach ($values as $value) {
            $parts[] = $this->stringifyValue($value);
        }

        return implode(', ', $parts);
    }

    /**
     * @param array<int|string, mixed> $details
     */
    protected function stringifyAssoc(array $details): string
    {
        $parts = [];
        foreach ($details as $key => $value) {
            $parts[] = (string)($key ?? '') . '=' . $this->stringifyValue($value);
        }

        return implode(', ', $parts);
    }

    protected function stringifyValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return (string)$value;
        }

        $encoded = json_encode($value);

        return $encoded === false ? '' : $encoded;
    }

    protected function resolveAttachmentFilename(mixed $file): string
    {
        if (is_string($file)) {
            return $file;
        }
        if ($file instanceof \CURLFile) {
            return $file->getFilename();
        }
        if (is_object($file) && isset($file->name) && is_string($file->name)) {
            return $file->name;
        }

        return '';
    }

    /**
     * @return array{error: string, code: int}
     */
    protected function handleGuzzleException(GuzzleException $exception): array
    {
        if ($exception instanceof RequestException) {
            $response = $exception->getResponse();
            $payload = $response !== null ? $response->getBody()->getContents() : '';
            $status = $payload !== '' ? json_decode($payload, true) : null;
            if (is_array($status) && ($status['status'] ?? '') === 'error') {
                $this->alertAndStop(
                    (string)($status['code'] ?? '') . ': ' . (string)($status['message'] ?? '')
                );
            }
        }

        return [
            'error' => $exception->getMessage(),
            'code'  => $exception->getCode(),
        ];
    }
}