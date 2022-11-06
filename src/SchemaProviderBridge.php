<?php declare(strict_types=1);

namespace Composite\DoctrineMigrations;

use Composite\DB\Attributes;
use Composite\DB\TableConfig;
use Composite\Entity\AbstractEntity;
use Composite\Entity\Columns;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\DBAL\Platforms;
use Doctrine\Migrations\Provider\SchemaProvider;
use Laminas\File\ClassFileLocator;

class SchemaProviderBridge implements SchemaProvider
{
    private readonly AbstractPlatform $platform;
    public function __construct(
        private readonly array $entityDirs,
        private readonly string $connectionName,
        Connection $connection,
    ) {
        $this->platform = $connection->getDatabasePlatform();
    }

    /**
     * @throws \Composite\Entity\Exceptions\EntityException
     */
    public function createSchema(): Schema
    {
        $doctrineSchema = new Schema();

        $entities = $this->findAllEntities();

        foreach ($entities as $entity) {
            try {
                $schema = $entity::schema();
                $tableConfig = TableConfig::fromEntitySchema($schema);
                if ($tableConfig->connectionName !== $this->connectionName) {
                    continue;
                }

                $table = $doctrineSchema->createTable($tableConfig->tableName);
                foreach ($schema->columns as $column) {
                    $table->addColumn(
                        name: $column->name,
                        typeName: $this->getColumnType($column),
                        options: $this->getColumnOptions($tableConfig, $column),
                    );
                }
                $table->setPrimaryKey($tableConfig->primaryKeys);
                foreach ($schema->attributes as $attribute) {
                    if ($attribute instanceof Attributes\Index) {
                        $indexName = $this->getIndexName($tableConfig, $attribute);
                        if ($attribute->isUnique) {
                            $table->addIndex(
                                columnNames: $attribute->columns,
                                indexName: $indexName,
                            );
                        } else {
                            $table->addUniqueIndex(
                                columnNames: $attribute->columns,
                                indexName: $indexName,
                            );
                        }
                    }
                }
            } catch (\Exception) {
                continue;
            }
        }
        return $doctrineSchema;
    }

    private function getColumnType(Columns\AbstractColumn $column): string
    {
        if ($column instanceof Columns\BackedEnumColumn) {
            /** @var \BackedEnum $enumClass */
            $enumClass = $column->type;
            $reflectionEnum = new \ReflectionEnum($enumClass);

            /** @var \ReflectionNamedType $backingType */
            $backingType = $reflectionEnum->getBackingType();
            if ($backingType->getName() === 'int') {
                return Types::INTEGER;
            }
        }

        return match ($column::class) {
            Columns\ArrayColumn::class, Columns\EntityColumn::class, Columns\ObjectColumn::class => Types::JSON,
            Columns\BoolColumn::class => Types::BOOLEAN,
            Columns\DateTimeColumn::class => $column->type === \DateTimeImmutable::class ? Types::DATETIME_IMMUTABLE : Types::DATETIME_MUTABLE,
            Columns\FloatColumn::class => Types::FLOAT,
            Columns\IntegerColumn::class => Types::INTEGER,
            default => TYpes::STRING,
        };
    }

    /**
     * length
     * precision
     * scale
     * unsigned
     * notnull
     * default
     * columndefinition
     */
    private function getColumnOptions(TableConfig $tableConfig, Columns\AbstractColumn $column): array
    {
        $options = [];

        /** @var Attributes\Column|null $columnAttribute */
        $columnAttribute = null;
        foreach ($column->attributes as $attribute) {
            if ($attribute instanceof Attributes\Column) {
                $columnAttribute = $attribute;
                break;
            }
        }

        if (!$column->isNullable) {
            $options['notnull'] = true;
        }

        if ($columnAttribute?->scale) {
            $options['scale'] = $columnAttribute->scale;
        }

        if ($columnAttribute?->precision) {
            $options['scale'] = $columnAttribute->precision;
        }

        if ($columnAttribute?->unsigned) {
            $options['unsigned'] = true;
        }

        if ($columnAttribute?->size) {
            $options['length'] = $columnAttribute->size;
        } elseif ($column instanceof Columns\StringColumn) {
            $options['length'] = 255;
        }

        if ($columnAttribute?->default) {
            $options['default'] = $columnAttribute->default;
        } elseif ($column->hasDefaultValue) {
            $options['default'] = $this->getDefaultValue($column);
        }

        if ($columnDefinition = $this->getColumnDefinition($column)) {
            $options['columnDefinition'] = $columnDefinition;
        }

        if ($column->name === $tableConfig->autoIncrementKey) {
            $options['autoincrement'] = true;
        }

        return $options;
    }

    private function getDefaultValue(Columns\AbstractColumn $column): string|int|float|bool|null
    {
        $defaultValue = $column->defaultValue;
        if ($defaultValue === null) {
            return null;
        }
        if ($defaultValue instanceof \DateTimeInterface) {
            $unixTime = intval($defaultValue->format('U'));
            $now = time();
            if ($unixTime === $now || $unixTime === $now - 1) {
                return 'CURRENT_TIMESTAMP';
            }
        }
        return $column->uncast($defaultValue);
    }

    private function getColumnDefinition(Columns\AbstractColumn $column): ?string
    {
        if ($column instanceof Columns\UnitEnumColumn) {
            /** @var \UnitEnum $enumClass */
            $enumClass = $column->type;
            $cases = array_map(
                fn (\UnitEnum $enum) => $enum->name,
                $enumClass::cases()
            );
            return $this->getEnumDefinition($cases);
        } elseif ($column instanceof Columns\BackedEnumColumn) {
            /** @var \BackedEnum $enumClass */
            $enumClass = $column->type;
            $reflectionEnum = new \ReflectionEnum($enumClass);

            /** @var \ReflectionNamedType $backingType */
            $backingType = $reflectionEnum->getBackingType();

            if ($backingType->getName() === 'string') {
                $cases = array_map(
                    fn (\BackedEnum $enum) => $enum->value,
                    $enumClass::cases()
                );
                return $this->getEnumDefinition($cases);
            }
        }
        return null;
    }

    private function getEnumDefinition(array $cases): ?string
    {
        if ($this->platform instanceof Platforms\MySQLPlatform) {
            return "ENUM('" . implode("', '", $cases) . "')";
        }
        return null;
    }

    private function getIndexName(TableConfig $tableConfig, Attributes\Index $attribute): string
    {
        if ($attribute->name) {
            return $attribute->name;
        }
        $parts = [
            $tableConfig->tableName,
            $attribute->isUnique ? 'unq' : 'idx',
        ];
        return implode('_', array_merge($parts, $attribute->columns));
    }

    private function findAllEntities(): array
    {
        $result = [];
        foreach ($this->entityDirs as $dir) {
            $locator = new ClassFileLocator($dir);
            foreach ($locator as $file) {
                foreach ($file->getClasses() as $class) {
                    if (!is_subclass_of($class, AbstractEntity::class)) {
                        continue;
                    }
                    $result[] = $class;
                }
            }
        }
        return $result;
    }
}