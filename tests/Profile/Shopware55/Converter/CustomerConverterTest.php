<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SwagMigrationAssistant\Test\Profile\Shopware55\Converter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\TestDefaults;
use SwagMigrationAssistant\Migration\Connection\SwagMigrationConnectionEntity;
use SwagMigrationAssistant\Migration\DataSelection\DefaultEntities;
use SwagMigrationAssistant\Migration\Mapping\Lookup\CountryLookup;
use SwagMigrationAssistant\Migration\Mapping\Lookup\CountryStateLookup;
use SwagMigrationAssistant\Migration\Mapping\Lookup\LanguageLookup;
use SwagMigrationAssistant\Migration\MigrationContext;
use SwagMigrationAssistant\Migration\MigrationContextInterface;
use SwagMigrationAssistant\Profile\Shopware\DataSelection\DataSet\CustomerDataSet;
use SwagMigrationAssistant\Profile\Shopware\Gateway\Local\ShopwareLocalGateway;
use SwagMigrationAssistant\Profile\Shopware\Premapping\PaymentMethodReader;
use SwagMigrationAssistant\Profile\Shopware\Premapping\SalutationReader;
use SwagMigrationAssistant\Profile\Shopware55\Converter\Shopware55CustomerConverter;
use SwagMigrationAssistant\Profile\Shopware55\Shopware55Profile;
use SwagMigrationAssistant\Test\Mock\Migration\Logging\DummyLoggingService;
use SwagMigrationAssistant\Test\Mock\Migration\Mapping\DummyMappingService;

#[Package('services-settings')]
class CustomerConverterTest extends TestCase
{
    use IntegrationTestBehaviour;

    private Shopware55CustomerConverter $customerConverter;

    private DummyLoggingService $loggingService;

    private MigrationContextInterface $migrationContext;

    private DummyMappingService $mappingService;

    private string $connectionId;

    protected function setUp(): void
    {
        $this->loggingService = new DummyLoggingService();
        $this->mappingService = new DummyMappingService();

        $validator = static::getContainer()->get('validator');

        $salesChannelRepo = static::getContainer()->get('sales_channel.repository');

        $this->customerConverter = new Shopware55CustomerConverter(
            $this->mappingService,
            $this->loggingService,
            $validator,
            $salesChannelRepo,
            static::getContainer()->get(CountryLookup::class),
            static::getContainer()->get(LanguageLookup::class),
            static::getContainer()->get(CountryStateLookup::class),
        );

        $this->connectionId = Uuid::randomHex();
        $runId = Uuid::randomHex();
        $connection = new SwagMigrationConnectionEntity();
        $connection->setId($this->connectionId);
        $connection->setName('shopware');
        $connection->setProfileName(Shopware55Profile::PROFILE_NAME);
        $connection->setGatewayName(ShopwareLocalGateway::GATEWAY_NAME);

        $this->migrationContext = new MigrationContext(
            new Shopware55Profile(),
            $connection,
            $runId,
            new CustomerDataSet(),
            0,
            250
        );

        $context = Context::createDefaultContext();
        $this->mappingService->getOrCreateMapping(
            $connection->getId(),
            DefaultEntities::SALES_CHANNEL,
            '1',
            $context,
            null,
            null,
            TestDefaults::SALES_CHANNEL
        );

        $this->mappingService->getOrCreateMapping($this->connectionId, PaymentMethodReader::getMappingName(), '3', $context, Uuid::randomHex(), [], Uuid::randomHex());
        $this->mappingService->getOrCreateMapping($this->connectionId, PaymentMethodReader::getMappingName(), '4', $context, Uuid::randomHex(), [], Uuid::randomHex());
        $this->mappingService->getOrCreateMapping($this->connectionId, PaymentMethodReader::getMappingName(), '5', $context, Uuid::randomHex(), [], Uuid::randomHex());

        $this->mappingService->getOrCreateMapping($this->connectionId, SalutationReader::getMappingName(), 'mr', $context, Uuid::randomHex(), [], Uuid::randomHex());
        $this->mappingService->getOrCreateMapping($this->connectionId, SalutationReader::getMappingName(), 'ms', $context, Uuid::randomHex(), [], Uuid::randomHex());

        $this->mappingService->getOrCreateMapping($this->connectionId, DefaultEntities::CUSTOMER_GROUP, '1', $context, Uuid::randomHex(), [], 'cfbd5018d38d41d8adca10d94fc8bdd6');
        $this->mappingService->getOrCreateMapping($this->connectionId, DefaultEntities::CUSTOMER_GROUP, '2', $context, Uuid::randomHex(), [], 'cfbd5018d38d41d8adca10d94fc8bdd6');

        $this->mappingService->getOrCreateMapping($this->connectionId, DefaultEntities::COUNTRY_STATE, '3', $context, null, [], '019243e2514672debd864b2b979544f4');
    }

