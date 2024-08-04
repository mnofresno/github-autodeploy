<?php

namespace Mariano\GitAutoDeploy\views\errors;

use Mariano\GitAutoDeploy\views\BaseView;

class RepoNotExists extends BaseView {
    public function render(): string {
        return "<span style=\"color: #ff0000\">Given repo does not exists</span>\n";
    }
}
