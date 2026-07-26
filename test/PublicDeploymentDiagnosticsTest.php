<?php

namespace Mariano\GitAutoDeploy\Test;

use Mariano\GitAutoDeploy\PublicDeploymentDiagnostics;
use PHPUnit\Framework\TestCase;

class PublicDeploymentDiagnosticsTest extends TestCase {
    public function testOnlyDeployPrefixedLinesAreReturned(): void {
        $diagnostics = PublicDeploymentDiagnostics::fromStatus([
            'failed_step' => [
                'output' => [
                    'AUTHENTIK_API_TOKEN=secret-value',
                    'docker login password=secret',
                    '[deploy] missing AUTHENTIK_API_TOKEN in host .env',
                    '[deploy] readiness failed',
                ],
            ],
        ]);

        $this->assertSame([
            '[deploy] missing AUTHENTIK_API_TOKEN in host .env',
            '[deploy] readiness failed',
        ], $diagnostics);
        $this->assertStringNotContainsString('secret', implode(' ', $diagnostics));
    }

    public function testDiagnosticsAreBoundedDeduplicatedAndSanitized(): void {
        $output = array_fill(0, 20, '[deploy] failure <unsafe> token=$SECRET');
        $diagnostics = PublicDeploymentDiagnostics::fromStatus(['failed_step' => ['output' => $output]]);

        $this->assertCount(1, $diagnostics);
        $this->assertSame('[deploy] failure unsafe token=SECRET', $diagnostics[0]);
        $this->assertLessThanOrEqual(180, strlen($diagnostics[0]));
    }

    public function testNoFailureOutputProducesNoDiagnostics(): void {
        $this->assertSame([], PublicDeploymentDiagnostics::fromStatus([]));
    }
}
