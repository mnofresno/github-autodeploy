<?php

namespace Mariano\GitAutoDeploy\Test;

use Mariano\GitAutoDeploy\ConfigReader;
use Mariano\GitAutoDeploy\ContainerProvider;
use Mariano\GitAutoDeploy\Request;
use Mariano\GitAutoDeploy\Response;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class LoggerTest extends TestCase {
    private $mockRequest;
    private $mockResponse;
    private $subject;
    private $mockConfig;

    public function setUp(): void {
        parent::setUp();
        $container = (new ContainerProvider())->provide();
        $this->mockResponse = $this->createMock(Response::class);
        $this->mockResponse->method('getRunId')->willReturn('run_id_for_tests');
        $this->mockRequest = $this->getMockBuilder(Request::class)
            ->onlyMethods(['getQueryParam', 'getHeaders', 'getBody', 'getRemoteAddress'])
            ->getMock();
        $container->set(Request::class, $this->mockRequest);
        $container->set(Response::class, $this->mockResponse);
        $this->mockConfig = $this->createMock(ConfigReader::class);
        $container->set(ConfigReader::class, $this->mockConfig);
        $this->subject = $container->get(Logger::class);
    }

    public function testWriteLogWithRequestBodyAndHeaders() {
        $this->mockConfig->method('get')->willReturnMap([['debug_level', 'INFO'], [ConfigReader::LOG_REQUEST_BODY, true]]);
        $this->mockRequest->expects($this->exactly(2))
            ->method('getQueryParam')
            ->will($this->returnValueMap([
                ['repo', 'the-given-repo'],
                ['key', 'the-given-key'],
            ]));
        $this->mockRequest->expects($this->once())
            ->method('getRemoteAddress')
            ->will($this->returnValue('181.241.11.9'));
        $this->mockRequest->expects($this->once())
            ->method('getHeaders')
            ->will($this->returnValue(['someheader' => 'somevalue']));
        $this->mockRequest->expects($this->once())
            ->method('getBody')
            ->will($this->returnValue([
                'first_request_body_field' => 'withavalue',
                'andotherkey' => 'withData',
            ]));
        $this->subject->info('message for test');
        $logContents = file_get_contents(ContainerProvider::LOG_FILE_PATH);
        $logRows = explode('\n', $logContents);
        $lastRow = end($logRows);
        $this->assertStringContainsString('message for test', $lastRow);
        $this->assertStringContainsString(
            '{"context":'
            . '{"runId":"run_id_for_tests",'
            . '"repo":"the-given-repo",'
            . '"key":"the-given-key",'
            . '"request":{'
                . '"body":{'
                    . '"first_request_body_field":"withavalue",'
                    . '"andotherkey":"withData"},'
                . '"headers":{"someheader":"somevalue"},'
                . '"remote_address":"181.241.11.9"}}'
            . '}',
            $lastRow
        );
    }

    public function testWriteLogWithoutRequestBodyJustHeaders() {
        $this->mockConfig->method('get')->willReturnMap([['debug_level', 'INFO'], [ConfigReader::LOG_REQUEST_BODY, false]]);
        $this->mockRequest->expects($this->exactly(2))
            ->method('getQueryParam')
            ->will($this->returnValueMap([
                ['repo', 'the-given-repo'],
                ['key', 'the-given-key'],
            ]));
        $this->mockRequest->expects($this->once())
            ->method('getRemoteAddress')
            ->will($this->returnValue('181.241.11.9'));
        $this->mockRequest->expects($this->once())
            ->method('getHeaders')
            ->will($this->returnValue(['someheader' => 'somevalue']));
        $this->mockRequest->expects($this->never())
            ->method('getBody')
            ->will($this->returnValue([
                'first_request_body_field' => 'withavalue',
                'andotherkey' => 'withData',
            ]));
        $this->subject->info('message for test');
        $logContents = file_get_contents(ContainerProvider::LOG_FILE_PATH);
        $logRows = explode('\n', $logContents);
        $lastRow = end($logRows);
        $this->assertStringContainsString('message for test', $lastRow);
        $this->assertStringContainsString(
            '{"context":'
            . '{"runId":"run_id_for_tests",'
            . '"repo":"the-given-repo",'
            . '"key":"the-given-key",'
            . '"request":{'
                . '"body":[],'
                . '"headers":{"someheader":"somevalue"},'
                . '"remote_address":"181.241.11.9"}}'
            . '}',
            $lastRow
        );
    }
}
