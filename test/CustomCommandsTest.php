<?php

namespace GitAutoDeploy\Test;

use GitAutoDeploy\ConfigReader;
use GitAutoDeploy\CustomCommands;
use GitAutoDeploy\Request;
use PHPUnit\Framework\TestCase;

class CustomCommandsTest extends TestCase
{
    private $subject;
    private $mockConfigReader;
    private $mockRequest;

    public function setUp(): void
    {
        parent::setUp();
        $this->mockConfigReader = $this->getMockBuilder(ConfigReader::class)
            ->onlyMethods(['getKey'])
            ->getMock();
        $this->mockRequest = $this->getMockBuilder(Request::class)
            ->onlyMethods(['getQueryParam'])
            ->getMock();
        $this->subject = new CustomCommands($this->mockConfigReader, $this->mockRequest);
    }

    public function testCustomCommandsAreNotSpecified()
    {
        $this->mockConfigReader->expects($this->once())
            ->method('getKey')
            ->with($this->equalTo(ConfigReader::CUSTOM_UPDATE_COMMANDS))
            ->will($this->returnValue(null));
        $this->mockRequest->expects($this->once())
            ->method('getQueryParam')
            ->with($this->equalTo('repo'))
            ->will($this->returnValue('repo-name-for-test'));
        $customCommands = $this->subject->get();
        $this->assertNull($customCommands);
    }

    public function testCustomCommandsAreSpecifiedAsCollection()
    {
        $this->mockConfigReader->expects($this->exactly(17))
            ->method('getKey')
            ->will(
                $this->returnValueMap([
                [ConfigReader::CUSTOM_UPDATE_COMMANDS, [
                    'command1',
                    'command2 $repo',
                    'command3',
                    'command4 $key',
                    'command5',
                    'command6 $ReposBasePath',
                    'command7',
                    'command8 $SSHKeysPath'
                ]],
                [ConfigReader::REPOS_BASE_PATH, '/var/www/repos'],
                [ConfigReader::SSH_KEYS_PATH, '/home/tests/.ssh']
            ])
            );
        $this->mockRequest->expects($this->exactly(17))
            ->method('getQueryParam')
            ->will(
                $this->returnValueMap([
                ['repo', 'example-repo-name'],
                ['key', 'example-ssh-key']
            ])
            );
        $customCommands = $this->subject->get();
        $this->assertEquals([
            'command1',
            'command2 example-repo-name',
            'command3',
            'command4 example-ssh-key',
            'command5',
            'command6 /var/www/repos',
            'command7',
            'command8 /home/tests/.ssh'
        ], $customCommands);
    }

    public function testCustomCommandsAreSpecifiedAsPerRepo()
    {
        $this->mockConfigReader->expects($this->exactly(17))
            ->method('getKey')
            ->will(
                $this->returnValueMap([
                    [ConfigReader::CUSTOM_UPDATE_COMMANDS, [
                        'example-repo1' => [
                            'command1',
                            'command2 $repo',
                            'command3',
                            'command4 $key',
                            'command5',
                            'command6 $ReposBasePath',
                            'command7',
                            'command8 $SSHKeysPath'
                        ],
                        'example-repo2' => [
                            'command9',
                            'command10 $repo',
                            'command11',
                            'command12 $key',
                            'command13',
                            'command14 $ReposBasePath',
                            'command15',
                            'command16 $SSHKeysPath'
                        ]
                    ]],
                    [ConfigReader::REPOS_BASE_PATH, '/var/www/repos'],
                    [ConfigReader::SSH_KEYS_PATH, '/home/tests/.ssh']
                ])
            );
        $this->mockRequest->expects($this->exactly(17))
            ->method('getQueryParam')
            ->will(
                $this->returnValueMap([
                ['repo', 'example-repo2'],
                ['key', 'example-ssh-key']
            ])
            );
        $customCommands = $this->subject->get();
        $this->assertEquals([
            'command9',
            'command10 example-repo2',
            'command11',
            'command12 example-ssh-key',
            'command13',
            'command14 /var/www/repos',
            'command15',
            'command16 /home/tests/.ssh'
        ], $customCommands);
    }

    public function testCustomCommandsAreSpecifiedAsPerRepoButNoRepoFound()
    {
        $this->mockConfigReader->expects($this->exactly(9))
            ->method('getKey')
            ->will(
                $this->returnValueMap([
                    [ConfigReader::CUSTOM_UPDATE_COMMANDS, [
                        'example-repo1' => [
                            'command1',
                            'command2 $repo',
                            'command3',
                            'command4 $key',
                            'command5',
                            'command6 $ReposBasePath',
                            'command7',
                            'command8 $SSHKeysPath'
                        ],
                        'example-repo2' => [
                            'command9',
                            'command10 $repo',
                            'command11',
                            'command12 $key',
                            'command13',
                            'command14 $ReposBasePath',
                            'command15',
                            'command16 $SSHKeysPath'
                        ],
                        '_default_' => [
                            'simple',
                            'commands',
                            'for-the-generic-repo-behavior $repo',
                            'finishing-command'
                        ]
                    ]],
                    [ConfigReader::REPOS_BASE_PATH, '/var/www/repos'],
                    [ConfigReader::SSH_KEYS_PATH, '/home/tests/.ssh']
                ])
            );
        $this->mockRequest->expects($this->exactly(9))
            ->method('getQueryParam')
            ->will(
                $this->returnValueMap([
                ['repo', 'other-repo-not-considered-in-config'],
                ['key', 'example-ssh-key']
            ])
            );
        $customCommands = $this->subject->get();
        $this->assertEquals([
            'simple',
            'commands',
            'for-the-generic-repo-behavior other-repo-not-considered-in-config',
            'finishing-command'
        ], $customCommands);
    }
}
