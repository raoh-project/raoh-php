<?php

declare(strict_types=1);

namespace Raoh\Builtin;

use Raoh\Decoder;
use Raoh\DecoderTrait;
use Raoh\Decoders;
use Raoh\Err;
use Raoh\FieldDecoder;
use Raoh\Issues;
use Raoh\Ok;
use Raoh\Path;
use Raoh\Result;

/**
 * Decodes each field from the same input, in the order they are given, and gives their values as
 * a list (the product of the specification). Every field is decoded, so the issues of every
 * failing field are given, in that order.
 *
 * A field is a {@see FieldDecoder} (`field`, `optional_field`, `optional_nullable_field`), which
 * reads one member; any other decoder is a flat field, which reads the whole input.
 *
 * @implements Decoder<mixed, list<mixed>>
 */
final class ObjectDecoder implements Decoder
{
    /** @use DecoderTrait<mixed, list<mixed>> */
    use DecoderTrait;

    /** @var list<Decoder<mixed, mixed>> */
    private readonly array $fields;

    /**
     * @param Decoder<mixed, mixed> ...$fields
     */
    public function __construct(Decoder ...$fields)
    {
        if ($fields === []) {
            throw new \InvalidArgumentException('an object has at least one field');
        }
        $this->fields = array_values($fields);
    }

    public function decode(mixed $in, ?Path $path = null): Result
    {
        $path ??= Path::root();
        $issues = Issues::empty();
        $values = [];
        foreach ($this->fields as $field) {
            $r = $field->decode($in, $path);
            if ($r instanceof Ok) {
                $values[] = $r->value;
            } else {
                assert($r instanceof Err);
                $issues = $issues->merge($r->issues);
            }
        }
        return $issues->isEmpty() ? Result::ok($values) : Result::err($issues);
    }

    /**
     * This object, also failing with `unknown_field` for every member no field names. Every field
     * has to name its member: a flat field would leave the members it reads unknown.
     *
     * @return Decoder<mixed, list<mixed>>
     */
    public function strict(): Decoder
    {
        $known = [];
        foreach ($this->fields as $field) {
            if (!$field instanceof FieldDecoder) {
                throw new \InvalidArgumentException('a strict object cannot have a flat field');
            }
            $known[] = $field->fieldName();
        }
        return Decoders::strict($this, $known);
    }
}
