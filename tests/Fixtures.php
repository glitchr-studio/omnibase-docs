<?php

namespace Base\Wikidoc\Tests;

use Base\Wikidoc\Manual\ManualRegistry;

/**
 * The repositories of tests/Fixtures/repos/acme: "widget" (README, docs/ with
 * a folder, a picture; a 1.x exported under Fixtures/export), "plain" (a
 * README only), "empty" (nothing to read), "script" (an npm package whose
 * docs/ has an index).
 */
final class Fixtures
{
    public const REPOS = __DIR__.'/Fixtures/repos';
    public const EXPORT = __DIR__.'/Fixtures/export';

    /** @param array<string, mixed> $options */
    public static function registry(array $options = [], array $configured = [], ?array $discover = null): ManualRegistry
    {
        return new ManualRegistry($configured, $discover ?? [self::REPOS.'/*/*'], $options + ['export_dir' => self::EXPORT]);
    }
}
