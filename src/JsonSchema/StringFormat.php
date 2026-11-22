<?php

declare(strict_types=1);

namespace Restate\JsonSchema;

enum StringFormat: string
{
    case DateTime = 'date-time';
    case Date = 'date';
    case Time = 'time';
    case Duration = 'duration';
    case Email = 'email';
    case IdnEmail = 'idn-email';
    case Hostname = 'hostname';
    case IdnHostname = 'idn-hostname';
    case Ipv4 = 'ipv4';
    case Ipv6 = 'ipv6';
    case Uri = 'uri';
    case UriReference = 'uri-reference';
    case Iri = 'iri';
    case IriReference = 'iri-reference';
    case UriTemplate = 'uri-template';
    case Uuid = 'uuid';
    case JsonPointer = 'json-pointer';
    case RelativeJsonPointer = 'relative-json-pointer';
    case Regex = 'regex';
}
