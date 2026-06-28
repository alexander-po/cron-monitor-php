<?php

declare(strict_types=1);

namespace CronMonitor\Api\Exception;

/**
 * Raised for any HTTP status without a dedicated exception (400 malformed request, 5xx server errors, or an unrecognised status). The concrete catch-all so the abstract base never has to be instantiated.
 *
 * Not `final`: {@see ChannelDeliveryException} narrows the channel-test 502
 * to a more specific type while staying catchable as this parent.
 */
class UnexpectedResponseException extends ApiException
{
}
