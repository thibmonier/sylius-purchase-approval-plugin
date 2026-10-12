<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remember when the order confirmation of an approved order was sent (tmonier/sylius-purchase-approval-plugin).';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('tmonier_purchase_approval_order_approval')->addColumn('confirmation_sent_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('tmonier_purchase_approval_order_approval')->dropColumn('confirmation_sent_at');
    }
}
