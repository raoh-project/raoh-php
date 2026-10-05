<?php

declare(strict_types=1);

namespace Raoh\Field;

use Raoh\Decoder;
use Raoh\DecoderTrait;
use Raoh\FieldDecoder;
use Raoh\Internal\Input;
use Raoh\Path;
use Raoh\Result;

/**
 * A required field: decodes the member of that name with the decoder, at the member's path. A
 * member that is not there is given to the decoder as absent, which most decoders report as
 * `required`. When the input is not an object, gives `type_mismatch` (expected "object") at the
 * member's path.
 *
 * @template T
 * @implements FieldDecoder<mixed, T>
 */
final class Field implements FieldDecoder
{
    /** @use DecoderTrait<mixed, T> */
    use DecoderTrait;

    /**
     * @param Decoder<mixed, T> $decoder
     */
    public function __construct(private readonly string $name, private readonly Decoder $decoder)
    {
    }

    public function fieldName(): string
    {
        return $this->name;
    }

    public function decode(mixed $in, ?Path $path = null): Result
    {
        $at = ($path ?? Path::root())->append($this->name);
        $members = Input::members($in);
        if ($members === null) {
            return Result::issue($at, 'type_mismatch', ['expected' => 'object', 'actual' => Input::kind($in)]);
        }
        return $this->decoder->decode(Input::member($members, $this->name), $at);
    }
}
