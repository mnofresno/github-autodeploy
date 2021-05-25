<?php

namespace GithubAutoDeploy\exceptions;

class ForbiddenException extends BaseException {
    protected function getStatus(): string {
        return '403 Forbidden';
    }
}
