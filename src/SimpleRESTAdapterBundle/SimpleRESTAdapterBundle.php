<?php

declare(strict_types=1);

namespace CIHub\Bundle\SimpleRESTAdapterBundle;

use Pimcore\Extension\Bundle\AbstractPimcoreBundle;
use Pimcore\Extension\Bundle\PimcoreBundleAdminClassicInterface;
use Pimcore\Extension\Bundle\Traits\PackageVersionTrait;

class SimpleRESTAdapterBundle extends AbstractPimcoreBundle implements PimcoreBundleAdminClassicInterface
{
    use PackageVersionTrait;

    public const PACKAGE_NAME = 'odyssey/simple-rest-adapter-bundle';

    public function getCssPaths(): array
    {
        return [
            '/bundles/simplerestadapter/pimcore/css/icons.css',
        ];
    }

    public function getJsPaths(): array
    {
        return [
            '/bundles/simplerestadapter/pimcore/js/startup.js',
            '/bundles/simplerestadapter/pimcore/js/adapter.js',
            '/bundles/simplerestadapter/pimcore/js/config-item.js',
            '/bundles/simplerestadapter/pimcore/js/grid-config-dialog.js',
        ];
    }

    // ✅ required by PimcoreBundleAdminClassicInterface in your Pimcore version
    public function getEditmodeJsPaths(): array
    {
        return [];
    }

    // ✅ required by PimcoreBundleAdminClassicInterface in your Pimcore version
    public function getEditmodeCssPaths(): array
    {
        return [];
    }

    protected function getComposerPackageName(): string
    {
        return self::PACKAGE_NAME;
    }
}