    public function testSupports(): void
    {
        $supportsDefinition = $this->customerConverter->supports($this->migrationContext);

        static::assertTrue($supportsDefinition);
    }

    public function testConvert(): void
    {
        $customerData = require __DIR__ . '/../../../_fixtures/customer_data.php';

        $context = Context::createDefaultContext();
        $convertResult = $this->customerConverter->convert(
            $customerData[0],
            $context,
            $this->migrationContext
        );

        $converted = $convertResult->getConverted();
        static::assertNotNull($converted);

        static::assertNull($convertResult->getUnmapped());
        static::assertNotNull($convertResult->getMappingUuid());
        static::assertArrayHasKey('id', $converted);
        static::assertArrayHasKey('addresses', $converted);
        static::assertSame(TestDefaults::SALES_CHANNEL, $converted['salesChannelId']);
        static::assertSame('Mustermann', $converted['lastName']);
        static::assertCount(0, $this->loggingService->getLoggingArray());
    }

    public function testConvertWithInvalidEmail(): void
    {
        $customerData = require __DIR__ . '/../../../_fixtures/customer_data.php';
        $customerData[0]['email'] = '42';

        $context = Context::createDefaultContext();
        $convertResult = $this->customerConverter->convert(
            $customerData[0],
            $context,
            $this->migrationContext
        );

        static::assertNull($convertResult->getConverted());

        $logs = $this->loggingService->getLoggingArray();
        static::assertCount(1, $logs);

        static::assertSame($logs[0]['code'], 'SWAG_MIGRATION__INVALID_EMAIL_ADDRESS');
        static::assertSame($logs[0]['parameters']['email'], '42');
    }

    #[DataProvider('requiredProperties')]
    public function testConvertWithoutRequiredProperties(string $property, ?string $value): void
    {
        $customerData = require __DIR__ . '/../../../_fixtures/customer_data.php';
        $customerData = $customerData[0];
        $customerData[$property] = $value;

        $context = Context::createDefaultContext();
        $convertResult = $this->customerConverter->convert(
            $customerData,
            $context,
            $this->migrationContext
        );
        static::assertNull($convertResult->getConverted());

        $logs = $this->loggingService->getLoggingArray();
        static::assertCount(1, $logs);

        static::assertSame($logs[0]['code'], 'SWAG_MIGRATION_EMPTY_NECESSARY_FIELD_CUSTOMER');
        static::assertSame($logs[0]['parameters']['emptyField'], $property);
    }

    /**
     * @return list<array{0: string, 1: string|null}>
     */
    public static function requiredProperties(): array
    {
        return [
            ['email', null],
            ['email', ''],
            ['firstname', null],
            ['firstname', ''],
            ['lastname', null],
            ['lastname', ''],
            ['defaultpayment', null],
            ['customerGroupId', ''],
            ['customerGroupId', null],
        ];
    }

    public function testConvertGuestAccount(): void
    {
        $customerData = require __DIR__ . '/../../../_fixtures/customer_data.php';

        $context = Context::createDefaultContext();
        $convertResult = $this->customerConverter->convert(
            $customerData[2],
            $context,
            $this->migrationContext
        );

        $converted = $convertResult->getConverted();
        static::assertNotNull($converted);

        static::assertNull($convertResult->getUnmapped());
        static::assertArrayHasKey('id', $converted);
        static::assertArrayHasKey('addresses', $converted);
        static::assertSame(TestDefaults::SALES_CHANNEL, $converted['salesChannelId']);
        static::assertSame('Test', $converted['lastName']);
        static::assertTrue($converted['guest']);
        static::assertCount(0, $this->loggingService->getLoggingArray());
    }

    public function testConvertCustomerWithoutNumber(): void
    {
        $customerData = require __DIR__ . '/../../../_fixtures/customer_data.php';
        $customerData = $customerData[0];
        $customerData['customernumber'] = null;

        $context = Context::createDefaultContext();
        $convertResult = $this->customerConverter->convert(
            $customerData,
            $context,
            $this->migrationContext
        );

        $converted = $convertResult->getConverted();
        static::assertNotNull($converted);

        static::assertNull($convertResult->getUnmapped());
        static::assertArrayHasKey('id', $converted);
        static::assertArrayHasKey('addresses', $converted);
        static::assertSame(TestDefaults::SALES_CHANNEL, $converted['salesChannelId']);
        static::assertSame('Mustermann', $converted['lastName']);
        static::assertSame('number-1', $converted['customerNumber']);
        static::assertCount(0, $this->loggingService->getLoggingArray());
    }

