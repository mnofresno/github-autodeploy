<?php

namespace Mariano\GitAutoDeploy\Test;

use Mariano\GitAutoDeploy\ConfigReader;
use Mariano\GitAutoDeploy\CustomCommands;
use Mariano\GitAutoDeploy\DeployConfigReader;
use Mariano\GitAutoDeploy\Executer;
use Mariano\GitAutoDeploy\IPAllowListManager;
use Mariano\GitAutoDeploy\Request;
use Mariano\GitAutoDeploy\Response;
use Mariano\GitAutoDeploy\Runner;
use Mariano\GitAutoDeploy\views\BaseView;
use Mariano\GitAutoDeploy\views\Footer;
use Mariano\GitAutoDeploy\views\Header;
use Monolog\Logger;
use PHPUnit\Framework\MockObject\Builder\InvocationMocker;
use PHPUnit\Framework\TestCase;

class RunnerTest extends TestCase {
    use ContainerAwareTrait;

    private $subject;
    private $mockRequest;
    private $mockResponse;
    private $mockConfigReader;
    private $mockRepoCreator;
    private $executerMock;

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
        $this->set(Request::class, $this->mockRequest);
        $this->set(Response::class, $this->mockResponse);
        $this->set(ConfigReader::class, $this->mockConfigReader);
        $this->subject = new Runner(
            $this->mockRequest,
            $this->mockResponse,
            $this->mockConfigReader,
            $this->createMock(Logger::class),
            $this->createMock(IPAllowListManager::class),
            $this->get(CustomCommands::class),
            $this->get(DeployConfigReader::class),
            $this->get(Executer::class)
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
        $this->mockRequest->expects($this->exactly(6))
            ->method('getHeaders')
            ->will($this->returnValue([]));
        $this->mockRequest->expects($this->exactly(6))
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
                            'command_output' => [],
                            'running_user' => $user,
                            'exit_code' => 0,
                        ],
                        [
                            'command' => "ls -a",
                            'command_output' => [
                                ".",
                                "..",
                                "test-file-in-repo",
                            ],
                            'running_user' => $user,
                            'exit_code' => 0,
                        ],
                    ];
                })],
                [$this->callback(function (BaseView $view) {
                    return $view instanceof Footer;
                })]
            );
        $this->subject->run();
    }

    public function testUseCustomCommands(): void {
        $this->setupForPrePostAndCustomCommandsTests()
            ->willReturn(new class () {
                public function customCommands(): array {
                    return ['whoami -ccc'];
                }

                public function postFetchCommands(): array {
                    return [];
                }

                public function preFetchCommands(): ?array {
                    return ['dir'];
                }
            });
        $this->executerMock
            ->expects($this->exactly(3))
            ->method('run')
            ->withConsecutive(
                ['dir'],
                ['echo -n ""']
            );
        $this->subject->run();
    }

    public function testUsePreFetchCommands(): void {
        $this->setupForPrePostAndCustomCommandsTests()
            ->willReturn(new class () {
                public function customCommands(): array {
                    return [];
                }

                public function postFetchCommands(): array {
                    return [];
                }

                public function preFetchCommands(): ?array {
                    return ['custom_pre_setting'];
                }
            });
        $this->executerMock
            ->expects($this->exactly(3))
            ->method('run')
            ->withConsecutive(
                ['custom_pre_setting'],
                ['echo -n ""'],
                ['ls -a'],
                ['GIT_SSH_COMMAND="ssh -i /test-key-name" git fetch origin'],
                ['git reset --hard origin/$(git symbolic-ref --short HEAD)'],
            );
        $this->subject->run();
    }

    public function testUsePostFetchCommands(): void {
        $this->setupForPrePostAndCustomCommandsTests()
            ->willReturn(new class () {
                public function customCommands(): array {
                    return [];
                }

                public function postFetchCommands(): array {
                    return ['install_deps', 'restart_services'];
                }

                public function preFetchCommands(): ?array {
                    return [];
                }
            });
        $this->executerMock
            ->expects($this->exactly(6))
            ->method('run')
            ->withConsecutive(
                ['echo $PWD'],
                ['whoami'],
                ['GIT_SSH_COMMAND="ssh -i /test-key-name" git fetch origin'],
                ['git reset --hard origin/$(git symbolic-ref --short HEAD)'],
                ['install_deps'],
                ['restart_services'],
            );
        $this->subject->run();
    }

    public function testRepoNotExistsAndNotAllowedToCreate(): void {
        $this->setupForRepoName(
            uniqid('not-existing-repo-test'),
            0
        );
        $this->executerMock
            ->expects($this->exactly(0))
            ->method('run')
            ->withConsecutive(
                ['custom_pre_setting'],
                ['echo -n ""'],
                ['ls -a'],
                ['GIT_SSH_COMMAND="ssh -i /test-key-name" git fetch origin'],
                ['git reset --hard origin/$(git symbolic-ref --short HEAD)'],
            );
        $this->mockResponse->expects($this->once())
            ->method('setStatusCode')
            ->with($this->equalTo(400));
        $this->subject->run(false);
    }

    public function testRepoNotExistsAndIsAllowedToCreate(): void {
        $this->setupForRepoName(
            $repoName = uniqid('not-existing-repo-test'),
            1
        );
        $repoFullPath = "/tmp/$repoName";
        $this->executerMock
            ->expects($this->exactly(4))
            ->method('run')
            ->withConsecutive(
                ['echo $PWD'],
                [$this->callback(function ($command) use ($repoFullPath, $repoName) {
                    if ($command === "GIT_SSH_COMMAND=\"ssh -i /test-key-name\" git clone 'git@github.com:testuser/'$repoName'.git' '$repoFullPath'") {
                        if (!is_dir($repoFullPath)) {
                            mkdir($repoFullPath, 0777, true);
                        }
                        return true;
                    }
                    return false;
                })],
                ['echo -n ""'],
                ['ls -a'],
                ['GIT_SSH_COMMAND="ssh -i /test-key-name" git fetch origin'],
                ['git reset --hard origin/$(git symbolic-ref --short HEAD)'],
            );
        $this->mockResponse->expects($this->once())
            ->method('setStatusCode')
            ->with($this->equalTo(201));
        $this->subject->run(true);
    }

    private function setupForPrePostAndCustomCommandsTests(): InvocationMocker {
        return $this->setupForRepoName($this->mockRepoCreator->testRepoName, 1, []);
    }

    private function setupForRepoName(string $repoName, int $countOfRepoReads): InvocationMocker {
        $this->mockConfigReader->expects($this->any())
            ->method('get')
            ->will($this->returnValueMap([
                [ConfigReader::IPS_ALLOWLIST, ['127.0.0.1']],
                [ConfigReader::REPOS_TEMPLATE_URI, 'git@github.com:testuser/{$repo_key}.git'],
                [ConfigReader::REPOS_BASE_PATH, $this->mockRepoCreator::BASE_REPO_DIR],
                [ConfigReader::CUSTOM_UPDATE_COMMANDS, [ConfigReader::DEFAULT_COMMANDS => ['echo -n ""', 'ls -a']]],
            ]));
        $this->mockRequest->expects($this->atLeast(1))
            ->method('getHeaders')
            ->will($this->returnValue([]));
        $this->mockRequest->expects($this->atLeast(1))
            ->method('getRemoteAddress')
            ->will($this->returnValue('127.0.0.1'));
        $this->subject = new Runner(
            $this->mockRequest,
            $this->mockResponse,
            $this->mockConfigReader,
            $this->createMock(Logger::class),
            $this->createMock(IPAllowListManager::class),
            $this->get(CustomCommands::class),
            $deployMock = $this->createMock(DeployConfigReader::class),
            $this->executerMock = $this->createMock(Executer::class)
        );
        $this->mockRequest->expects($this->any())
            ->method('getQueryParam')
            ->will($this->returnValueMap([
                [Request::REPO_QUERY_PARAM, $repoName],
                [Request::KEY_QUERY_PARAM, 'test-key-name'],
            ]));
        return $deployMock
            ->expects($this->atLeast($countOfRepoReads))
            ->method('fetchRepoConfig');
    }
}
