# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.9.0] - 2026-10-06

Follows the [Raoh Specification](https://github.com/raoh-project/raoh-specification) 0.9 and reads text with [notation-199x](https://github.com/raoh-project/notation-199x) 0.2.0, as Raoh for Java, TypeScript, Go and Rust 0.9.0 do. Checked with the specification's verifier: core, encode, messages-en and messages-ja are all conformant. The repository moved to [raoh-project/raoh-php](https://github.com/raoh-project/raoh-php).

### Added

- The input model: `Raoh\Input\Json::parse()` reads a JSON text keeping every number as written (`JsonNumber`) and every object's members in order (`JsonObject`); `from_json()` uses it
- Decoders `long()` (int64), `double()` (float64), `decimal()`, `dict()`, `object()`, `strict_object()`, `strict()`, `one_of()`, `discriminate()`, `discriminate_by()`, and the generic `refine()`, `nullable()`, `withDefault()`, `recover()` and `recoverWith()` on every decoder
- `Raoh\Value\Decimal`, which keeps its scale; `Raoh\Value\Float32`; the temporal values `LocalDate`, `LocalTime`, `LocalDateTime`, `OffsetDateTime` and `Instant` of `Raoh\Value\Temporal`
- String operations `normalize()`, `uri()`, `cuid()`, `toLong()`, `toDecimal()`, `date()`, `time()`, `dateTime()`, `offsetDateTime()` and `iso8601()`, with `before()`, `after()` and `between()` on the temporal values
- List operations `nonempty()`, `minSize()`, `maxSize()`, `fixedSize()`, `unique()`, `contains()`, `containsAll()` and `toSet()`; the size operations on `dict()`
- `Raoh\Messages`: the specification's English and Japanese catalogues, shipped in `resources/messages/`, usable as a resolver for `Issues::resolve()`
- `Issue::custom()`, `Result::failCustom()`: an issue whose message its maker gives, which resolving leaves alone
- `Issue::withMeta()`: the issue with other metadata, held to the same invariants
- Metadata is documented as `array<array-key, mixed>` wherever it is taken or given, the map Issue accepts, whose names PHP may key as ints; a test holds every PHPDoc of `$meta` to it
- `conformance/` and `scripts/conformance.sh`: the runner of the specification's cases, pinned to its 0.9 release in `conformance/spec.lock`
- CI on every pull request and on pushes to `main` and `develop`: `composer validate`, PHPStan, and the tests on the oldest and the newest PHP without intl, bcmath or gmp, and the specification's cases checked by its verifier
- The `Release` workflow, which checks a commit on `main`, runs CI on it, and only then tags it `vX.Y.Z` and publishes the release notes from this file; the README says how a release is made

### Changed

- `trim()`, `nonBlank()`, `toLowerCase()`, `toUpperCase()` and lengths read text by Unicode 18.0.0 through notation-199x, whatever Unicode version PHP was built with; `trim()` removes every White_Space character, not ASCII whitespace alone; `minLength()` and the others count Unicode scalar values
- `email()`, `ipv4()`, `ipv6()`, `ip()`, `uuid()`, `ulid()` and `url()` follow the definitions of the specification; `uuid()` gives the UUID in lower case, and `url()` no longer checks the port against a range
- Decoding never throws: whatever PHP value a decoder is handed, bad input is an issue. A value the input model has no place for, such as a `DateTime`, an uploaded file left in a request array, a resource, a NaN or infinite float, a string that is not UTF-8 or an array with a key that is not UTF-8, gives `type_mismatch` whose `actual` names what was found (`DateTimeImmutable`, `NAN`, `non-UTF-8 string`)
- A PHP float is read as the canonical decimal of the float64 it is, by the specification's algorithm, whatever `serialize_precision` and `precision` the php.ini sets
- An argument of the wrong type, such as a non-string allowed value of `string_()->oneOf()`, a variant of `discriminate()` that is not a decoder, or a bound of another temporal type, is refused with an `\InvalidArgumentException` when the decoder is built, not when a value is decoded
- Every string the library keeps is UTF-8, as every string of the input model is: a message given to an operation, a field name, a literal, a prefix, a symbol, a variant name, a known field, an issue's code, message key, message and metadata, a path segment, a template and a property name that is not UTF-8 is refused with an `\InvalidArgumentException` where it is given, so that no issue holds what a client cannot write as JSON
- What an issue's metadata may hold is defined once (`Internal\Wire`), and an issue refuses the rest when it is made, rather than json_encode failing when the issues are written: null, bools, ints, floats, `Float32`, UTF-8 strings, `Decimal`, the temporal values, enum cases, presences (written `"absent"`, `"null"` or `{"present": v}`), the issues `one_of_failed` lists, and lists and maps of these, which is every value a decoder of this library gives. A resource, an object of another class and a `Stringable` are refused; `contains()` and `refine()` refuse them when built
- `unique()` lists a duplicate the value model has no place for, such as an object a function given to `map()` made, by its PHP type (`App\Tag`), as a decoder names the kind of an input outside the model, rather than failing to make its issue
- `Messages::fromProperties()` reads the whole properties format as `java.util.Properties` reads it: a line ending in an odd number of backslashes continues on the next, a comment does not continue, `:` and white space separate a key as `=` does, and a malformed `\u` escape is refused
- `refine()` makes its issue when it is built, by `Issue` itself, so that a code, message key, message or metadata an issue refuses is refused then, and not when a value fails the predicate; only metadata a Closure computes from the value waits for the value. A metadata name may be a number, as PHP keys `"404"` as `404`; metadata that is a list names nothing and is refused
- `from_json()` gives `required` for a null or absent input, as every other decoder does, where it gave `type_mismatch`
- A public value type holds its own invariants, whoever makes it: a `JsonObject` refuses a member name given twice (`1` and `"1"` included), an `Issue` a message key that does not refine its code, `Issues::of()` what is not an `Issue`, and an `Err` no issue at all
- `asList()` reads an array as `list_of()` does, giving `required` or `type_mismatch` for other input
- `Issues::toJsonList()` writes a decimal or a temporal value in `meta` as its text, and a float JSON cannot carry as a tag such as `{"float": "-0"}`
- `ErrorCodes` and `MessageKeys` name every code and message key of the specification's catalogue, and `MessageKeys::InvalidFormatJson` the one raoh-php gives of its own; a test holds them to the catalogue

### Breaking

Inputs and values:

- `int_()` decodes int32, and `string_()->toInt()` gives int32 too, with `type_mismatch.numeric_range` outside it; `long()` and `toLong()` give int64. `int_()` no longer reads numeric strings: `"42"` gives `type_mismatch`; read form data with `string_()->toInt()`
- `float_()` decodes float32 and gives a `Raoh\Value\Float32`, and its bounds are float32; `double()` gives a PHP float. Neither reads numeric strings
- `string_()` gives `type_mismatch` for a string that is not UTF-8; `bytes()` still reads any PHP string
- `from_json()` and `JsonDecoders::fromJson()` give their decoder the input model, not what `json_decode` gives: a `JsonObject` for an object, a `JsonNumber` for a number and a list for an array. The decoders of this library read both, but a decoder of your own written to read PHP arrays reads them no longer; its PHPDoc type is `Decoder<mixed, T>` now, not `Decoder<array<string, mixed>, T>`. Text that is not JSON gives `invalid_format.json`, with the parser's `reason` in `meta`, instead of `invalid_format` with the reason in the message

Issues:

- Every message is the catalogue's, so the wording of many changed (`not a valid email address` is `not a valid email`, `expected integer` keeps its words); a message you gave is kept as before
- `type_mismatch` carries `actual`, the kind of input found as the specification names it (`number`, `string`, `null`, `object`, `missing` ...), instead of PHP's `gettype()` (`integer`, `NULL`, ...)
- `field()` gives `type_mismatch` (expected `object`) at the member's path when the input is not an object, where it gave `required`; a member that is not there is given to the field's decoder as absent
- `one_of()` gives one `one_of_failed`, listing each candidate's issues in `meta.candidates`, where it gave the candidates' issues one after another
- `int_()->oneOf()` gives `not_allowed`, where it gave `invalid_value`; the numeric sign checks give their bound (`min` or `max`) besides `actual`

Combinators:

- `recover()` and `withDefault()` take the value itself and no longer call a callable with the issues: `Decoders::recover($d, fn (Issues $i) => 42)` gave 42 and would now give the closure, so a function, a Closure, an object with `__invoke` or an array callable, is refused with an `\InvalidArgumentException`. Use `recoverWith($d, fn (Issues $i) => 42)`. A string is a value even where it names a function: `withDefault('date')` gave what `date()` gave for the issues, and gives `'date'` now
- `Decoders::withDefault()` gives the default for a null or absent input only, and no longer when the inner decoder's issues are all `required`: put it on the field's decoder, `field('a', int_()->withDefault(0))`
- `enum_of()` matches ASCII case-insensitively and gives `invalid_format.enum`; a string-backed enum is matched by its values and any other enum by its case names, so an int-backed enum is matched by name and not by its int value; it also takes a list of symbol names
- `literal()` compares a string, read with a string decoder, and gives `invalid_format.literal`
- `pattern()` takes the pattern language of the specification, matched against the whole string, instead of a PCRE regex with delimiters: drop the delimiters and the anchors, `pattern('[0-9]{3}-[0-9]{4}')`. It no longer takes a `$code`, so its second argument is the message: `pattern($re, 'my_code')` gives the message `my_code` now

Types and classes:

- The built-in decoders (`StringDecoder`, `IntDecoder`, `FloatDecoder`, `BoolDecoder` and the new ones) are made by `string_()`, `int_()` and the other factories; their constructors take the step that runs, not a `Decoder` to wrap, so `new StringDecoder($decoder)` no longer works. Apply the operations of a decoder of your own with `map()`, `flatMap()` or `pipe()`
- They are no longer generic over their input: write `StringDecoder`, not `StringDecoder<mixed>`, in PHPDoc
- An `Issue` whose metadata holds what JSON cannot carry, such as a `DateTime`, a resource or another object, is refused; write such a value as a string or one of the library's values
- An `Issue` whose message key is not its code, or its code, a dot and more, is refused (`Issue::of($p, 'required', 'm', [], 'blank')`), as is an `Err` with no issue, `Result::err(Issues::empty())`
- Removed `StringDecoder::allowBlank()`, `toFloat()` and `toDate($format)` (use `date()`, which reads ISO 8601), and `FloatDecoder::scale()` (use `decimal()->scale()`)
- Requires a 64-bit PHP and `raoh/notation-199x`

The public API is recorded in `tests/public-api.txt`, which a test holds the code to, so that a later change to it shows in review beside its entry here.

### Earlier in this cycle

Error-reporting semantics were brought in line with Raoh 0.8.0 (`code` taxonomy, `messageKey`, resolver fallback — closing [#7](https://github.com/raoh-project/raoh-php/issues/7)):

#### Added

- `Issue::$messageKey`, refining `code` into the specific constraint that failed (defaults to `code`, preserved by `rebase()` and `withCustomMessage()`)
- `MessageKeys` enum with the refined keys for numeric bounds and string formats, matching kawasima/raoh's `MessageKeys` string-for-string
- `Result::failWith()`, distinguishing an explicit `?string $message` (kept as-is, protected from resolver overwrite) from a constraint's builtin default (subject to `messageKey` resolution)

#### Changed

- `Issue::resolve(callable $resolver)`: the resolver may now return `null` to decline (no template for this issue, including one it cannot fully interpolate), in which case resolution falls back from `messageKey` to `code`, and finally to the issue's existing message. A resolver that always returns a `string` still behaves as before.
- All builtin constraints that expose `?string $message = null` (`IntDecoder`, `FloatDecoder`, `StringDecoder`, `BoolDecoder`) now go through `Result::failWith()`, so an explicit custom message is no longer silently replaced when `resolve()` runs.

#### Breaking

- Numeric bound constraints report `out_of_range` instead of `too_small`/`too_big`: `IntDecoder::{min,max,positive,negative,nonNegative,nonPositive}` and `FloatDecoder::{min,max,positive}`. `too_small`/`too_big` remain reserved for collection-size constraints. This aligns the `code` taxonomy with Raoh 0.8.0 and raoh-rust.

## [0.1.0] - 2026-03-14

### Added

- Core types: `Result<T>`, `Ok<T>`, `Err<T>`, `Path`, `Issue`, `Issues`
- `Decoder` interface with `DecoderTrait` providing `map`, `flatMap`, `pipe`, `asList`
- `CallableDecoder` — closure-to-Decoder adapter
- `StaticConstructor` trait — first-class callable shorthand for constructors
- Built-in decoders: `StringDecoder`, `IntDecoder`, `FloatDecoder`, `BoolDecoder` with fluent constraint chains
- `Combiner` — applicative combinator with full error accumulation
- `Decoders` utility: `combine`, `lazy`, `withDefault`, `recover`, `oneOf`, `strict`
- `Presence` tri-state (`Absent`, `PresentNull`, `Present<T>`) for PATCH semantics
- `ErrorCodes` enum with 20 standard error codes
- `Boundary\Array_` module with `use function` API: `field`, `optional_field`, `optional_nullable_field`, `nested`, `list_of`, `nullable`, `combine`, `enum_of`, `literal`
- `Boundary\Json` module: `from_json` wrapping any array decoder to accept raw JSON strings
- Laravel example application demonstrating the library in a real HTTP context
