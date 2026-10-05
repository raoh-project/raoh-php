<?php

declare(strict_types=1);

namespace Raoh;

use Raoh\Internal\Arguments;
use Raoh\Internal\MessageForm;
use Raoh\Internal\Properties;

/**
 * A message catalogue: a template for each message key, which an issue's metadata fills in.
 *
 * The English and Japanese catalogues are those of the Raoh Specification, shipped in
 * `resources/messages/`. A template's `{name}` is replaced by the message form of the metadata
 * entry `name`; a placeholder with no such entry stays as it is written. A key with no template
 * falls back to the template of the issue's code.
 *
 * A catalogue is a resolver: pass it to {@see Issues::resolve()} to write every derived message
 * in its language. A message the user of the library gave is left as it is.
 */
final class Messages
{
    private static ?self $english = null;
    private static ?self $japanese = null;

    /**
     * @param array<string, string> $templates by message key, without the `raoh.` of a properties file
     */
    private function __construct(private readonly array $templates)
    {
    }

    public static function english(): self
    {
        return self::$english ??= self::load('en');
    }

    public static function japanese(): self
    {
        return self::$japanese ??= self::load('ja');
    }

    /**
     * Reads a catalogue written as a properties file whose keys are `raoh.` and a message key.
     */
    public static function fromProperties(string $text): self
    {
        $templates = [];
        foreach (Properties::read($text) as $key => $template) {
            $key = (string) $key;
            if (str_starts_with($key, 'raoh.')) {
                $templates[substr($key, 5)] = $template;
            }
        }
        return new self($templates);
    }

    /**
     * A catalogue with these templates in place of, or besides, this one's.
     *
     * @param array<string, string> $templates
     */
    public function with(array $templates): self
    {
        foreach ($templates as $key => $template) {
            Arguments::text((string) $key, 'a message key');
            Arguments::text($template, "the template of {$key}");
        }
        return new self([...$this->templates, ...$templates]);
    }

    /**
     * @return array<string, string>
     */
    public function templates(): array
    {
        return $this->templates;
    }

    /**
     * The message for an issue of that key and code, or null when neither has a template.
     *
     * @param array<string, mixed> $meta
     */
    public function format(string $messageKey, string $code, array $meta): ?string
    {
        $template = $this->templates[$messageKey] ?? $this->templates[$code] ?? null;
        if ($template === null) {
            return null;
        }
        return preg_replace_callback(
            '/\{([^{}]*)\}/',
            static function (array $m) use ($meta): string {
                if (!array_key_exists($m[1], $meta)) {
                    return $m[0];
                }
                return MessageForm::of($meta[$m[1]]) ?? $m[0];
            },
            $template,
        ) ?? $template;
    }

    /**
     * As a resolver for {@see Issue::resolve()}: the template of exactly this key, filled in.
     *
     * @param array<string, mixed> $meta
     */
    public function __invoke(string $key, array $meta): ?string
    {
        return isset($this->templates[$key]) ? $this->format($key, $key, $meta) : null;
    }

    private static function load(string $locale): self
    {
        $path = dirname(__DIR__) . "/resources/messages/{$locale}.properties";
        $text = file_get_contents($path);
        if ($text === false) {
            throw new \RuntimeException("cannot read {$path}");
        }
        return self::fromProperties($text);
    }
}
