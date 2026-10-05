<?php

declare(strict_types=1);

namespace Raoh\Boundary\Array_\Encode;

use Raoh\Internal\Arguments;
use Raoh\Encoder;

/**
 * Binds a map key, a getter, and a value encoder together.
 *
 * @template T
 */
final class PropertyEncoder
{
    /**
     * @param \Closure(T): mixed $getter
     * @param Encoder<mixed, mixed> $encoder
     */
    public function __construct(
        public readonly string $key,
        private readonly \Closure $getter,
        private readonly Encoder $encoder,
    ) {
        Arguments::text($key, 'a property name');
    }

    /** @param T $value */
    public function encode(mixed $value): mixed
    {
        return $this->encoder->encode(($this->getter)($value));
    }
}
