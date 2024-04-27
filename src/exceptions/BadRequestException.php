<?php

namespace Mariano\GitAutoDeploy\exceptions;

class BadRequestException extends BaseException {
    function getStatusCode(): int {
        return 400;
    }
}
