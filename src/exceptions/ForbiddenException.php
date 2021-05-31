<?php

namespace GitAutoDeploy\exceptions;

class ForbiddenException extends BaseException {
    function getStatusCode(): int {
        return 403;
    }
}
