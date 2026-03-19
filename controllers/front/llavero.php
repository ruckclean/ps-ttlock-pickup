<?php
/**
 * API endpoint for NFC llavero association
 * 
 * Usage:
 *   POST /index.php?fc=module&module=rkpickup&controller=llavero&token=XXX
 *   Body: { "order_ref": "ABCDEFGH", "llavero_uid": "A1B2C3D4" }
 *   
 *   Or by order ID:
 *   Body: { "id_order": 123, "llavero_uid": "A1B2C3D4" }
 */

class RkpickupLlaveroModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        header('Content-Type: application/json');
        
        // Verify token
        $token = Tools::getValue('token');
        $expectedToken = Configuration::get('RKPICKUP_CRON_TOKEN');
        
        if (!$token || $token !== $expectedToken) {
            $this->jsonResponse(['success' => false, 'error' => 'Invalid token'], 401);
            return;
        }
        
        // Get JSON body
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            // Try form data
            $input = [
                'order_ref' => Tools::getValue('order_ref'),
                'id_order' => Tools::getValue('id_order'),
                'llavero_uid' => Tools::getValue('llavero_uid'),
            ];
        }
        
        $llaveroUid = isset($input['llavero_uid']) ? strtoupper(trim(preg_replace('/[^A-Fa-f0-9]/', '', $input['llavero_uid']))) : '';
        
        if (empty($llaveroUid)) {
            $this->jsonResponse(['success' => false, 'error' => 'llavero_uid required'], 400);
            return;
        }
        
        // Find assignment by order reference or ID
        $prefix = _DB_PREFIX_;
        $idOrder = null;
        
        if (!empty($input['id_order'])) {
            $idOrder = (int) $input['id_order'];
        } elseif (!empty($input['order_ref'])) {
            $orderRef = pSQL($input['order_ref']);
            $idOrder = (int) Db::getInstance()->getValue("SELECT id_order FROM {$prefix}orders WHERE reference = '{$orderRef}'");
        }
        
        if (!$idOrder) {
            $this->jsonResponse(['success' => false, 'error' => 'Order not found'], 404);
            return;
        }
        
        // Find active assignment for this order
        $assignment = Db::getInstance()->getRow("SELECT * FROM {$prefix}rkpickup_assignment WHERE id_order = {$idOrder} AND status IN ('pending', 'ready', 'expired_grace') ORDER BY id_assignment DESC LIMIT 1");
        
        if (!$assignment) {
            $this->jsonResponse(['success' => false, 'error' => 'No active assignment for this order'], 404);
            return;
        }
        
        // Update llavero UID
        $result = Db::getInstance()->update('rkpickup_assignment', [
            'llavero_uid' => pSQL($llaveroUid),
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_assignment = ' . (int) $assignment['id_assignment']);
        
        if ($result) {
            $this->jsonResponse([
                'success' => true,
                'message' => 'Llavero asociado correctamente',
                'data' => [
                    'id_order' => $idOrder,
                    'id_assignment' => $assignment['id_assignment'],
                    'llavero_uid' => $llaveroUid,
                ]
            ]);
        } else {
            $this->jsonResponse(['success' => false, 'error' => 'Database error'], 500);
        }
    }
    
    protected function jsonResponse($data, $statusCode = 200)
    {
        http_response_code($statusCode);
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
