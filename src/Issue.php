<?php

declare(strict_types=1);

namespace Raoh;

use Raoh\Internal\Wire;
use Raoh\Internal\Arguments;

final readonly class Issue
{
    public readonly string $messageKey;

    /**
     * An issue holds what a client writes as JSON, so its code, message key, message and metadata
     * are refused here when they are not: a string that is not UTF-8, metadata that is not a map
     * from names or holds a value {@see Wire} does not write, and a message key that does not
     * refine its code (it is the code, or the code, a
     * dot and more, as the specification's issues.md says).
     *
     * @param array<array-key, mixed> $meta
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public readonly Path $path,
        public readonly string $code,
        public readonly string $message,
        public readonly array $meta = [],
        public readonly bool $customMessage = false,
        ?string $messageKey = null,
    ) {
        Arguments::text($code, "an issue's code");
        Arguments::text($message, "an issue's message");
        $this->messageKey = Arguments::text($messageKey ?? $code, "an issue's message key");
        if ($this->messageKey !== $code && !str_starts_with($this->messageKey, $code . '.')) {
            throw new \InvalidArgumentException("the message key {$this->messageKey} does not refine the code {$code}");
        }
        Wire::checkMap($meta, "an issue's metadata");
    }

    /**
     * @param array<array-key, mixed> $meta
     */
    public static function of(
        Path $path,
        string $code,
        string $message,
        array $meta = [],
        ?string $messageKey = null,
    ): self {
        return new self($path, $code, $message, $meta, false, $messageKey);
    }

    /**
     * An issue of a variant the decoders give: its code is the message key up to the first dot,
     * and its message is the one given or else the one the English catalogue derives.
     *
     * @param array<array-key, mixed> $meta
     */
    public static function derived(Path $path, string $messageKey, array $meta = [], ?string $message = null): self
    {
        $code = explode('.', $messageKey, 2)[0];
        if ($message !== null) {
            return new self($path, $code, $message, $meta, true, $messageKey);
        }
        $derived = Messages::english()->format($messageKey, $code, $meta) ?? $messageKey;
        return new self($path, $code, $derived, $meta, false, $messageKey);
    }

    /**
     * An issue with a message given by its maker, which resolving leaves as it is.
     *
     * @param array<array-key, mixed> $meta
     */
    public static function custom(
        Path $path,
        string $code,
        string $message,
        array $meta = [],
        ?string $messageKey = null,
    ): self {
        return new self($path, $code, $message, $meta, true, $messageKey);
    }

    public function withCustomMessage(string $message): self
    {
        return new self($this->path, $this->code, $message, $this->meta, true, $this->messageKey);
    }

    /**
     * This issue with other metadata, held to the same invariants.
     *
     * @param array<array-key, mixed> $meta
     */
    public function withMeta(array $meta): self
    {
        return new self($this->path, $this->code, $this->message, $meta, $this->customMessage, $this->messageKey);
    }

    public function rebase(Path $prefix): self
    {
        return new self(
            $prefix->appendPath($this->path),
            $this->code,
            $this->message,
            $this->meta,
            $this->customMessage,
            $this->messageKey,
        );
    }

    /**
     * Resolves the message via the given resolver, unless one was already set explicitly.
     *
     * The resolver is tried with `messageKey` first, then with `code` if that returned
     * `null` and the two differ (a resolver has no reason to be asked the same question
     * twice). A `null` result means the resolver cannot produce a final message for this
     * issue — including a template it cannot fully interpolate — not merely "no template
     * for this key", so the issue's own message is kept rather than replaced by a worse one.
     *
     * @param callable(string, array<array-key, mixed>): ?string $resolver
     */
    public function resolve(callable $resolver): self
    {
        if ($this->customMessage) {
            return $this;
        }
        $resolved = $resolver($this->messageKey, $this->meta);
        if ($resolved === null && $this->messageKey !== $this->code) {
            $resolved = $resolver($this->code, $this->meta);
        }
        return new self(
            $this->path,
            $this->code,
            $resolved ?? $this->message,
            $this->meta,
            true,
            $this->messageKey,
        );
    }
}
