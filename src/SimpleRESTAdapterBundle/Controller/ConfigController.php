<?php

declare(strict_types=1);

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Controller;

use CIHub\Bundle\SimpleRESTAdapterBundle\Model\Event\ConfigurationEvent as SimpleRestConfigurationEvent;
use CIHub\Bundle\SimpleRESTAdapterBundle\Model\Event\GetModifiedConfigurationEvent;
use CIHub\Bundle\SimpleRESTAdapterBundle\Reader\ConfigReader;
use CIHub\Bundle\SimpleRESTAdapterBundle\Repository\DataHubConfigurationRepository;
use CIHub\Bundle\SimpleRESTAdapterBundle\SimpleRESTAdapterEvents;
use Pimcore\Bundle\DataHubBundle\Controller\ConfigController as BaseConfigController;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Bundle\DataHubBundle\GraphQL\Service as GraphQlService;
use Pimcore\Controller\Traits\JsonHelperTrait;
use Pimcore\Model\Exception\ConfigWriteException;
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
        private readonly DataHubConfigurationRepository $configRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly RouterInterface $router,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly RequestStack $requestStack
    ) {
    }

    public function listAction(Request $request): JsonResponse
    {
        $this->checkPermission(BaseConfigController::CONFIG_NAME);

        $list = $this->configRepository->getList(['simpleRest']);
        $tree = [];

        foreach ($list as $item) {
            if (!$item instanceof Configuration || !$item->isAllowed('read')) {
                continue;
            }

            $tree[] = [
                'id' => $item->getName(),
                'text' => htmlspecialchars((string) $item->getName()),
                'type' => 'config',
                'iconCls' => 'plugin_pimcore_datahub_icon_' . ($item->getType() ?: 'simpleRest'),
                'expandable' => false,
                'leaf' => true,
                'adapter' => $item->getType() ?: 'simpleRest',
                'writeable' => $item->isWriteable(),
                'permissions' => [
                    'delete' => $item->isAllowed('delete'),
                    'update' => $item->isAllowed('update'),
                ],
                'modificationDate' => $item->getConfiguration()['general']['modificationDate']
                    ?? $this->configRepository->getModificationDate(),
            ];
        }

        return new JsonResponse(['success' => true, 'data' => $tree]);
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

        if ((new Configuration(null, null))->isWriteable() === false) {
            throw new ConfigWriteException();
        }

        $data = $request->request->getString('data');
        $modificationDate = $request->request->getInt('modificationDate', 0);
        $dataDecoded = json_decode($data, true);
        if (!is_array($dataDecoded)) {
            throw new \InvalidArgumentException('Invalid configuration payload.');
        }

        $name = (string) ($dataDecoded['general']['name'] ?? '');
        $config = $this->configRepository->findOneByName($name);
        if (!$config instanceof Configuration) {
            throw new \InvalidArgumentException(sprintf('No DataHub configuration found for name "%s".', $name));
        }

        if (!$config->isAllowed('read') || !$config->isAllowed('update')) {
            return new JsonResponse(['success' => false, 'permissionError' => true]);
        }

        $priorConfiguration = $config->getConfiguration();
        $savedModificationDate = 0;
        if ($priorConfiguration && isset($priorConfiguration['general']['modificationDate'])) {
            $savedModificationDate = (int) $priorConfiguration['general']['modificationDate'];
        }

        if ($modificationDate > 0 && $modificationDate < $savedModificationDate) {
            return new JsonResponse([
                'success' => false,
                'message' => 'The configuration was modified during editing, please reload the configuration and make your changes again',
            ]);
        }

        $dataDecoded['general']['modificationDate'] = time();

        $preSaveEvent = new GetModifiedConfigurationEvent($dataDecoded, $priorConfiguration);
        $this->eventDispatcher->dispatch($preSaveEvent, SimpleRESTAdapterEvents::CONFIGURATION_PRE_SAVE);

        $modifiedConfiguration = $preSaveEvent->getModifiedConfiguration() ?? $dataDecoded;
        $config->setConfiguration($modifiedConfiguration);
        $config->save();

        $postSaveEvent = new SimpleRestConfigurationEvent($config->getConfiguration(), $priorConfiguration);
        $this->eventDispatcher->dispatch($postSaveEvent, SimpleRESTAdapterEvents::CONFIGURATION_POST_SAVE);

        return new JsonResponse([
            'success' => true,
            'modificationDate' => $config->getConfiguration()['general']['modificationDate'] ?? null,
        ]);
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

    public function labelListAction(Request $request): JsonResponse
    {
        $this->checkPermission(BaseConfigController::CONFIG_NAME);

        $name = $request->query->getString('name');
        $configuration = $this->configRepository->findOneByName($name);

        if (!$configuration instanceof Configuration) {
            return new JsonResponse(['success' => false, 'message' => sprintf('No DataHub configuration found for name "%s".', $name)]);
        }

        $labelSettings = (new ConfigReader($configuration->getConfiguration()))->getLabelSettings();
        $labelList = [];

        foreach ($labelSettings as $setting) {
            $labelId = $setting['id'] ?? null;
            if (is_string($labelId) && $labelId !== '') {
                $labelList[] = $labelId;
            }
        }

        return new JsonResponse(['success' => true, 'labelList' => array_values(array_unique($labelList))]);
    }

    public function thumbnailsAction(Request $request): JsonResponse
    {
        return $this->thumbnailTreeAction($request);
    }

    private function absoluteRoute(string $routeName, array $params = []): string
    {
        $scheme = $this->router->getContext()->getScheme();
        $host = $this->router->getContext()->getHost();

        $path = $this->urlGenerator->generate($routeName, $params);

        return $scheme . '://' . $host . $path;
    }
}
