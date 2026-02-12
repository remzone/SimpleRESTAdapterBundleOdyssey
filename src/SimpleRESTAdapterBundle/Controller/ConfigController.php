<?php

declare(strict_types=1);

namespace SimpleRESTAdapterBundle\Controller;

use Pimcore\Bundle\DataHubBundle\Controller\ConfigController as BaseConfigController;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Bundle\DataHubBundle\GraphQL\Service;
use Pimcore\Controller\Traits\JsonHelperTrait;
use SimpleRESTAdapterBundle\Config\Config;
use SimpleRESTAdapterBundle\Event\DataHubConfigEvent;
use SimpleRESTAdapterBundle\Event\DataHubEvents;
use SimpleRESTAdapterBundle\Repository\ConfigRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * ВАЖНО:
 * - НЕ переопределяем checkPermission(): он уже есть в Pimcore\Controller\UserAwareController (protected)
 * - Сигнатура getAction должна совпадать с DataHub ConfigController (Pimcore 11)
 */
class ConfigController extends BaseConfigController
{
    use JsonHelperTrait;

    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {
    }

    public function listAction(Request $request): JsonResponse
    {
        // Pimcore permission guard (родительский, совместимый)
        $this->checkPermission(BaseConfigController::CONFIG_NAME);

        $list = $this->configRepository->getList();

        // Поддержка modificationDate (как у тебя было)
        foreach ($list as $key => $item) {
            if (!isset($item['name'])) {
                continue;
            }

            $cfg = $this->configRepository->findOneByName((string) $item['name']);
            if ($cfg instanceof Configuration) {
                $list[$key]['modificationDate'] = $cfg->getConfiguration()['general']['modificationDate']
                    ?? $this->configRepository->getModificationDate();
            }
        }

        return new JsonResponse(['success' => true, 'data' => $list]);
    }

    /**
     * Pimcore DataHub signature:
     * public function getAction(Request $request, Service $graphQlService, EventDispatcherInterface $eventDispatcher): JsonResponse
     */
    public function getAction(
        Request $request,
        Service $graphQlService,
        EventDispatcherInterface $eventDispatcher
    ): JsonResponse {
        $this->checkPermission(BaseConfigController::CONFIG_NAME);

        // как в DataHub
        $name = $request->query->getString('name');

        $configuration = $this->configRepository->findOneByName($name);

        if (!$configuration instanceof Configuration) {
            throw new \InvalidArgumentException(sprintf('No DataHub configuration found for name "%s".', $name));
        }

        if (method_exists($configuration, 'isAllowed') && !$configuration->isAllowed('read')) {
            throw $this->createAccessDeniedHttpException();
        }

        $config = $configuration->getConfiguration();

        // Добавляем ссылки на твои endpoint'ы (не ломая DataHub структуру)
        $config['simpleRestAdapter'] = array_merge($config['simpleRestAdapter'] ?? [], [
            'swaggerUrl' => $this->absoluteRoute('simple_rest_adapter_swagger_ui'),
            'treeItemsUrl' => $this->absoluteRoute('simple_rest_adapter_endpoints_tree_items', ['config' => $name]),
            'searchUrl' => $this->absoluteRoute('simple_rest_adapter_endpoints_get_element', ['config' => $name]),
            'getElementByIdUrl' => $this->absoluteRoute('simple_rest_adapter_endpoints_get_element', ['config' => $name]),
        ]);

        $supportedQueryDataTypes = $graphQlService->getSupportedDataObjectQueryDataTypes();
        $supportedMutationDataTypes = $graphQlService->getSupportedDataObjectMutationDataTypes();

        return new JsonResponse([
            'name' => $configuration->getName(),
            'configuration' => $config,

            'userPermissions' => [
                'update' => method_exists($configuration, 'isAllowed') ? $configuration->isAllowed('update') : true,
                'delete' => method_exists($configuration, 'isAllowed') ? $configuration->isAllowed('delete') : true,
            ],

            'supportedGraphQLQueryDataTypes' => $supportedQueryDataTypes,
            'supportedGraphQLMutationDataTypes' => $supportedMutationDataTypes,

            'modificationDate' => $config['general']['modificationDate'] ?? $this->configRepository->getModificationDate(),
        ]);
    }

    public function saveAction(Request $request): JsonResponse
    {
        $this->checkPermission(BaseConfigController::CONFIG_NAME);

        $data = (string) $request->get('data');
        $config = Config::getConfig($data);

        $config = $this->configRepository->save($config);

        $event = new DataHubConfigEvent($config->getName(), $config->getConfiguration());
        $this->eventDispatcher->dispatch($event, DataHubEvents::CONFIG_SAVE);

        return new JsonResponse(['success' => true]);
    }

    public function getEndpointAction(Request $request): JsonResponse
    {
        $this->checkPermission(BaseConfigController::CONFIG_NAME);

        return new JsonResponse([
            'success' => true,
            'url' => $this->getEndpoint(
                (string) $request->get('name'),
                (string) $request->get('endpoint'),
                (string) $request->get('type'),
                $this->container->get(RequestStack::class),
                $this->container->get(RouterInterface::class)->getContext()->getScheme()
                    . '://'
                    . $this->container->get(RouterInterface::class)->getContext()->getHost(),
                $this->container->get(UrlGeneratorInterface::class),
            ),
        ]);
    }

    /**
     * Оставляю твою реализацию (для pimcore_datahub_webservice).
     */
    private function getEndpoint(
        string $name,
        string $endpoint,
        string $type,
        RequestStack $requestStack,
        string $url,
        UrlGeneratorInterface $urlGenerator
    ): string {
        $path = $urlGenerator->generate('pimcore_datahub_webservice', [
            'name' => $name,
            'endpoint' => $endpoint,
        ]);

        return $url . $path;
    }

    /**
     * Абсолютная ссылка на роут (нужно для UI/внешних вызовов).
     */
    private function absoluteRoute(string $routeName, array $params = []): string
    {
        /** @var RouterInterface $router */
        $router = $this->container->get(RouterInterface::class);

        $scheme = $router->getContext()->getScheme();
        $host = $router->getContext()->getHost();

        /** @var UrlGeneratorInterface $urlGenerator */
        $urlGenerator = $this->container->get(UrlGeneratorInterface::class);

        $path = $urlGenerator->generate($routeName, $params);

        return $scheme . '://' . $host . $path;
    }
}
