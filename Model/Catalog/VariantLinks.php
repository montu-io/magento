<?php
/**
 * Montu Affiliate Tracking Extension - Variant Links
 *
 * Set-based reads of configurable product links. The link tables point at the
 * parent's link field: entity_id on Magento Open Source / Mage-OS, row_id on
 * Adobe Commerce (content staging). Parents are always joined through
 * catalog_product_entity on that field, like core does, so every id returned or
 * accepted here is an entity id.
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model\Catalog;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;

class VariantLinks
{
    private const CHUNK = 1000;

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resourceConnection;

    /**
     * @var MetadataPool
     */
    private MetadataPool $metadataPool;

    /**
     * @var string|null
     */
    private ?string $linkField = null;

    /**
     * @param ResourceConnection $resourceConnection
     * @param MetadataPool $metadataPool
     */
    public function __construct(ResourceConnection $resourceConnection, MetadataPool $metadataPool)
    {
        $this->resourceConnection = $resourceConnection;
        $this->metadataPool = $metadataPool;
    }

    /**
     * Configurable products the given products are variants of
     *
     * @param int[] $childIds
     * @return int[]
     */
    public function parentIds(array $childIds): array
    {
        $connection = $this->resourceConnection->getConnection();
        $parents = [];
        foreach (array_chunk($this->ids($childIds), self::CHUNK) as $chunk) {
            $parents[] = $connection->fetchCol(
                $connection->select()
                    ->distinct()
                    ->from(['l' => $this->table('catalog_product_super_link')], [])
                    ->join(['p' => $this->table('catalog_product_entity')], 'p.' . $this->linkField() . ' = l.parent_id', ['entity_id'])
                    ->where('l.product_id IN (?)', $chunk)
            );
        }

        return array_map('intval', array_merge([], ...$parents));
    }

    /**
     * Which of the given products are variants of some configurable product
     *
     * @param int[] $ids
     * @return array<int, true>
     */
    public function variantIds(array $ids): array
    {
        $connection = $this->resourceConnection->getConnection();
        $found = [];
        foreach (array_chunk($this->ids($ids), self::CHUNK) as $chunk) {
            $found[] = $connection->fetchCol(
                $connection->select()
                    ->distinct()
                    ->from(['l' => $this->table('catalog_product_super_link')], ['product_id'])
                    ->join(['p' => $this->table('catalog_product_entity')], 'p.' . $this->linkField() . ' = l.parent_id', [])
                    ->where('l.product_id IN (?)', $chunk)
            );
        }

        return array_fill_keys(array_map('intval', array_merge([], ...$found)), true);
    }

    /**
     * Variant ids per configurable product
     *
     * @param int[] $parentIds
     * @return array<int, int[]>
     */
    public function childrenByParent(array $parentIds): array
    {
        $connection = $this->resourceConnection->getConnection();
        $result = [];
        foreach (array_chunk($this->ids($parentIds), self::CHUNK) as $chunk) {
            foreach ($connection->fetchAll(
                $connection->select()
                    ->from(['l' => $this->table('catalog_product_super_link')], ['child_id' => 'product_id'])
                    ->join(
                        ['p' => $this->table('catalog_product_entity')],
                        'p.' . $this->linkField() . ' = l.parent_id',
                        ['parent_id' => 'entity_id']
                    )
                    ->where('p.entity_id IN (?)', $chunk)
            ) as $row) {
                $result[(int) $row['parent_id']][(int) $row['child_id']] = (int) $row['child_id'];
            }
        }
        foreach ($result as $parentId => $children) {
            sort($children);
            $result[$parentId] = $children;
        }

        return $result;
    }

    /**
     * The attributes each configurable product is configured by, in order, with
     * the product's label for the store view (else its default label, else the
     * attribute's label for the store view, else its admin label)
     *
     * @param int[] $parentIds
     * @param int $storeId
     * @return array<int, array<int, array{code: string, label: string}>>
     */
    public function optionsByParent(array $parentIds, int $storeId): array
    {
        $parentIds = $this->ids($parentIds);
        if ($parentIds === []) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['sa' => $this->table('catalog_product_super_attribute')], ['position'])
                ->join(
                    ['p' => $this->table('catalog_product_entity')],
                    'p.' . $this->linkField() . ' = sa.product_id',
                    ['parent_id' => 'entity_id']
                )
                ->join(['ea' => $this->table('eav_attribute')], 'ea.attribute_id = sa.attribute_id', ['attribute_code', 'frontend_label'])
                ->joinLeft(
                    ['al' => $this->table('eav_attribute_label')],
                    $connection->quoteInto('al.attribute_id = sa.attribute_id AND al.store_id = ?', $storeId),
                    ['attribute_store_label' => 'value']
                )
                ->joinLeft(
                    ['sl' => $this->table('catalog_product_super_attribute_label')],
                    $connection->quoteInto('sl.product_super_attribute_id = sa.product_super_attribute_id AND sl.store_id = ?', $storeId),
                    ['store_label' => 'value']
                )
                ->joinLeft(
                    ['dl' => $this->table('catalog_product_super_attribute_label')],
                    'dl.product_super_attribute_id = sa.product_super_attribute_id AND dl.store_id = 0',
                    ['default_label' => 'value']
                )
                ->where('p.entity_id IN (?)', $parentIds)
                ->order(['p.entity_id', 'sa.position', 'sa.attribute_id'])
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['parent_id']][] = [
                'code' => (string) $row['attribute_code'],
                'label' => (string) ($row['store_label'] ?: $row['default_label']
                    ?: $row['attribute_store_label'] ?: $row['frontend_label']),
            ];
        }

        return $result;
    }

    /**
     * Child id => configurable parent id (one parent per child is assumed, as the
     * storefront does)
     *
     * @param int[] $childIds
     * @return array<int, int>
     */
    public function parentByChild(array $childIds): array
    {
        $connection = $this->resourceConnection->getConnection();
        $map = [];
        foreach (array_chunk($this->ids($childIds), self::CHUNK) as $chunk) {
            foreach ($connection->fetchAll(
                $connection->select()
                    ->from(['l' => $this->table('catalog_product_super_link')], ['child_id' => 'product_id'])
                    ->join(
                        ['p' => $this->table('catalog_product_entity')],
                        'p.' . $this->linkField() . ' = l.parent_id',
                        ['parent_id' => 'entity_id']
                    )
                    ->where('l.product_id IN (?)', $chunk)
            ) as $row) {
                $map[(int) $row['child_id']] = (int) $row['parent_id'];
            }
        }

        return $map;
    }

    /**
     * Attribute id => code of the attributes the given configurable products are
     * configured by
     *
     * @param int[] $parentIds
     * @return array<int, string>
     */
    public function optionAttributesOf(array $parentIds): array
    {
        $parentIds = $this->ids($parentIds);
        if ($parentIds === []) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();

        return array_map('strval', $connection->fetchPairs(
            $connection->select()
                ->distinct()
                ->from(['sa' => $this->table('catalog_product_super_attribute')], ['attribute_id'])
                ->join(['p' => $this->table('catalog_product_entity')], 'p.' . $this->linkField() . ' = sa.product_id', [])
                ->join(['ea' => $this->table('eav_attribute')], 'ea.attribute_id = sa.attribute_id', ['attribute_code'])
                ->where('p.entity_id IN (?)', $parentIds)
        ));
    }

    /**
     * Codes of every attribute some configurable product is configured by
     *
     * @return string[]
     */
    public function optionAttributeCodes(): array
    {
        $connection = $this->resourceConnection->getConnection();

        return $connection->fetchCol(
            $connection->select()
                ->distinct()
                ->from(['sa' => $this->table('catalog_product_super_attribute')], [])
                ->join(['ea' => $this->table('eav_attribute')], 'ea.attribute_id = sa.attribute_id', ['attribute_code'])
        );
    }

    /**
     * @return string entity_id, or row_id on Adobe Commerce
     */
    private function linkField(): string
    {
        if ($this->linkField === null) {
            $this->linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        }

        return $this->linkField;
    }

    /**
     * @param string $name
     * @return string
     */
    private function table(string $name): string
    {
        return $this->resourceConnection->getTableName($name);
    }

    /**
     * @param array<int, int|string> $ids
     * @return int[]
     */
    private function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    }
}
