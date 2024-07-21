<?php

use Mariano\GitAutoDeploy\DeployConfigReader;
use Mariano\GitAutoDeploy\ConfigReader;
use Mariano\GitAutoDeploy\exceptions\InvalidDeployFileException;
use Mariano\GitAutoDeploy\Test\MockRepoCreator;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class DeployConfigReaderTest extends TestCase {
    private $configReaderMock;
    private $loggerMock;
    private $deployConfigReader;
    private $mockRepoCreator;

    protected function setUp(): void {
        $this->configReaderMock = $this->createMock(ConfigReader::class);
        $this->loggerMock = $this->createMock(Logger::class);
        $this->deployConfigReader = new DeployConfigReader($this->configReaderMock, $this->loggerMock);
        $this->mockRepoCreator = new MockRepoCreator();
        $this->mockRepoCreator->spinUp();
    }

    protected function tearDown(): void {
        $this->mockRepoCreator->spinDown();
    }

    public function testFetchRepoConfigJson() {
        $this->configReaderMock->method('get')
            ->with(ConfigReader::REPOS_BASE_PATH)
            ->willReturn(MockRepoCreator::BASE_REPO_DIR);

        $this->mockRepoCreator->withConfigJson([
            ConfigReader::CUSTOM_UPDATE_COMMANDS => ['command1', 'command2'],
            ConfigReader::POST_FETCH_COMMANDS => ['command3', 'command4'],
            ConfigReader::PRE_FETCH_COMMANDS => ['command5', 'command6'],
        ]);

        $config = $this->deployConfigReader->fetchRepoConfig($this->mockRepoCreator->testRepoName);

        $this->assertNotNull($config);
        $this->assertEquals(['command1', 'command2'], $config->customCommands());
        $this->assertEquals(['command3', 'command4'], $config->postFetchCommands());
        $this->assertEquals(['command5', 'command6'], $config->preFetchCommands());
    }

    public function testFetchRepoConfigYaml() {
        $this->configReaderMock->method('get')
            ->with(ConfigReader::REPOS_BASE_PATH)
            ->willReturn(MockRepoCreator::BASE_REPO_DIR);

        $this->mockRepoCreator->withConfigYaml([
            ConfigReader::CUSTOM_UPDATE_COMMANDS => ['command1', 'command2'],
            ConfigReader::POST_FETCH_COMMANDS => ['command3', 'command4'],
            ConfigReader::PRE_FETCH_COMMANDS => ['command5', 'command6'],
        ]);

        $config = $this->deployConfigReader->fetchRepoConfig($this->mockRepoCreator->testRepoName);

        $this->assertNotNull($config);
        $this->assertEquals(['command1', 'command2'], $config->customCommands());
        $this->assertEquals(['command3', 'command4'], $config->postFetchCommands());
        $this->assertEquals(['command5', 'command6'], $config->preFetchCommands());
    }

    public function testFetchRepoConfigFileNotFound() {
        $this->configReaderMock->method('get')
            ->with(ConfigReader::REPOS_BASE_PATH)
            ->willReturn(MockRepoCreator::BASE_REPO_DIR);

        $config = $this->deployConfigReader->fetchRepoConfig('non-existent-repo');

        $this->assertNull($config);
    }

    public function testFetchRepoConfigJsonException() {
        $this->configReaderMock->method('get')
            ->with(ConfigReader::REPOS_BASE_PATH)
            ->willReturn(MockRepoCreator::BASE_REPO_DIR);

        file_put_contents($this->mockRepoCreator->getTestRepoPath() . DIRECTORY_SEPARATOR . DeployConfigReader::CUSTOM_CONFIG_FILE_NAME . '.json', '{invalid-json}');

        $this->loggerMock->expects($this->once())
            ->method('error');

        $config = $this->deployConfigReader->fetchRepoConfig($this->mockRepoCreator->testRepoName);

        $this->assertNull($config);
    }

    public function testFetchRepoConfigYamlException() {
        $this->configReaderMock->method('get')
            ->with(ConfigReader::REPOS_BASE_PATH)
            ->willReturn(MockRepoCreator::BASE_REPO_DIR);

        file_put_contents($this->mockRepoCreator->getTestRepoPath() . DIRECTORY_SEPARATOR . DeployConfigReader::CUSTOM_CONFIG_FILE_NAME . '.yaml', "invalid: yaml\n: invalid");

        $this->loggerMock->expects($this->once())
            ->method('error');

        $config = $this->deployConfigReader->fetchRepoConfig($this->mockRepoCreator->testRepoName);

        $this->assertNull($config);
    }

    public function testFetchRepoConfigExampleYaml() {
        $sourceFilePath = __DIR__ . '/.git-auto-deploy.example.yml';

        if (!file_exists($sourceFilePath)) {
            $this->markTestSkipped('El archivo .git-auto-deploy.example.yml no existe.');
        }

        $this->configReaderMock->method('get')
            ->with(ConfigReader::REPOS_BASE_PATH)
            ->willReturn(MockRepoCreator::BASE_REPO_DIR);

        $this->mockRepoCreator->withYmlFile($sourceFilePath);

        $config = $this->deployConfigReader->fetchRepoConfig($this->mockRepoCreator->testRepoName);

        $this->assertNotNull($config);
        $this->assertEquals(['ls'], $config->preFetchCommands());
        $this->assertEquals([
            'composer install',
            './auto-update/create_revision.sh',
            './build_apk.sh',
            'echo "Succesfully upgraded to last version: $(cat auto-update/public/revision.js)"',
        ], $config->postFetchCommands());

        foreach ($config->preFetchCommands() as $command) {
            $this->assertIsString($command);
        }

        foreach ($config->postFetchCommands() as $command) {
            $this->assertIsString($command);
        }
    }

    public function testInvalidDeployFileException() {
        $this->configReaderMock->method('get')
            ->with(ConfigReader::REPOS_BASE_PATH)
            ->willReturn(MockRepoCreator::BASE_REPO_DIR);

        $this->mockRepoCreator->withConfigYaml([
            ConfigReader::CUSTOM_UPDATE_COMMANDS => ['command1', 123, 'command3'],
            ConfigReader::POST_FETCH_COMMANDS => ['command4', new \stdClass(), 'command6'],
            ConfigReader::PRE_FETCH_COMMANDS => ['command7', ['array'], 'command9'],
        ]);

        $this->loggerMock->expects($this->once())
            ->method('error');
        $this->expectException(InvalidDeployFileException::class);

        $this->deployConfigReader->fetchRepoConfig($this->mockRepoCreator->testRepoName);
    }
}