    public function testConvertCustomerWithoutPaymentAndWithDefaultPayment(): void
    {
        $customerData = require __DIR__ . '/../../../_fixtures/customer_data.php';
        $customerData = $customerData[0];
        unset($customerData['defaultpayment']);

        $context = Context::createDefaultContext();
        $mapping = $this->mappingService->getOrCreateMapping($this->connectionId, PaymentMethodReader::getMappingName(), PaymentMethodReader::SOURCE_ID, $context, null, [], Uuid::randomHex());
        $convertResult = $this->customerConverter->convert(
            $customerData,
            $context,
            $this->migrationContext
        );

        $converted = $convertResult->getConverted();
        $logs = $this->loggingService->getLoggingArray();

        static::assertNull($convertResult->getUnmapped());
        static::assertNotNull($converted);
        static::assertCount(0, $logs);
        static::assertArrayHasKey('id', $converted);
        static::assertArrayHasKey('addresses', $converted);
        static::assertSame(TestDefaults::SALES_CHANNEL, $converted['salesChannelId']);
        static::assertSame('Mustermann', $converted['lastName']);
        static::assertSame($mapping['entityUuid'], $converted['defaultPaymentMethodId']);
        static::assertCount(0, $this->loggingService->getLoggingArray());
    }

    public function testConvertCustomerWithoutAddresses(): void
    {
        $customerData = require __DIR__ . '/../../../_fixtures/customer_data.php';
        $customerData = $customerData[0];
        unset($customerData['addresses']);

        $context = Context::createDefaultContext();
        $convertResult = $this->customerConverter->convert(
            $customerData,
            $context,
            $this->migrationContext
        );

        static::assertNull($convertResult->getConverted());

        $logs = $this->loggingService->getLoggingArray();
        static::assertCount(1, $logs);

        static::assertSame($logs[0]['code'], 'SWAG_MIGRATION_EMPTY_NECESSARY_FIELD_CUSTOMER');
        static::assertSame($logs[0]['parameters']['sourceId'], $customerData['id']);
        static::assertSame($logs[0]['parameters']['emptyField'], 'address data');
    }

    public function testConvertCustomerWithoutValidAddresses(): void
    {
        $customerData = require __DIR__ . '/../../../_fixtures/customer_data.php';
        $customerData = $customerData[1];

        $customerData['addresses'][0]['firstname'] = '';
        $customerData['addresses'][1]['lastname'] = '';

        $context = Context::createDefaultContext();
        $convertResult = $this->customerConverter->convert(
            $customerData,
            $context,
            $this->migrationContext
        );

        static::assertNull($convertResult->getConverted());

        $logs = $this->loggingService->getLoggingArray();
        static::assertCount(3, $logs);

        static::assertSame($logs[0]['code'], 'SWAG_MIGRATION_EMPTY_NECESSARY_FIELD_CUSTOMER_ADDRESS');
        static::assertSame($logs[0]['parameters']['sourceId'], $customerData['addresses'][0]['id']);
        static::assertSame($logs[0]['parameters']['emptyField'], 'firstname');

        static::assertSame($logs[1]['code'], 'SWAG_MIGRATION_EMPTY_NECESSARY_FIELD_CUSTOMER_ADDRESS');
        static::assertSame($logs[1]['parameters']['sourceId'], $customerData['addresses'][1]['id']);
        static::assertSame($logs[1]['parameters']['emptyField'], 'lastname');

        static::assertSame($logs[2]['code'], 'SWAG_MIGRATION_EMPTY_NECESSARY_FIELD_CUSTOMER');
        static::assertSame($logs[2]['parameters']['sourceId'], $customerData['id']);
        static::assertSame($logs[2]['parameters']['emptyField'], 'address data');
    }

    /**
     * @return list<array{0: string, 1: string|null}>
     */
    public static function requiredAddressProperties(): array
    {
        return [
            ['firstname', null],
            ['firstname', ''],
            ['lastname', null],
            ['lastname', ''],
            ['zipcode', null],
            ['zipcode', ''],
            ['city', null],
            ['city', ''],
            ['street', null],
            ['street', ''],
        ];
    }

