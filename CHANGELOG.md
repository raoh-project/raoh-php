# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Follows the [Raoh Specification](https://github.com/raoh-project/raoh-specification) 0.9 and reads text with [notation-199x](https://github.com/raoh-project/notation-199x) 0.2.0, as Raoh for Java, TypeScript, Go and Rust 0.9.0 do. Checked with the specification's verifier: core, encode, messages-en and messages-ja are all conformant. Available ahead of a tagged `0.9.0` release via `composer require raoh/raoh:0.9.x-dev`, aliased from the `develop` branch. The repository moved to [raoh-project/raoh-php](https://github.com/raoh-project/raoh-php).

### Added

- The input model: `Raoh\Input\Json::parse()` reads a JSON text keeping every number as written (`JsonNumber`) and every object's members in order (`JsonObject`); `from_json()` uses it
- Decoders `long()` (int64), `double()` (float64), `decimal()`, `dict()`, `object()`, `strict_object()`, `strict()`, `one_of()`, `discriminate()`, `discriminate_by()`, and the generic `refine()`, `nullable()`, `withDefault()`, `recover()` and `recoverWith()` on every decoder
- `Raoh\Value\Decimal`, which keeps its scale; `Raoh\Value\Float32`; the temporal values `LocalDate`, `LocalTime`, `LocalDateTime`, `OffsetDateTime` and `Instant` of `Raoh\Value\Temporal`
- String operations `normalize()`, `uri()`, `cuid()`, `toLong()`, `toDecimal()`, `date()`, `time()`, `dateTime()`, `offsetDateTime()` and `iso8601()`, with `before()`, `after()` and `between()` on the temporal values
- List operations `nonempty()`, `minSize()`, `maxSize()`, `fixedSize()`, `unique()`, `contains()`, `containsAll()` and `toSet()`; the size operations on `dict()`
- `Raoh\Messages`: the specification's English and Japanese catalogues, shipped in `resources/messages/`, usable as a resolver for `Issues::resolve()`
- `Issue::custom()`, `Result::failCustom()`: an issue whose message its maker gives, which resolving leaves alone
- `conformance/` and `scripts/conformance.sh`: the runner of the specification's cases, pinned to its 0.9 release in `conformance/spec.lock`
- CI on every pull request and on pushes to `main` and `develop`: `composer validate`, PHPStan, and the tests on the oldest and the newest PHP without intl, bcmath or gmp, and the specification's cases checked by its verifier
- The `Release` workflow, which checks a commit on `main`, runs CI on it, and only then tags it `vX.Y.Z` and publishes the release notes from this file; the README says how a release is made

### Changed

- `trim()`, `nonBlank()`, `toLowerCase()`, `toUpperCase()` and lengths read text by Unicode 18.0.0 through notation-199x, whatever Unicode version PHP was built with; `minLength()` and the others count Unicode scalar values
- `email()`, `ipv4()`, `ipv6()`, `ip()`, `uuid()`, `ulid()` and `url()` follow the definitions of the specification; `uuid()` gives the UUID in lower case, and `url()` no longer checks the port against a range
- Every issue's message is derived from the English catalogue when it is made, with the message forms of the specification (`must be at least 0.1` for a float32 bound)
- `field()` gives a member that is not there to its decoder as absent, and gives `type_mismatch` (expected `object`) when the input is not an object; `type_mismatch` carries `actual`, the kind of input found
- `one_of()` gives `one_of_failed` listing each candidate's issues in `meta.candidates`
- `withDefault()` gives the default for a null or absent input only; a failure of the inner decoder is given as it is
- A PHP value the input model has no place for, such as a `DateTime`, an uploaded file left in a request array, or a NaN or infinite float, gives `type_mismatch` whose `actual` names its PHP type (`DateTimeImmutable`, `NAN`); a PHP float is read as its shortest text whatever `serialize_precision` is
- `Issues::toJsonList()` writes a decimal or a temporal value in `meta` as its text, and a float JSON cannot carry as a tag such as `{"float": "-0"}`

### Breaking

- `int_()` decodes int32 and no longer reads numeric strings: `"42"` gives `type_mismatch`; read form data with `string_()->toInt()`
- `float_()` decodes float32 and gives a `Raoh\Value\Float32`; `double()` gives a PHP float. Neither reads numeric strings
- `pattern()` takes the pattern language of the specification, matched against the whole string, instead of a PCRE regex with delimiters, and no longer takes a `$code`
- `enum_of()` matches ASCII case-insensitively and gives `invalid_format.enum`; a string-backed enum is matched by its values, any other enum by its case names; it also takes a list of symbol names
- `literal()` compares a string and gives `invalid_format.literal`
- `Decoders::withDefault()` no longer falls back when the inner decoder's issues are all `required`: put it on the field's decoder, `field('a', int_()->withDefault(0))`
- Removed `StringDecoder::allowBlank()`, `toFloat()` and `toDate($format)` (use `date()`), and `FloatDecoder::scale()` (use `decimal()->scale()`)
- Requires a 64-bit PHP and `raoh/notation-199x`

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
