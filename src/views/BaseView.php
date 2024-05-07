<?php

namespace Mariano\GitAutoDeploy\views;

abstract class BaseView {
    abstract function render(): string;
}
