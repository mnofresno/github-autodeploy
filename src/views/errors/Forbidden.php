<?php

namespace Mariano\GitAutoDeploy\views\errors;

use Mariano\GitAutoDeploy\views\BaseView;

class Forbidden extends BaseView {
    public function render(): string {
        return "<span style=\"color: #ff0000\">Sorry, no hamster - better convince your parents!</span>\n";
    }
}
