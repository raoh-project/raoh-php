<?php

declare(strict_types=1);

namespace Raoh\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raoh\Internal\Wire;
use Raoh\Ok;

/**
 * Every value a decoder of this library gives is a value an issue's metadata can hold, so that an
 * operation may put what it decoded into an issue (the duplicates `unique()` lists). Every decoder
 * of the specification's suite is run on its case's input, and what it gives is checked as
 * metadata is.
 */
class DecodedValuesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, mixed, mixed}>
     */
    public static function cases(): iterable
    {
        $cases = SuiteForms::cases();
        if ($cases === []) {
            yield 'suite not found' => ['', null, null];
            return;
        }
        foreach ($cases as [$id, $form, $input]) {
            yield $id => [$id, $form, $input];
        }
    }

    #[DataProvider('cases')]
    public function testWhatADecoderGivesIsMetadata(string $id, mixed $form, mixed $input): void
    {
        if ($id === '') {
            $this->markTestSkipped('raoh-specification not found; set RAOH_SPECIFICATION_DIR');
        }
        [$decoder] = SuiteForms::binder()->decoder($form);
        $r = $decoder->decode($input);
        if ($r instanceof Ok) {
            Wire::check($r->value, "what {$id} gave");
        }
        $this->addToAssertionCount(1);
    }
}
