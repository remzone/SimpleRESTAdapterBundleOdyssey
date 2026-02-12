// src/SimpleRESTAdapterBundle/Controller/ConfigController.php
<?php
/**
 * Simple REST Adapter.
 *
 * LICENSE
 *
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @copyright  Copyright (c) 2021 CI HUB GmbH (https://ci-hub.com)
 * @license    https://github.com/ci-hub-gmbh/SimpleRESTAdapterBundle/blob/master/gpl-3.0.txt GNU General Public License version 3 (GPLv3)
 */

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Controller;

use Exception;
use InvalidArgumentException;
use Pimcore\Bundle\AdminBundle\Controller\AdminController;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Bundle\DataHubBundle\Controller\ConfigController as BaseConfigController;
use Pimcore\Bundle\DataHubBundle\WorkspaceHelper;
use Pimcore\Model\Asset\Image\Thumbnail;
use Pimcore\Model\User;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use CIHub\Bundle\SimpleRESTAdapterBundle\Extractor\LabelExtractorInterface;
use CIHub\Bundle\SimpleRESTAdapterBundle\Manager\IndexManager;
use CIHub\Bundle\SimpleRESTAdapterBundle\Model\Event\ConfigurationEvent;
use CIHub\Bundle\SimpleRESTAdapterBundle\Model\Event\GetModifiedConfigurationEvent;
use CIHub\Bundle\SimpleRESTAdapterBundle\Reader\ConfigReader;
use CIHub\Bundle\SimpleRESTAdapterBundle\Repository\DataHubConfigurationRepository;
use CIHub\Bundle\SimpleRESTAdapterBundle\SimpleRESTAdapterEvents;

class ConfigController extends BaseConfigController
{
    private DataHubConfigurationRepository $configRepository;
    private EventDispatcherInterface $eventDispatcher;

