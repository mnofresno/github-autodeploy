<?php

$TITLE   = 'Git Deployment Hamster';
$VERSION = '0.11';

echo <<<EOT
<!DOCTYPE HTML>
<html lang="en-US">
<head>
	<meta charset="UTF-8">
	<title>$TITLE</title>
</head>
<body style="background-color: #000000; color: #FFFFFF; font-weight: bold; padding: 0 10px;">
<pre>
  o-o    $TITLE
 /\\"/\   v$VERSION
(`=*=')
 ^---^`-.
EOT;

// Check whether client is allowed to trigger an update

$config = json_decode(
    file_get_contents(__DIR__ . '/config.json'),
    true
);

$ssh_keys_path = $config['SSHKeysPath'];
$allowed_ips = $config['IPsAllowList'];

$allowed = false;

$headers = array_intersect_key(
    $_SERVER,
    array_flip(
        preg_grep(
            '/^HTTP_/',
            array_keys($_SERVER),
            0
        )
    )
);

if (@$headers["HTTP_X_FORWARDED_FOR"]) {
    $ips = explode(",",$headers["HTTP_X_FORWARDED_FOR"]);
    $ip  = $ips[0];
} else {
    $ip = $_SERVER['REMOTE_ADDR'];
}

foreach ($allowed_ips as $allow) {
    if (stripos($ip, $allow) !== false) {
        $allowed = true;
        break;
    }
}

if (!$allowed) {
	header('HTTP/1.1 403 Forbidden');
 	echo "<span style=\"color: #ff0000\">Sorry, no hamster - better convince your parents!</span>\n";
    echo "</pre>\n</body>\n</html>";
    exit;
}

flush();

$sanitize = function (string $input): string {
    return preg_replace('/[^A-Za-z0-9_\-]/', '_', $input);
};

$escapedRepo = $sanitize($_GET['repo']);
$escapedKey = $sanitize($_GET['key']);

if (!$escapedRepo || !$escapedKey) {
	header('HTTP/1.1 422 Invalid Hook');
	echo "<span style=\"color: #ff0000\">Error reading hook no repo or key passed</span>\n";
	echo "</pre>\n</body>\n</html>";
	exit;
}

chdir('/var/www/' . $escapedRepo);

// Actually run the update

$commands = array(
	'echo $PWD',
	'whoami',
	'GIT_SSH_COMMAND="ssh -i ' . $ssh_keys_path . '/' . $escapedKey . '" git pull',
	'git status',
	'git submodule sync',
	'git submodule update',
	'git submodule status',
//    'test -e /usr/share/update-notifier/notify-reboot-required && echo "system restart required"',
);

$output = "\n";

$log = "####### ".date('Y-m-d H:i:s'). " #######\n";

foreach($commands AS $command){
    // Run it
    $tmp = shell_exec("$command 2>&1");
    // Output
    $output .= "<span style=\"color: #6BE234;\">\$</span> <span style=\"color: #729FCF;\">{$command}\n</span>";
    $output .= htmlentities(trim($tmp)) . "\n";

    $log  .= "\$ $command\n".trim($tmp)."\n";
}

$log .= "\n";

file_put_contents (__DIR__ . '/deploy-log.log',$log,FILE_APPEND);

echo $output;

?>
</pre>
</body>
</html>
