<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SwagMigrationAssistant\DataProvider\Provider\Data;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use SwagMigrationAssistant\Migration\DataSelection\DefaultEntities;

class ShippingMethodProvider extends AbstractProvider
{
    /**
     * @var EntityRepositoryInterface
     */
    private $shippingMethodRepo;

    public function __construct(EntityRepositoryInterface $shippingMethodRepo)
    {
        $this->shippingMethodRepo = $shippingMethodRepo;
    }

    public function getIdentifier(): string
    {
        return DefaultEntities::SHIPPING_METHOD;
    }

    public function getProvidedData(int $limit, int $offset, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->setLimit($limit);
        $criteria->setOffset($offset);
        $criteria->addAssociation('translations');
        $criteria->addAssociation('prices');
        $criteria->addAssociation('tags');
        $criteria->addSorting(new FieldSorting('id'));
        $result = $this->shippingMethodRepo->search($criteria, $context);

        return $this->cleanupSearchResult($result, ['shippingMethodId', 'deliveryTime']);
    }

    public function getProvidedTotal(Context $context): int
    {
        return $this->readTotalFromRepo($this->shippingMethodRepo, $context);
    }
}
