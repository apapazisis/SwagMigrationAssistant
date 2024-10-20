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
use SwagMigrationAssistant\Migration\DataSelection\DefaultEntities;
use SwagMigrationAssistant\Migration\Logging\LoggingServiceInterface;
use SwagMigrationAssistant\Migration\Mapping\Lookup\CurrencyLookup;
use SwagMigrationAssistant\Migration\Media\MediaFileServiceInterface;
use SwagMigrationAssistant\Profile\Shopware6\Converter\CurrencyConverter;
use SwagMigrationAssistant\Profile\Shopware6\DataSelection\DataSet\CurrencyDataSet;
use SwagMigrationAssistant\Profile\Shopware6\Mapping\Shopware6MappingServiceInterface;

#[Package('services-settings')]
class CurrencyConverterTest extends ShopwareConverterTest
{
    protected function createConverter(
        Shopware6MappingServiceInterface $mappingService,
        LoggingServiceInterface $loggingService,
        MediaFileServiceInterface $mediaFileService,
        ?array $mappingArray = [],
    ): ConverterInterface {
        $currencyLookup = $this->createMock(CurrencyLookup::class);

        static::assertIsArray($mappingArray);

        foreach ($mappingArray as $mapping) {
            if ($mapping['entityName'] === DefaultEntities::CURRENCY) {
                $currencyLookup->method('get')->willReturn($mapping['newIdentifier']);
            }
        }

        return new CurrencyConverter(
            $mappingService,
            $loggingService,
            $currencyLookup,
        );
    }

    protected function createDataSet(): DataSet
    {
        return new CurrencyDataSet();
    }

    protected static function getFixtureBasePath(): string
    {
        return __DIR__ . '/../../../_fixtures/Shopware6/Currency/';
    }
}
