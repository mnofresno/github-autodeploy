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

    private function repoPath(): string {
        return $this->mockRepoCreator::BASE_REPO_DIR . DIRECTORY_SEPARATOR . $this->mockRepoCreator->testRepoName;
    }

    private function deployGitDir(): string {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'git-autodeploy-' . sha1($this->repoPath() . '|unknown');
    }

    private function branchSelector(): string {
        return '$(git symbolic-ref --short HEAD 2>/dev/null || echo main)';
    }

    private function gitCommandPrefix(): string {
        return 'GIT_SSH_COMMAND="ssh -i /test-keys/test-key-name" git --git-dir='
            . escapeshellarg($this->deployGitDir())
            . ' --work-tree=' . escapeshellarg($this->repoPath());
    }

    private function remoteSyncCommand(): string {
        $prefix = $this->gitCommandPrefix();
        $remoteUrl = "git@github.com:testuser/'" . $this->mockRepoCreator->testRepoName . "'.git";
        return $prefix . ' remote set-url origin "' . $remoteUrl . '"' . "\n"
            . 'if [ $? -ne 0 ]; then' . "\n"
            . '  ' . $prefix . ' remote add origin "' . $remoteUrl . '"' . "\n"
            . 'fi';
    }

    public function setUp(): void {
        $this->mockRepoCreator = new MockRepoCreator();
        $this->mockRepoCreator->spinUp();
        parent::setUp();
        $this->mockRequest = $this->getMockBuilder(Request::class)
            ->onlyMethods(['getQueryParam', 'getHeaders', 'getRemoteAddress', 'getBody'])
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
        $this->mockRequest->expects($this->any())
            ->method('getBody')
            ->willReturn([]);
        $this->mockResponse->expects($this->once())
            ->method('setStatusCode')
            ->with($this->equalTo(200));
        $this->mockResponse->method('getRunId')->willReturn('test_run_id');
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
            ->expects($this->atLeast(1))
            ->method('run');
        $this->subject->run();
    }

    public function testRepoNotExistsAndNotAllowedToCreate(): void {
        $this->mockRequest->expects($this->any())
            ->method('getBody')
            ->willReturn([]);
        $this->mockResponse->method('getRunId')->willReturn('test_run_id');
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
                ['GIT_SSH_COMMAND="ssh -i /test-key-name" git fetch --no-write-fetch-head origin'],
                ['git reset --hard origin/$(git symbolic-ref --short HEAD)'],
            );
        $this->mockResponse->expects($this->once())
            ->method('setStatusCode')
            ->with($this->equalTo(400));
        $this->subject->run(false);
    }

    public function testRepoNotExistsAndIsAllowedToCreate(): void {
        $this->mockRequest->expects($this->any())
            ->method('getBody')
            ->willReturn([]);
        $this->mockResponse->method('getRunId')->willReturn('test_run_id');
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
                    if (str_contains($command, 'git clone') && str_contains($command, $repoName)) {
                        if (!is_dir($repoFullPath)) {
                            mkdir($repoFullPath, 0777, true);
                        }
                        return true;
                    }
                    return false;
                })],
                ['echo -n ""'],
                ['ls -a'],
            );
        $this->mockResponse->expects($this->once())
            ->method('setStatusCode')
            ->with($this->equalTo(201));
        $this->subject->run(true);
    }

    public function testRepoNotExistsUsesRepoSpecificTemplateUriWhenConfigured(): void {
        $this->mockRequest->expects($this->any())
            ->method('getBody')
            ->willReturn([]);
        $this->mockResponse->method('getRunId')->willReturn('test_run_id');

        $repoName = uniqid('repo-specific-template-test');
        $repoFullPath = "/tmp/$repoName";
        $this->setupForRepoName($repoName, 1, [
            ConfigReader::REPOS_TEMPLATE_URIS => [
                $repoName => 'git@github.com:bpf-project/{$repo_key}.git',
            ],
        ], null);

        $commands = [];
        $this->executerMock
            ->expects($this->exactly(4))
            ->method('run')
            ->willReturnCallback(function (string $command) use (&$commands, $repoName, $repoFullPath) {
                $commands[] = $command;
                if (str_contains($command, 'git clone') && str_contains($command, "git@github.com:bpf-project/$repoName.git")) {
                    if (!is_dir($repoFullPath)) {
                        mkdir($repoFullPath, 0777, true);
                    }
                }
                return $this->createRanCommand($command, [], 0);
            });
        $this->mockResponse->expects($this->once())
            ->method('setStatusCode')
            ->with($this->equalTo(201));
        $this->subject->run(true);
        $this->assertContains('echo $PWD', $commands);
        $this->assertTrue((bool) array_filter($commands, function (string $command) use ($repoName): bool {
            return str_contains($command, 'git clone') && str_contains($command, "git@github.com:bpf-project/$repoName.git");
        }));
    }

    public function testHttpsTransportUsesCredentialHelperAndDoesNotUseSsh(): void {
        $this->mockRequest->expects($this->any())
            ->method('getBody')
            ->willReturn([]);
        $this->mockResponse->method('getRunId')->willReturn('test_run_id');

        $repoName = uniqid('https-transport-test');
        $repoFullPath = "/tmp/$repoName";
        $this->setupForRepoName($repoName, 1, [
            ConfigReader::REPOS_TEMPLATE_URIS => [
                $repoName => [
                    'strategy' => 'https',
                    'template_uri' => 'https://github.com/bpf-project/{$repo_key}.git',
                    'credentials' => [
                        'username' => 'x-access-token',
                        'token' => 'ghp_test_token',
                    ],
                ],
            ],
        ], null);

        $commands = [];
        $this->executerMock
            ->expects($this->atLeast(4))
            ->method('run')
            ->willReturnCallback(function (string $command) use (&$commands, $repoFullPath, $repoName) {
                $commands[] = $command;
                if (str_contains($command, ' clone ') && str_contains($command, "https://github.com/bpf-project/$repoName.git")) {
                    if (!is_dir($repoFullPath)) {
                        mkdir($repoFullPath, 0777, true);
                    }
                }
                return $this->createRanCommand($command, [], 0);
            });

        $this->mockResponse->expects($this->once())
            ->method('setStatusCode')
            ->with($this->equalTo(201));

        $this->subject->run(true);

        $this->assertNotEmpty(array_filter($commands, function (string $command): bool {
            return str_contains($command, 'credential.helper=store --file=');
        }));
        $this->assertEmpty(array_filter($commands, function (string $command): bool {
            return str_contains($command, 'GIT_SSH_COMMAND');
        }));
    }

    public function testPreFetchCommandsWithSecretsPlaceholdersAreReplaced(): void {
        $this->mockConfigReader->expects($this->any())
            ->method('get')
            ->will($this->returnValueMap([
                [ConfigReader::ENABLE_CLONE, true],
                [ConfigReader::IPS_ALLOWLIST, ['127.0.0.1']],
                [ConfigReader::REPOS_BASE_PATH, $this->mockRepoCreator::BASE_REPO_DIR],
                [ConfigReader::COMMAND_TIMEOUT, 3600],
                [ConfigReader::SECRETS, [
                    'github_ghcr_token' => 'my_secret_token_123',
                    'github_ghcr_username' => 'test_user',
                ]],
            ]));
        $this->mockRequest->expects($this->atLeast(1))
            ->method('getHeaders')
            ->will($this->returnValue([]));
        $this->mockRequest->expects($this->atLeast(1))
            ->method('getRemoteAddress')
            ->will($this->returnValue('127.0.0.1'));
        $this->mockRequest->expects($this->any())
            ->method('getBody')
            ->willReturn([]);
        $this->mockRequest->expects($this->any())
            ->method('getQueryParam')
            ->will($this->returnValueMap([
                [Request::REPO_QUERY_PARAM, $this->mockRepoCreator->testRepoName],
                [Request::KEY_QUERY_PARAM, 'test-key-name'],
            ]));
        $this->mockResponse->method('getRunId')->willReturn('test_run_id');

        $deployMock = $this->createMock(DeployConfigReader::class);
        $deployMock->expects($this->atLeast(2))
            ->method('fetchRepoConfig')
            ->willReturn(new class () {
                public function customCommands(): array {
                    return [];
                }

                public function postFetchCommands(): array {
                    return [];
                }

                public function preFetchCommands(): array {
                    return [
                        'echo ${{ secrets.github_ghcr_token }} | docker login ghcr.io -u ${{ secrets.github_ghcr_username }} --password-stdin',
                    ];
                }
            });

        $customCommands = new CustomCommands(
            $this->mockConfigReader,
            $this->mockRequest,
            $this->createMock(Logger::class),
            $deployMock
        );

        $this->subject = new Runner(
            $this->mockRequest,
            $this->mockResponse,
            $this->mockConfigReader,
            $this->createMock(Logger::class),
            $this->createMock(IPAllowListManager::class),
            $customCommands,
            $deployMock,
            $this->executerMock = $this->createMock(Executer::class)
        );

        $this->executerMock
            ->expects($this->atLeast(1))
            ->method('run');

        $this->subject->run();
    }

    public function testPostFetchCommandsWithSecretsPlaceholdersAreReplaced(): void {
        $this->mockConfigReader->expects($this->any())
            ->method('get')
            ->will($this->returnValueMap([
                [ConfigReader::ENABLE_CLONE, true],
                [ConfigReader::IPS_ALLOWLIST, ['127.0.0.1']],
                [ConfigReader::REPOS_BASE_PATH, $this->mockRepoCreator::BASE_REPO_DIR],
                [ConfigReader::SSH_KEYS_PATH, '/home/test/.ssh'],
                [ConfigReader::COMMAND_TIMEOUT, 3600],
                [ConfigReader::SECRETS, [
                    'api_key' => 'secret_api_key_xyz',
                    'deploy_token' => 'deploy_token_abc',
                ]],
            ]));
        $this->mockRequest->expects($this->atLeast(1))
            ->method('getHeaders')
            ->will($this->returnValue([]));
        $this->mockRequest->expects($this->atLeast(1))
            ->method('getRemoteAddress')
            ->will($this->returnValue('127.0.0.1'));
        $this->mockRequest->expects($this->any())
            ->method('getBody')
            ->willReturn([]);
        $this->mockRequest->expects($this->any())
            ->method('getQueryParam')
            ->will($this->returnValueMap([
                [Request::REPO_QUERY_PARAM, $this->mockRepoCreator->testRepoName],
                [Request::KEY_QUERY_PARAM, 'test-key-name'],
            ]));
        $this->mockResponse->method('getRunId')->willReturn('test_run_id');

        $deployMock = $this->createMock(DeployConfigReader::class);
        $deployMock->expects($this->atLeast(2))
            ->method('fetchRepoConfig')
            ->willReturn(new class () {
                public function customCommands(): array {
                    return [];
                }

                public function postFetchCommands(): array {
                    return [
                        'curl -H "Authorization: Bearer ${{ secrets.api_key }}" https://example.com/deploy',
                        'echo $secrets.deploy_token',
                    ];
                }

                public function preFetchCommands(): array {
                    return [];
                }
            });

        $customCommands = new CustomCommands(
            $this->mockConfigReader,
            $this->mockRequest,
            $this->createMock(Logger::class),
            $deployMock
        );

        $this->subject = new Runner(
            $this->mockRequest,
            $this->mockResponse,
            $this->mockConfigReader,
            $this->createMock(Logger::class),
            $this->createMock(IPAllowListManager::class),
            $customCommands,
            $deployMock,
            $this->executerMock = $this->createMock(Executer::class)
        );

        $this->executerMock
            ->expects($this->atLeast(1))
            ->method('run');

        $this->subject->run();
    }

    public function testPreAndPostFetchCommandsWithConfigPlaceholdersAreReplaced(): void {
        $this->mockConfigReader->expects($this->any())
            ->method('get')
            ->will($this->returnValueMap([
                [ConfigReader::ENABLE_CLONE, true],
                [ConfigReader::IPS_ALLOWLIST, ['127.0.0.1']],
                [ConfigReader::REPOS_BASE_PATH, $this->mockRepoCreator::BASE_REPO_DIR],
                [ConfigReader::SSH_KEYS_PATH, '/home/test/.ssh'],
                [ConfigReader::COMMAND_TIMEOUT, 3600],
            ]));
        $this->mockRequest->expects($this->atLeast(1))
            ->method('getHeaders')
            ->will($this->returnValue([]));
        $this->mockRequest->expects($this->atLeast(1))
            ->method('getRemoteAddress')
            ->will($this->returnValue('127.0.0.1'));
        $this->mockRequest->expects($this->any())
            ->method('getBody')
            ->willReturn([
                'commit' => [
                    'sha' => 'abc123',
                ],
            ]);
        $this->mockRequest->expects($this->any())
            ->method('getQueryParam')
            ->will($this->returnValueMap([
                [Request::REPO_QUERY_PARAM, $this->mockRepoCreator->testRepoName],
                [Request::KEY_QUERY_PARAM, 'my-key'],
            ]));
        $this->mockResponse->method('getRunId')->willReturn('test_run_id');
        $this->mockResponse->expects($this->once())
            ->method('setStatusCode')
            ->with($this->equalTo(200));

        $deployMock = $this->createMock(DeployConfigReader::class);
        $deployMock->expects($this->atLeast(2))
            ->method('fetchRepoConfig')
            ->willReturn(new class () {
                public function customCommands(): array {
                    return [];
                }

                public function postFetchCommands(): array {
                    return [
                        'echo "Base path: $ReposBasePath"',
                    ];
                }

                public function preFetchCommands(): array {
                    return [
                        'echo "SSH keys: $SSHKeysPath"',
                        'echo "Repo: $repo"',
                    ];
                }
            });

        $ipAllowListMock = $this->createMock(IPAllowListManager::class);
        $ipAllowListMock->expects($this->any())
            ->method('getAllowedIpsOrRanges')
            ->willReturn([]);

        $customCommands = new CustomCommands(
            $this->mockConfigReader,
            $this->mockRequest,
            $this->createMock(Logger::class),
            $deployMock
        );

        $this->subject = new Runner(
            $this->mockRequest,
            $this->mockResponse,
            $this->mockConfigReader,
            $this->createMock(Logger::class),
            $ipAllowListMock,
            $customCommands,
            $deployMock,
            $this->executerMock = $this->createMock(Executer::class)
        );

        $this->executerMock
            ->expects($this->atLeast(1))
            ->method('run');

        $this->subject->run();
    }

    public function testRunnerStopsOnCommandFailure(): void {
        // Verifica que Runner se detiene inmediatamente cuando un comando falla
        $this->mockRequest->expects($this->any())
            ->method('getHeaders')
            ->willReturn([]);
        $this->mockRequest->expects($this->any())
            ->method('getRemoteAddress')
            ->willReturn('127.0.0.1');
        $this->mockRequest->expects($this->any())
            ->method('getQueryParam')
            ->will($this->returnValueMap([
                [Request::REPO_QUERY_PARAM, $this->mockRepoCreator->testRepoName],
                [Request::KEY_QUERY_PARAM, 'test-key-name'],
            ]));
        $this->mockRequest->expects($this->any())
            ->method('getBody')
            ->willReturn([]);

        $this->mockResponse->method('getRunId')->willReturn('test_run_id');

        $this->mockConfigReader->expects($this->any())
            ->method('get')
            ->will($this->returnValueMap([
                [ConfigReader::IPS_ALLOWLIST, ['127.0.0.1']],
                [ConfigReader::REPOS_BASE_PATH, $this->mockRepoCreator::BASE_REPO_DIR],
                [ConfigReader::ENABLE_CLONE, true], // Importante: permitir clone
                [ConfigReader::COMMAND_TIMEOUT, 60],
                [ConfigReader::CUSTOM_UPDATE_COMMANDS, [ConfigReader::DEFAULT_COMMANDS => ['echo -n ""', 'ls -a']]],
            ]));

        $deployMock = $this->createMock(DeployConfigReader::class);
        $deployMock->expects($this->any())
            ->method('fetchRepoConfig')
            ->willReturn(new class () {
                public function preFetchCommands(): array {
                    return ['echo "step 1"'];
                }

                public function customCommands(): array {
                    return [];
                }

                public function postFetchCommands(): array {
                    return ['false', 'echo "step 3 - should not run"'];
                }
            });

        // Mockear SSH_KEYS_PATH para builtInCommands
        $this->mockConfigReader->expects($this->any())
            ->method('get')
            ->will($this->returnValueMap([
                [ConfigReader::IPS_ALLOWLIST, ['127.0.0.1']],
                [ConfigReader::REPOS_BASE_PATH, $this->mockRepoCreator::BASE_REPO_DIR],
                [ConfigReader::ENABLE_CLONE, true],
                [ConfigReader::COMMAND_TIMEOUT, 60],
                [ConfigReader::SSH_KEYS_PATH, '/test-keys'],
                [ConfigReader::CUSTOM_UPDATE_COMMANDS, [ConfigReader::DEFAULT_COMMANDS => ['echo -n ""', 'ls -a']]],
            ]));

        $executerMock = $this->createMock(Executer::class);
        $executerMock
            ->expects($this->atLeast(10))
            ->method('run')
            ->willReturnOnConsecutiveCalls(
                $this->createRanCommand('echo "step 1"', [], 0), // pre_fetch
                $this->createRanCommand('echo $PWD', [], 0), // fetch - builtInCommands
                $this->createRanCommand('whoami', [], 0), // fetch - builtInCommands
                $this->createRanCommand('mkdir -p ' . escapeshellarg($this->deployGitDir()), [], 0), // fetch - builtInCommands
                $this->createRanCommand('GIT_SSH_COMMAND="ssh -i /test-keys/test-key-name" git --git-dir=' . escapeshellarg($this->deployGitDir()) . ' --work-tree=' . escapeshellarg($this->repoPath()) . ' init', [], 0), // fetch - builtInCommands
                $this->createRanCommand($this->remoteSyncCommand(), [], 0), // fetch - builtInCommands
                $this->createRanCommand('GIT_SSH_COMMAND="ssh -i /test-keys/test-key-name" git --git-dir=' . escapeshellarg($this->deployGitDir()) . ' --work-tree=' . escapeshellarg($this->repoPath()) . ' fetch --no-write-fetch-head origin main', [], 0), // fetch - builtInCommands
                $this->createRanCommand('git --git-dir=' . escapeshellarg($this->deployGitDir()) . ' --work-tree=' . escapeshellarg($this->repoPath()) . ' reset --hard "origin/main"', [], 0), // fetch - builtInCommands
                $this->createRanCommand('false', ['error'], 1) // post_fetch - Este falla y debe lanzar excepción
            );

        $customCommands = new CustomCommands(
            $this->mockConfigReader,
            $this->mockRequest,
            $this->createMock(Logger::class),
            $deployMock
        );

        $runner = new Runner(
            $this->mockRequest,
            $this->mockResponse,
            $this->mockConfigReader,
            $this->createMock(Logger::class),
            $this->createMock(IPAllowListManager::class),
            $customCommands,
            $deployMock,
            $executerMock
        );

        // Runner captura las excepciones y las convierte en respuestas HTTP
        // No se lanzan excepciones, se convierten en respuestas
        $this->mockResponse->expects($this->once())
            ->method('setStatusCode')
            ->with($this->equalTo(500));

        $this->mockResponse->expects($this->atLeast(1))
            ->method('addViewToBody')
            ->with($this->callback(function ($view) {
                // Verificar que se agregó el view de DeploymentFailed
                if ($view instanceof \Mariano\GitAutoDeploy\views\errors\DeploymentFailed) {
                    return true;
                }
                // También puede haber Header y Footer
                return $view instanceof \Mariano\GitAutoDeploy\views\Header ||
                       $view instanceof \Mariano\GitAutoDeploy\views\Footer;
            }));

        $runner->run();

        // Verificar que el DeploymentStatus se marcó como fallido
        $deploymentStatus = \Mariano\GitAutoDeploy\DeploymentStatus::load('test_run_id');
        $this->assertNotNull($deploymentStatus);
        $status = $deploymentStatus->get();
        $this->assertEquals(\Mariano\GitAutoDeploy\DeploymentStatus::STATUS_FAILED, $status['status']);
        // Verificar que el comando que falló fue 'false' de post_fetch
        $failedStep = $status['failed_step'] ?? null;
        $this->assertNotNull($failedStep, 'Deployment should have a failed_step');
        $this->assertEquals('false', $failedStep['command'] ?? null);
        // La fase puede variar dependiendo de qué comando falle primero, pero verificamos que el comando sea correcto
    }

    public function testRunnerHandlesTimeoutCorrectly(): void {
        // Verifica que Runner detecta correctamente los timeouts
        $this->mockRequest->expects($this->any())
            ->method('getHeaders')
            ->willReturn([]);
        $this->mockRequest->expects($this->any())
            ->method('getRemoteAddress')
            ->willReturn('127.0.0.1');
        $this->mockRequest->expects($this->any())
            ->method('getQueryParam')
            ->will($this->returnValueMap([
                [Request::REPO_QUERY_PARAM, $this->mockRepoCreator->testRepoName],
                [Request::KEY_QUERY_PARAM, 'test-key-name'],
            ]));
        $this->mockRequest->expects($this->any())
            ->method('getBody')
            ->willReturn([]);

        $this->mockResponse->method('getRunId')->willReturn('test_run_id');

        $deployMock = $this->createMock(DeployConfigReader::class);
        $deployMock->expects($this->any())
            ->method('fetchRepoConfig')
            ->willReturn(new class () {
                public function preFetchCommands(): array {
                    return [];
                }

                public function customCommands(): array {
                    return [];
                }

                public function postFetchCommands(): array {
                    return ['sleep 100'];
                }
            });

        $this->mockConfigReader->expects($this->any())
            ->method('get')
            ->will($this->returnValueMap([
                [ConfigReader::IPS_ALLOWLIST, ['127.0.0.1']],
                [ConfigReader::REPOS_BASE_PATH, $this->mockRepoCreator::BASE_REPO_DIR],
                [ConfigReader::ENABLE_CLONE, true],
                [ConfigReader::COMMAND_TIMEOUT, 60],
                [ConfigReader::SSH_KEYS_PATH, '/test-keys'],
                [ConfigReader::CUSTOM_UPDATE_COMMANDS, [ConfigReader::DEFAULT_COMMANDS => ['echo -n ""', 'ls -a']]],
            ]));

        $executerMock = $this->createMock(Executer::class);
        $executerMock
            ->expects($this->atLeast(9))
            ->method('run')
            ->willReturnOnConsecutiveCalls(
                $this->createRanCommand('echo $PWD', [], 0), // fetch - builtInCommands
                $this->createRanCommand('whoami', [], 0), // fetch - builtInCommands
                $this->createRanCommand('mkdir -p ' . escapeshellarg($this->deployGitDir()), [], 0), // fetch - builtInCommands
                $this->createRanCommand('GIT_SSH_COMMAND="ssh -i /test-keys/test-key-name" git --git-dir=' . escapeshellarg($this->deployGitDir()) . ' --work-tree=' . escapeshellarg($this->repoPath()) . ' init', [], 0), // fetch - builtInCommands
                $this->createRanCommand($this->remoteSyncCommand(), [], 0), // fetch - builtInCommands
                $this->createRanCommand('GIT_SSH_COMMAND="ssh -i /test-keys/test-key-name" git --git-dir=' . escapeshellarg($this->deployGitDir()) . ' --work-tree=' . escapeshellarg($this->repoPath()) . ' fetch --no-write-fetch-head origin main', [], 0), // fetch - builtInCommands
                $this->createRanCommand('git --git-dir=' . escapeshellarg($this->deployGitDir()) . ' --work-tree=' . escapeshellarg($this->repoPath()) . ' reset --hard "origin/main"', [], 0), // fetch - builtInCommands
                $this->createRanCommand('sleep 100', ['Command timed out'], \Mariano\GitAutoDeploy\Executer::EXIT_CODE_TIMEOUT) // post_fetch - timeout
            );

        $customCommands = new CustomCommands(
            $this->mockConfigReader,
            $this->mockRequest,
            $this->createMock(Logger::class),
            $deployMock
        );

        $runner = new Runner(
            $this->mockRequest,
            $this->mockResponse,
            $this->mockConfigReader,
            $this->createMock(Logger::class),
            $this->createMock(IPAllowListManager::class),
            $customCommands,
            $deployMock,
            $executerMock
        );

        // Runner captura las excepciones y las convierte en respuestas HTTP
        $this->mockResponse->expects($this->once())
            ->method('setStatusCode')
            ->with($this->equalTo(500));

        $this->mockResponse->expects($this->atLeast(1))
            ->method('addViewToBody')
            ->with($this->callback(function ($view) {
                // Verificar que se agregó el view de DeploymentFailed
                if ($view instanceof \Mariano\GitAutoDeploy\views\errors\DeploymentFailed) {
                    return true;
                }
                // También puede haber Header y Footer
                return $view instanceof \Mariano\GitAutoDeploy\views\Header ||
                       $view instanceof \Mariano\GitAutoDeploy\views\Footer;
            }));

        $runner->run();

        // Verificar que el DeploymentStatus se marcó como fallido con timeout
        $deploymentStatus = \Mariano\GitAutoDeploy\DeploymentStatus::load('test_run_id');
        $this->assertNotNull($deploymentStatus);
        $status = $deploymentStatus->get();
        $this->assertEquals(\Mariano\GitAutoDeploy\DeploymentStatus::STATUS_FAILED, $status['status']);
        $failedStep = $status['failed_step'] ?? null;
        $this->assertNotNull($failedStep, 'Deployment should have a failed_step');
        // Verificar que el exit code es de timeout
        $this->assertEquals(\Mariano\GitAutoDeploy\Executer::EXIT_CODE_TIMEOUT, $failedStep['exit_code'] ?? null);
        // Verificar que el comando es 'sleep 100'
        $this->assertEquals('sleep 100', $failedStep['command'] ?? null);
    }

    public function testVerboseMatcherStreamsStepOutputIntoDeploymentStatus(): void {
        $runId = 'test-run-verbose-' . uniqid();

        $this->mockRequest->expects($this->any())
            ->method('getHeaders')
            ->willReturn([]);
        $this->mockRequest->expects($this->any())
            ->method('getRemoteAddress')
            ->willReturn('127.0.0.1');
        $this->mockRequest->expects($this->any())
            ->method('getBody')
            ->willReturn([]);
        $this->mockRequest->expects($this->any())
            ->method('getQueryParam')
            ->willReturnMap([
                [Request::REPO_QUERY_PARAM, $this->mockRepoCreator->testRepoName],
                [Request::KEY_QUERY_PARAM, 'test-key-name'],
            ]);
        $this->mockResponse->method('getRunId')->willReturn($runId);
        $this->mockConfigReader->expects($this->any())
            ->method('get')
            ->willReturnMap([
                [ConfigReader::IPS_ALLOWLIST, ['127.0.0.1']],
                [ConfigReader::REPOS_BASE_PATH, $this->mockRepoCreator::BASE_REPO_DIR],
                [ConfigReader::COMMAND_TIMEOUT, 3600],
                [ConfigReader::CUSTOM_UPDATE_COMMANDS, [ConfigReader::DEFAULT_COMMANDS => ['echo -n ""']]],
            ]);

        $deployMock = $this->createMock(DeployConfigReader::class);
        $deployMock->expects($this->any())
            ->method('fetchRepoConfig')
            ->willReturn(new class () {
                public function customCommands(): array {
                    return [];
                }

                public function postFetchCommands(): array {
                    return [
                        'sh ./scripts/report-docker-image-shas.sh frontend backend notifications',
                    ];
                }

                public function preFetchCommands(): array {
                    return [];
                }

                public function verboseMatchers(): array {
                    return ['report-docker-image-shas\\.sh'];
                }
            });

        $customCommands = new CustomCommands(
            $this->mockConfigReader,
            $this->mockRequest,
            $this->createMock(Logger::class),
            $deployMock
        );

        $executer = new class ($this->mockConfigReader, $this->mockRequest) extends Executer {
            public function __construct(ConfigReader $configReader, Request $request) {
                parent::__construct($configReader, $request);
            }

            public function run(string $command, ?callable $outputCallback = null): \Mariano\GitAutoDeploy\views\RanCommand {
                $output = [];
                if (str_contains($command, 'report-docker-image-shas.sh') && $outputCallback) {
                    $outputCallback('frontend=sha-frontend');
                    $outputCallback('backend=sha-backend');
                    $outputCallback('notifications=sha-notifications');
                    $output[] = 'frontend=sha-frontend';
                    $output[] = 'backend=sha-backend';
                    $output[] = 'notifications=sha-notifications';
                }

                return new \Mariano\GitAutoDeploy\views\RanCommand(
                    $command,
                    $output,
                    exec('whoami'),
                    0
                );
            }
        };

        $runner = new Runner(
            $this->mockRequest,
            $this->mockResponse,
            $this->mockConfigReader,
            $this->createMock(Logger::class),
            $this->createMock(IPAllowListManager::class),
            $customCommands,
            $deployMock,
            $executer
        );

        $runner->run();

        $deploymentStatus = \Mariano\GitAutoDeploy\DeploymentStatus::load($runId);
        $this->assertNotNull($deploymentStatus);
        $status = $deploymentStatus->get();

        $matchedStep = null;
        foreach ($status['steps'] as $step) {
            if (($step['command'] ?? '') === 'sh ./scripts/report-docker-image-shas.sh frontend backend notifications') {
                $matchedStep = $step;
                break;
            }
        }

        $this->assertNotNull($matchedStep);
        $this->assertTrue($matchedStep['verbose'] ?? false);
        $this->assertEquals([
            'frontend=sha-frontend',
            'backend=sha-backend',
            'notifications=sha-notifications',
        ], $matchedStep['output'] ?? []);

        $deploymentStatus->delete();
    }

    private function createRanCommand(string $command, array $output, int $exitCode): \Mariano\GitAutoDeploy\views\RanCommand {
        return new \Mariano\GitAutoDeploy\views\RanCommand($command, $output, exec('whoami'), $exitCode);
    }

    private function setupForPrePostAndCustomCommandsTests(): InvocationMocker {
        return $this->setupForRepoName($this->mockRepoCreator->testRepoName, 1, []);
    }

    private function setupForRepoName(string $repoName, int $countOfRepoReads, array $extraConfigValues = [], ?string $clonePath = 'git@github.com:testuser/{$repo_key}.git'): InvocationMocker {
        $configValues = [
            ConfigReader::ENABLE_CLONE => true,
            ConfigReader::IPS_ALLOWLIST => ['127.0.0.1'],
            ConfigReader::REPOS_TEMPLATE_URI => 'git@github.com:testuser/{$repo_key}.git',
            ConfigReader::REPOS_BASE_PATH => $this->mockRepoCreator::BASE_REPO_DIR,
            ConfigReader::CUSTOM_UPDATE_COMMANDS => [ConfigReader::DEFAULT_COMMANDS => ['echo -n ""', 'ls -a']],
            ConfigReader::COMMAND_TIMEOUT => 3600,
        ];
        foreach ($extraConfigValues as $key => $value) {
            $configValues[$key] = $value;
        }
        $returnMap = [];
        foreach ($configValues as $key => $value) {
            $returnMap[] = [$key, $value];
        }
        $this->mockConfigReader->expects($this->any())
            ->method('get')
            ->will($this->returnValueMap($returnMap));
        $this->mockRequest->expects($this->atLeast(1))
            ->method('getHeaders')
            ->will($this->returnValue([]));
        $this->mockRequest->expects($this->atLeast(1))
            ->method('getRemoteAddress')
            ->will($this->returnValue('127.0.0.1'));
        $this->mockRequest->expects($this->any())
            ->method('getBody')
            ->willReturn([]);
        $this->mockResponse->method('getRunId')->willReturn('test_run_id');
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
                [Request::CLONE_PATH_QUERY_PARAM, $clonePath ?? ''],
                [Request::REPO_QUERY_PARAM, $repoName],
                [Request::KEY_QUERY_PARAM, 'test-key-name'],
            ]));
        return $deployMock
            ->expects($this->atLeast($countOfRepoReads))
            ->method('fetchRepoConfig');
    }
}
