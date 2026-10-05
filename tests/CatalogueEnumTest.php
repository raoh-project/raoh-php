<?php

declare(strict_types=1);

namespace Raoh\Tests;

use PHPUnit\Framework\TestCase;
use Raoh\ErrorCodes;
use Raoh\Messages;
use Raoh\MessageKeys;

/**
 * ErrorCodes and MessageKeys name exactly the keys of the message catalogue raoh-php ships,
 * which is the specification's: a code is a key with no dot, a message key one with a dot. A key
 * the catalogue gains and an enum lacks would make `MessageKeys::from($issue->messageKey)` throw
 * for an issue the decoders give.
 */
class CatalogueEnumTest extends TestCase
{
    /** The message keys raoh-php gives of its own, which the catalogue does not have. */
    private const OWN = ['invalid_format.json'];

    public function testErrorCodesAreTheCodesOfTheCatalogue(): void
    {
        $codes = array_values(array_filter(
            array_keys(Messages::english()->templates()),
            static fn (string $k): bool => !str_contains($k, '.'),
        ));
        $this->assertEqualsCanonicalizing($codes, array_map(static fn (ErrorCodes $c): string => $c->value, ErrorCodes::cases()));
    }

    public function testMessageKeysAreTheDottedKeysOfTheCatalogueAndRaohPhpsOwn(): void
    {
        $keys = array_values(array_filter(
            array_keys(Messages::english()->templates()),
            static fn (string $k): bool => str_contains($k, '.'),
        ));
        $this->assertEqualsCanonicalizing(
            [...$keys, ...self::OWN],
            array_map(static fn (MessageKeys $k): string => $k->value, MessageKeys::cases()),
        );
    }

    public function testEveryMessageKeyRefinesACode(): void
    {
        foreach (MessageKeys::cases() as $key) {
            $code = explode('.', $key->value, 2)[0];
            $this->assertNotNull(ErrorCodes::tryFrom($code), "{$key->value} refines no code");
        }
    }
}
