<?php

declare(strict_types=1);

namespace Raoh\Field;

use Raoh\Internal\Arguments;
use Raoh\Absent;
use Raoh\Decoder;
use Raoh\DecoderTrait;
use Raoh\FieldDecoder;
use Raoh\Internal\Input;
use Raoh\Path;
use Raoh\Present;
use Raoh\PresentNull;
use Raoh\Result;

/**
 * A field that tells three cases apart, as a PATCH request needs: {@see Absent} when the member is
 * not there or the input is not an object, {@see PresentNull} when it is null, and otherwise
 * {@see Present} holding what the decoder gives.
 *
 * @template T
 * @implements FieldDecoder<mixed, Absent|PresentNull|Present<T>>
 */
final class OptionalNullableField implements FieldDecoder
{
    /** @use DecoderTrait<mixed, Absent|PresentNull|Present<T>> */
    use DecoderTrait;

    /**
     * @param Decoder<mixed, T> $decoder
     */
    public function __construct(private readonly string $name, private readonly Decoder $decoder)
    {
        Arguments::text($name, 'a field name');
    }

    public function fieldName(): string
    {
        return $this->name;
    }

    public function decode(mixed $in, ?Path $path = null): Result
    {
        $value = Input::member(Input::members($in) ?? [], $this->name);
        if ($value instanceof Absent) {
            return Result::ok($value);
        }
        if ($value === null) {
            return Result::ok(new PresentNull());
        }
        return $this->decoder->decode($value, ($path ?? Path::root())->append($this->name))
            ->map(static fn (mixed $v): Present => new Present($v));
    }
}
