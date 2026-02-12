<?php

declare(strict_types=1);

/**
 * ...header...
 */

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Controller;

use CIHub\Bundle\SimpleRESTAdapterBundle\Configuration\DataHubConfiguration;
use CIHub\Bundle\SimpleRESTAdapterBundle\Configuration\DataHubConfigGeneratorInterface;
use CIHub\Bundle\SimpleRESTAdapterBundle\Repository\DataHubConfigurationRepository;
use CIHub\Bundle\SimpleRESTAdapterBundle\Util\Storage\AdapterStorageInterface;
use Pimcore\Bundle\AdminBundle\Controller\AdminController;
use Pimcore\Bundle\DataHubBundle\Controller\ConfigController as BaseConfigController;
use Pimcore\Bundle\DataHubBundle\Event\ConfigurationEvent;
use Pimcore\Bundle\DataHubBundle\Event\DataHubEvents;
use Pimcore\Bundle\DataHubBundle\GraphQL\Service;
use Pimcore\Bundle\DataHubBundle\GraphQL\Util\GraphQL;
use Pimcore\Bundle\DataHubBundle\GraphQL\Util\I18n\LabelExtractor;
use Pimcore\Bundle\DataHubBundle\GraphQL\Util\I18n\LabelExtractorInterface;
use Pimcore\Config as ConfigManager;
use Pimcore\Translation\Translator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class ConfigController extends BaseConfigController
{
    public function __construct(
        private readonly DataHubConfigurationRepository $configRepository,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {
    }

    /**
     * @throws \Exception
     */
    public function getEndpointAction(Service $graphQlService, string $name, RequestStack $requestStack): JsonResponse
    {
        $configuration = $this->getConfiguration($this->configRepository, $name);

        $router = $this->container->get(RouterInterface::class);
        $graphQlUrl = $router->generate('pimcore_bundle_datahub_graphql', ['configuration' => $name]);

        $request = $requestStack->getCurrentRequest();
        $rootUrl = $request->getSchemeAndHttpHost();

        return $this->json(
            [
                'graphql' => [
                    'url' => $rootUrl . $graphQlUrl,
                    'clientName' => 'datahub',
                    'realm' => 'pimcore',
                    'cache' => [
                        'enabled' => $configuration->isCachingEnabled(),
                        'lifetime' => $configuration->getCacheLifetime(),
                    ],
                ],
                'schema' => $graphQlService->buildSchema($configuration),
            ]
        );
    }

    /**
     * @throws AccessDeniedException
     */
    public function getGraphiQlAction(): JsonResponse
    {
        if (!$this->checkPermission('plugin_datahub_config')) {
            throw new AccessDeniedException();
        }

        $router = $this->container->get(RouterInterface::class);

        return $this->json(
            [
                'datahub' => [
                    'graphQlUrl' => $router->generate('pimcore_bundle_datahub_graphql'),
                    'csrfToken' => $this->container->get('security.csrf.token_manager')
                        ->getToken('graphql_introspection')
                        ->getValue(),
                ],
            ]
        );
    }

    public function getGraphiQlHtmlAction(): JsonResponse
    {
        $this->checkPermission('plugin_datahub_config');

        $configManager = $this->container->get(ConfigManager::class);
        $settings = $configManager->getSystemConfiguration('datahub');
        $router = $this->container->get(RouterInterface::class);

        $translator = $this->container->get(Translator::class);

        $defaultLanguage = $settings['graphql']['ide']['defaultLanguage'] ?? 'en';

        return $this->json(
            [
                'template' => $this->renderView(
                    '@PimcoreDataHub/config/graphiql.html.twig',
                    [
                        'translations' => [
                            'newTab' => $translator->trans('graphiql_new_tab', [], 'admin'),
                            'closeTab' => $translator->trans('graphiql_close_tab', [], 'admin'),
                            'openTab' => $translator->trans('graphiql_open_tab', [], 'admin'),
                        ],
                        'defaultLanguage' => $defaultLanguage,
                        'settings' => [
                            'datahubUrl' => $router->generate('pimcore_bundle_datahub_graphiql_config'),
                            'graphqlUrl' => $router->generate('pimcore_bundle_datahub_graphql'),
                        ],
                    ]
                ),
            ]
        );
    }

    /**
     * DataHub 11: deleteAction(Request $request): ?JsonResponse
     */
    public function deleteAction(Request $request): ?JsonResponse
    {
        $this->checkPermission('config');

        $name = $request->get('name');
        $configuration = $this->getConfiguration($this->configRepository, $name);

        $this->eventDispatcher->dispatch(
            new ConfigurationEvent($configuration, $request),
            DataHubEvents::PRE_DELETE
        );

        $this->configRepository->delete($name);

        $this->eventDispatcher->dispatch(
            new ConfigurationEvent($configuration, $request),
            DataHubEvents::POST_DELETE
        );

        return $this->json(['success' => true]);
    }

    /**
     * DataHub 11: getAction(Request $request): JsonResponse
     */
    public function getAction(Request $request): JsonResponse
    {
        $this->checkPermission('config');

        $configuration = $this->getConfiguration($this->configRepository, $request->get('name'));

        return $this->json($configuration->getObjectVars());
    }

    /**
     * DataHub 11: listAction(Request $request): JsonResponse
     */
    public function listAction(Request $request): JsonResponse
    {
        $this->checkPermission('config');

        $configurations = $this->getConfigurationList($this->configRepository);

        $output = [];
        foreach ($configurations as $configuration) {
            $output[] = array_merge(
                $configuration->getObjectVars(),
                ['modificationDate' => $this->configRepository->getModificationDate($configuration->getName())]
            );
        }

        return $this->json(['data' => $output]);
    }

    /**
     * DataHub 11: saveAction(Request $request): JsonResponse
     */
    public function saveAction(Request $request): JsonResponse
    {
        $this->checkPermission('config');

        $data = json_decode((string)$request->get('data'), true);

        $configuration = new DataHubConfiguration();
        $configuration->setValues($data);

        $this->eventDispatcher->dispatch(
            new ConfigurationEvent($configuration, $request),
            DataHubEvents::PRE_SAVE
        );

        $this->configRepository->save($configuration);

        $this->eventDispatcher->dispatch(
            new ConfigurationEvent($configuration, $request),
            DataHubEvents::POST_SAVE
        );

        return $this->json(
            [
                'success' => true,
                'data' => array_merge(
                    $configuration->getObjectVars(),
                    ['modificationDate' => $this->configRepository->getModificationDate($configuration->getName())]
                ),
            ]
        );
    }

    /**
     * @throws \Exception
     */
    public function thumbnailsAction(Service $graphQlService, string $name, RequestStack $requestStack): JsonResponse
    {
        $this->checkPermission('config');

        $configuration = $this->getConfiguration($this->configRepository, $name);
        $configuration->setGraphQlService($graphQlService);

        $adapterStorage = $this->container->get(AdapterStorageInterface::class);
        $adapter = $adapterStorage->getAdapter($configuration->getName());
        $resultData = $adapter->thumbnailsAction(
            $configuration,
            $requestStack->getCurrentRequest()->get('thumbnail', [])
        );

        $this->eventDispatcher->dispatch(new ConfigurationEvent($configuration), DataHubEvents::PRE_SEND_DATA);

        return $this->json($resultData);
    }

    /**
     * @throws \Exception
     */
    public function labelListAction(Service\IndexService $indexManager, LabelExtractorInterface $labelExtractor, string $name, Request $request): JsonResponse
    {
        $configuration = $this->getConfiguration($this->configRepository, $name);
        $configuration->setRuntimeData(Service::OPERATION_NAME_STACK, $indexManager->getClientNameStack($configuration));

        $labels = $labelExtractor->extractLabels($configuration, $request->get('language', null));
        $this->eventDispatcher->dispatch(new ConfigurationEvent($configuration, $request), DataHubEvents::PRE_SEND_DATA);

        return $this->json(['labels' => $labels]);
    }

    public function getDataHubConfigAction(DataHubConfigGeneratorInterface $dataHubConfigGenerator, string $configName): JsonResponse
    {
        $dataHubConfiguration = $this->getConfiguration($this->configRepository, $configName);

        $request = $this->container->get('request_stack')->getMainRequest();
        $endpoint = $this->getEndpoint($request);
        $dataHubConfig = $dataHubConfigGenerator->getConfig($dataHubConfiguration, $endpoint);

        return $this->json($dataHubConfig);
    }

    public function downloadConfigAction(DataHubConfigGeneratorInterface $dataHubConfigGenerator, string $configName): JsonResponse
    {
        $dataHubConfiguration = $this->getConfiguration($this->configRepository, $configName);

        $request = $this->container->get('request_stack')->getMainRequest();
        $endpoint = $this->getEndpoint($request);
        $dataHubConfig = $dataHubConfigGenerator->getConfig($dataHubConfiguration, $endpoint);

        return $this->json($dataHubConfig, 200, [
            'Content-Disposition' => 'attachment; filename="config.json"',
        ]);
    }

    private function getEndpoint(Request $request): string
    {
        $router = $this->container->get(RouterInterface::class);
        $urlGenerator = $this->container->get(UrlGeneratorInterface::class);

        return $urlGenerator->generate(
            $router->getRouteCollection()->get('pimcore_bundle_datahub_graphql'),
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }

    /**
     * Pimcore permission check helper
     */
    private function checkPermission(string $permission): void
    {
        if (!$this->getAdminUser() || !$this->getAdminUser()->isAllowed($permission)) {
            throw new AccessDeniedException();
        }
    }
}
