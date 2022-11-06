<?php declare(strict_types=1);

namespace Composite\DoctrineMigrations\Tests\TestStand;

use Composite\DB\Attributes;
use Composite\Entity\AbstractEntity;

#[Attributes\Table(connection: 'sqlite', name: 'TestTable')]
class TestEntity extends AbstractEntity
{
    public function __construct(
        #[Attributes\PrimaryKey]
        public readonly string $id,
        public readonly string $name,
        public readonly \DateTimeImmutable $created_at = new \DateTimeImmutable(),
    ) {}
}