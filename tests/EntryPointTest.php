<?php

declare(strict_types=1);

namespace Raoh\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raoh\Boundary\Array_\Encode\PropertyEncoder;
use Raoh\Builtin\BoolDecoder;
use Raoh\Builtin\DecimalDecoder;
use Raoh\Builtin\DictDecoder;
use Raoh\Builtin\DoubleDecoder;
use Raoh\Builtin\FloatDecoder;
use Raoh\Builtin\IntDecoder;
use Raoh\Builtin\ListDecoder;
use Raoh\Builtin\LongDecoder;
use Raoh\Builtin\NumberDecoder;
use Raoh\Builtin\ObjectDecoder;
use Raoh\Builtin\StringDecoder;
use Raoh\Builtin\TemporalDecoder;
use Raoh\CallableDecoder;
use Raoh\CallableEncoder;
use Raoh\Combinator\Combiner;
use Raoh\Decoders;
use Raoh\Field\Field;
use Raoh\Field\OptionalField;
use Raoh\Field\OptionalNullableField;
use Raoh\Input\JsonObject;
use Raoh\Issue;
use Raoh\Issues;
use Raoh\Messages;
use Raoh\Path;
use Raoh\Value\Temporal\LocalDate;

/**
 * Every entry point of the public API that takes a string refuses one that is not UTF-8, with an
 * \InvalidArgumentException, where it is given: a string the library keeps ends up in an issue, a
 * path or a message, which a client writes as JSON. The entry points are found by reflection over
 * the API PublicApi records, so a method added later is held to the rule without being listed
 * here; one this test cannot call fails until it is given its arguments below.
 *
 * Readers of text are the exception: what they are given is input, and text that is not UTF-8 is
 * input they reject as they reject any other bad input.
 */
class EntryPointTest extends TestCase
{
    /** Entry points whose string is input to read, not an argument to keep, and how they reject bad input. */
    private const READERS = [
        'Raoh\Input\Json::parse' => 'throws JsonException for text that is not JSON',
        'Raoh\Input\JsonObject::get' => 'looks a name up; no member has a non-UTF-8 name',
        'Raoh\Input\JsonObject::has' => 'looks a name up; no member has a non-UTF-8 name',
        'Raoh\Messages::format' => 'looks a key up; no template has a non-UTF-8 key',
        'Raoh\Messages::__invoke' => 'looks a key up; no template has a non-UTF-8 key',
        'Raoh\Value\Decimal::parse' => 'gives null for text that is not a decimal',
        'Raoh\Value\Temporal\Instant::parse' => 'gives null for text that is not an instant',
        'Raoh\Value\Temporal\LocalDate::parse' => 'gives null for text that is not a date',
        'Raoh\Value\Temporal\LocalDateTime::parse' => 'gives null for text that is not a date-time',
        'Raoh\Value\Temporal\LocalTime::parse' => 'gives null for text that is not a time',
        'Raoh\Value\Temporal\OffsetDateTime::parse' => 'gives null for text that is not an offset date-time',
    ];

    /**
     * @return iterable<string, array{\ReflectionMethod|\ReflectionFunction, int}>
     */
    public static function stringParameters(): iterable
    {
        foreach (PublicApi::entries() as $entry) {
            $name = EntryPoints::name($entry);
            if (isset(self::READERS[$name])) {
                continue;
            }
            foreach ($entry->getParameters() as $i => $p) {
                if (EntryPoints::takesString($p)) {
                    yield "{$name}(\${$p->getName()})" => [$entry, $i];
                }
            }
        }
    }

    #[DataProvider('stringParameters')]
    public function testANonUtf8StringIsRefusedWhereItIsGiven(\ReflectionMethod|\ReflectionFunction $entry, int $at): void
    {
        $args = EntryPoints::arguments($entry);
        // The call with every argument well formed is accepted, so that a refusal below is the
        // refusal of the one argument that is not.
        EntryPoints::call($entry, $args);
        $args[$at] = "\xff";
        try {
            EntryPoints::call($entry, $args);
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
            return;
        }
        $this->fail(EntryPoints::name($entry) . ' kept a string that is not UTF-8 in $' . $entry->getParameters()[$at]->getName());
    }

    public function testEveryReaderIsAnEntryPoint(): void
    {
        $names = array_map(EntryPoints::name(...), PublicApi::entries());
        foreach (array_keys(self::READERS) as $reader) {
            $this->assertContains($reader, $names, "{$reader} is no longer an entry point; take it out of READERS");
        }
    }
}
