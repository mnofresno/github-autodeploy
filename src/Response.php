<?php

namespace Mariano\GitAutoDeploy;

use Mariano\GitAutoDeploy\views\BaseView;

class Response {
    const STATUS_MAP = [
        200 => 'OK',
        400 => 'Bad Request',
        403 => 'Forbidden'
    ];

    private $body = '';
    private $statusCode = 200;
    private $runId;

    public function __construct(string $runId) {
        $this->runId = $runId;
    }

    public function getRunId(): string {
        return $this->runId;
    }

    public function addViewToBody(BaseView $view): void {
        $this->addToBody($view->render());
    }

    function addToBody(string $contents): void {
        $this->body .= $contents;
    }

    function setStatusCode(int $statusCode): void {
        $this->statusCode = $statusCode;
    }

    function send(string $contentType = 'text/html;charset=UTF-8'): void {
        header("Content-Type: {$contentType}");
        header(
            sprintf(
                "HTTP/1.1 %s %s",
                $this->statusCode,
                self::STATUS_MAP[$this->statusCode]
            )
        );
        echo $this->body;
    }
}
