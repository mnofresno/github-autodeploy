<?php

namespace Mariano\GitAutoDeploy;

use DI\Container;
use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Ramsey\Uuid\Uuid;

class ContainerProvider {
    private $lastRunId;

    public const LOG_FILE_PATH = __DIR__ . '/../deploy-log.log';

    public function provide(): Container {
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->addDefinitions([
            Logger::class => static function (ConfigReader $configReader, LoggerContext $loggerContext): Logger {
                $levels = Logger::getLevels();
                $debugLevel = $configReader->get('debug_level') ?? 'DEBUG';
                if (!array_key_exists($debugLevel, $levels)) {
                    throw new \InvalidArgumentException("Invalid debug level: $debugLevel");
                }
                $logger = new Logger('github-autodeploy');
                $logger->pushProcessor(function ($fullMessage) use ($loggerContext) {
                    $fullMessage['context'] = $loggerContext->append($fullMessage['context']);
                    return $fullMessage;
                });
                $handler = new StreamHandler(self::LOG_FILE_PATH, $levels[$debugLevel]);
                $logger->pushHandler($handler);
                return $logger;
            },
            Request::class => static function (): Request {
                return Request::fromHttp();
            },
            Response::class => function () {
                return new Response($this->getLastRunId());
            },
        ]);
        return $containerBuilder->build();
    }

    private function getLastRunId(): string {
        return $this->lastRunId ?? $this->lastRunId = Uuid::uuid4();
    }
}
