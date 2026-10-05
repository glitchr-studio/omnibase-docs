<?php

namespace Base\Wikidoc\Manual;

/**
 * What an address under the manuals' prefix names: a manual, one of its
 * versions, a page path inside it ("" is the manual's home).
 */
final class ManualLocation
{
    public function __construct(
        public readonly Manual $manual,
        public readonly ManualVersion $version,
        public readonly string $path,
        /** False when the address names no version ("glitchr/omnitrade/webhooks") or says "current". */
        public readonly bool $explicit,
    ) {
    }
}
