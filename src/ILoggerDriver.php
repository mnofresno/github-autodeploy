<?php

namespace GitAutoDeploy;

interface ILoggerDriver {
    function write(string $content, string $date);
}
