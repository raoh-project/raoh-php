<?php

declare(strict_types=1);

namespace Raoh\Field;

use Raoh\Absent;
use Raoh\Decoder;
use Raoh\DecoderTrait;
use Raoh\FieldDecoder;
use Raoh\Internal\Input;
use Raoh\Path;
use Raoh\Result;

/**
 * An optional field: null when the member is not there or the input is not an object, and
 * otherwise what the decoder gives for the member, a null member included.
 *
 * @template T
 * @implements FieldDecoder<mixed, T|null>
 */
final class OptionalField implements FieldDecoder
{
    /** @use DecoderTrait<mixed, T|null> */
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
        $value = Input::member(Input::members($in) ?? [], $this->name);
        if ($value instanceof Absent) {
            return Result::ok(null);
        }
        return $this->decoder->decode($value, ($path ?? Path::root())->append($this->name));
    }
}
