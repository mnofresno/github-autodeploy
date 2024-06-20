<?php

namespace Mariano\GitAutoDeploy\views;

class Footer extends BaseView {
    private $runId;

    public function __construct(string $runId) {
        $this->runId = $runId;
    }

    public function render(): string {
        return "</pre>\n<div><b>RUN ID: {$this->runId}</b></div></body>\n</html>";
    }
}
