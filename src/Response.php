<?php

namespace Mariano\GitAutoDeploy;

use Mariano\GitAutoDeploy\views\BaseView;

class Response {
    public const STATUS_MAP = [
        200 => 'OK',
        201 => 'Created',
        400 => 'Bad Request',
        403 => 'Forbidden',
        500 => 'Internal Server Error',
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

    public function addToBody(string $contents): void {
        $this->body .= $contents;
    }

    public function setStatusCode(int $statusCode): void {
        $this->statusCode = $statusCode;
    }

    public function send(string $contentType = 'text/html;charset=UTF-8'): void {
        header("Content-Type: {$contentType}");
        $statusMessage = self::STATUS_MAP[$this->statusCode] ?? 'Unknown';
        header(
            sprintf(
                "HTTP/1.1 %s %s",
                $this->statusCode,
                $statusMessage
            )
        );
        echo $this->body;
    }
}
