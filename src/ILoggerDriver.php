<?php

namespace Mariano\GitAutoDeploy;

interface ILoggerDriver {
    function write(string $content, string $date);
}