    public function __construct(
        DataHubConfigurationRepository $configRepository,
        EventDispatcherInterface $eventDispatcher
    ) {
        $this->configRepository = $configRepository;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * Pimcore 11 / DataHub: parent signature is deleteAction(Request): ?JsonResponse
     */
    public function deleteAction(Request $request): ?JsonResponse
    {
        $this->checkPermission(BaseConfigController::CONFIG_NAME);

        try {
            $name = $request->get('name');
            $configuration = $this->configRepository->findOneByName($name);

            if (!$configuration instanceof Configuration) {
                throw new InvalidArgumentException(
                    sprintf('No DataHub configuration found for name "%s".', $name)
                );
            }

            $config = $configuration->getConfiguration();
            $preDeleteEvent = new ConfigurationEvent($config);
            $this->eventDispatcher->dispatch($preDeleteEvent, SimpleRESTAdapterEvents::CONFIGURATION_PRE_DELETE);

            WorkspaceHelper::deleteConfiguration($configuration);
            $configuration->delete();

            $postDeleteEvent = new ConfigurationEvent($config);
            $this->eventDispatcher->dispatch($postDeleteEvent, SimpleRESTAdapterEvents::CONFIGURATION_POST_DELETE);

            return $this->json(['success' => true]);
        } catch (Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * @param DataHubConfigurationRepository $configRepository
     * @param Request                        $request
     *
     * @return JsonResponse
     */
    public function getAction(DataHubConfigurationRepository $configRepository, Request $request): JsonResponse
    {
        $this->checkPermission(BaseConfigController::CONFIG_NAME);

        $configName = $request->get('name');
        $configuration = $configRepository->findOneByName($configName);

        if (!$configuration instanceof Configuration) {
            throw new InvalidArgumentException(
                sprintf('No DataHub configuration found for name "%s".', $configName)
            );
        }

        // Add endpoint routes to current config
        $reader = new ConfigReader($configuration->getConfiguration());
        $reader->add([
            'swaggerUrl' => $this->getEndpoint('simple_rest_adapter_swagger_ui'),
            'treeItemsUrl' => $this->getEndpoint('simple_rest_adapter_endpoints_tree_items', ['config' => $configName]),
            'searchUrl' => $this->getEndpoint('simple_rest_adapter_endpoints_get_element', ['config' => $configName]),
            'getElementByIdUrl' => $this->getEndpoint('simple_rest_adapter_endpoints_get_element', ['config' => $configName]),
        ]);

        return $this->json([
            'name' => $configName,
            'configuration' => $reader->toArray(),
            'modificationDate' => $configRepository->getModificationDate(),
        ]);
    }

    /**
     * @param DataHubConfigurationRepository $configRepository
     * @param IndexManager                   $indexManager
     * @param LabelExtractorInterface        $labelExtractor
     * @param Request                        $request
     *
     * @return JsonResponse
     */
    public function saveAction(
        DataHubConfigurationRepository $configRepository,
        IndexManager $indexManager,
        LabelExtractorInterface $labelExtractor,
        Request $request
    ): JsonResponse {
        $this->checkPermission(BaseConfigController::CONFIG_NAME);

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            throw new InvalidArgumentException('Invalid JSON body.');
        }

        $configName = $data['name'] ?? null;
        if (!is_string($configName) || $configName === '') {
            throw new InvalidArgumentException('Missing "name" in request body.');
        }

        $configuration = $configRepository->findOneByName($configName);
        if (!$configuration instanceof Configuration) {
            throw new InvalidArgumentException(
                sprintf('No DataHub configuration found for name "%s".', $configName)
            );
        }

        // Merge existing config with new values
        $reader = new ConfigReader($configuration->getConfiguration());
        $reader->merge($data['configuration'] ?? []);

        // Allow modifications via event
        $event = new GetModifiedConfigurationEvent($reader->toArray(), $configuration);
        $this->getEventDispatcher()->dispatch($event, SimpleRESTAdapterEvents::CONFIGURATION_MODIFY);

        $configuration->setConfiguration($event->getConfiguration());
        $configuration->save();

        // Update index config
        $indexManager->writeIndexConfiguration($configuration->getName(), $labelExtractor);

        return $this->json(['success' => true]);
    }

    /**
     * @param DataHubConfigurationRepository $configRepository
     * @param Request                        $request
     *
     * @return JsonResponse
     */
    public function listAction(DataHubConfigurationRepository $configRepository, Request $request): JsonResponse
    {
        $this->checkPermission(BaseConfigController::CONFIG_NAME);

        $configs = $configRepository->findAll();
        $configArray = [];

        foreach ($configs as $config) {
            if (!$config instanceof Configuration) {
                continue;
            }

            $configArray[] = [
                'name' => $config->getName(),
                'modificationDate' => $configRepository->getModificationDate(),
            ];
        }

        return $this->json([
            'configs' => $configArray,
        ]);
    }

    /**
     * @param RequestStack $requestStack
     * @param UrlGeneratorInterface $urlGenerator
     *
     * @return JsonResponse
     */
    public function getContextMenuAction(RequestStack $requestStack, UrlGeneratorInterface $urlGenerator): JsonResponse
    {
        $this->checkPermission(BaseConfigController::CONFIG_NAME);

        $request = $requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            throw new RuntimeException('Missing request.');
        }

        $configName = $request->get('name');
        if (!is_string($configName) || $configName === '') {
            throw new InvalidArgumentException('Missing "name".');
        }

        $links = [
            [
                'text' => 'Swagger UI',
                'url' => $urlGenerator->generate('simple_rest_adapter_swagger_ui', ['config' => $configName]),
            ],
        ];

        return $this->json([
            'success' => true,
            'links' => $links,
        ]);
    }

    /**
     * @param RequestStack $requestStack
     *
     * @return JsonResponse
     */
    public function getThumbnailsAction(RequestStack $requestStack): JsonResponse
    {
        $this->checkPermission(BaseConfigController::CONFIG_NAME);

        $request = $requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            throw new RuntimeException('Missing request.');
        }

        $thumbnailNames = Thumbnail\Config::getAssetThumbnailNames();
        $thumbnails = [];

        foreach ($thumbnailNames as $thumbnailName) {
            $thumbnails[] = [
                'name' => $thumbnailName,
            ];
        }

        return $this->json([
            'success' => true,
            'thumbnails' => $thumbnails,
        ]);
    }

    private function getEndpoint(string $route, array $params = []): string
    {
        return $this->generateUrl($route, $params, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function getEventDispatcher(): EventDispatcherInterface
    {
        // совместимость: в Pimcore/AdminController есть container, но тут проще явно.
        // в нашем deleteAction используем $this->eventDispatcher
        return $this->eventDispatcher;
    }
}