    #[DataProvider('requiredAddressProperties')]
    public function testConvertWithoutRequiredAddressPropertiesForBillingDefault(string $property, ?string $value): void
    {
        $customerData = require __DIR__ . '/../../../_fixtures/customer_data.php';
        $customerData = $customerData[0];
        $customerData['addresses'][0][$property] = $value;

        $context = Context::createDefaultContext();
        $convertResult = $this->customerConverter->convert(
            $customerData,
            $context,
            $this->migrationContext
        );

        $converted = $convertResult->getConverted();
        static::assertNotNull($converted);

        static::assertNull($convertResult->getUnmapped());
        static::assertArrayHasKey('id', $converted);
        static::assertArrayHasKey('addresses', $converted);

        static::assertSame('Mustermannstraße 92', $converted['addresses'][0]['street']);
        static::assertSame($converted['addresses'][0]['id'], $converted['defaultBillingAddressId']);
        static::assertSame($converted['addresses'][0]['id'], $converted['defaultShippingAddressId']);

        $logs = $this->loggingService->getLoggingArray();
        static::assertCount(2, $logs);

        static::assertSame($logs[0]['code'], 'SWAG_MIGRATION_EMPTY_NECESSARY_FIELD_CUSTOMER_ADDRESS');
        static::assertSame($logs[0]['parameters']['sourceId'], $customerData['addresses'][0]['id']);
        static::assertSame($logs[0]['parameters']['emptyField'], $property);

        static::assertSame($logs[1]['code'], 'SWAG_MIGRATION_CUSTOMER_ENTITY_FIELD_REASSIGNED');
        static::assertSame($logs[1]['parameters']['emptyField'], 'default billing address');
        static::assertSame($logs[1]['parameters']['replacementField'], 'default shipping address');
    }

    #[DataProvider('requiredAddressProperties')]
    public function testConvertWithoutRequiredAddressPropertiesForShippingDefault(string $property, ?string $value): void
    {
        $customerData = require __DIR__ . '/../../../_fixtures/customer_data.php';
        $customerData = $customerData[0];
        $customerData['addresses'][1][$property] = $value;

        $context = Context::createDefaultContext();
        $convertResult = $this->customerConverter->convert(
            $customerData,
            $context,
            $this->migrationContext
        );

        $converted = $convertResult->getConverted();
        static::assertNotNull($converted);

        static::assertNull($convertResult->getUnmapped());
        static::assertArrayHasKey('id', $converted);
        static::assertArrayHasKey('addresses', $converted);

        static::assertSame('Musterstr. 55', $converted['addresses'][0]['street']);
        static::assertSame($converted['addresses'][0]['id'], $converted['defaultBillingAddressId']);
        static::assertSame($converted['addresses'][0]['id'], $converted['defaultShippingAddressId']);

        $logs = $this->loggingService->getLoggingArray();
        static::assertCount(2, $logs);

        static::assertSame($logs[0]['code'], 'SWAG_MIGRATION_EMPTY_NECESSARY_FIELD_CUSTOMER_ADDRESS');
        static::assertSame($logs[0]['parameters']['sourceId'], $customerData['addresses'][1]['id']);
        static::assertSame($logs[0]['parameters']['emptyField'], $property);

        static::assertSame($logs[1]['code'], 'SWAG_MIGRATION_CUSTOMER_ENTITY_FIELD_REASSIGNED');
        static::assertSame($logs[1]['parameters']['emptyField'], 'default shipping address');
        static::assertSame($logs[1]['parameters']['replacementField'], 'default billing address');
    }

    #[DataProvider('requiredAddressProperties')]
    public function testConvertWithoutRequiredAddressPropertiesForDefaultBillingAndShipping(string $property, ?string $value): void
    {
        $customerData = require __DIR__ . '/../../../_fixtures/customer_data.php';
        $customerData = $customerData[0];
        $customerData['addresses'][0][$property] = $value;
        $customerData['addresses'][1][$property] = $value;

        $context = Context::createDefaultContext();
        $convertResult = $this->customerConverter->convert(
            $customerData,
            $context,
            $this->migrationContext
        );

        $converted = $convertResult->getConverted();
        static::assertNotNull($converted);

        static::assertNull($convertResult->getUnmapped());
        static::assertArrayHasKey('id', $converted);
        static::assertArrayHasKey('addresses', $converted);

        static::assertSame('Musterstraße 3', $converted['addresses'][0]['street']);
        static::assertSame($converted['addresses'][0]['id'], $converted['defaultBillingAddressId']);
        static::assertSame($converted['addresses'][0]['id'], $converted['defaultShippingAddressId']);

        $logs = $this->loggingService->getLoggingArray();
        static::assertCount(3, $logs);

        static::assertSame($logs[0]['code'], 'SWAG_MIGRATION_EMPTY_NECESSARY_FIELD_CUSTOMER_ADDRESS');
        static::assertSame($logs[0]['parameters']['sourceId'], $customerData['addresses'][0]['id']);
        static::assertSame($logs[0]['parameters']['emptyField'], $property);

        static::assertSame($logs[1]['code'], 'SWAG_MIGRATION_EMPTY_NECESSARY_FIELD_CUSTOMER_ADDRESS');
        static::assertSame($logs[1]['parameters']['sourceId'], $customerData['addresses'][1]['id']);
        static::assertSame($logs[1]['parameters']['emptyField'], $property);

        static::assertSame($logs[2]['code'], 'SWAG_MIGRATION_CUSTOMER_ENTITY_FIELD_REASSIGNED');
        static::assertSame($logs[2]['parameters']['emptyField'], 'default billing and shipping address');
        static::assertSame($logs[2]['parameters']['replacementField'], 'first address');
    }

