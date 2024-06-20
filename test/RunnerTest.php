<?php

namespace Mariano\GitAutoDeploy\Test;

use Mariano\GitAutoDeploy\ConfigReader;
use Mariano\GitAutoDeploy\IPAllowListManager;
use Mariano\GitAutoDeploy\Request;
use Mariano\GitAutoDeploy\Response;
use Mariano\GitAutoDeploy\Runner;
use Mariano\GitAutoDeploy\Security;
use Mariano\GitAutoDeploy\views\BaseView;
use Mariano\GitAutoDeploy\views\Footer;
use Mariano\GitAutoDeploy\views\Header;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class RunnerTest extends TestCase {
    private $subject;
    private $mockRequest;
    private $mockResponse;
    private $mockConfigReader;
    private $mockRepoCreator;

    public function setUp(): void {
        $this->mockRepoCreator = new MockRepoCreator();
        $this->mockRepoCreator->spinUp();
        parent::setUp();
        $this->mockRequest = $this->getMockBuilder(Request::class)
            ->onlyMethods(['getQueryParam', 'getHeaders', 'getRemoteAddress'])
            ->getMock();
        $this->mockConfigReader = $this->getMockBuilder(ConfigReader::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $this->mockResponse = $this->getMockBuilder(Response::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addViewToBody', 'setStatusCode', 'getRunId'])
            ->getMock();
        $this->subject = new Runner(
            $this->mockRequest,
            $this->mockResponse,
            $this->mockConfigReader,
            $this->createMock(Logger::class),
            $this->createMock(IPAllowListManager::class)
        );
    }

    public function tearDown(): void {
        $this->mockRepoCreator->spinDown();
    }

    public function testRunNoQueryParamsGivenBadRequest() {
        $this->mockRequest->expects($this->once())
            ->method('getHeaders')
            ->will($this->returnValue([]));
        $this->mockRequest->expects($this->once())
            ->method('getRemoteAddress')
            ->will($this->returnValue('127.0.0.1'));
        $this->mockResponse->method('getRunId')->willReturn('run_id_for_runner_tests');
        $this->mockResponse->expects($this->once())
            ->method('setStatusCode')
            ->with($this->equalTo(400));
        $this->mockConfigReader->expects($this->once())
            ->method('get')
            ->will($this->returnValue(['127.0.0.1']));
        $this->subject->run();
    }

    public function testAllAssertionsMetOk() {
        $this->mockRequest->expects($this->once())
            ->method('getHeaders')
            ->will($this->returnValue([]));
        $this->mockRequest->expects($this->once())
            ->method('getRemoteAddress')
            ->will($this->returnValue('127.0.0.1'));
        $this->mockRequest->expects($this->any())
            ->method('getQueryParam')
            ->will($this->returnValueMap([
                [Request::REPO_QUERY_PARAM, $this->mockRepoCreator->testRepoName],
                [Request::KEY_QUERY_PARAM, 'test-key-name'],
            ]));
        $this->mockResponse->expects($this->once())
            ->method('setStatusCode')
            ->with($this->equalTo(200));
        $this->mockConfigReader->expects($this->any())
            ->method('get')
            ->will($this->returnValueMap([
                [ConfigReader::IPS_ALLOWLIST, ['127.0.0.1']],
                [ConfigReader::REPOS_BASE_PATH, $this->mockRepoCreator::BASE_REPO_DIR],
                [ConfigReader::CUSTOM_UPDATE_COMMANDS, [ConfigReader::DEFAULT_COMMANDS => ['echo -n ""', 'ls -a']]],
            ]));
        $user = exec('whoami');
        $this->mockResponse->expects($this->any())
            ->method('addViewToBody')
            ->withConsecutive(
                [$this->callback(function (BaseView $view) {
                    return $view instanceof Header;
                })],
                [$this->callback(function (BaseView $view) use ($user) {
                    $result = json_decode(json_encode($view), true);
                    return $result === [
                        [
                            'command' => 'echo -n ""',
                            'commandOutput' => [],
                            'runningUser' => $user,
                        ],
                        [
                            'command' => "ls -a",
                            'commandOutput' => [
                                ".",
                                "..",
                                "test-file-in-repo",
                            ],
                            'runningUser' => $user,
                        ],
                    ];
                })],
                [$this->callback(function (BaseView $view) {
                    return $view instanceof Footer;
                })]
            );
        $this->subject->run();
    }
}
