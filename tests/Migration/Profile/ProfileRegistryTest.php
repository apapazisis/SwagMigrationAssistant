<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SwagMigrationAssistant\Test\Migration\Profile;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Log\Package;
use SwagMigrationAssistant\Exception\MigrationException;
use SwagMigrationAssistant\Migration\Profile\ProfileRegistry;
use SwagMigrationAssistant\Migration\Profile\ProfileRegistryInterface;
use SwagMigrationAssistant\Test\Mock\DummyCollection;
use SwagMigrationAssistant\Test\Mock\Profile\Dummy\DummyProfile;

#[Package('services-settings')]
class ProfileRegistryTest extends TestCase
{
    private ProfileRegistryInterface $profileRegistry;

    protected function setUp(): void
    {
        $this->profileRegistry = new ProfileRegistry(new DummyCollection([new DummyProfile()]));
    }

    public function testGetProfileNotFound(): void
    {
        try {
            $this->profileRegistry->getProfile('foo');
        } catch (MigrationException $e) {
            static::assertSame(MigrationException::PROFILE_NOT_FOUND, $e->getErrorCode());

            return;
        }

        static::fail('Exception not thrown');
    }
}
