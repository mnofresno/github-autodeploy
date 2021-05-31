<?php

namespace GitAutoDeploy\views;

abstract class BaseView {
    static function show(): string {
        return (new static())->render();
    }

    abstract function render(): string;
}
