<?php
/**
 * Cron Controller - Process expirations and send warnings
 * 
 * Call via: https://ruckclean.com/index.php?fc=module&module=rkpickup&controller=cron&token=YOUR_SECRET_TOKEN
 * 
 * Recommended: Run every hour via cron
 */

class RkPickupCronModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        // Security: Check token
        $token = Tools::getValue('token');
        $expectedToken = Configuration::get('RKPICKUP_CRON_TOKEN');
        
        if (!$expectedToken || $token !== $expectedToken) {
            header('HTTP/1.1 403 Forbidden');
            die(json_encode(['error' => 'Invalid token']));
        }
        
        header('Content-Type: application/json');
        
        $results = [
            'timestamp' => date('Y-m-d H:i:s'),
            'warnings_sent' => 0,
            'expirations_processed' => 0,
            'errors' => [],
        ];
        
        try {
            // 1. Send expiration warnings (24h before)
            $warningsResult = $this->sendExpirationWarnings();
            $results['warnings_sent'] = $warningsResult['sent'];
            if (!empty($warningsResult['errors'])) {
                $results['errors'] = array_merge($results['errors'], $warningsResult['errors']);
            }
            
            // 2. Process actual expirations
            $expirationsResult = $this->module->processExpirations();
            $results['expirations_processed'] = $expirationsResult['processed'];
            
        } catch (Exception $e) {
            $results['errors'][] = $e->getMessage();
            PrestaShopLogger::addLog('RkPickup Cron Error: ' . $e->getMessage(), 3);
        }
        
        // Log execution
        PrestaShopLogger::addLog(
            sprintf('RkPickup Cron: %d warnings, %d expirations processed', 
                $results['warnings_sent'], 
                $results['expirations_processed']
            ),
            1
        );
        
        die(json_encode($results, JSON_PRETTY_PRINT));
    }
    
    /**
     * Send warning emails to assignments expiring in next 24 hours
     * Only sends once (checks if warning already sent)
     */
    protected function sendExpirationWarnings()
    {
        $prefix = _DB_PREFIX_;
        $now = date('Y-m-d H:i:s');
        $in24h = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        // Find assignments expiring in next 24h that haven't received warning
        $sql = "SELECT a.*, l.name as locker_name, l.lock_id, o.id_lang, o.reference as order_reference, c.firstname, c.lastname, c.email
                FROM {$prefix}rkpickup_assignment a
                JOIN {$prefix}rkpickup_locker l ON a.id_locker = l.id_locker
                JOIN {$prefix}orders o ON a.id_order = o.id_order
                JOIN {$prefix}customer c ON o.id_customer = c.id_customer
                WHERE a.status = 'ready'
                AND a.valid_until > '{$now}'
                AND a.valid_until <= '{$in24h}'
                AND (a.warning_sent IS NULL OR a.warning_sent = 0)";
        
        $assignments = Db::getInstance()->executeS($sql);
        
        $sent = 0;
        $errors = [];
        
        foreach ($assignments as $assignment) {
            try {
                $this->sendWarningEmail($assignment);
                
                // Mark warning as sent
                Db::getInstance()->update('rkpickup_assignment', ['warning_sent' => 1, 'date_upd' => date('Y-m-d H:i:s')], 'id_assignment = ' . (int) $assignment['id_assignment']);
                
                $sent++;
                
            } catch (Exception $e) {
                $errors[] = 'Order #' . $assignment['id_order'] . ': ' . $e->getMessage();
            }
        }
        
        return ['sent' => $sent, 'errors' => $errors];
    }
    
    /**
     * Send expiration warning email
     */
    protected function sendWarningEmail($assignment)
    {
        $templateVars = [
            '{firstname}' => $assignment['firstname'],
            '{lastname}' => $assignment['lastname'],
            '{order_reference}' => $assignment['order_reference'],
            '{locker_name}' => $assignment['locker_name'],
            '{pin_code}' => $assignment['pin_code'],
            '{valid_until}' => date('d/m/Y H:i', strtotime($assignment['valid_until'])),
            '{pickup_address}' => Configuration::get('RKPICKUP_PICKUP_ADDRESS'),
        ];
        
        $result = Mail::Send(
            (int) $assignment['id_lang'],
            'pickup_expiring_warning',
            $this->module->l('⏰ Aviso: Tu pedido expira en menos de 24h'),
            $templateVars,
            $assignment['email'],
            $assignment['firstname'] . ' ' . $assignment['lastname'],
            null, null, null, null,
            dirname(__FILE__) . '/../../mails/',
            false,
            (int) Configuration::get('PS_SHOP_DEFAULT')
        );
        
        if (!$result) {
            throw new Exception('Failed to send email');
        }
        
        PrestaShopLogger::addLog('RkPickup: Warning email sent for order #' . $assignment['id_order'], 1, null, 'Order', $assignment['id_order'], true);
    }
}
