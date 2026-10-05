# raoh-php

[![License](https://img.shields.io/github/license/raoh-project/raoh-php)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)

PHP implementation of [Raoh](https://github.com/raoh-project/raoh-specification) — a decoder library for turning untyped boundary input into typed domain values. It is checked against the [Raoh Specification](https://github.com/raoh-project/raoh-specification), as Raoh for Java, TypeScript, Go and Rust are, so the same input gives the same values and the same issues in all of them.

It is built around a parse-don't-validate approach:

- decode at the boundary
- keep invalid states out of the domain model
- return failures as values instead of throwing
- attach structured errors to precise paths

raoh-php is closer to a parser/decoder library than to a traditional validation library.

If you are coming from a validator-oriented library, the main difference in feel is this:

- you do not validate an already-constructed domain object
- you decode raw input into a domain object
- object construction happens only after decoding succeeds

## Requirements

- 64-bit PHP 8.2+
- Composer

raoh-php depends on [`raoh/notation-199x`](https://github.com/raoh-project/notation-199x), which reads text by the rules Raoh and Souther share: the Unicode 18.0.0 `White_Space` set, case mapping and normalization, lengths in Unicode scalar values, the pattern language of the specification, and the grammar of dates and times. PHP's own `trim`, `mb_strtolower`, `Normalizer` and PCRE answer by the Unicode version and the libraries PHP was built with, so a string read on one server would be read otherwise on another. Neither package needs a PHP extension: not mbstring, intl, bcmath or gmp.

Install and run tests:

```bash
composer install
./vendor/bin/phpunit
```

## Package Layout

```
src/
├── Result.php, Ok.php, Err.php   # the result of decoding
├── Path.php                      # JSON Pointer path (immutable cons-list)
├── Issue.php, Issues.php         # an issue (path, code, messageKey, message, meta), and a list of them
├── Messages.php                  # the English and Japanese message catalogues
├── Decoder.php, DecoderTrait.php # interface Decoder; map / flatMap / refine / nullable / withDefault / recover
├── Decoders.php                  # every constructor and combinator
├── Absent.php, PresentNull.php, Present.php   # tri-state presence
├── Input/                        # the input model: Json::parse, JsonNumber, JsonObject
├── Value/                        # Decimal, Float32, and Temporal/ (LocalDate, LocalTime, ...)
├── Builtin/                      # StringDecoder, IntDecoder, LongDecoder, FloatDecoder, DoubleDecoder,
│                                 # DecimalDecoder, BoolDecoder, TemporalDecoder, ListDecoder, DictDecoder,
│                                 # ObjectDecoder
├── Field/                        # Field, OptionalField, OptionalNullableField
├── Combinator/Combiner.php       # combine(...)->map(fn(...$values))
└── Boundary/
    ├── Array_/                   # use function imports, and Encode/
    └── Json/                     # from_json and use function imports, and Encode/
resources/messages/               # en.properties, ja.properties
conformance/                      # the runner of the specification's cases
```

## Core Model

### `Result`

Decoding returns a value instead of throwing:

- `Ok` for success
- `Err` for failure

`Result` supports:

- `map(...)`
- `flatMap(...)`
- `fold(...)`
- `getOrThrow()`
- `orElseThrow(...)`
- `Result::map2(...)` — applicative combination of two results
- `Result::traverse(...)` — list traversal with full error accumulation

### `Issue`, `Issues`, and `Path`

Each error includes:

- `path`
- `code`, the class of problem, such as `out_of_range`
- `messageKey`, the kind of problem within its class, such as `out_of_range.minimum`
- `message`
- `meta`, such as the bound that was not met

The codes, message keys and metadata are those of the Raoh Specification. A message is derived from the English catalogue when the issue is made, unless one was given: every operation that checks something takes an optional last argument, the message to give in place of the catalogue's. `$issues->resolve(Messages::japanese())` writes every derived message from the Japanese catalogue, and leaves given ones as they are.

Paths use JSON Pointer notation (RFC 6901), for example:

- `/email`
- `/address/city`
- `/items/0/name`

`Issues` can be merged, rebased, flattened, formatted, or converted to JSON-like data.

### `Decoder`

The core abstraction is:

```php
interface Decoder {
    public function decode(mixed $in, ?Path $path = null): Result;
}
```

A decoder reads an input value and produces either:

- a typed value wrapped in `Ok`
- structured issues wrapped in `Err`

A decoder reads the input model of the specification: what a JSON text denotes, with every number kept as it is written. `Raoh\Input\Json::parse($text)` reads a JSON text into it, every number a `JsonNumber` holding its lexeme and every object a `JsonObject` holding its members in order; `from_json($decoder)` does the same for a decoder. `json_decode` cannot be used for this: it turns `1.50` into 1.5, `-0` into 0, and an integer past 2⁶³ into a float, and it gives `{}` and `[]` as the same PHP value.

A decoder also reads the PHP values an application already has: an associative array or a `stdClass` is an object, a list is an array, and an int or a float is a number, read as the number it already is. `[]` is both an empty object and an empty array, since PHP does not tell them apart. A string is a string: `int_()` rejects `"42"`, and form data is read with `string_()->toInt()`.

The values decoders give are a PHP `int` for `int_()` (int32) and `long()` (int64), a PHP `float` for `double()`, a `Raoh\Value\Float32` for `float_()` (PHP has no float32 of its own), a `Raoh\Value\Decimal` for `decimal()`, which keeps the scale it was written with, and the `LocalDate`, `LocalTime`, `LocalDateTime`, `OffsetDateTime` and `Instant` of `Raoh\Value\Temporal` for the temporal operations of a string.

Two boundary modules give the same decoders as functions:

- `Raoh\Boundary\Array_` — PHP arrays and form data
- `Raoh\Boundary\Json` — raw JSON strings

### `Encoder`

The symmetric counterpart to `Decoder` is:

```php
interface Encoder {
    public function encode(mixed $value): mixed;
    public function contramap(callable $f): Encoder;
    public function andThen(Encoder $next): Encoder;
}
```

An encoder converts a trusted domain object into an external representation (array, JSON string, etc.) and never fails.

- `contramap($f)` — pre-process the input before encoding (useful for unwrapping value objects)
- `andThen($next)` — post-process the output after encoding (useful for chaining transformations)

## What It Feels Like

The normal raoh-php workflow looks like this:

1. Start from raw input such as a JSON string or PHP array.
2. Define small decoders for domain primitives such as `Email`, `Age`, or `UserId`.
3. Combine them into object decoders.
4. If decoding succeeds, you get a fully-typed value.
5. If decoding fails, you get structured issues with paths.

That means the "happy path" looks like object construction, while the failure path looks like machine-readable diagnostics.

## Quick Start

### Decode an array into a domain object

```php
<?php

use function Raoh\Boundary\Array_\{field, string_, int_, combine};

class Email
{
    public function __construct(public readonly string $value) {}
}

class Age
{
    public function __construct(public readonly int $value) {}
}

class User
{
    use \Raoh\StaticConstructor;

    public function __construct(
        public readonly Email $email,
        public readonly Age   $age,
    ) {}
}

function emailDecoder(): \Raoh\Decoder {
    return string_()->trim()->toLowerCase()->email()
        ->map(fn($v) => new Email($v));
}

function ageDecoder(): \Raoh\Decoder {
    return int_()->range(0, 150)
        ->map(fn($v) => new Age($v));
}

function userDecoder(): \Raoh\Decoder {
    return combine(
        field('email', emailDecoder()),
        field('age',   ageDecoder()),
    )->map(User::of(...));
}
```

Use it like this:

```php
$result = userDecoder()->decode($_POST);
```

Success case:

```php
$result->fold(
    fn(User $user)        => saveUser($user),
    fn(\Raoh\Issues $errs) => respond(422, $errs->toJsonList()),
);
```

Example failure shape:

```json
[
  { "path": "/email", "code": "invalid_format", "message": "not a valid email", "meta": {} }
]
```

### Decode a JSON string

```php
<?php

use function Raoh\Boundary\Json\{field, string_, int_, combine, from_json};

$dec = from_json(combine(
    field('host', string_()->nonBlank()),
    field('port', int_()->range(1, 65535)),
)->map(fn($host, $port) => new Config($host, $port)));

$result = $dec->decode('{"host":"localhost","port":5432}');
```

This is useful for:

- HTTP request bodies
- webhook payloads
- configuration files

## Built-in Decoders

Every operation that checks something takes an optional last argument, the message to give in place of the catalogue's.

### Strings

`string_()` gives a `StringDecoder`:

- transforms: `trim()`, `toLowerCase()`, `toUpperCase()`, `normalize($form = 'NFC')` — by Unicode 18.0.0, with no language tailoring
- checks: `nonBlank()`, `minLength(...)`, `maxLength(...)`, `fixedLength(...)` (lengths in Unicode scalar values), `oneOf([...])`, `startsWith(...)`, `endsWith(...)`, `includes(...)`, `pattern(...)`
- formats: `email()`, `ipv4()`, `ipv6()`, `ip()`, `ulid()`, `cuid()`, `uuid()` (gives the UUID in lower case), `url()`, `uri()`
- conversions: `toInt()`, `toLong()`, `toDecimal()`, `toBool()`, and `date()`, `time()`, `dateTime()`, `offsetDateTime()`, `iso8601()` (an instant), which give a `TemporalDecoder` with `before(...)`, `after(...)` and `between(...)`

`pattern(...)` takes the pattern language of the specification ([pattern.md](https://github.com/raoh-project/raoh-specification/blob/main/spec/pattern.md)), not PCRE's: it is matched against the whole string, with no delimiters and no flags, in time linear in the string. `pattern('[0-9]{3}-[0-9]{4}')`. A pattern the language does not have, such as a lookahead or a back reference, is refused with an `\InvalidArgumentException`.

### Numbers

`int_()` (int32), `long()` (int64), `float_()` (float32), `double()` (float64) and `decimal()` give decoders with `min(...)`, `max(...)`, `range(...)`, `positive()`, `negative()`, `nonNegative()` and `nonPositive()`. The integers and floats have `oneOf([...])`; the integers and `decimal()` have `multipleOf(...)`; `decimal()` has `scale(...)`.

An integer decoder accepts a number written as an integer, with no fraction and no exponent: `1` but not `1.0`. Floats are compared in the float order of the value model, in which -0 is less than +0, so `negative()` accepts -0. Decimals are compared by value: `1.5` and `1.50` are equal for `min(...)`, and different decimals.

### Booleans

`bool_()` gives a `BoolDecoder` with `isTrue()` and `isFalse()`.

### Lists and dictionaries

`list_of($dec)` gives a `ListDecoder` with `nonempty()`, `minSize(...)`, `maxSize(...)`, `fixedSize(...)`, `unique()`, `contains(...)`, `containsAll([...])` and `toSet()`. `dict($dec)` decodes every member of an object and gives a PHP array keyed by member name, with `nonempty()`, `minSize(...)`, `maxSize(...)` and `fixedSize(...)`.

## Object Decoding

raoh-php distinguishes these cases:

- `field($name, $dec)` — required field: a missing member is given to `$dec` as absent, which most decoders report as `required`
- `optional_field($name, $dec)` — a missing member, or an input that is not an object, gives `null`
- `nullable($dec)` — `null` value is allowed
- `optional_nullable_field($name, $dec)` — tri-state presence

`object(...$fields)` gives the fields' values as a list, and `combine(...$fields)->map(fn ($a, $b) => ...)` spreads them into a function. A decoder that is not a field reads the whole input, as a flat field does in the specification.

Tri-state presence returns one of:

- `Absent` — field not present in the input
- `PresentNull` — field explicitly set to null
- `Present` — field present with a value

This distinction matters when "missing" and "explicitly null" have different meanings, which often comes up in PATCH-style APIs:

```php
$dec = optional_nullable_field('nickname', string_());
// Absent:       don't update
// PresentNull:  clear the existing value
// Present:      set to the new value
```

## A More Realistic Example

```php
<?php

use Raoh\Issues;
use Raoh\Path;
use Raoh\Result;
use function Raoh\Boundary\Array_\{field, string_, double, combine, enum_of, nested};

enum Currency: string
{
    case JPY = 'JPY';
    case USD = 'USD';
}

class Money
{
    private function __construct(
        public readonly float    $amount,
        public readonly Currency $currency,
    ) {}

    public static function parse(float $amount, Currency $currency): Result
    {
        if ($amount <= 0) {
            return Result::fail(Path::root(), 'out_of_range', 'amount must be positive');
        }
        return Result::ok(new self($amount, $currency));
    }
}

class User
{
    use \Raoh\StaticConstructor;

    public function __construct(
        public readonly string $email,
        public readonly Money  $balance,
    ) {}
}

function moneyDecoder(): \Raoh\Decoder {
    return combine(
        field('amount',   double()->positive()),
        field('currency', enum_of(Currency::class)),
    )->flatMap(Money::parse(...));
}

function userDecoder(): \Raoh\Decoder {
    return combine(
        field('email',   string_()->trim()->toLowerCase()->email()),
        field('balance', nested(moneyDecoder())),
    )->map(User::of(...));
}

$result = userDecoder()->decode($input);

$result->fold(
    fn(User $user)   => saveUser($user),
    fn(Issues $errs) => respond(422, $errs->toJsonList()),
);
```

This reads naturally as:

- "read `email` as a trimmed lowercased email"
- "read `balance` structurally, then apply domain rules"
- "construct `User` only if everything succeeded"

## Composition Patterns

raoh-php offers four distinct composition patterns.

### `combine(...)->map(...)`

All fields decoded independently; errors accumulate:

```php
combine(
    field('email', string_()->email()),
    field('age',   int_()->range(0, 150)),
)->map(fn($email, $age) => new User($email, $age));
```

### `combine(...)->flatMap(...)`

All fields decoded first, then a second step runs that can also fail — useful for cross-field validation:

```php
combine(
    field('password',        string_()->minLength(8)),
    field('passwordConfirm', string_()),
)->flatMap(function ($pw, $confirm) {
    if ($pw !== $confirm) {
        return Result::fail(Path::of('passwordConfirm'), 'invalid_value', 'passwords do not match');
    }
    return Result::ok(['password' => $pw]);
});
```

### `Result::map2(...)`

Applicative combination of exactly two results:

```php
$result = Result::map2(
    $emailResult,
    $ageResult,
    fn($email, $age) => new User($email, $age),
);
```

### `Result::traverse(...)`

Decode every element in a list and accumulate all errors:

```php
$result = Result::traverse($items, fn($item) => itemDecoder()->decode($item));
```

or use `list_of(...)` which wraps this:

```php
field('tags', list_of(string_()->nonBlank()))
```

## Error Accumulation

Given this decoder:

```php
$dec = combine(
    field('email', string_()->email()),
    field('age',   int_()->range(0, 150)),
)->map(fn($email, $age) => ['email' => $email, 'age' => $age]);
```

And this input:

```php
['email' => 'not-an-email', 'age' => 300]
```

raoh-php returns both issues:

```php
$err->issues->flatten();
// [
//   '/email' => ['not a valid email'],
//   '/age'   => ['must be between 0 and 150'],
// ]
```

## Utility Combinators

The `Decoders` class and boundary functions provide reusable combinators.

- `Decoders::lazy(callable $fn)` — for recursive decoders
- `Decoders::withDefault(Decoder $dec, mixed $default)`, `$dec->withDefault($default)` — the default for a null or absent input
- `Decoders::recover(Decoder $dec, mixed $fallback)`, `$dec->recover($fallback)` — the fallback for any decoding failure; `recoverWith(fn (Issues $issues) => ...)` computes it
- `Decoders::oneOf(Decoder ...$candidates)` — the first candidate that succeeds; `one_of_failed` with each candidate's issues if all fail
- `discriminate($field, ['circle' => $circle, 'square' => $square])` — the variant the tag member names; `discriminate_by($field, $tag, $variants)` reads the tag with a decoder of its own
- `enum_of(['RED', 'GREEN'])` or `enum_of(Color::class)` — one of the symbols, ASCII case-insensitively: a string-backed enum's symbols are its values, any other enum's its case names
- `literal('v1')` — exactly that string
- `$dec->refine($predicate, $code, $message)` — an issue of your own when the predicate does not hold

### `strict(...)`

Reject unknown fields:

```php
strict_object(
    field('name', string_()),
    field('age',  int_()),
);

combine(
    field('name', string_()),
    field('age',  int_()),
)->strict(fn($name, $age) => new Person($name, $age));
```

`strict($dec, ['name', 'age'])` does the same around any decoder, such as a `discriminate`.

### `lazy(...)`

For recursive structures:

```php
use Raoh\Decoders;

// An arrow function would capture $commentDecoder while it is still null, so take it by reference.
$commentDecoder = null;
$commentDecoder = combine(
    field('body',    string_()->nonBlank()),
    field('replies', list_of(Decoders::lazy(function () use (&$commentDecoder) {
        return $commentDecoder;
    }))->withDefault([])),
)->map(fn($body, $replies) => new Comment($body, $replies));
```

### `discriminate(...)` and `one_of(...)`

For discriminated union decoding:

```php
$contactDecoder = discriminate('kind', [
    'email' => combine(field('value', string_()->email()))->map(fn($v) => new EmailContact($v)),
    'phone' => combine(field('value', string_()->pattern('[0-9]+')))->map(fn($v) => new PhoneContact($v)),
]);
```

A tag that names no variant gives `not_allowed` at `/kind`. When there is no tag, `one_of(...)` tries each candidate in turn; if all fail, `one_of_failed` is returned with candidate-specific errors in `meta.candidates`.

### `withDefault(...)` vs `recover(...)`

Use `withDefault(...)` when a value is conceptually optional and you want a fallback for a missing or null value. It goes on the field's decoder, which is what sees the missing member:

```php
field('role', enum_of(Role::class)->withDefault(Role::Member))
```

Use `recover(...)` when you want to tolerate any decoding failure:

```php
field('pageSize', int_()->range(1, 100)->recover(20))
```

`recover(...)` is more permissive. `withDefault(...)` is stricter: a value of the wrong kind is still reported.

## `StaticConstructor` Trait

PHP does not support `new ClassName(...)` as a first-class callable. The `StaticConstructor` trait bridges this gap:

```php
class User
{
    use \Raoh\StaticConstructor;

    public function __construct(
        public readonly string $email,
        public readonly int    $age,
    ) {}
}

// enables this syntax:
combine(
    field('email', string_()->email()),
    field('age',   int_()->range(0, 150)),
)->map(User::of(...));
```

Without the trait, use a closure:

```php
)->map(fn($email, $age) => new User($email, $age));
```

## Boundary Modules

raoh-php ships two boundary modules for different input types.

### `Raoh\Boundary\Array_`

For PHP arrays (form data, deserialized YAML, framework request objects, etc.):

```php
use function Raoh\Boundary\Array_\{field, string_, int_, long, float_, double, decimal, bool_,
    object, strict_object, strict, combine, optional_field, optional_nullable_field, nullable,
    nested, list_of, dict, one_of, discriminate, discriminate_by, enum_of, literal, bytes};
```

### `Raoh\Boundary\Json`

For raw JSON strings (HTTP bodies, webhook payloads, config files):

```php
use function Raoh\Boundary\Json\{from_json, field, string_, int_, float_, bool_, combine,
    optional_field, nullable, nested, list_of};
```

`from_json($dec)` wraps any decoder to accept a raw JSON string as input. It reads the text with `Raoh\Input\Json::parse()`, which keeps every number as it is written; text that is not JSON gives `invalid_format`.

The Json boundary exposes a subset of the Array_ helpers. The decoders are the same, so use the others from `Raoh\Boundary\Array_` or `Raoh\Decoders` inside a `from_json()` decoder.

### `Raoh\Boundary\Array_\Encode` (Encoder)

Converts domain objects to PHP arrays suitable for DB inserts, framework responses, or further serialization:

```php
use function Raoh\Boundary\Array_\Encode\{string_, int_, float_, bool_,
    date_, date_time_, enum_of, nullable, with_default,
    property, object_, nested, list_};
```

| Function | Purpose |
| -------- | ------- |
| `string_()`, `int_()`, `float_()`, `bool_()` | Primitive pass-through encoders |
| `date_()` | `DateTimeInterface` → `'Y-m-d'` string |
| `date_time_()` | `DateTimeInterface` → ISO-8601 string |
| `enum_of()` | `BackedEnum` → backing value; pure enum → case name |
| `nullable($enc)` | Passes `null` through; delegates non-null to `$enc` |
| `with_default($enc, $default)` | Encodes `$default` when input is `null` |
| `property($key, $getter, $enc)` | Binds a map key, a getter, and a value encoder |
| `object_(...$props)` | Domain object → `array<string, mixed>` |
| `nested($enc)` | Marks an object encoder as a nested value (intent signal) |
| `list_($enc)` | Encodes every element of a list |

**Example — domain object to array:**

```php
use function Raoh\Boundary\Array_\Encode\{object_, property, string_, int_};

$userEncoder = object_(
    property('id',    fn(User $u): string => $u->id,    string_()),
    property('email', fn(User $u): string => $u->email, string_()),
    property('age',   fn(User $u): int    => $u->age,   int_()),
);

$row = $userEncoder->encode($user);
// ['id' => '...', 'email' => '...', 'age' => 30]
```

**`contramap` — unwrapping value objects:**

```php
$idEncoder = string_()->contramap(fn(UserId $id): string => $id->value);
```

### `Raoh\Boundary\Json\Encode` (Encoder)

Converts domain objects directly to a JSON string:

```php
use function Raoh\Boundary\Json\Encode\to_json;

$encode = to_json($userEncoder);
$json = $encode->encode($user);
// '{"id":"...","email":"...","age":30}'
```

`to_json($enc)` returns an `Encoder<mixed, string>` and uses `JSON_THROW_ON_ERROR` so encoding failures throw a `\JsonException` rather than returning silently.

## Error Handling

Use `fold()`:

```php
$result->fold(
    fn(User $user)     => saveUser($user),
    fn(Issues $issues) => respond(422, $issues->toJsonList()),
);
```

Or use `instanceof`:

```php
if ($result instanceof \Raoh\Ok) {
    $user = $result->value;
} else {
    $issues = $result->issues;
}
```

Useful helpers on `Issues`:

- `flatten()` — path-keyed list of messages, convenient for form-like UIs
- `format()` — nested structure with `_errors` keys
- `toJsonList()` — flat list of `{path, code, message, meta}` objects, convenient for APIs; a decimal or a temporal value in `meta` is written as its text, and a float JSON cannot carry (-0, NaN, ±Infinity) as a tag such as `{"float": "-0"}`
- `toArray()` — access the raw list of `Issue` objects

## Supported Usage Patterns

The current implementation covers:

- decoding nested objects
- decoding lists
- optional, nullable, and tri-state fields
- custom constraints via `flatMap`
- cross-field validation
- defaults and recovery
- strict mode
- recursive decoders
- discriminated variants
- single-value decoding
- constructor shorthand via `StaticConstructor`

Examples:

**Nested object decoding:**

```php
combine(
    field('name',    string_()),
    field('address', nested(addressDecoder())),
)->map(fn($name, $address) => new User($name, $address));
```

**Cross-field validation:**

```php
combine(
    field('start', int_()),
    field('end',   int_()),
)->flatMap(function ($start, $end) {
    if ($start >= $end) {
        return Result::fail(Path::of('end'), 'invalid_value', 'end must be after start');
    }
    return Result::ok(new Period($start, $end));
});
```

**Defaults:**

```php
field('role', Decoders::withDefault(enum_of(Role::class), Role::Member))
```

**Strict mode:**

```php
combine(
    field('id',    string_()->uuid()),
    field('email', string_()->email()),
    field('age',   int_()->range(0, 150)),
)->strict(fn($id, $email, $age) => new User($id, $email, $age));
```

**Single value decoding:**

```php
$result = string_()->email()->decode($input);
```

## Conformance

raoh-php is checked against the [Raoh Specification](https://github.com/raoh-project/raoh-specification) at the commit `conformance/spec.lock` pins, with the verifier of that commit:

```sh
scripts/conformance.sh
```

Raoh Specification 0.9 — core: conformant; encode: conformant; messages-en: conformant; messages-ja: conformant.

The script needs git, jq and Go besides PHP. `RAOH_SPECIFICATION_DIR` names a checkout of the specification to use instead of cloning one; it has to be at the pinned commit.

## Design Direction

The intended workflow is:

1. Read dirty external input at the boundary.
2. Decode it into domain values.
3. Either get a fully-typed object or a structured error value.

This avoids passing partially-valid data deeper into the application and keeps the domain model focused on valid states.
