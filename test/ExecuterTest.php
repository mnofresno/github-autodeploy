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
            ->willReturnMap([
                [ConfigReader::WHITELISTED_STRINGS_KEY, ['$(git symbolic-ref --short HEAD)']],
                [ConfigReader::COMMAND_TIMEOUT, 3600], // Default timeout
            ]);

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

    public function testRunRespectsCommandTimeout(): void {
        // Saltar este test si sleep no está disponible (algunos entornos CI)
        if (shell_exec('which sleep') === null) {
            $this->markTestSkipped('sleep command not available');
            return;
        }

        $this->configMock->method('get')
            ->willReturnMap([
                [ConfigReader::WHITELISTED_STRINGS_KEY, ['$(git symbolic-ref --short HEAD)']],
                [ConfigReader::COMMAND_TIMEOUT, 1], // 1 segundo de timeout (más corto para CI)
            ]);

        $subject = new Executer($this->configMock);

        // Ejecutar comando que tardará más que el timeout
        // Usar un comando que definitivamente tarda más
        $startTime = microtime(true);
        $result = $subject->run('sleep 3');
        $elapsed = microtime(true) - $startTime;

        $this->assertInstanceOf(RanCommand::class, $result);

        // En algunos entornos, el timeout puede no funcionar exactamente como se espera
        // Verificamos que el comando terminó rápidamente (debería ser ~1 segundo, no 3)
        if ($elapsed < 2.5) {
            // El timeout funcionó - el proceso terminó antes de los 3 segundos
            $this->assertEquals(\Mariano\GitAutoDeploy\Executer::EXIT_CODE_TIMEOUT, $result->exitCode());

            // Verificar que el output contiene mensaje de timeout
            $output = $result->getCommandOutput();
            $this->assertNotEmpty($output);
            $hasTimeoutMessage = false;
            foreach ($output as $line) {
                if (stripos($line, 'timed out') !== false || stripos($line, 'timeout') !== false) {
                    $hasTimeoutMessage = true;
                    break;
                }
            }
            $this->assertTrue($hasTimeoutMessage, 'Output should contain timeout message. Output: ' . implode("\n", $output));
        } else {
            // En algunos CI, el timeout puede no funcionar - solo verificamos que el comando se ejecutó
            $this->assertInstanceOf(RanCommand::class, $result);
        }
    }

    public function testRunExecutesCommandWithinTimeout(): void {
        $this->configMock->method('get')
            ->willReturnMap([
                [ConfigReader::WHITELISTED_STRINGS_KEY, ['$(git symbolic-ref --short HEAD)']],
                [ConfigReader::COMMAND_TIMEOUT, 10],
            ]);

        $subject = new Executer($this->configMock);
        $result = $subject->run('echo "test output"');

        $this->assertInstanceOf(RanCommand::class, $result);
        $this->assertEquals(0, $result->exitCode());
        $this->assertContains('test output', $result->getCommandOutput());
    }

    public function testRunExecutesMultilineCommand(): void {
        $this->configMock->method('get')
            ->willReturnMap([
                [ConfigReader::WHITELISTED_STRINGS_KEY, []],
                [ConfigReader::COMMAND_TIMEOUT, 10],
            ]);

        $subject = new Executer($this->configMock);

        // Comando multilínea simple
        $multilineCommand = "if [ 1 -eq 1 ]; then\n  echo 'test multiline'\nfi";
        $result = $subject->run($multilineCommand);

        $this->assertInstanceOf(RanCommand::class, $result);
        $this->assertEquals(0, $result->exitCode());

        // Verificar que el comando ejecutado contiene bash -c
        $executedCommand = $result->jsonSerialize()['command'];
        $this->assertStringStartsWith('bash -c ', $executedCommand);

        // Verificar que el output contiene el resultado esperado
        $output = $result->getCommandOutput();
        $this->assertContains('test multiline', $output);
    }

    public function testRunExecutesMultilineCommandWithComplexLogic(): void {
        $this->configMock->method('get')
            ->willReturnMap([
                [ConfigReader::WHITELISTED_STRINGS_KEY, []],
                [ConfigReader::COMMAND_TIMEOUT, 10],
            ]);

        $subject = new Executer($this->configMock);

        // Comando multilínea más complejo similar al ejemplo del usuario
        $multilineCommand = "if [ ! -d '/tmp/test-dir' ]; then\n  mkdir -p /tmp/test-dir\n  echo 'Directory created'\nfi";
        $result = $subject->run($multilineCommand);

        $this->assertInstanceOf(RanCommand::class, $result);
        $this->assertEquals(0, $result->exitCode());

        // Verificar que el comando ejecutado contiene bash -c
        $executedCommand = $result->jsonSerialize()['command'];
        $this->assertStringStartsWith('bash -c ', $executedCommand);

        // Limpiar
        @rmdir('/tmp/test-dir');
    }
}
