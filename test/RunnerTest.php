<?php

namespace Mariano\GitAutoDeploy\Test;

use Mariano\GitAutoDeploy\ConfigReader;
use Mariano\GitAutoDeploy\Request;
use Mariano\GitAutoDeploy\Response;
use Mariano\GitAutoDeploy\Runner;
use Mariano\GitAutoDeploy\Security;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class RunnerTest extends TestCase {
    private $subject;
    private $mockRequest;
    private $mockResponse;
    private $mockConfigReader;

    function setUp(): void {
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
            ->onlyMethods(['addToBody', 'setStatusCode', 'getRunId'])
            ->getMock();
        $this->subject = new Runner(
            $this->mockRequest,
            $this->mockResponse,
            $this->mockConfigReader,
            $this->createMock(Logger::class),
            $this->createMock(Security::class)
        );
    }

    function testRunNoQueryParamsGivenBadRequest() {
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

    function testAllAssertionsMetOk() {
        $thisDirectory = __DIR__;
        $this->mockRequest->expects($this->once())
            ->method('getHeaders')
            ->will($this->returnValue([]));
        $this->mockRequest->expects($this->once())
            ->method('getRemoteAddress')
            ->will($this->returnValue('127.0.0.1'));
        $this->mockRequest->expects($this->exactly(6))
            ->method('getQueryParam')
            ->will($this->returnValueMap([
                [Request::REPO_QUERY_PARAM, '.'],
                [Request::KEY_QUERY_PARAM, 'test-key-name']
            ]));
        $this->mockResponse->expects($this->once())
            ->method('setStatusCode')
            ->with($this->equalTo(200));
        $this->mockConfigReader->expects($this->exactly(5))
            ->method('get')
            ->will($this->returnValueMap([
                [ConfigReader::IPS_ALLOWLIST, ['127.0.0.1']],
                [ConfigReader::REPOS_BASE_PATH, $thisDirectory],
                [ConfigReader::CUSTOM_UPDATE_COMMANDS, [ConfigReader::DEFAULT_COMMANDS => ['echo -n ""']]]
            ]));
        $this->mockResponse->expects($this->exactly(3))
            ->method('addToBody')
            ->withConsecutive(
                ["<!DOCTYPE HTML>\n"
                ."<html lang=\"en-US\">\n"
                ."<head>\n"
                ."    <meta charset=\"UTF-8\">\n"
                ."    <title>Git Deployment Hamster</title>\n"
                ."</head>\n"
                ."<body style=\"background-color: #000000; color: #FFFFFF; font-weight: bold; padding: 0 10px;\">\n"
                ."<pre>\n"
                ."  o-o    Git Deployment Hamster\n"
                ." /\\\"/\   v0.11\n"
                ."(`=*=')\n"
                ." ^---^`-."],
                ["<span style=\"color: #6BE234;\">$</span>  <span style=\"color: #729FCF;\">echo -n \"\"\n"
                ."</span>"],
                ["</pre>\n"
                ."<div><b>RUN ID: </b></div></body>\n"
                ."</html>"]
            );
        $this->subject->run();
    }
}
