<?php

declare(strict_types=1);

namespace CronMonitor\Api\Exception;

/**
 * Raised when the request never produced a usable HTTP response: a request
 * body that could not be encoded as JSON, a PSR-18 transport failure (DNS,
 * connection, TLS, timeout), or a response body that could not be decoded /
 * hydrated into the expected DTO.
 *
 * `$statusCode` is null for genuine transport failures, which chain the
 * PSR-18 client's exception as `$previous`. A body that could not be hydrated
 * chains the hydration error; a body that could not be encoded or decoded as
 * JSON chains nothing and names the JSON error in the message instead.
 */
final class ApiTransportException extends ApiException
{
}
