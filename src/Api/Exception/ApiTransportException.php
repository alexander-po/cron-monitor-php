<?php

declare(strict_types=1);

namespace CronMonitor\Api\Exception;

/**
 * Raised when the request never produced a usable HTTP response: a PSR-18
 * transport failure (DNS, connection, TLS, timeout) or a response body
 * that could not be decoded / hydrated into the expected DTO.
 *
 * `$statusCode` is null for genuine transport failures. When a response
 * arrived but its body was unparseable, the originating exception is
 * attached as `$previous`.
 */
final class ApiTransportException extends ApiException
{
}
