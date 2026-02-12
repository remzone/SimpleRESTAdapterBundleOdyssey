<?php

declare(strict_types=1);

/**
 *  Copyright 2021 CI Hub GmbH. All rights reserved.
 */

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Controller;

use CIHub\Bundle\SimpleRESTAdapterBundle\Configuration\DataHubConfigGeneratorInterface;
use CIHub\Bundle\SimpleRESTAdapterBundle\Extractor\LabelExtractorInterface;
use CIHub\Bundle\SimpleRESTAdapterBundle\Repository\DataHubConfigurationRepository;
use Pimcore\Bundle\DataHubBundle\Controller\ConfigController as BaseConfigController;
use Pimcore\Bundle\DataHubBundle\Event\DataHubConfigEvent;
use Pimcore\Bundle\DataHubBundle\Event\DataHubEvents;
use Pimcore\Bundle\DataHubBundle\GraphQL\DataHub\GraphQLDataHubProcessor\Config;
use Pimcore\Bundle\DataHubBundle\GraphQL\Service\IndexManager;
use Pimcore\Bundle\DataHubBundle\GraphQL\Service\IndexNameGeneratorInterface;
use Pimcore\Bundle\DataHubBundle\GraphQL\Service\QueryColumnConfig\Helper\QueryConfig;
use Pimcore\Controller\UserAwareController;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\User;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Pimcore 11 / DataHub controller adapter.
 *
 * В Pimcore 11 методы контроллеров DataHub имеют строгие сигнатуры.
 * Если вы переопределяете listAction/saveAction/getAction/deleteAction,
 * сигнатуры должны совпадать с базовым контроллером DataHub.
 */
class ConfigController extends BaseConfigController implements UserAwareController
{
    public function __construct(
        private readonly DataHubConfigurationRepository $configRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function initAction(): void
    {
        $this->checkPermission('config');
    }

    public function labelListAction(
        IndexManager $indexManager,
        LabelExtractorInterface $labelExtractor,
        Request $request
    ): JsonResponse {
        $this->checkPermission('config');

        $configName = (string) $request->get('name');
        $configuration = $this->configRepository->findOneByName($configName);

        if (!$configuration) {
            return new JsonResponse(['success' => false, 'message' => 'Config not found'], 404);
        }

        $index = $indexManager->getIndex($configuration->getName());
        $objectClass = ClassDefinition::getById($configuration->getClassId());

        if (null === $objectClass) {
            return new JsonResponse(['success' => false, 'message' => 'No class found'], 404);
        }

        $columns = QueryConfig::fromArray($configuration->getColumnConfig());
        $labelList = $labelExtractor->getLabelList($columns, $objectClass, $index->getClient());

        return new JsonResponse(['success' => true, 'data' => $labelList]);
    }

    public function columnsConfigAction(
        DataHubConfigGeneratorInterface $configGenerator,
        Request $request
    ): JsonResponse {
        $this->checkPermission('config');

        $data = $request->get('data');
        $config = Config::getConfig($data);
        $config = $configGenerator->generateConfig($config);

        return new JsonResponse([
            'success' => true,
            'data' => $config->getColumnConfig(),
        ]);
    }

    public function thumbnailsAction(
        IndexNameGeneratorInterface $indexNameGenerator,
        IndexManager $indexManager,
        Request $request
    ): JsonResponse {
        $this->checkPermission('config');

        $name = (string) $request->get('name');

        $client = $indexManager->getIndex($indexNameGenerator->generateName($name))->getClient();
        $response = $client->indices()->getMapping();
        $fields = current($response)['mappings']['properties'] ?? [];

        $thumbnails = [];
        $images = $fields['images']['properties'] ?? null;

        if ($images !== null) {
            foreach ($images as $key => $value) {
                if (str_starts_with((string) $key, 'thumbnail')) {
                    $short = substr((string) $key, 9);
                    if (!empty($short)) {
                        $thumbnails[$short] = $short;
                    }
                }
            }
        }

        return new JsonResponse([
            'success' => true,
            'data' => $thumbnails,
        ]);
    }

    /**
     * IMPORTANT (Pimcore 11): signature must match base DataHub controller.
     */
    public function deleteAction(Request $request): JsonResponse
    {
        $this->checkPermission('config');

        $name = (string) $request->get('name');

        $config = $this->configRepository->findOneByName($name);
        if (!$config) {
            return new JsonResponse(['success' => false, 'message' => 'Config not found'], 404);
        }

        $event = new DataHubConfigEvent($name, $config->getConfiguration());
        $this->eventDispatcher->dispatch($event, DataHubEvents::CONFIG_DELETE);

        $this->configRepository->delete($name);

        return new JsonResponse(['success' => true]);
    }

    /**
     * IMPORTANT (Pimcore 11): signature must match base DataHub controller.
     */
    public function getAction(Request $request): JsonResponse
    {
        $this->checkPermission('config');

        $name = (string) $request->get('name');
        $config = $this->configRepository->findOneByName($name);

        if (!$config) {
            return new JsonResponse(['success' => false, 'message' => 'Config not found'], 404);
        }

        return new JsonResponse([
            'success' => true,
            'data' => $config->getConfiguration(),
        ]);
    }

    /**
     * IMPORTANT (Pimcore 11): signature must match base DataHub controller.
     */
    public function listAction(Request $request): JsonResponse
    {
        $this->checkPermission('config');

        $list = $this->getConfigurationList($this->configRepository);

        foreach ($list as $key => $configuration) {
            $cfg = $this->configRepository->findOneByName($configuration['name']);
            if ($cfg) {
                $list[$key]['modificationDate'] = $cfg->getModificationDate();
            }
        }

        return new JsonResponse(['success' => true, 'data' => $list]);
    }

    /**
     * IMPORTANT (Pimcore 11): signature must match base DataHub controller.
     */
    public function saveAction(Request $request): JsonResponse
    {
        $this->checkPermission('config');

        $data = $request->get('data');
        $config = Config::getConfig($data);

        $config = $this->configRepository->save($config);

        $event = new DataHubConfigEvent($config->getName(), $config->getConfiguration());
        $this->eventDispatcher->dispatch($event, DataHubEvents::CONFIG_SAVE);

        return new JsonResponse(['success' => true]);
    }

    public function getEndpointAction(Request $request): JsonResponse
    {
        $this->checkPermission('config');

        /** @var RequestStack $requestStack */
        $requestStack = $this->container->get(RequestStack::class);

        /** @var RouterInterface $router */
        $router = $this->container->get(RouterInterface::class);

        /** @var UrlGeneratorInterface $urlGenerator */
        $urlGenerator = $this->container->get(UrlGeneratorInterface::class);

        $baseUrl = $router->getContext()->getScheme() . '://' . $router->getContext()->getHost();

        return new JsonResponse([
            'success' => true,
            'url' => $this->getEndpoint(
                (string) $request->get('name'),
                (string) $request->get('endpoint'),
                (string) $request->get('type'),
                $requestStack,
                $baseUrl,
                $urlGenerator
            ),
        ]);
    }

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

    private function checkPermission(string $permission): void
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        // admin => allow all
        if (method_exists($user, 'isAdmin') && $user->isAdmin()) {
            return;
        }

        if (!$user->isAllowed($permission)) {
            throw new AccessDeniedException();
        }
    }
}
