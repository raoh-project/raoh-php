<?php

declare(strict_types=1);

// Runs the cases of the Raoh Specification on raoh-php and writes a runner result
// (spec/conformance.md), which raoh-verify checks against conformance/conformance.json.
// scripts/conformance.sh runs both.

namespace Raoh\Conformance;

use Raoh\Input\Json as InputJson;
use Raoh\Input\JsonObject;
use Raoh\Messages;
use Raoh\Ok;
use Raoh\Err;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * The features the runner binds, besides the operations below. A feature the catalogue has and
 * this does not name is not bound, so the cases that need it are not run.
 */
const BINDS = <<<'TXT'
decoder.string decoder.int decoder.long decoder.float decoder.double decoder.decimal decoder.bool
decoder.list decoder.dict decoder.object decoder.strictObject decoder.strict decoder.nullable
decoder.enum decoder.enum.message decoder.literal decoder.literal.message decoder.discriminate
decoder.discriminateBy decoder.oneOf decoder.withDefault decoder.recover decoder.recoverWith
field.field field.optionalField field.optionalNullableField field.flat
encoder.string encoder.object property.propertyWithDefault
fixture.first fixture.square_side fixture.square fixture.area fixture.shift_add_10
fixture.shift_add_100 fixture.shift_add_1000 fixture.decimal_string fixture.even
fixture.ordered_period fixture.issue_count_plus_10 fixture.identity
operation.any.map operation.any.refine operation.any.flatMap
TXT;

/** The operations bound on each kind of receiver, each with its `.message` facet where it has one. */
const OPERATIONS = [
    'string' => 'minLength maxLength fixedLength oneOf startsWith endsWith includes email ipv4 ipv6 ip ulid cuid '
        . 'uuid url uri toInt toLong toDecimal toBool trim nonBlank toLowerCase toUpperCase normalize pattern '
        . 'date time dateTime offsetDateTime iso8601',
    'int32' => 'min max range positive negative nonNegative nonPositive oneOf multipleOf',
    'int64' => 'min max range positive negative nonNegative nonPositive oneOf multipleOf',
    'float32' => 'min max range positive negative nonNegative nonPositive oneOf',
    'float64' => 'min max range positive negative nonNegative nonPositive oneOf',
    'decimal' => 'min max range positive negative nonNegative nonPositive multipleOf scale',
    'bool' => 'isTrue',
    'list' => 'nonempty minSize maxSize fixedSize unique contains containsAll toSet',
    'map' => 'nonempty minSize maxSize fixedSize',
    'date' => 'before after between',
    'time' => 'before after between',
    'datetime' => 'before after between',
    'offset_datetime' => 'before after between',
    'instant' => 'before after between',
];

/**
 * @return array<string, true>
 */
function binds(): array
{
    $out = [];
    foreach (preg_split('/\s+/', trim(BINDS)) ?: [] as $f) {
        $out[$f] = true;
    }
    foreach (OPERATIONS as $kind => $names) {
        foreach (explode(' ', $names) as $name) {
            $out["operation.{$kind}.{$name}"] = true;
            $out["operation.{$kind}.{$name}.message"] = true;
        }
    }
    return $out;
}

/**
 * The features the catalogue has, with the `.message` facet of each form that takes a message.
 *
 * @param array<string, mixed> $operations
 * @param array<string, mixed> $fixtures
 * @return array<string, true>
 */
function catalogueFeatures(array $operations, array $fixtures): array
{
    $out = [];
    $takesMessage = static fn (array $args): bool => in_array('message', array_column($args, 'kind'), true);
    foreach ($operations['constructors'] as $name => $c) {
        $out["decoder.{$name}"] = true;
        if ($takesMessage($c['args'] ?? [])) {
            $out["decoder.{$name}.message"] = true;
        }
    }
    foreach (['fields' => 'field', 'encoders' => 'encoder', 'properties' => 'property'] as $section => $prefix) {
        foreach (array_keys($operations[$section]) as $name) {
            $out["{$prefix}.{$name}"] = true;
        }
    }
    foreach ($operations['operations'] as $o) {
        foreach ($o['receivers'] as $receiver) {
            $outer = explode('<', $receiver)[0];
            $kind = $outer === '*' ? 'any' : $outer;
            $out["operation.{$kind}.{$o['name']}"] = true;
            if ($takesMessage($o['args'] ?? [])) {
                $out["operation.{$kind}.{$o['name']}.message"] = true;
            }
        }
    }
    foreach (array_keys($fixtures) as $name) {
        $out["fixture.{$name}"] = true;
    }
    return $out;
}

