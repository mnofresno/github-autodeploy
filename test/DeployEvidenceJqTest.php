<?php

namespace Mariano\GitAutoDeploy\Test;

use PHPUnit\Framework\TestCase;

class DeployEvidenceJqTest extends TestCase {
    public function testEvidenceFilterCompilesOnRunnerJq(): void {
        $jq = trim((string) shell_exec('command -v jq 2>/dev/null'));
        if ($jq === '') {
            $this->markTestSkipped('jq is not installed in this environment');
        }

        $filter = '{repository:$repository,commit_sha:$commit_sha,status:$status,run_id:(if $run_id=="" then null else $run_id end),error_code:(if $error_code=="" then null else $error_code end),failed_step:(if ($phase=="" and $step_id=="" and $exit_code=="") then null else {phase:(if $phase=="" then null else $phase end),step_id:(if $step_id=="" then null else ($step_id | tonumber? // $step_id) end),exit_code:(if $exit_code=="" then null else ($exit_code | tonumber? // $exit_code) end)} end)}';
        $command = sprintf(
            '%s -nc --arg repository repo --arg commit_sha %s --arg status FAILED --arg run_id run-1 --arg phase deploy --arg step_id 2 --arg exit_code 1 --arg error_code failed %s 2>&1',
            escapeshellarg($jq),
            escapeshellarg(str_repeat('a', 40)),
            escapeshellarg($filter)
        );

        exec($command, $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
        $decoded = json_decode(implode("\n", $output), true);
        $this->assertSame(2, $decoded['failed_step']['step_id']);
        $this->assertSame(1, $decoded['failed_step']['exit_code']);
    }

    public function testProductionScriptUsesPortableAlternativeOperatorSpacing(): void {
        $script = file_get_contents(__DIR__ . '/../.github/actions/deploy-and-poll/deploy-and-poll.sh');
        $this->assertStringContainsString('tonumber? // $step_id', $script);
        $this->assertStringContainsString('tonumber? // $exit_code', $script);
        $this->assertStringNotContainsString('tonumber?//', $script);
    }
}
