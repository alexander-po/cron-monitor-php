<?php

declare(strict_types=1);

namespace CronMonitor\Api\Exception;

/**
 * Raised when a channel test send reaches the backend cleanly but the
 * downstream destination rejects or fails to receive the delivery — the
 * HTTP 502 that {@see \CronMonitor\Api\MonitorApiClient::testChannel()}
 * answers with.
 *
 * It subclasses {@see UnexpectedResponseException} on purpose: that 502 was
 * already surfaced as `UnexpectedResponseException` before this type
 * existed, so code that catches the parent (or {@see ApiException}) keeps
 * working unchanged — this is a strictly additive narrowing, not a breaking
 * change. Catch this narrower type to tell "the destination rejected the
 * test delivery" apart from any other bad gateway without inspecting
 * `$statusCode`.
 *
 * Only `testChannel()` raises it; a 502 from any other endpoint stays a
 * plain {@see UnexpectedResponseException}, because elsewhere the status
 * carries no channel-delivery meaning.
 */
final class ChannelDeliveryException extends UnexpectedResponseException
{
}
