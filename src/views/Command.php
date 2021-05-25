<?php

namespace GitAutoDeploy\views;

class Command extends BaseView {
    private $commands = [];

    function add(string $command, array $commandOutput = []) {
        $output = implode("\n", $commandOutput);
        $html = "<span style=\"color: #6BE234;\">\$</span>";
        $html .= "  <span style=\"color: #729FCF;\">{$command}\n</span>";
        $html .= htmlentities(trim($output));
        $this->commands[] = $html;
    }

    function render() {
        echo implode("\n", $this->commands);
    }
}
