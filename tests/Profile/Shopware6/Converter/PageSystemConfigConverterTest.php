<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SwagMigrationAssistant\Test\Profile\Shopware6\Converter;

use Shopware\Core\Framework\Log\Package;
use SwagMigrationAssistant\Migration\Converter\ConverterInterface;
use SwagMigrationAssistant\Migration\DataSelection\DataSet\DataSet;
use SwagMigrationAssistant\Migration\Logging\LoggingServiceInterface;
use SwagMigrationAssistant\Migration\Media\MediaFileServiceInterface;
use SwagMigrationAssistant\Profile\Shopware6\Converter\PageSystemConfigConverter;
use SwagMigrationAssistant\Profile\Shopware6\DataSelection\DataSet\PageSystemConfigDataSet;
use SwagMigrationAssistant\Profile\Shopware6\Mapping\Shopware6MappingServiceInterface;

#[Package('services-settings')]
class PageSystemConfigConverterTest extends ShopwareConverterTest
{
    protected function createConverter(
        Shopware6MappingServiceInterface $mappingService,
        LoggingServiceInterface $loggingService,
        MediaFileServiceInterface $mediaFileService,
        ?array $mappingArray = [],
    ): ConverterInterface {
        return new PageSystemConfigConverter($mappingService, $loggingService);
    }

    protected function createDataSet(): DataSet
    {
        return new PageSystemConfigDataSet();
    }

    protected static function getFixtureBasePath(): string
    {
        return __DIR__ . '/../../../_fixtures/Shopware6/PageSystemConfig/';
    }
}
