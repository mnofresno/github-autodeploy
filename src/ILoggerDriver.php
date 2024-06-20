<?php

namespace Mariano\GitAutoDeploy;

interface ILoggerDriver {
    public function write(string $content, string $date): void;
}
