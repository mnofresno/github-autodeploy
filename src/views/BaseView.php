<?php

namespace Mariano\GitAutoDeploy\views;

abstract class BaseView {
    abstract public function render(): string;
}
