<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SwagMigrationAssistant\Test\Migration\Media;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Log\Package;
use SwagMigrationAssistant\Exception\MigrationException;
use SwagMigrationAssistant\Migration\Connection\SwagMigrationConnectionEntity;
use SwagMigrationAssistant\Migration\Media\MediaFileProcessorRegistry;
use SwagMigrationAssistant\Migration\Media\MediaFileProcessorRegistryInterface;
use SwagMigrationAssistant\Migration\MigrationContext;
use SwagMigrationAssistant\Profile\Shopware\Gateway\Local\ShopwareLocalGateway;
use SwagMigrationAssistant\Profile\Shopware55\Shopware55Profile;
use SwagMigrationAssistant\Test\Mock\DummyCollection;
use SwagMigrationAssistant\Test\Mock\Gateway\Dummy\Local\DummyLocalGateway;
use SwagMigrationAssistant\Test\Mock\Migration\Media\DummyHttpMediaDownloadService;
use SwagMigrationAssistant\Test\Profile\Shopware\DataSet\FooDataSet;
use Symfony\Component\HttpFoundation\Response;

#[Package('services-settings')]
class MediaFileProcessorRegistryTest extends TestCase
{
    private MediaFileProcessorRegistryInterface $processorRegistry;

    protected function setUp(): void
    {
        $this->processorRegistry = new MediaFileProcessorRegistry(
            new DummyCollection(
                [
                    new DummyHttpMediaDownloadService(),
                ]
            )
        );
    }

    public function testGetProcessorNotFound(): void
    {
        $connection = new SwagMigrationConnectionEntity();
        $connection->setProfileName(Shopware55Profile::PROFILE_NAME);
        $connection->setGatewayName(ShopwareLocalGateway::GATEWAY_NAME);
        $connection->setCredentialFields([]);

        $context = new MigrationContext(
            new Shopware55Profile(),
            $connection,
            '',
            new FooDataSet()
        );
        $context->setGateway(new DummyLocalGateway());

        try {
            $this->processorRegistry->getProcessor($context);
        } catch (MigrationException $e) {
            static::assertSame(Response::HTTP_NOT_FOUND, $e->getStatusCode());
            static::assertSame(MigrationException::PROCESSOR_NOT_FOUND, $e->getErrorCode());
            static::assertSame(['profile' => 'shopware55', 'gateway' => 'local'], $e->getParameters());
        }
    }
}
