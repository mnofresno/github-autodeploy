<?php

namespace GithubAutoDeploy\views;

abstract class BaseView {
    static function show() {
        (new static())->render();
    }

    abstract function render();
}
