<?php

declare(strict_types=1);

namespace Raoh\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The public API is what tests/public-api.txt says it is. A pull request that changes the API
 * changes that file too, by `composer public-api`, and the CHANGELOG says what the change means
 * for code that uses raoh-php: a renamed parameter breaks a call with named arguments, and a
 * changed PHPDoc type is a change for those who check their code with PHPStan or Psalm.
 */
class PublicApiTest extends TestCase
{
    /**
     * An issue's metadata is a map whose names PHP may key as ints ("404" is 404), which Issue
     * accepts; every PHPDoc of metadata says so, so that a static analyser holds callers to what
     * the code holds them to, and not to less.
     */
    public function testEveryPhpDocOfMetadataIsTheContractTheCodeHolds(): void
    {
        foreach (PublicApi::entries() as $entry) {
            foreach ($entry->getParameters() as $p) {
                if ($p->getName() !== 'meta') {
                    continue;
                }
                $doc = (string) $entry->getDocComment();
                $this->assertMatchesRegularExpression(
                    '/@param\s+array<array-key, mixed>(\|\\\\Closure\(T\): array<array-key, mixed>)? \$meta/',
                    $doc,
                    EntryPoints::name($entry) . ' documents $meta otherwise',
                );
            }
        }
    }

    public function testThePublicApiIsTheOneRecorded(): void
    {
        $recorded = file_get_contents(__DIR__ . '/public-api.txt');
        $this->assertNotFalse($recorded, 'tests/public-api.txt is missing; write it with composer public-api');
        $this->assertSame(
            $recorded,
            PublicApi::describe(),
            'The public API changed. Write tests/public-api.txt with `composer public-api`, '
                . 'and say in CHANGELOG.md what the change means for code that uses raoh-php.',
        );
    }
}
