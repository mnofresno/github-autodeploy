<?php

namespace Mariano\GitAutoDeploy;

class GithubClient {
    public function fetchActionsCidrs(): array {
        $url = 'https://api.github.com/meta';
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: PHP',
                ],
            ],
        ];
        $context = stream_context_create($opts);
        $response = file_get_contents($url, false, $context);
        $data = json_decode($response, true);

        return $data['actions'] ?? [];
    }
}