/**
 * What a case observed, or null where it needs a feature the runner does not bind.
 *
 * @param array<string, true> $bound
 */
function runCase(JsonObject $c, Binder $binder, array $bound): mixed
{
    try {
        if ($c->has('encoder')) {
            $observed = new JsonObject(['ok' => Value::meta($binder->encode($c->get('encoder'), $c->get('value')))]);
        } else {
            [$decoder, $ty] = $binder->decoder($c->get('decoder'));
            $r = $decoder->decode($c->get('input'));
            if ($r instanceof Ok) {
                $observed = new JsonObject(['ok' => Value::observe($ty, $r->value)]);
            } else {
                assert($r instanceof Err);
                $observed = new JsonObject(['issues' => array_map(Value::issue(...), $r->issues->toArray())]);
            }
        }
    } catch (\Throwable $e) {
        $observed = new JsonObject(['error' => get_class($e) . ': ' . $e->getMessage()]);
    }
    foreach (array_keys($binder->used) as $feature) {
        if (!isset($bound[$feature])) {
            return null;
        }
    }
    return $observed;
}

/**
 * @param array<string, string> $args
 */
function run(array $args): void
{
    $spec = $args['spec'];
    $json = static fn (string $path): array => json_decode(
        (string) file_get_contents("{$spec}/{$path}"),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $version = $json('specification.json')['version'];
    $operations = $json('catalog/operations.json');
    $fixtures = $json('catalog/fixtures.json');

    $defs = static fn (array $args): array => array_map(
        static fn (array $a): array => ['kind' => $a['kind'], 'optional' => $a['optional'] ?? false],
        $args,
    );
    $constructors = [];
    foreach ($operations['constructors'] as $name => $c) {
        $constructors[$name] = $defs($c['args'] ?? []);
    }
    $ops = [];
    foreach ($operations['operations'] as $o) {
        $ops[$o['name']] ??= $defs($o['args'] ?? []);
    }

    $bound = array_intersect_key(catalogueFeatures($operations, $fixtures), binds());
    ksort($bound);

    $results = [];
    foreach (['core', 'encode'] as $profile) {
        $files = glob("{$spec}/suite/{$profile}/*.json") ?: [];
        sort($files);
        foreach ($files as $file) {
            // Read as the input model reads it, so that each input reaches its decoder with its
            // lexemes and its member order as the file writes them.
            $cases = InputJson::parse((string) file_get_contents($file));
            foreach ($cases as $c) {
                $observed = runCase($c, new Binder($constructors, $ops), $bound);
                if ($observed !== null) {
                    $results[(string) $c->get('id')] = new JsonObject(['observed' => $observed]);
                }
            }
        }
    }

    $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);
    $result = new JsonObject([
        'format' => 'raoh-runner-result/v1',
        'specification' => new JsonObject([
            'version' => $version,
            'revision' => $args['revision'],
            'manifest_digest' => $args['manifest-digest'],
        ]),
        'implementation' => new JsonObject([
            'name' => 'raoh-php',
            'version' => $composer['extra']['branch-alias']['dev-develop'] ?? 'dev',
            'revision' => $args['implementation-revision'],
        ]),
        'environment' => new JsonObject([
            'language' => 'php',
            'language_version' => PHP_VERSION,
            'os' => PHP_OS_FAMILY,
            'arch' => php_uname('m'),
        ]),
        'bound_features' => array_map('strval', array_keys($bound)),
        'results' => new JsonObject($results),
        'catalogs' => new JsonObject([
            'en' => new JsonObject(Messages::english()->templates()),
            'ja' => new JsonObject(Messages::japanese()->templates()),
        ]),
    ]);
    file_put_contents($args['out'], Json::write($result) . "\n");
}

$options = getopt('', ['spec:', 'revision:', 'manifest-digest:', 'implementation-revision:', 'out:']);
foreach (['spec', 'revision', 'manifest-digest', 'implementation-revision', 'out'] as $name) {
    if (!isset($options[$name]) || !is_string($options[$name])) {
        fwrite(STDERR, "--{$name} is required\n");
        exit(1);
    }
}
/** @var array<string, string> $options */
run($options);
