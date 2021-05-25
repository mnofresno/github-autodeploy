<?php

namespace GitAutoDeploy\exceptions;

class BadRequestException extends BaseException {
    protected function getStatus(): string {
        return '422 Bad Request';
    }
}
