<?php

declare(strict_types=1);

namespace Raoh\Builtin;

use Raoh\Internal\Arguments;

/**
 * @extends BaseDecoder<bool>
 */
final class BoolDecoder extends BaseDecoder
{
    /** Fails for false. */
    public function isTrue(?string $message = null): static
    {
        $message = Arguments::message($message);
        return $this->check(
            static fn (bool $v): bool => $v,
            'invalid_value',
            ['expected' => true, 'actual' => false],
            $message,
        );
    }

    /** Fails for true. */
    public function isFalse(?string $message = null): static
    {
        $message = Arguments::message($message);
        return $this->check(
            static fn (bool $v): bool => !$v,
            'invalid_value',
            ['expected' => false, 'actual' => true],
            $message,
        );
    }
}
