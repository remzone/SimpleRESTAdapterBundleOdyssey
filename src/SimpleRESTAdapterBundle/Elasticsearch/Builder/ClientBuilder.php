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

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Elasticsearch\Builder;

use CIHub\Bundle\SimpleRESTAdapterBundle\Exception\ESClientException;

final class ClientBuilder implements ClientBuilderInterface
{
    /**
     * @var string
     */
    private $engine;

    /**
     * @var array<int, string>
     */
    private $hosts;

    /**
     * @param string             $engine
     * @param array<int, string> $hosts
     */
    public function __construct(string $engine, array $hosts)
    {
        $this->engine = $engine;
        $this->hosts = $hosts;
    }

    /**
     * {@inheritdoc}
     */
    public function build(): object
    {
        if ('opensearch' === $this->engine) {
            if (!class_exists(\OpenSearch\ClientBuilder::class)) {
                throw new ESClientException(
                    'Search engine "opensearch" selected, but package "opensearch-project/opensearch-php" is not installed.'
                );
            }

            $client = \OpenSearch\ClientBuilder::create();
            $client->setHosts($this->hosts);

            return $client->build();
        }

        if ('elasticsearch' === $this->engine) {
            if (!class_exists(\Elastic\Elasticsearch\ClientBuilder::class)) {
                throw new ESClientException(
                    'Search engine "elasticsearch" selected, but package "elastic/elasticsearch" is not installed.'
                );
            }

            $client = \Elastic\Elasticsearch\ClientBuilder::create();
            $client->setHosts($this->hosts);

            return $client->build();
        }

        throw new ESClientException(sprintf('Unsupported search engine "%s".', $this->engine));
    }
}
