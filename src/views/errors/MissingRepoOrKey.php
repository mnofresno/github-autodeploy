<?php

namespace Mariano\GitAutoDeploy\views\errors;

use Mariano\GitAutoDeploy\views\BaseView;

class MissingRepoOrKey extends BaseView {
    public function render(): string {
        return "<span style=\"color: #ff0000\">Error reading hook no repo or key passed</span>\n";
    }
}
