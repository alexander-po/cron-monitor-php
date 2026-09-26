<?php

declare(strict_types=1);

namespace CronMonitor\Api\Exception;

/**
 * The HTTP 410 that ends a terminal signup, raised by
 * {@see \CronMonitor\Api\MonitorApiClient::pollSignupToken()}: the request is
 * unknown, expired, cancelled or already claimed, and polling again never
 * succeeds. `$detail` carries the backend's recovery advice. A subclass of
 * {@see UnexpectedResponseException}, like {@see ChannelDeliveryException}, so
 * existing catches keep working; a 410 from any other endpoint stays the parent.
 */
final class SignupExpiredException extends UnexpectedResponseException
{
}
