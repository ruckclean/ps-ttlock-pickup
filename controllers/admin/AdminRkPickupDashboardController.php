<?php
/**
 * Admin Dashboard Controller for RkPickup
 */

class AdminRkPickupDashboardController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->display = 'view';
        
        parent::__construct();
        
        $this->meta_title = $this->l('Taquillas de Recogida');
    }

    public function initContent()
    {
        parent::initContent();
        
        // Handle actions
        if (Tools::isSubmit('markAvailable') && Tools::getValue('id_locker')) {
            $this->markLockerAvailable((int) Tools::getValue('id_locker'));
        }
        
        if (Tools::isSubmit('releaseLocker') && Tools::getValue('id_locker')) {
            $this->releaseLocker((int) Tools::getValue('id_locker'));
        }

        if (Tools::getValue('generateOperatorPin') && Tools::getValue('id_locker')) {
            $this->generateOperatorPin((int) Tools::getValue('id_locker'));
        }

        if (Tools::getValue('setMaintenance') && Tools::getValue('id_locker')) {
            $this->setMaintenance((int) Tools::getValue('id_locker'));
        }

        // Get stats
        $stats = $this->getStats();
        
        // Get lockers
        $lockers = $this->getLockers();
        
        // Get active assignments
        $activeAssignments = $this->getActiveAssignments();
        
        // Get waiting assignments
        $waitingAssignments = $this->getWaitingAssignments();
        
        // Get recent history
        $recentHistory = $this->getRecentHistory();
        
        // Get operations history
        $operationsHistory = $this->getOperationsHistory();

        $this->context->smarty->assign([
            'stats' => $stats,
            'lockers' => $lockers,
            'active_assignments' => $activeAssignments,
            'waiting_assignments' => $waitingAssignments,
            'recent_history' => $recentHistory,
            'operations_history' => $operationsHistory,
            'current_url' => $this->context->link->getAdminLink('AdminRkPickupDashboard'),
            'order_link_base' => $this->context->link->getAdminLink('AdminOrders'),
        ]);

        $this->setTemplate('dashboard.tpl');
    }

    protected function getStats()
    {
        $prefix = _DB_PREFIX_;
        
        return [
            'available' => (int) Db::getInstance()->getValue("SELECT COUNT(*) FROM {$prefix}rkpickup_locker WHERE status = 'available' AND active = 1"),
            'pending_pickup' => (int) Db::getInstance()->getValue("SELECT COUNT(*) FROM {$prefix}rkpickup_assignment WHERE status IN ('pending', 'ready', 'expired_grace')"),
            'pending_refill' => (int) Db::getInstance()->getValue("SELECT COUNT(*) FROM {$prefix}rkpickup_locker WHERE status = 'pending_refill' AND active = 1"),
            'waiting' => (int) Db::getInstance()->getValue("SELECT COUNT(*) FROM {$prefix}rkpickup_assignment WHERE status = 'waiting'"),
            'picked_today' => (int) Db::getInstance()->getValue("SELECT COUNT(*) FROM {$prefix}rkpickup_assignment WHERE status = 'picked_up' AND DATE(picked_up_at) = CURDATE()"),
            'expired_grace' => (int) Db::getInstance()->getValue("SELECT COUNT(*) FROM {$prefix}rkpickup_assignment WHERE status = 'expired_grace'"),
        ];
    }

    protected function getLockers()
    {
        $prefix = _DB_PREFIX_;
        $sql = "SELECT l.*, 
                       l.operator_pin,
                       DATE_FORMAT(l.operator_pin_valid_until, '%d/%m/%Y %H:%i') as operator_pin_valid_until,
                       a.id_order as current_order_id, 
                       o.reference as current_order_ref, 
                       CONCAT(c.firstname, ' ', c.lastname) as current_customer, 
                       a.pin_code as current_pin, 
                       a.valid_until as current_valid_until,
                       a.status as assignment_status
                FROM {$prefix}rkpickup_locker l 
                LEFT JOIN {$prefix}rkpickup_assignment a ON l.id_locker = a.id_locker AND a.status IN ('pending', 'ready', 'expired_grace') 
                LEFT JOIN {$prefix}orders o ON a.id_order = o.id_order 
                LEFT JOIN {$prefix}customer c ON o.id_customer = c.id_customer 
                WHERE l.active = 1 
                ORDER BY l.name ASC";
        return Db::getInstance()->executeS($sql);
    }

    protected function getActiveAssignments()
    {
        $prefix = _DB_PREFIX_;
        $sql = "SELECT a.*, 
                       l.name as locker_name, 
                       o.reference as order_reference, 
                       CONCAT(c.firstname, ' ', c.lastname) as customer_name,
                       c.email as customer_email
                FROM {$prefix}rkpickup_assignment a 
                JOIN {$prefix}rkpickup_locker l ON a.id_locker = l.id_locker 
                JOIN {$prefix}orders o ON a.id_order = o.id_order 
                JOIN {$prefix}customer c ON o.id_customer = c.id_customer 
                WHERE a.status IN ('pending', 'ready', 'expired_grace') 
                ORDER BY a.date_add DESC";
        return Db::getInstance()->executeS($sql);
    }

    protected function getWaitingAssignments()
    {
        $prefix = _DB_PREFIX_;
        $sql = "SELECT a.*, 
                       o.reference as order_reference, 
                       CONCAT(c.firstname, ' ', c.lastname) as customer_name,
                       c.email as customer_email
                FROM {$prefix}rkpickup_assignment a 
                JOIN {$prefix}orders o ON a.id_order = o.id_order 
                JOIN {$prefix}customer c ON o.id_customer = c.id_customer 
                WHERE a.status = 'waiting' 
                ORDER BY a.date_add ASC";
        return Db::getInstance()->executeS($sql);
    }

    protected function getRecentHistory()
    {
        $prefix = _DB_PREFIX_;
        $sql = "SELECT a.*, 
                       l.name as locker_name, 
                       o.reference as order_reference 
                FROM {$prefix}rkpickup_assignment a 
                JOIN {$prefix}rkpickup_locker l ON a.id_locker = l.id_locker 
                JOIN {$prefix}orders o ON a.id_order = o.id_order 
                WHERE a.status IN ('picked_up', 'expired', 'cancelled') 
                ORDER BY a.date_upd DESC 
                LIMIT 10";
        return Db::getInstance()->executeS($sql);
    }

    protected function getOperationsHistory()
    {
        $prefix = _DB_PREFIX_;
        $sql = "SELECT h.*, 
                       l.name as locker_name, 
                       o.reference as order_reference 
                FROM {$prefix}rkpickup_history h 
                LEFT JOIN {$prefix}rkpickup_locker l ON h.id_locker = l.id_locker 
                LEFT JOIN {$prefix}orders o ON h.id_order = o.id_order 
                ORDER BY h.date_add DESC 
                LIMIT 20";
        return Db::getInstance()->executeS($sql);
    }

    protected function markLockerAvailable($idLocker)
    {
        // Clear any operator PIN
        Db::getInstance()->update('rkpickup_locker', [
            'status' => 'available',
            'operator_pin' => null,
            'operator_pin_id' => null,
            'operator_pin_valid_until' => null,
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_locker = ' . (int) $idLocker);

        // Check if there are waiting orders and auto-assign
        $module = Module::getInstanceByName('rkpickup');
        if ($module && method_exists($module, 'processWaitingQueue')) {
            $assigned = $module->processWaitingQueue($idLocker);
            if ($assigned) {
                $this->confirmations[] = $this->l('Taquilla asignada automáticamente a pedido en espera');
                return;
            }
        }

        $this->confirmations[] = $this->l('Taquilla marcada como disponible');
    }

    protected function releaseLocker($idLocker)
    {
        // Cancel any active assignment
        Db::getInstance()->update('rkpickup_assignment', [
            'status' => 'cancelled',
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_locker = ' . (int) $idLocker . ' AND status IN ("pending", "ready", "expired_grace")');

        // Mark locker as available
        Db::getInstance()->update('rkpickup_locker', [
            'status' => 'available',
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_locker = ' . (int) $idLocker);

        $this->confirmations[] = $this->l('Taquilla liberada');
    }

    /**
     * Set locker to maintenance mode
     */
    protected function setMaintenance($idLocker)
    {
        Db::getInstance()->update('rkpickup_locker', [
            'status' => 'maintenance',
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_locker = ' . (int) $idLocker);

        $this->confirmations[] = $this->l('Taquilla puesta en mantenimiento');
    }

    /**
     * Generate a temporary operator PIN to open the locker
     */
    protected function generateOperatorPin($idLocker)
    {
        require_once _PS_MODULE_DIR_ . 'rkpickup/classes/TTLockAPI.php';
        
        // Get locker info
        $locker = Db::getInstance()->getRow('SELECT * FROM '._DB_PREFIX_.'rkpickup_locker WHERE id_locker = '.(int)$idLocker);
        
        if (!$locker) {
            $this->errors[] = $this->l('Taquilla no encontrada');
            return;
        }

        $api = new TTLockAPI(
            Configuration::get('RKPICKUP_TTLOCK_CLIENT_ID'),
            Configuration::get('RKPICKUP_TTLOCK_CLIENT_SECRET')
        );

        $authResult = $api->authenticate(
            Configuration::get('RKPICKUP_TTLOCK_USERNAME'),
            Configuration::get('RKPICKUP_TTLOCK_PASSWORD')
        );

        if (!$authResult['success']) {
            $this->errors[] = $this->l('Error de autenticación TTLock');
            return;
        }

        // Create PIN valid for 1 hour
        $validFrom = time() * 1000;
        $validUntil = $validFrom + (3600 * 1000); // 1 hour

        $result = $api->createPasscode(
            $locker['lock_id'],
            $validFrom,
            $validUntil
        );

        if ($result['success']) {
            // Store operator PIN temporarily in locker record
            Db::getInstance()->update('rkpickup_locker', [
                'operator_pin' => pSQL($result['passcode']),
                'operator_pin_id' => pSQL($result['passcode_id']),
                'operator_pin_valid_until' => date('Y-m-d H:i:s', $validUntil / 1000),
                'date_upd' => date('Y-m-d H:i:s'),
            ], 'id_locker = ' . (int) $idLocker);
            
            $this->confirmations[] = sprintf(
                $this->l('PIN de operario generado: %s (válido 1 hora)'),
                $result['passcode']
            );
        } else {
            $this->errors[] = $this->l('Error al generar PIN: ') . ($result['error'] ?? 'Unknown');
        }
    }
}
