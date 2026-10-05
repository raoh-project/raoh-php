<?php

declare(strict_types=1);

namespace Raoh\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raoh\Conformance\Json as JsonWriter;
use Raoh\Conformance\Value;
use Raoh\Err;
use Raoh\Input\Json;
use Raoh\Ok;

use function Raoh\Boundary\Json\from_json;

/**
 * The conformance runner checks the decoders as the Decoders class makes them. Applications reach
 * them through the facades as well, so every decoder case of the suite is also run through
 * from_json() on the text of its input, and has to give what the decoder gives that input.
 */
class FacadeTest extends TestCase
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
    public function testFromJsonGivesWhatTheDecoderGives(string $id, mixed $form, mixed $input): void
    {
        if ($id === '') {
            $this->markTestSkipped('raoh-specification not found; set RAOH_SPECIFICATION_DIR');
        }
        [$decoder, $ty] = SuiteForms::binder()->decoder($form);
        $text = JsonWriter::write($input);
        $this->assertSame(
            self::observe($decoder->decode(Json::parse($text)), $ty),
            self::observe(from_json($decoder)->decode($text), $ty),
            "{$id} through from_json() on {$text}",
        );
    }

    /**
     * @param array<string, mixed> $ty
     */
    private static function observe(mixed $r, array $ty): string
    {
        if ($r instanceof Ok) {
            return 'ok ' . JsonWriter::write(Value::observe($ty, $r->value));
        }
        assert($r instanceof Err);
        return 'issues ' . JsonWriter::write(array_map(Value::issue(...), $r->issues->toArray()));
    }
}
