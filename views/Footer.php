<?php

namespace GithubAutoDeploy\views;

class Footer implements ViewInterface {
    function render() {
        echo "</pre>\n</body>\n</html>";
    }

    static function show() {
        (new self())->render();
    }
}
