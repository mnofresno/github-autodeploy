<?php

namespace Mariano\GitAutoDeploy;

class LoggerContext {
    private $request;
    private $response;
    private $config;

    public function __construct(Request $request, Response $response, ConfigReader $config) {
        $this->request = $request;
        $this->response = $response;
        $this->config = $config;
    }

    public function append(array $context): array {
        $contextualizedMessage = [
            'context' => [
                'runId' => $this->response->getRunId(),
                'repo' => $this->request->getQueryParam(Request::REPO_QUERY_PARAM),
                'key' => $this->request->getQueryParam(Request::KEY_QUERY_PARAM),
                'request' => [
                    'body' => $this->config->get(ConfigReader::LOG_REQUEST_BODY) ? $this->request->getBody() : [],
                    'headers' => $this->request->getHeaders(),
                    'remote_address' => $this->request->getRemoteAddress()
                ]
            ]
        ];
        return array_merge($contextualizedMessage, $context);
    }
}
