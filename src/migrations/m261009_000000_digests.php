<?php

namespace justinholtweb\freelog\migrations;

use craft\db\Migration;
use justinholtweb\freelog\services\Digest;

/**
 * Adds the error digest's marker and per-log read positions.
 */
class m261009_000000_digests extends Migration
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
