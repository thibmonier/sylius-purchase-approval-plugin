<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Written with the Schema API (not raw SQL) so that it runs on MySQL, MariaDB and PostgreSQL.
 */
final class Version20261013200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the order approval and member limit tables (tmonier/sylius-purchase-approval-plugin).';
    }

    public function up(Schema $schema): void
    {
        $approval = $schema->createTable('tmonier_purchase_approval_order_approval');
        $approval->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $approval->addColumn('order_id', Types::INTEGER);
        $approval->addColumn('company_id', Types::INTEGER, ['notnull' => false]);
        $approval->addColumn('decided_by_id', Types::INTEGER, ['notnull' => false]);
        $approval->addColumn('state', Types::STRING, ['length' => 16]);
        $approval->addColumn('total', Types::INTEGER);
        $approval->addColumn('currency_code', Types::STRING, ['length' => 3, 'notnull' => false]);
        $approval->addColumn('order_limit', Types::INTEGER, ['notnull' => false]);
        $approval->addColumn('decider_name', Types::STRING, ['length' => 255, 'notnull' => false]);
        $approval->addColumn('decider_type', Types::STRING, ['length' => 16, 'notnull' => false]);
        $approval->addColumn('comment', Types::TEXT, ['notnull' => false]);
        $approval->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $approval->addColumn('decided_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $approval->setPrimaryKey(['id']);
        $approval->addUniqueIndex(['order_id'], 'UNIQ_FAEEF3FB8D9F6D38');
        $approval->addIndex(['company_id'], 'IDX_FAEEF3FB979B1AD6');
        $approval->addIndex(['decided_by_id'], 'IDX_FAEEF3FBE26B496B');
        $approval->addIndex(['state', 'created_at'], 'tmonier_purchase_approval_state_idx');
        $approval->addForeignKeyConstraint('sylius_order', ['order_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_FAEEF3FB8D9F6D38');
        $approval->addForeignKeyConstraint('tmonier_company', ['company_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_FAEEF3FB979B1AD6');
        $approval->addForeignKeyConstraint('sylius_customer', ['decided_by_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_FAEEF3FBE26B496B');

        $limit = $schema->createTable('tmonier_purchase_approval_member_limit');
        $limit->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $limit->addColumn('member_id', Types::INTEGER);
        $limit->addColumn('order_limit', Types::INTEGER, ['notnull' => false]);
        $limit->addColumn('approval_limit', Types::INTEGER, ['notnull' => false]);
        $limit->addColumn('currency_code', Types::STRING, ['length' => 3, 'notnull' => false]);
        $limit->addColumn('updated_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $limit->setPrimaryKey(['id']);
        $limit->addUniqueIndex(['member_id'], 'UNIQ_972A47537597D3FE');
        $limit->addForeignKeyConstraint('tmonier_company_member', ['member_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_972A47537597D3FE');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('tmonier_purchase_approval_member_limit');
        $schema->dropTable('tmonier_purchase_approval_order_approval');
    }
}
