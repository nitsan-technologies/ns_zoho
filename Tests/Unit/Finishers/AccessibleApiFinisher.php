<?php

declare(strict_types=1);

namespace Nitsan\NsZoho\Tests\Unit\Finishers;

use Nitsan\NsZoho\Finishers\ApiFinisher;

/**
 * Exposes protected helpers so existing finisher behaviour can be asserted
 * without posting to Zoho CRM.
 */
final class AccessibleApiFinisher extends ApiFinisher
{
    /**
     * @param list<int|string|null> $formIdentifiers
     * @param list<string> $zohoFieldNames
     * @param array<int|string, mixed> $formValues
     * @return array<int|string, mixed>
     */
    public function publicCombineMappedFields(array $formIdentifiers, array $zohoFieldNames, array $formValues): array
    {
        return $this->combineMappedFields($formIdentifiers, $zohoFieldNames, $formValues);
    }

    /**
     * @param array<int|string, mixed> $values
     */
    public function publicStringifyList(array $values): string
    {
        return $this->stringifyList($values);
    }

    /**
     * @param array<int|string, mixed> $details
     */
    public function publicStringifyAssoc(array $details): string
    {
        return $this->stringifyAssoc($details);
    }

    public function publicResolveAttachmentFilename(mixed $file): string
    {
        return $this->resolveAttachmentFilename($file);
    }

    public function publicToAbsoluteUrl(string $path): string
    {
        return $this->toAbsoluteUrl($path);
    }

    public function publicGetCurrentPageId(): int
    {
        return $this->getCurrentPageId();
    }
}
