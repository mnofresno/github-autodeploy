<?php

namespace Mariano\GitAutoDeploy\Test;

use Mariano\GitAutoDeploy\ConfigReader;
use Mariano\GitAutoDeploy\CustomCommands;
use Mariano\GitAutoDeploy\DeployConfigReader;
use Mariano\GitAutoDeploy\Request;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class CustomCommandsTest extends TestCase {
    private $subject;
    private $mockConfigReader;
    private $mockRequest;
    private $mockLogger;
    private $mockRepoCreator;

    public function setUp(): void {
        $this->mockRepoCreator = new MockRepoCreator();
        $this->mockRepoCreator->spinUp();
        parent::setUp();
        $this->mockConfigReader = $this->getMockBuilder(ConfigReader::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $this->mockRequest = $this->getMockBuilder(Request::class)
            ->onlyMethods(['getQueryParam'])
            ->getMock();
        $this->mockLogger = $this->createMock(Logger::class);
        $this->subject = new CustomCommands(
            $this->mockConfigReader,
            $this->mockRequest,
            $this->mockLogger,
            new DeployConfigReader(
                $this->mockConfigReader,
                $this->mockLogger
            )
        );
    }

    public function testCustomCommandsAreNotSpecified() {
        $this->mockConfigReader->expects($this->exactly(2))
            ->method('get')
            ->willReturnMap([
                [ConfigReader::CUSTOM_UPDATE_COMMANDS, null],
                [ConfigReader::REPOS_BASE_PATH, $this->mockRepoCreator::BASE_REPO_DIR],
            ]);
        $this->mockRequest->expects($this->once())
            ->method('getQueryParam')
            ->with($this->equalTo(Request::REPO_QUERY_PARAM))
            ->willReturn('repo-name-for-test');
        $customCommands = $this->subject->get();
        $this->assertNull($customCommands);
    }

    public function testGlobalCustomCommandsAreSpecifiedAsCollection() {
        $this->mockConfigReader->expects($this->atLeast(3))
            ->method('get')
            ->willReturnMap([
                [ConfigReader::CUSTOM_UPDATE_COMMANDS, [
                    ConfigReader::DEFAULT_COMMANDS => [
                        'command1',
                        'command2 $repo',
                        'command3',
                        'command4 $key',
                        'command5',
                        'command6 $ReposBasePath',
                        'command7',
                        'command8 $SSHKeysPath',
                    ],
                ]],
                [ConfigReader::REPOS_BASE_PATH, $this->mockRepoCreator::BASE_REPO_DIR],
                [ConfigReader::SSH_KEYS_PATH, '/home/tests/.ssh'],
            ]);
        $this->mockRequest->expects($this->atLeast(2))
            ->method('getQueryParam')
            ->willReturnMap([
                [Request::REPO_QUERY_PARAM, 'example-repo-name'],
                [Request::KEY_QUERY_PARAM, 'example-ssh-key'],
            ]);
        $customCommands = $this->subject->get();
        $this->assertEquals([
            'command1',
            'command2 example-repo-name',
            'command3',
            'command4 example-ssh-key',
            'command5',
            'command6 ' . $this->mockRepoCreator::BASE_REPO_DIR,
            'command7',
            'command8 /home/tests/.ssh',
        ], $customCommands);
    }

    public function testCustomCommandsAreSpecifiedAsPerRepoWithRepoJsonConfigFile() {
        $this->mockRepoCreator->withConfigJson([
            ConfigReader::CUSTOM_UPDATE_COMMANDS => ['ls -a $SSHKeysPath'],
        ]);
        $this->mockConfigReader->expects($this->exactly(3))
            ->method('get')
            ->willReturnMap([
                [ConfigReader::CUSTOM_UPDATE_COMMANDS, [
                    'example-repo1' => [
                        'command1',
                        'command2 $repo',
                        'command3',
                        'command4 $key',
                        'command5',
                        'command6 $ReposBasePath',
                        'command7',
                        'command8 $SSHKeysPath',
                    ],
                ]],
                [ConfigReader::REPOS_BASE_PATH, $this->mockRepoCreator::BASE_REPO_DIR],
                [ConfigReader::SSH_KEYS_PATH, '/home/tests/.ssh'],
            ]);
        $this->mockRequest->expects($this->atLeast(1))
            ->method('getQueryParam')
            ->willReturnMap([
                [Request::REPO_QUERY_PARAM, $this->mockRepoCreator->testRepoName],
                [Request::KEY_QUERY_PARAM, 'example-ssh-key'],
            ]);
        $customCommands = $this->subject->get();
        $this->assertEquals([
            'ls -a /home/tests/.ssh',
        ], $customCommands);
    }

    /**
     * @testWith ["yaml", "yml"]
     */
    public function testCustomCommandsAreSpecifiedAsPerRepoWithRepoYamlConfigFile(string $extension) {
        $this->mockRepoCreator->withConfigYaml([
            ConfigReader::CUSTOM_UPDATE_COMMANDS => ['runnotfound'],
        ], $extension);
        $this->mockConfigReader->expects($this->exactly(2))
            ->method('get')
            ->willReturnMap([
                [ConfigReader::CUSTOM_UPDATE_COMMANDS, [
                    'example-repo1' => [
                        'command1',
                        'command2 $repo',
                        'command3',
                        'command4 $key',
                        'command5',
                        'command6 $ReposBasePath',
                        'command7',
                        'command8 $SSHKeysPath',
                    ],
                ]],
                [ConfigReader::REPOS_BASE_PATH, $this->mockRepoCreator::BASE_REPO_DIR],
            ]);
        $this->mockRequest->expects($this->atLeast(1))
            ->method('getQueryParam')
            ->willReturnMap([
                [Request::REPO_QUERY_PARAM, $this->mockRepoCreator->testRepoName],
                [Request::KEY_QUERY_PARAM, 'example-ssh-key'],
            ]);
        $customCommands = $this->subject->get();
        $this->assertEquals([
            'runnotfound',
        ], $customCommands);
    }

    public function testYamlConfigWithUnknownKeysFallbackToGlobal() {
        $this->mockRepoCreator->withConfigYaml([
            'AUnknownKeyInTheConfigFile' => ['runnotfound'],
        ], 'yml');
        $this->mockConfigReader->expects($this->exactly(3))
            ->method('get')
            ->willReturnMap([
                [ConfigReader::CUSTOM_UPDATE_COMMANDS, [
                    '_default_' => [
                        'command45',
                        'command46 $ReposBasePath',
                        'command47 $key',
                    ],
                ]],
                [ConfigReader::REPOS_BASE_PATH, $this->mockRepoCreator::BASE_REPO_DIR],
            ]);
        $this->mockRequest->expects($this->atLeast(1))
            ->method('getQueryParam')
            ->willReturnMap([
                [Request::REPO_QUERY_PARAM, $this->mockRepoCreator->testRepoName],
                [Request::KEY_QUERY_PARAM, 'example-ssh-key'],
            ]);
        $customCommands = $this->subject->get();
        $this->assertEquals([
            'command45',
            'command46 ' . $this->mockRepoCreator::BASE_REPO_DIR,
            'command47 example-ssh-key',
        ], $customCommands);
    }

    public function testCustomCommandsAreSpecifiedAsPerRepo() {
        $this->mockConfigReader->expects($this->atLeast(2))
            ->method('get')
            ->willReturnMap([
                [ConfigReader::CUSTOM_UPDATE_COMMANDS, [
                    'example-repo1' => [
                        'command1',
                        'command2 $repo',
                        'command3',
                        'command4 $key',
                        'command5',
                        'command6 $ReposBasePath',
                        'command7',
                        'command8 $SSHKeysPath',
                    ],
                    'example-repo2' => [
                        'command9',
                        'command10 $repo',
                        'command11',
                        'command12 $key',
                        'command13',
                        'command14 $ReposBasePath',
                        'command15',
                        'command16 $SSHKeysPath',
                    ],
                ]],
                [ConfigReader::REPOS_BASE_PATH, $this->mockRepoCreator::BASE_REPO_DIR],
                [ConfigReader::SSH_KEYS_PATH, '/home/tests/.ssh'],
            ]);
        $this->mockRequest->expects($this->atLeast(2))
            ->method('getQueryParam')
            ->willReturnMap([
                [Request::REPO_QUERY_PARAM, 'example-repo2'],
                [Request::KEY_QUERY_PARAM, 'example-ssh-key'],
            ]);
        $customCommands = $this->subject->get();
        $this->assertEquals([
            'command9',
            'command10 example-repo2',
            'command11',
            'command12 example-ssh-key',
            'command13',
            'command14 ' . $this->mockRepoCreator::BASE_REPO_DIR,
            'command15',
            'command16 /home/tests/.ssh',
        ], $customCommands);
    }

    public function testCustomCommandsAreSpecifiedAsPerRepoButNoRepoFound() {
        $this->mockConfigReader->expects($this->atLeast(2))
            ->method('get')
            ->willReturnMap([
                [ConfigReader::CUSTOM_UPDATE_COMMANDS, [
                    'example-repo1' => [
                        'command1',
                        'command2 $repo',
                        'command3',
                        'command4 $key',
                        'command5',
                        'command6 $ReposBasePath',
                        'command7',
                        'command8 $SSHKeysPath',
                    ],
                    '_default_' => [
                        'simple',
                        'commands',
                        'for-the-generic-repo-behavior $repo',
                        'finishing-command',
                    ],
                ]],
                [ConfigReader::REPOS_BASE_PATH, $this->mockRepoCreator::BASE_REPO_DIR],
                [ConfigReader::SSH_KEYS_PATH, '/home/tests/.ssh'],
            ]);
        $this->mockRequest->expects($this->exactly(2))
            ->method('getQueryParam')
            ->willReturnMap([
                [Request::REPO_QUERY_PARAM, 'other-repo-not-considered-in-config'],
                [Request::KEY_QUERY_PARAM, 'example-ssh-key'],
            ]);
        $customCommands = $this->subject->get();
        $this->assertEquals([
            'simple',
            'commands',
            'for-the-generic-repo-behavior other-repo-not-considered-in-config',
            'finishing-command',
        ], $customCommands);
    }

    public function testCustomCommandsHandleSecrets() {
        $this->mockConfigReader->expects($this->atLeast(3))
            ->method('get')
            ->willReturnMap([
                [ConfigReader::CUSTOM_UPDATE_COMMANDS, [
                    ConfigReader::DEFAULT_COMMANDS => [
                        'echo ${{ secrets.github_ghcr_token }}',
                        'echo ${{ secrets.github_ghcr_username }}',
                        'echo $secrets.github_ghcr_token',
                        'echo $SSHKeysPath',
                        'echo $secrets.github_ghcr_username',
                    ],
                ]],
                [ConfigReader::REPOS_BASE_PATH, $this->mockRepoCreator::BASE_REPO_DIR],
                [ConfigReader::SECRETS, [
                    'github_ghcr_token' => 'my_secret_token',
                    'github_ghcr_username' => 'myusername',
                ]],
            ]);
        $this->mockRequest->expects($this->once())
            ->method('getQueryParam')
            ->with($this->equalTo(Request::REPO_QUERY_PARAM))
            ->willReturn('example-repo-name');
        $customCommands = $this->subject->get();
        $this->assertEquals([
            'echo my_secret_token',
            'echo myusername',
            'echo my_secret_token',
            'echo $SSHKeysPath',
            'echo myusername',
        ], $customCommands);
    }
}
