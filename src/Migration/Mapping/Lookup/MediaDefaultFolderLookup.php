<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SwagMigrationAssistant\Migration\Mapping\Lookup;

use Shopware\Core\Content\Media\Aggregate\MediaDefaultFolder\MediaDefaultFolderCollection;
use Shopware\Core\Content\Media\Aggregate\MediaDefaultFolder\MediaDefaultFolderEntity;
use Shopware\Core\Content\Media\Aggregate\MediaFolder\MediaFolderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Log\Package;
use Symfony\Contracts\Service\ResetInterface;

#[Package('services-settings')]
class MediaDefaultFolderLookup implements ResetInterface
{
    /**
     * @var array<string, string|null>
     */
    private array $cache = [];

    /**
     * @param EntityRepository<MediaDefaultFolderCollection> $mediaFolderRepository
     *
     * @internal
     */
    public function __construct(
        private readonly EntityRepository $mediaFolderRepository,
    ) {
    }

    public function get(string $entityName, Context $context): ?string
    {
        if (\array_key_exists($entityName, $this->cache)) {
            return $this->cache[$entityName];
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('entity', $entityName));

        $result = $this->mediaFolderRepository->search($criteria, $context)->getEntities()->first();
        if (!$result instanceof MediaDefaultFolderEntity) {
            $this->cache[$entityName] = null;

            return null;
        }

        $folderResult = $result->getFolder();
        if (!$folderResult instanceof MediaFolderEntity) {
            $this->cache[$entityName] = null;

            return null;
        }

        $this->cache[$entityName] = $folderResult->getId();

        return $folderResult->getId();
    }

    public function reset(): void
    {
        $this->cache = [];
    }
}
