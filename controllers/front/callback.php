<?php
/**
 * TTLock Callback Controller
 * Receives webhook notifications from TTLock API
 */

class RkPickupCallbackModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $ajax = true;

    public function initContent()
    {
        parent::initContent();
        
        // Log all incoming requests for debugging
        $input = file_get_contents('php://input');
        $headers = getallheaders();
        
        PrestaShopLogger::addLog(
            'TTLock Callback received: ' . substr($input, 0, 500),
            1, null, 'TTLock', 0, true
        );

        // Parse JSON input
        $data = json_decode($input, true);
        
        if (!$data) {
            // TTLock test might send empty or simple request
            $this->respondSuccess();
            return;
        }

        // Handle different event types
        $eventType = $data['eventType'] ?? $data['type'] ?? null;
        
        switch ($eventType) {
            case 'lock':
            case 'unlock':
                $this->handleLockEvent($data);
                break;
            case 'passcode_used':
                $this->handlePasscodeUsed($data);
                break;
            default:
                // Unknown event, just acknowledge
                PrestaShopLogger::addLog(
                    'TTLock unknown event: ' . $eventType,
                    2, null, 'TTLock', 0, true
                );
        }

        $this->respondSuccess();
    }

    /**
     * Handle lock/unlock events
     */
    protected function handleLockEvent($data)
    {
        $lockId = $data['lockId'] ?? null;
        $action = $data['eventType'] ?? $data['type'] ?? null;
        
        if (!$lockId) {
            return;
        }

        PrestaShopLogger::addLog(
            sprintf('TTLock %s event for lock %s', $action, $lockId),
            1, null, 'TTLock', 0, true
        );

        // If unlocked, check if this completes a pickup
        if ($action == 'unlock') {
            $this->checkPickupCompletion($lockId);
        }
    }

    /**
     * Handle passcode used event - auto-mark as picked up
     */
    protected function handlePasscodeUsed($data)
    {
        $lockId = $data['lockId'] ?? null;
        $passcode = $data['passcode'] ?? null;
        
        if (!$lockId) {
            return;
        }

        PrestaShopLogger::addLog(
            sprintf('TTLock passcode used on lock %s', $lockId),
            1, null, 'TTLock', 0, true
        );

        $this->checkPickupCompletion($lockId, $passcode);
    }

    /**
     * Check if we should mark an order as picked up
     */
    protected function checkPickupCompletion($lockId, $passcode = null)
    {
        // Find locker by lock_id
        $locker = Db::getInstance()->getRow('
            SELECT * FROM ' . _DB_PREFIX_ . 'rkpickup_locker 
            WHERE lock_id = "' . pSQL($lockId) . '"
        ');

        if (!$locker) {
            return;
        }

        // Find active assignment
        $sql = 'SELECT * FROM ' . _DB_PREFIX_ . 'rkpickup_assignment 
                WHERE id_locker = ' . (int) $locker['id_locker'] . '
                AND status IN ("pending", "ready")';
        
        if ($passcode) {
            $sql .= ' AND pin_code = "' . pSQL($passcode) . '"';
        }
        
        $assignment = Db::getInstance()->getRow($sql);

        if ($assignment) {
            // Mark as picked up
            Db::getInstance()->update('rkpickup_assignment', [
                'status' => 'picked_up',
                'picked_up_at' => date('Y-m-d H:i:s'),
                'date_upd' => date('Y-m-d H:i:s'),
            ], 'id_assignment = ' . (int) $assignment['id_assignment']);

            // Release locker
            Db::getInstance()->update('rkpickup_locker', [
                'status' => 'available',
                'date_upd' => date('Y-m-d H:i:s'),
            ], 'id_locker = ' . (int) $locker['id_locker']);

            PrestaShopLogger::addLog(
                sprintf('Order #%d auto-marked as picked up via TTLock', $assignment['id_order']),
                1, null, 'Order', $assignment['id_order'], true
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
