<?php

namespace GitAutoDeploy\Test;

use GitAutoDeploy\ILoggerDriver;
use GitAutoDeploy\Logger;
use GitAutoDeploy\Request;
use PHPUnit\Framework\TestCase;

class LoggerTest extends TestCase {
    private $mockRequest;
    private $mockDriver;

    function setUp(): void {
        parent::setUp();
        $this->mockDriver = $this->getMockBuilder(ILoggerDriver::class)
            ->setMockClassName('SomeDriverForTests')
            ->onlyMethods(['write'])
            ->getMock();
        $this->mockRequest = $this->getMockBuilder(Request::class)
            ->onlyMethods(['getQueryParam', 'getHeaders', 'getBody', 'getRemoteAddress'])
            ->getMock();
    }

    function testWriteLogWithRequestBodyAndHeaders() {
        $date = date('Y-m-d H:i:s');
        $this->mockRequest->expects($this->exactly(2))
            ->method('getQueryParam')
            ->will($this->returnValueMap([
                ['repo', 'the-given-repo'],
                ['key', 'the-given-key']
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
                'andotherkey' => 'withData'
            ]));
        $this->mockDriver->expects($this->once())
            ->method('write')
            ->with(
                $this->equalTo(
                    '{"context":'
                    .'{"timestamp":"'.$date.'",'
                    .'"repo":"the-given-repo",'
                    .'"key":"the-given-key",'
                    .'"request":{'
                        .'"body":{'
                            .'"first_request_body_field":"withavalue",'
                            .'"andotherkey":"withData"},'
                        .'"headers":{"someheader":"somevalue"},'
                        .'"remote_address":"181.241.11.9"}},'
                    .'"message":{"this":"field","must":"belogged"}'
                    .'}'
                ), $this->equalTo($date));
        Logger::log(
            ['this' => 'field', 'must' => 'belogged'],
            $this->mockRequest,
            $this->mockDriver
        );
    }
}
