<?php

namespace Mariano\GitAutoDeploy\exceptions;

class ForbiddenException extends BaseException {
    function getStatusCode(): int {
        return 403;
    }
}
