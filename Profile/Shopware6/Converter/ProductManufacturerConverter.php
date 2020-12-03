<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SwagMigrationAssistant\Profile\Shopware6\Converter;

use SwagMigrationAssistant\Migration\Converter\ConvertStruct;
use SwagMigrationAssistant\Migration\DataSelection\DefaultEntities;

abstract class ProductManufacturerConverter extends ShopwareMediaConverter
{
    public function getMediaUuids(array $converted): ?array
    {
        $mediaIds = [];
        foreach ($converted as $category) {
            if (isset($category['media']['id'])) {
                $mediaIds[] = $category['media']['id'];
            }
        }

        return $mediaIds;
    }

    public function convertData(array $data): ConvertStruct
    {
        $converted = $data;

        $this->mainMapping = $this->getOrCreateMappingMainCompleteFacade(
            DefaultEntities::PRODUCT_MANUFACTURER,
            $data['id'],
            $converted['id']
        );

        $this->updateAssociationIds(
            $converted['translations'],
            DefaultEntities::LANGUAGE,
            'languageId',
            DefaultEntities::PRODUCT_MANUFACTURER
        );

        if (isset($converted['media'])) {
            $this->updateMediaAssociation($converted['media']);
        }

        return new ConvertStruct($converted, null, $this->mainMapping['id'] ?? null);
    }
}
