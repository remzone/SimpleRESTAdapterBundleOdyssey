<?php

declare(strict_types=1);

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Controller;

use CIHub\Bundle\SimpleRESTAdapterBundle\Config\Config;
use CIHub\Bundle\SimpleRESTAdapterBundle\Event\DataHubConfigEvent;
use CIHub\Bundle\SimpleRESTAdapterBundle\Event\DataHubEvents;
use CIHub\Bundle\SimpleRESTAdapterBundle\Repository\ConfigRepository;
use Pimcore\Bundle\DataHubBundle\Controller\ConfigController as BaseConfigController;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Bundle\DataHubBundle\GraphQL\Service as GraphQlService;
use Pimcore\Controller\Traits\JsonHelperTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

class ConfigController extends BaseConfigController
{
    use JsonHelperTrait;

    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly RouterInterface $router,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly RequestStack $requestStack
    ) {
    }

    public function listAction(Request $request): JsonResponse
    {
        $this->checkPermission(BaseConfigController::CONFIG_NAME);

        $list = $this->configRepository->getList();

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

    public function getAction(
        Request $request,
        GraphQlService $graphQlService,
        EventDispatcherInterface $eventDispatcher
    ): JsonResponse {
        $this->checkPermission(BaseConfigController::CONFIG_NAME);

        // В Pimcore DataHub это query string, так же используем здесь
        $name = $request->query->getString('name');

        $configuration = $this->configRepository->findOneByName($name);

        if (!$configuration instanceof Configuration) {
            throw new \InvalidArgumentException(sprintf('No DataHub configuration found for name "%s".', $name));
        }

        if (method_exists($configuration, 'isAllowed') && !$configuration->isAllowed('read')) {
            throw $this->createAccessDeniedHttpException();
        }

        $config = $configuration->getConfiguration();

        // Добавляем ссылки на endpoints Simple REST Adapter, не ломая структуру DataHub
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

        $name = (string) $request->get('name');
        $endpoint = (string) $request->get('endpoint');

        $url = $this->router->getContext()->getScheme()
            . '://'
            . $this->router->getContext()->getHost()
            . $this->urlGenerator->generate('pimcore_datahub_webservice', [
                'name' => $name,
                'endpoint' => $endpoint,
            ]);

        return new JsonResponse([
            'success' => true,
            'url' => $url,
        ]);
    }

    private function absoluteRoute(string $routeName, array $params = []): string
    {
        $scheme = $this->router->getContext()->getScheme();
        $host = $this->router->getContext()->getHost();

        $path = $this->urlGenerator->generate($routeName, $params);

        return $scheme . '://' . $host . $path;
    }
}
