<?php

namespace Mariano\GitAutoDeploy\exceptions;

class BadRequestException extends BaseException {
    public function getStatusCode(): int {
        return 400;
    }
}
