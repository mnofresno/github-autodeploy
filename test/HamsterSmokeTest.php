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
            ->willReturnMap([
                ['previous_run_id', $runId],
                ['fields', $fields],
            ]);

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
            ->expects($this->exactly(3))
            ->method('getQueryParam')
            ->will($this->returnValueMap([
                ['previous_run_id', ''],
                ['run_in_background', 'false'],
                ['create_repo_if_not_exists', json_encode($createRepo)],
            ]));

        $this->runner
            ->expects($this->exactly(1))
            ->method('run')
            ->with($this->equalTo($createRepo));

        $this->hamster->run();
    }
}
