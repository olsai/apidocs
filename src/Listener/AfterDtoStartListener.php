<?php

declare(strict_types=1);

namespace Hyperf\ApiDocs\Listener;

use Closure;
use Hyperf\ApiDocs\Swagger\SwaggerJson;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\DTO\Event\AfterDtoStart;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\HttpServer\Router\Handler;
use Hyperf\Utils\ApplicationContext;
use RuntimeException;

class AfterDtoStartListener implements ListenerInterface
{
    public function listen(): array
    {
        return [
            AfterDtoStart::class,
        ];
    }

    /**
     * @param AfterDtoStart $event
     */
    public function process(object $event): void
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(StdoutLoggerInterface::class);
        $config = $container->get(ConfigInterface::class);
        $server = $event->serverConfig;
        $router = $event->router;

        if (! $config->get('api_docs.enable', false)) {
            return;
        }
        $outputDir = $config->get('api_docs.output_dir');
        if (! $outputDir) {
            return;
        }
        $swagger = new SwaggerJson($server['name']);
        foreach ($router->getData() ?? [] as $routeData) {
            foreach ($routeData ?? [] as $methods => $handlerArr) {
                array_walk_recursive($handlerArr, function ($item) use ($swagger, $methods) {
                    if ($item instanceof Handler && ! ($item->callback instanceof Closure)) {
                        $prepareHandler = $this->prepareHandler($item->callback);
                        if (count($prepareHandler) > 1) {
                            [$controller, $methodName] = $prepareHandler;
                            $swagger->addPath($controller, $methodName, $item->route, $methods);
                        }
                    }
                });
            }
        }
        $swagger->save();
        $logger->debug('swagger server:[' . $server['name'] . '] file has been generated');
    }

    protected function prepareHandler($handler): array
    {
        if (is_string($handler)) {
            if (str_contains($handler, '@')) {
                return explode('@', $handler);
            }
            return explode('::', $handler);
        }
        if (is_array($handler) && isset($handler[0], $handler[1])) {
            return $handler;
        }
        throw new RuntimeException('Handler not exist.');
    }
}
