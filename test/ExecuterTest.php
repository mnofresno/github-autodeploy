<?php

namespace Mariano\GitAutoDeploy\Test;

use Mariano\GitAutoDeploy\ConfigReader;
use Mariano\GitAutoDeploy\Executer;
use Mariano\GitAutoDeploy\views\RanCommand;
use PHPUnit\Framework\TestCase;

class ExecuterTest extends TestCase {
    private $subject;
    private $configMock;

    public function setUp(): void {
        $this->configMock = $this->createMock(ConfigReader::class);
        $this->configMock->method('get')
            ->with('whitelisted_command_strings')
            ->willReturn(['$(git symbolic-ref --short HEAD)']);

        $this->subject = new Executer($this->configMock);
    }

    public function testRunEscapesCommandWithoutWhitelistedStrings(): void {
        $command = 'ls -la';
        $result = $this->subject->run($command);

        $this->assertInstanceOf(RanCommand::class, $result);
        $this->assertEquals(escapeshellcmd($command), $result->jsonSerialize()['command']);
    }

    public function testRunDoesNotEscapeWhitelistedStrings(): void {
        $command = 'echo $(git symbolic-ref --short HEAD)';
        $result = $this->subject->run($command);

        $this->assertInstanceOf(RanCommand::class, $result);
        $this->assertEquals($command, $result->jsonSerialize()['command']);
    }

    public function testRunHandlesMultipleWhitelistedStrings(): void {
        $command = 'echo $(git symbolic-ref --short HEAD) && echo $(git symbolic-ref --short HEAD)';
        $expectedCommand = 'echo $(git symbolic-ref --short HEAD) \&\& echo $(git symbolic-ref --short HEAD)';
        $result = $this->subject->run($command);

        $this->assertInstanceOf(RanCommand::class, $result);
        $this->assertEquals($expectedCommand, $result->jsonSerialize()['command']);
    }

    public function testRunReplacesAndRestoresWhitelistedStrings(): void {
        $command = 'echo $(git symbolic-ref --short HEAD) && ls -la';
        $expectedCommand = 'echo $(git symbolic-ref --short HEAD) \&\& ls -la';
        $result = $this->subject->run($command);

        $this->assertInstanceOf(RanCommand::class, $result);
        $this->assertEquals($expectedCommand, $result->jsonSerialize()['command']);
    }
}
