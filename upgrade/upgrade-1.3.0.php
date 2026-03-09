<?php
/**
 * Upgrade to v1.3.0
 * - Add warning_sent column to assignments table
 * - Add expired_grace to assignment status enum
 * - Create custom order statuses
 * - Generate cron token
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_3_0($module)
{
    $prefix = _DB_PREFIX_;
    $sql = [];
    
    // Add warning_sent column
    $sql[] = "ALTER TABLE {$prefix}rkpickup_assignment ADD COLUMN IF NOT EXISTS warning_sent TINYINT(1) UNSIGNED DEFAULT 0 AFTER valid_until";
    
    // Update status enum to include expired_grace
    $sql[] = "ALTER TABLE {$prefix}rkpickup_assignment MODIFY COLUMN status ENUM('pending', 'ready', 'waiting', 'expired_grace', 'picked_up', 'expired', 'cancelled') DEFAULT 'pending'";
    
    foreach ($sql as $query) {
        try {
            Db::getInstance()->execute($query);
        } catch (Exception $e) {
            PrestaShopLogger::addLog('RkPickup upgrade error: ' . $e->getMessage(), 3);
        }
    }
    
    // Generate cron token if not exists
    if (!Configuration::get('RKPICKUP_CRON_TOKEN')) {
        Configuration::updateValue('RKPICKUP_CRON_TOKEN', bin2hex(random_bytes(16)));
    }
    
    // Create order statuses
    $statuses = [
        'RKPICKUP_OS_WAITING' => ['name' => 'Esperando taquilla', 'color' => '#E74C3C'],
        'RKPICKUP_OS_READY' => ['name' => 'Listo para recoger', 'color' => '#00D4AA'],
        'RKPICKUP_OS_EXPIRED_GRACE' => ['name' => 'Plazo expirado (gracia)', 'color' => '#F39C12'],
        'RKPICKUP_OS_EXPIRED' => ['name' => 'Reserva cancelada', 'color' => '#C0392B'],
        'RKPICKUP_OS_PICKED_UP' => ['name' => 'Recogido', 'color' => '#27AE60'],
    ];

    foreach ($statuses as $configKey => $data) {
        if (Configuration::get($configKey)) {
            continue;
        }

        $orderState = new OrderState();
        $orderState->color = $data['color'];
        $orderState->send_email = false;
        $orderState->module_name = $module->name;
        $orderState->unremovable = false;
        $orderState->hidden = false;
        $orderState->logable = true;
        $orderState->delivery = false;
        $orderState->shipped = false;
        $orderState->paid = true;
        $orderState->pdf_invoice = false;
        $orderState->pdf_delivery = false;

        foreach (Language::getLanguages(true) as $lang) {
            $orderState->name[$lang['id_lang']] = $data['name'];
        }

        if ($orderState->add()) {
            Configuration::updateValue($configKey, $orderState->id);
        }
    }
    
    PrestaShopLogger::addLog('RkPickup upgraded to v1.3.0', 1);
    
    return true;
}
