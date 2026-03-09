<?php
/**
 * TTLock Callback Controller
 * Receives webhook notifications from TTLock API
 * 
 * TTLock sends POST data in URL-encoded format:
 * - notifyType: 1=unlock record, 2=gateway status
 * - records: JSON array with unlock details
 * - lockId, lockMac, admin, etc.
 */

class RkPickupCallbackModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $ajax = true;

    public function initContent()
    {
        parent::initContent();
        
        // Get both POST and raw input
        $rawInput = file_get_contents('php://input');
        
        PrestaShopLogger::addLog(
            'TTLock Callback received: ' . substr($rawInput, 0, 500),
            1, null, 'TTLock', 0, true
        );

        // TTLock sends URL-encoded form data, not JSON
        // Parse from $_POST or raw input
        $notifyType = $_POST['notifyType'] ?? null;
        $lockId = $_POST['lockId'] ?? null;
        $records = $_POST['records'] ?? null;
        
        // If not in POST, try parsing raw input
        if (!$notifyType && $rawInput) {
            parse_str($rawInput, $parsed);
            $notifyType = $parsed['notifyType'] ?? null;
            $lockId = $parsed['lockId'] ?? null;
            $records = $parsed['records'] ?? null;
        }

        PrestaShopLogger::addLog(
            sprintf('TTLock notifyType=%s lockId=%s', $notifyType, $lockId),
            1, null, 'TTLock', 0, true
        );

        // notifyType=1 means unlock records
        if ($notifyType == 1 && $records) {
            $this->handleUnlockRecords($lockId, $records);
        }
        // notifyType=2 is gateway status, just acknowledge
        
        $this->respondSuccess();
    }

    /**
     * Handle unlock records from TTLock
     * recordType 4 = keypad password unlock
     */
    protected function handleUnlockRecords($lockId, $recordsJson)
    {
        $records = json_decode($recordsJson, true);
        
        if (!is_array($records)) {
            PrestaShopLogger::addLog(
                'TTLock: Could not parse records JSON',
                2, null, 'TTLock', 0, true
            );
            return;
        }

        foreach ($records as $record) {
            // recordType 4 = keypad password used
            if (($record['recordType'] ?? 0) == 4 && ($record['success'] ?? 0) == 1) {
                $keyboardPwd = $record['keyboardPwd'] ?? null;
                $recordLockId = $record['lockId'] ?? $lockId;
                
                PrestaShopLogger::addLog(
                    sprintf('TTLock: PIN %s used on lock %s', $keyboardPwd, $recordLockId),
                    1, null, 'TTLock', 0, true
                );

                if ($keyboardPwd) {
                    $this->markAsPickedUp($recordLockId, $keyboardPwd);
                }
            }
        }
    }

    /**
     * Mark order as picked up, release locker, send email
     */
    protected function markAsPickedUp($lockId, $pinCode)
    {
        // Find assignment by PIN code
        $assignment = Db::getInstance()->getRow('
            SELECT a.*, l.id_locker, l.name as locker_name, l.lock_id
            FROM ' . _DB_PREFIX_ . 'rkpickup_assignment a
            INNER JOIN ' . _DB_PREFIX_ . 'rkpickup_locker l ON a.id_locker = l.id_locker
            WHERE a.pin_code = "' . pSQL($pinCode) . '"
            AND a.status IN ("pending", "ready")
            AND l.lock_id = "' . pSQL($lockId) . '"
        ');

        if (!$assignment) {
            PrestaShopLogger::addLog(
                sprintf('TTLock: No active assignment found for PIN %s on lock %s', $pinCode, $lockId),
                2, null, 'TTLock', 0, true
            );
            return;
        }

        $idOrder = (int) $assignment['id_order'];
        $idLocker = (int) $assignment['id_locker'];
        $idAssignment = (int) $assignment['id_assignment'];

        // 1. Update assignment status
        Db::getInstance()->update('rkpickup_assignment', [
            'status' => 'picked_up',
            'picked_up_at' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_assignment = ' . $idAssignment);

        // 2. Set locker to "pending_refill" (waiting for staff to add new item)
        Db::getInstance()->update('rkpickup_locker', [
            'status' => 'pending_refill',
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_locker = ' . $idLocker);

        // 3. Delete/invalidate the PIN via TTLock API
        $this->invalidatePin($assignment);

        // 4. Update order status to "Recogido en taquilla"
        $module = Module::getInstanceByName('rkpickup');
        if ($module) {
            $module->updateOrderStatusFromAssignment($idOrder, 'picked_up');
        }
        
        PrestaShopLogger::addLog(
            sprintf('Order #%d marked as picked up (locker: %s)', $idOrder, $assignment['locker_name']),
            1, null, 'Order', $idOrder, true
        );

        // 5. Send custom pickup confirmation email
        $this->sendPickupConfirmationEmail($order, $assignment);
    }

    /**
     * Invalidate/delete the PIN via TTLock API
     */
    protected function invalidatePin($assignment)
    {
        if (empty($assignment['ttlock_passcode_id'])) {
            return;
        }

        try {
            require_once _PS_MODULE_DIR_ . 'rkpickup/classes/TTLockAPI.php';
            
            $api = new TTLockAPI(
                Configuration::get('RKPICKUP_TTLOCK_CLIENT_ID'),
                Configuration::get('RKPICKUP_TTLOCK_CLIENT_SECRET')
            );

            $authResult = $api->authenticate(
                Configuration::get('RKPICKUP_TTLOCK_USERNAME'),
                Configuration::get('RKPICKUP_TTLOCK_PASSWORD')
            );

            if ($authResult['success']) {
                $result = $api->deletePasscode(
                    $assignment['lock_id'],
                    $assignment['ttlock_passcode_id']
                );
                
                if ($result['success']) {
                    PrestaShopLogger::addLog(
                        sprintf('TTLock: PIN %s deleted for order #%d', $assignment['pin_code'], $assignment['id_order']),
                        1, null, 'TTLock', 0, true
                    );
                } else {
                    PrestaShopLogger::addLog(
                        sprintf('TTLock: Failed to delete PIN: %s', $result['error'] ?? 'unknown'),
                        2, null, 'TTLock', 0, true
                    );
                }
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'TTLock: Error invalidating PIN: ' . $e->getMessage(),
                2, null, 'TTLock', 0, true
            );
        }
    }

    /**
     * Send pickup confirmation email
     */
    protected function sendPickupConfirmationEmail($order, $assignment)
    {
        if (!Validate::isLoadedObject($order)) {
            return;
        }

        $customer = new Customer($order->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            return;
        }

        $templateVars = [
            '{firstname}' => $customer->firstname,
            '{lastname}' => $customer->lastname,
            '{order_name}' => $order->reference,
            '{locker_name}' => $assignment['locker_name'],
            '{pickup_time}' => date('d/m/Y H:i'),
        ];

        // Try to send custom email, fall back to log if template doesn't exist
        try {
            Mail::Send(
                (int) $order->id_lang,
                'pickup_confirmation',
                'Tu pedido ha sido recogido - Ruckclean',
                $templateVars,
                $customer->email,
                $customer->firstname . ' ' . $customer->lastname,
                null,
                null,
                null,
                null,
                _PS_MODULE_DIR_ . 'rkpickup/mails/',
                false,
                (int) $order->id_shop
            );
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'RkPickup: Could not send pickup email: ' . $e->getMessage(),
                2, null, 'Order', $order->id, true
            );
        }
    }

    /**
     * Respond with success (TTLock expects 200 OK)
     */
    protected function respondSuccess()
    {
        header('Content-Type: application/json');
        http_response_code(200);
        echo json_encode(['success' => true]);
        exit;
    }
}
