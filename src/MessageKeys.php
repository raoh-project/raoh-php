<?php

declare(strict_types=1);

namespace Raoh;

/**
 * Refines an {@see ErrorCodes} value into the constraint that actually failed.
 *
 * `code` classifies a failure for a program to branch on; `messageKey` says which
 * wording describes it. Several constraints share one code — `positive()`, `min()`
 * and `range()` all report `out_of_range` — but no single sentence fits all three,
 * and their metadata does not carry the same placeholders. Every key here is the
 * code it refines, a dot, and a qualifier.
 *
 * An issue's `messageKey` is either one of these or, where nothing refines the code,
 * the code itself, one of {@see ErrorCodes}. The cases are the dotted keys of the
 * Raoh Specification's issue catalogue (catalog/issues.json), and the keys raoh-php
 * gives of its own; tests/CatalogueEnumTest.php holds them to the catalogue.
 */
enum MessageKeys: string
{
    case TypeMismatchStringKeys      = 'type_mismatch.string_keys';
    case TypeMismatchNumericRange    = 'type_mismatch.numeric_range';
    case OutOfRangeMinimum           = 'out_of_range.minimum';
    case OutOfRangeMaximum           = 'out_of_range.maximum';
    case OutOfRangeRange             = 'out_of_range.range';
    case OutOfRangePositive          = 'out_of_range.positive';
    case OutOfRangeNonNegative       = 'out_of_range.non_negative';
    case OutOfRangeNegative          = 'out_of_range.negative';
    case OutOfRangeNonPositive       = 'out_of_range.non_positive';
    case OutOfRangeBefore            = 'out_of_range.before';
    case OutOfRangeAfter             = 'out_of_range.after';
    case OutOfRangeBetween           = 'out_of_range.between';
    case TooSmallNonempty            = 'too_small.nonempty';
    case InvalidFormatEmail          = 'invalid_format.email';
    case InvalidFormatUrl            = 'invalid_format.url';
    case InvalidFormatUri            = 'invalid_format.uri';
    case InvalidFormatUuid           = 'invalid_format.uuid';
    case InvalidFormatIp             = 'invalid_format.ip';
    case InvalidFormatIpv4           = 'invalid_format.ipv4';
    case InvalidFormatIpv6           = 'invalid_format.ipv6';
    case InvalidFormatUlid           = 'invalid_format.ulid';
    case InvalidFormatCuid           = 'invalid_format.cuid';
    case InvalidFormatStartsWith     = 'invalid_format.starts_with';
    case InvalidFormatEndsWith       = 'invalid_format.ends_with';
    case InvalidFormatIncludes       = 'invalid_format.includes';
    case InvalidFormatEnum           = 'invalid_format.enum';
    case InvalidFormatLiteral        = 'invalid_format.literal';
    case InvalidFormatInstant        = 'invalid_format.instant';
    case InvalidFormatDate           = 'invalid_format.date';
    case InvalidFormatTime           = 'invalid_format.time';
    case InvalidFormatDateTime       = 'invalid_format.date_time';
    case InvalidFormatOffsetDateTime = 'invalid_format.offset_date_time';

    // raoh-php's own: from_json() given text that is not JSON.
    case InvalidFormatJson           = 'invalid_format.json';
}