    public function testGetCustomerWithShopScope(): void
    {
        $customerData = require __DIR__ . '/../../../_fixtures/customer_data.php';
        $customerData = $customerData[0];
        $customerData['shop'] = [
            'customer_scope' => '1',
        ];

        $context = Context::createDefaultContext();
        $convertResult = $this->customerConverter->convert(
            $customerData,
            $context,
            $this->migrationContext
        );

        $converted = $convertResult->getConverted();

        static::assertNull($convertResult->getUnmapped());
        static::assertIsArray($converted);
        static::assertNotNull($converted['boundSalesChannelId']);
    }

    public function testConvertCountryStateWithMapping(): void
    {
        $customerData = require __DIR__ . '/../../../_fixtures/customer_data.php';
        $customerData = $customerData[0];

        $context = Context::createDefaultContext();
        $convertResult = $this->customerConverter->convert(
            $customerData,
            $context,
            $this->migrationContext
        );

        $converted = $convertResult->getConverted();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('shortCode', 'DE-NW'));
        $expectedStateId = $this->getContainer()->get('country_state.repository')->searchIds($criteria, $context)->firstId();

        static::assertNotNull($converted);
        static::assertArrayHasKey('id', $converted);
        static::assertArrayHasKey('addresses', $converted);
        static::assertArrayHasKey('countryState', $converted['addresses'][0]);
        static::assertArrayHasKey('id', $converted['addresses'][0]['countryState']);
        static::assertSame($expectedStateId, $converted['addresses'][0]['countryState']['id']);
    }

    public function testConvertExistingCountryStateWithoutMapping(): void
    {
        $customerData = require __DIR__ . '/../../../_fixtures/customer_data.php';
        $customerData = $customerData[0];
        $customerData['addresses'][0]['state_id'] = '9999';

        $context = Context::createDefaultContext();
        $convertResult = $this->customerConverter->convert(
            $customerData,
            $context,
            $this->migrationContext
        );

        $converted = $convertResult->getConverted();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('shortCode', 'DE-NW'));
        $expectedStateId = $this->getContainer()->get('country_state.repository')->searchIds($criteria, $context)->firstId();

        static::assertNotNull($converted);
        static::assertArrayHasKey('id', $converted);
        static::assertArrayHasKey('addresses', $converted);
        static::assertArrayHasKey('countryState', $converted['addresses'][0]);
        static::assertArrayHasKey('id', $converted['addresses'][0]['countryState']);
        static::assertSame($expectedStateId, $converted['addresses'][0]['countryState']['id']);
    }

    public function testConvertNotExistingCountryStateWithoutMapping(): void
    {
        $customerData = require __DIR__ . '/../../../_fixtures/customer_data.php';
        $customerData = $customerData[0];
        $customerData['addresses'][0]['state_id'] = '9999';
        unset($customerData['addresses'][0]['state']);

        $context = Context::createDefaultContext();
        $convertResult = $this->customerConverter->convert(
            $customerData,
            $context,
            $this->migrationContext
        );

        $converted = $convertResult->getConverted();

        static::assertNotNull($converted);
        static::assertArrayHasKey('id', $converted);
        static::assertArrayHasKey('addresses', $converted);
        static::assertArrayNotHasKey('countryStateId', $converted['addresses'][0]);

        $logs = $this->loggingService->getLoggingArray();

        static::assertCount(1, $logs);

        static::assertSame($logs[0]['code'], 'SWAG_MIGRATION_COUNTRY_STATE_ENTITY_UNKNOWN');
        static::assertSame($logs[0]['parameters']['sourceId'], '9999');
        static::assertSame($logs[0]['parameters']['entity'], DefaultEntities::COUNTRY_STATE);
        static::assertSame($logs[0]['parameters']['requiredForSourceId'], $customerData['id']);
        static::assertSame($logs[0]['parameters']['requiredForEntity'], DefaultEntities::CUSTOMER);
    }
}
