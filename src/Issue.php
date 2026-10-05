<?php

declare(strict_types=1);

namespace Raoh;

final readonly class Issue
{
    public readonly string $messageKey;

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly Path $path,
        public readonly string $code,
        public readonly string $message,
        public readonly array $meta = [],
        public readonly bool $customMessage = false,
        ?string $messageKey = null,
    ) {
        $this->messageKey = $messageKey ?? $code;
    }

    /**
     * @param array<string, mixed> $meta
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
     * @param array<string, mixed> $meta
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
     * @param array<string, mixed> $meta
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
     * @param callable(string, array<string, mixed>): ?string $resolver
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
