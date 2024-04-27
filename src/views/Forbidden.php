<?php

namespace Mariano\GitAutoDeploy\views;

class Forbidden extends BaseView {
    function render(): string {
        return "<span style=\"color: #ff0000\">Sorry, no hamster - better convince your parents!</span>\n";
    }
}
