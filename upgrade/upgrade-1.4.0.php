<?php
/**
 * Upgrade script for rkpickup module v1.4.0
 * Adds llavero_uid column for NFC keyfob tracking
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_4_0($module)
{
    $prefix = _DB_PREFIX_;
    
    // Add llavero_uid column to assignments table
    $sql = "ALTER TABLE {$prefix}rkpickup_assignment ADD COLUMN llavero_uid VARCHAR(20) NULL AFTER pin_code";
    
    if (!Db::getInstance()->execute($sql)) {
        // Column might already exist
        $checkSql = "SHOW COLUMNS FROM {$prefix}rkpickup_assignment LIKE 'llavero_uid'";
        $exists = Db::getInstance()->executeS($checkSql);
        if (empty($exists)) {
            return false;
        }
    }
    
    return true;
}
