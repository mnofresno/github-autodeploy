<?php

namespace Mariano\GitAutoDeploy\Test;

use Mariano\GitAutoDeploy\ConfigReader;
use Mariano\GitAutoDeploy\Request;
use Mariano\GitAutoDeploy\Response;
use Mariano\GitAutoDeploy\Runner;
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
            ->onlyMethods(['getKey'])
            ->getMock();
        $this->mockResponse = $this->getMockBuilder(Response::class)
            ->onlyMethods(['addToBody', 'setStatusCode'])
            ->getMock();
        $this->subject = new Runner(
            $this->mockRequest,
            $this->mockResponse,
            $this->mockConfigReader
        );
    }

    function testRunNoQueryParamsGivenBadRequest() {
        $this->mockRequest->expects($this->once())
            ->method('getHeaders')
            ->will($this->returnValue([]));
        $this->mockRequest->expects($this->once())
            ->method('getRemoteAddress')
            ->will($this->returnValue('127.0.0.1'));
        $this->mockResponse->expects($this->once())
            ->method('setStatusCode')
            ->with($this->equalTo(400));
        $this->mockConfigReader->expects($this->once())
            ->method('getKey')
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
            ->method('getKey')
            ->will($this->returnValueMap([
                [ConfigReader::IPS_ALLOWLIST, ['127.0.0.1']],
                [ConfigReader::REPOS_BASE_PATH, $thisDirectory],
                [ConfigReader::CUSTOM_UPDATE_COMMANDS, ['ls $key $repo $ReposBasePath $SSHKeysPath']]
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
                ["<span style=\"color: #6BE234;\">$</span>  <span style=\"color: #729FCF;\">ls test-key-name . $thisDirectory \n"
                ."</span>ls: cannot access 'test-key-name': No such file or directory\n"
                .".:\n"
                ."CustomCommandsTest.php\n"
                ."LoggerTest.php\n"
                ."RunnerTest.php\n"
                ."SecurityTest.php\n"
                ."\n"
                ."$thisDirectory:\n"
                ."CustomCommandsTest.php\n"
                ."LoggerTest.php\n"
                ."RunnerTest.php\n"
                ."SecurityTest.php"],
                ["</pre>\n"
                ."</body>\n"
                ."</html>"]
            );
        $this->subject->run();
    }
}
