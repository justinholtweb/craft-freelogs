<?php

namespace justinholtweb\freelog\migrations;

use craft\db\Migration;
use justinholtweb\freelog\services\Digest;

/**
 * Freelog's one table: the error digest's marker and per-log read positions.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(Digest::TABLE)) {
            Digest::createTable($this);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Digest::TABLE);

        return true;
    }
}
