<?php

namespace Mariano\GitAutoDeploy\Test;

use Mariano\GitAutoDeploy\ConfigReader;
use Mariano\GitAutoDeploy\Hamster;
use Mariano\GitAutoDeploy\Request;
use Mariano\GitAutoDeploy\Response;
use Mariano\GitAutoDeploy\Runner;
use Mariano\GitAutoDeploy\RunSearcher;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class HamsterTest extends TestCase {
    use ContainerAwareTrait;

    private $logger;
    private $runner;
    private $response;
    private $request;
    private $configReader;
    private $runSearcher;
    private $hamster;

    public function setUp(): void {
        $this->logger = $this->createMock(Logger::class);
        $this->runner = $this->createMock(Runner::class);
        $this->response = $this->createMock(Response::class);
        $this->request = $this->createMock(Request::class);
        $this->configReader = $this->createMock(ConfigReader::class);
        $this->runSearcher = $this->createMock(RunSearcher::class);
        $this->hamster = new Hamster(
            $this->logger,
            $this->runner,
            $this->response,
            $this->request,
            $this->configReader,
            $this->runSearcher
        );
    }

    public function testHamsterConstruct(): void {
        $mockConfig = $this->createMock(ConfigReader::class);
        $this->set(ConfigReader::class, $mockConfig);
        $subject = $this->get(Hamster::class);
        $this->assertNotNull($subject);
    }

    public function testRunWithFields(): void {
        $runId = '4b1d273c-af80-4f01-b61a-3889a4d7a5c1';
        $fields = 'date,message';

        $this->request
            ->expects($this->atLeast(1))
            ->method('getQueryParam')
            ->willReturnCallback(function ($param) use ($runId, $fields) {
                if ($param === 'previous_run_id') {
                    return $runId;
                }
                if ($param === 'fields') {
                    return $fields;
                }
                if ($param === 'deployment_status' || $param === 'wait_deployment') {
                    return '';
                }
                return '';
            });

        $expectedResults = [
            [
                'date' => '2024-06-21T02:31:56.368058+00:00',
                'message' => 'Background run disabled',
            ],
        ];

        $this->runSearcher
            ->method('search')
            ->with($runId, ['date', 'message'])
            ->willReturn($expectedResults);

        $this->response
            ->expects($this->once())
            ->method('addToBody')
            ->with(json_encode([
                'message' => "Given run Id: $runId",
                'results' => $expectedResults,
            ], JSON_PRETTY_PRINT));

        $this->response
            ->expects($this->once())
            ->method('send')
            ->with('application/json; charset=utf-8');

        $this->hamster->run();
    }

    /**
     * @testWith [true, false]
     */
    public function testRunnerReceivesCorrectBooleanForCreateRepoIfNotExists(bool $createRepo): void {
        $this->request
            ->expects($this->atLeast(1))
            ->method('getQueryParam')
            ->willReturnCallback(function ($param) use ($createRepo) {
                if ($param === 'create_repo_if_not_exists') {
                    return json_encode($createRepo);
                }
                if ($param === 'run_in_background') {
                    return 'false';
                }
                if ($param === 'previous_run_id' || $param === 'deployment_status' ||
                    $param === 'wait_deployment' || $param === 'wait') {
                    return '';
                }
                return '';
            });

        $this->request
            ->method('getBody')
            ->willReturn([]);

        $this->runner
            ->expects($this->exactly(1))
            ->method('run')
            ->with($this->equalTo($createRepo));

        $this->hamster->run();
    }

    public function testRunInBackgroundReadFromJsonBody(): void {
        $this->request
            ->expects($this->atLeast(1))
            ->method('getQueryParam')
            ->willReturnCallback(function ($param) {
                if ($param === 'previous_run_id' || $param === 'deployment_status' ||
                    $param === 'wait_deployment' || $param === 'wait') {
                    return '';
                }
                return '';
            });

        // getBody returns run_in_background in JSON (new workflow format)
        $this->request
            ->method('getBody')
            ->willReturn([
                'run_in_background' => true,
                'key' => 'test-key',
                'commit' => [
                    'sha' => 'abc123',
                    'author' => 'test-user'
                ]
            ]);

        $this->configReader
            ->method('get')
            ->willReturn('https://example.com');

        $this->response
            ->expects($this->once())
            ->method('getRunId')
            ->willReturn('test-run-id');

        $this->response
            ->expects($this->once())
            ->method('setStatusCode')
            ->with(201);

        $this->response
            ->expects($this->once())
            ->method('addToBody')
            ->with($this->stringContains('status'));

        $this->response
            ->expects($this->once())
            ->method('send')
            ->with('application/json; charset=utf-8');

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('info')
            ->with($this->stringContains('Background run enabled'));

        // Should call finishRequest for async execution
        $this->runner
            ->expects($this->once())
            ->method('run')
            ->with(false);

        $this->hamster->run();
    }

    public function testRunInBackgroundReadFromQueryParamsBackwardCompatibility(): void {
        $this->request
            ->expects($this->atLeast(1))
            ->method('getQueryParam')
            ->willReturnCallback(function ($param) {
                if ($param === 'run_in_background') {
                    return 'true';
                }
                if ($param === 'previous_run_id' || $param === 'deployment_status' ||
                    $param === 'wait_deployment' || $param === 'wait') {
                    return '';
                }
                return '';
            });

        // getBody returns empty array (backward compatibility)
        $this->request
            ->method('getBody')
            ->willReturn([]);

        $this->configReader
            ->method('get')
            ->willReturn('https://example.com');

        $this->response
            ->expects($this->once())
            ->method('getRunId')
            ->willReturn('test-run-id');

        $this->response
            ->expects($this->once())
            ->method('setStatusCode')
            ->with(201);

        $this->response
            ->expects($this->once())
            ->method('addToBody')
            ->with($this->stringContains('status'));

        $this->response
            ->expects($this->once())
            ->method('send')
            ->with('application/json; charset=utf-8');

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('info')
            ->with($this->stringContains('Background run enabled'));

        // Should call finishRequest for async execution
        $this->runner
            ->expects($this->once())
            ->method('run')
            ->with(false);

        $this->hamster->run();
    }

    public function testRunInBackgroundReturnsFalseWhenNotSpecified(): void {
        $this->request
            ->expects($this->atLeast(1))
            ->method('getQueryParam')
            ->willReturnCallback(function ($param) {
                if ($param === 'previous_run_id' || $param === 'deployment_status' ||
                    $param === 'wait_deployment' || $param === 'wait') {
                    return '';
                }
                return '';
            });

        // getBody returns data without run_in_background
        $this->request
            ->method('getBody')
            ->willReturn([
                'key' => 'test-key',
                'commit' => [
                    'sha' => 'abc123',
                    'author' => 'test-user'
                ]
            ]);

        // Should execute synchronously (no background)
        $this->logger
            ->expects($this->atLeastOnce())
            ->method('info')
            ->with('Synchronous deployment (no background, no wait)');

        $this->runner
            ->expects($this->once())
            ->method('run')
            ->with(false);

        $this->response
            ->expects($this->once())
            ->method('send');

        $this->hamster->run();
    }
}
