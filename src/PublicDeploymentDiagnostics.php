<?php

namespace Mariano\GitAutoDeploy;

/**
 * Produces a deliberately tiny, connector-safe view of deployment output.
 * Only application-owned lines prefixed with `[deploy]` are considered.
 */
class PublicDeploymentDiagnostics {
    private const PREFIX = '[deploy]';
    private const MAX_LINES = 8;
    private const MAX_LENGTH = 180;

    public static function fromStatus(array $status): array {
        $failedStep = is_array($status['failed_step'] ?? null) ? $status['failed_step'] : [];
        $output = is_array($failedStep['output'] ?? null) ? $failedStep['output'] : [];
        $diagnostics = [];

        foreach ($output as $line) {
            if (!is_string($line) || strpos($line, self::PREFIX) !== 0) {
                continue;
            }
            $sanitized = preg_replace('/[^A-Za-z0-9 ._\/:=()\[\]-]/', '', $line);
            $sanitized = trim((string) $sanitized);
            if ($sanitized === '' || $sanitized === self::PREFIX) {
                continue;
            }
            $diagnostics[] = substr($sanitized, 0, self::MAX_LENGTH);
        }

        return array_slice(array_values(array_unique($diagnostics)), -self::MAX_LINES);
    }
}
