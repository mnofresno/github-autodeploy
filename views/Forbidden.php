<?php

namespace GithubAutoDeploy\views;

class Forbidden {
    static function render() {
        header('HTTP/1.1 422 Invalid Hook');
        echo "<span style=\"color: #ff0000\">Error reading hook no repo or key passed</span>\n";
        echo "</pre>\n</body>\n</html>";
    }
}