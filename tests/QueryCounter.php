<?php

namespace Ovrflo\JitHydrator\Tests;

use Psr\Log\AbstractLogger;

/**
 * Counts SQL statements executed against a connection, via DBAL's logging
 * middleware. Used to assert that accessing an unloaded field on a partial
 * lazy ghost triggers exactly one extra SELECT - a plain value assertion
 * can't tell a genuine lazy (re)load apart from data that was already
 * fetched eagerly.
 */
class QueryCounter extends AbstractLogger
{
    public int $count = 0;

    public function log($level, $message, array $context = []): void
    {
        if (isset($context['sql'])) {
            $this->count++;
        }
    }
}
