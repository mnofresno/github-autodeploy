<?php

namespace Mariano\GitAutoDeploy\Test;

use Mariano\GitAutoDeploy\Request;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase {
    public function testGetQueryParamWithCommas() {
        $serverVars = [
            'QUERY_STRING' => 'fields=date,message,logLevel',
        ];
        $request = Request::fromHttp($serverVars);
        $queryParam = $request->getQueryParam('fields');
        $this->assertEquals('date,message,logLevel', $queryParam);
    }

    public function testFromHttp() {
        $serverVars = [
            'REMOTE_ADDR' => '127.0.0.1',
            'QUERY_STRING' => 'fields=date,message,logLevel',
        ];
        $request = Request::fromHttp($serverVars);
        $this->assertEquals('127.0.0.1', $request->getRemoteAddress());
        $this->assertEquals('date,message,logLevel', $request->getQueryParam('fields'));
    }
}
