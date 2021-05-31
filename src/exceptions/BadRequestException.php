<?php

namespace GitAutoDeploy\exceptions;

class BadRequestException extends BaseException {
    function getStatusCode(): int {
        return 400;
    }
}
