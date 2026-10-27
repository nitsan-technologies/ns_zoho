<?php

declare(strict_types=1);

/**
 * Standalone compatibility checks for EXT:ns_zoho on TYPO3 13/14 and PHP 8.2–8.5.
 * Run: php typo3conf/ext/ns_zoho/Tests/run-compatibility.php
 */

use Nitsan\NsZoho\Finishers\ApiFinisher;
use Nitsan\NsZoho\Tests\Unit\Finishers\AccessibleApiFinisher;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Form\Domain\Finishers\AbstractFinisher;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

$extensionRoot = dirname(__DIR__);
$projectRoot = $extensionRoot;
$autoload = null;
for ($i = 0; $i < 6; $i++) {
    $candidate = $projectRoot . '/vendor/autoload.php';
    if (is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
    $projectRoot = dirname($projectRoot);
}
if ($autoload === null) {
    fwrite(STDERR, "Could not find vendor/autoload.php\n");
    exit(1);
}

$loader = require $autoload;
$loader->addPsr4('Nitsan\\NsZoho\\', $extensionRoot . '/Classes/');
$loader->addPsr4('Nitsan\\NsZoho\\Tests\\', $extensionRoot . '/Tests/');

$failures = [];
$passes = 0;

$assert = static function (bool $ok, string $message) use (&$failures, &$passes): void {
    if ($ok) {
        $passes++;
        echo '  PASS  ' . $message . PHP_EOL;
        return;
    }
    $failures[] = $message;
    echo '  FAIL  ' . $message . PHP_EOL;
};

echo 'PHP ' . PHP_VERSION . PHP_EOL;
$typo3 = class_exists(Typo3Version::class) ? (new Typo3Version())->getVersion() : 'unknown';
echo 'TYPO3 ' . $typo3 . PHP_EOL . PHP_EOL;

$finisher = new AccessibleApiFinisher();

echo 'Existing Zoho field mapping' . PHP_EOL;
$mapped = $finisher->publicCombineMappedFields(
    ['name', 'email', 'company'],
    ['Last_Name', 'Email', 'Company'],
    ['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'company' => 'Analytical Engine']
);
$assert(
    $mapped === [
        'Last_Name' => 'Ada Lovelace',
        'Email' => 'ada@example.com',
        'Company' => 'Analytical Engine',
    ],
    'Lead fields map identifier → Zoho API name in insertion order'
);

$assert(
    $finisher->publicCombineMappedFields([], [], []) === [],
    'No mapped fields yields an empty payload (does not throw)'
);

$assert(
    $finisher->publicCombineMappedFields(['name'], ['Last_Name', 'Email'], ['name' => 'Ada']) === [],
    'Mismatched mapping lengths yield empty payload instead of ValueError'
);

$nullKeyMapped = $finisher->publicCombineMappedFields(
    [null, 'email'],
    ['Last_Name', 'Email'],
    ['' => 'Ada', 'email' => 'ada@example.com']
);
$assert(
    $nullKeyMapped === ['Last_Name' => 'Ada', 'Email' => 'ada@example.com'],
    'Null form identifiers are stored as empty-string keys (PHP 8.5)'
);

echo PHP_EOL . 'Value stringification (same results as implode for scalars)' . PHP_EOL;
$assert($finisher->publicStringifyList(['red', 'green']) === 'red, green', 'List of strings');
$assert($finisher->publicStringifyList([1, 2]) === '1, 2', 'List of ints');
$assert($finisher->publicStringifyList([]) === '', 'Empty list');
$assert(
    $finisher->publicStringifyAssoc(['api_name' => 'Last_Name', 'json_path' => ['data', 0]]) === 'api_name=Last_Name, json_path=["data",0]',
    'Nested Zoho error details do not array-to-string'
);

echo PHP_EOL . 'Attachment path resolution' . PHP_EOL;
$assert($finisher->publicResolveAttachmentFilename('/tmp/photo.jpg') === '/tmp/photo.jpg', 'String path is used as-is');
$curlFile = new CURLFile('/tmp/photo.jpg', 'image/jpeg', 'photo.jpg');
$assert($finisher->publicResolveAttachmentFilename($curlFile) === '/tmp/photo.jpg', 'CURLFile::getFilename()');
$assert($finisher->publicResolveAttachmentFilename(null) === '', 'Null attachment is empty');

echo PHP_EOL . 'TYPO3 13/14 API surface' . PHP_EOL;
$assert(is_subclass_of(ApiFinisher::class, AbstractFinisher::class), 'Finisher extends core AbstractFinisher');
$assert(method_exists(ContentObjectRenderer::class, 'setRequest'), 'ContentObjectRenderer::setRequest exists (v13+v14)');
$assert(method_exists(ContentObjectRenderer::class, 'typoLink_URL'), 'ContentObjectRenderer::typoLink_URL exists (v13+v14)');

$locationHeader = new ReflectionMethod(GeneralUtility::class, 'locationHeaderUrl');
$paramCount = $locationHeader->getNumberOfParameters();
$assert($paramCount >= 1 && $paramCount <= 2, 'locationHeaderUrl has 1 param (v13) or 2 (v14.3+)');

$major = class_exists(Typo3Version::class) ? (new Typo3Version())->getMajorVersion() : 0;
if ($major >= 14) {
    $assert($paramCount >= 2, 'TYPO3 14 locationHeaderUrl accepts a PSR-7 request');
} elseif ($major === 13) {
    $assert($paramCount === 1, 'TYPO3 13 locationHeaderUrl accepts only the path');
}

$pageInformation = new ReflectionClass(\TYPO3\CMS\Frontend\Page\PageInformation::class);
$assert($pageInformation->hasMethod('getId'), 'frontend.page.information::getId exists (v13+v14)');

$siteSettings = new ReflectionClass(\TYPO3\CMS\Core\Site\Entity\SiteSettings::class);
$assert($siteSettings->hasMethod('getMap') && $siteSettings->hasMethod('getAllFlat'), 'SiteSettings getMap/getAllFlat exist (v13+v14)');

echo PHP_EOL . 'locationHeaderUrl deprecation (request-aware)' . PHP_EOL;
$deprecations = [];
set_error_handler(static function (int $severity, string $message) use (&$deprecations): bool {
    if ($severity === E_USER_DEPRECATED) {
        $deprecations[] = $message;
    }
    return true;
});
$request = new ServerRequest('https://example.com/contact', 'GET');
$GLOBALS['TYPO3_REQUEST'] = $request;
$absolute = $finisher->publicToAbsoluteUrl('https://example.com/contact');
restore_error_handler();

$assert($absolute === 'https://example.com/contact', 'Absolute URLs are returned unchanged');
$locationDeprecations = array_filter(
    $deprecations,
    static fn(string $message): bool => str_contains($message, 'locationHeaderUrl')
);
$assert($locationDeprecations === [], 'toAbsoluteUrl does not deprecate locationHeaderUrl when a request is present');

$assert($finisher->publicGetCurrentPageId() === 0, 'Missing page information returns 0 (no TypeError)');

unset($GLOBALS['TYPO3_REQUEST']);

echo PHP_EOL . 'OAuth helpers do not TypeError on empty config' . PHP_EOL;
$token = $finisher->generateNewAccessToken([], '');
$assert(is_object($token) && ($token->error ?? null) === 'missing_refresh_token', 'Empty refresh token returns an error object');

$refresh = $finisher->generateRefreshToken([]);
$assert(is_object($refresh), 'generateRefreshToken always returns an object');

echo PHP_EOL;
if ($failures === []) {
    echo $passes . ' checks passed on PHP ' . PHP_VERSION . ' / TYPO3 ' . $typo3 . PHP_EOL;
    exit(0);
}

echo count($failures) . ' failed:' . PHP_EOL;
foreach ($failures as $failure) {
    echo ' - ' . $failure . PHP_EOL;
}
exit(1);
