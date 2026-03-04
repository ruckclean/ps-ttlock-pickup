<?php
/**
 * Ruckclean TTLock Pickup
 * 
 * Integración con TTLock para cajas de recogida de pedidos
 * 
 * @author Ruckclean
 * @copyright 2025 Ruckclean
 * @license MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class RkPickup extends Module
{
    public function __construct()
    {
        $this->name = 'rkpickup';
        $this->tab = 'shipping_logistics';
        $this->version = '1.2.1';
        $this->author = 'Ruckclean';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '1.7.0.0',
            'max' => '8.99.99',
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Ruckclean TTLock Pickup');
        $this->description = $this->l('Sistema de recogida en taquillas con código PIN automático');
        $this->confirmUninstall = $this->l('¿Seguro que quieres desinstalar? Se perderán los datos de las taquillas.');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('actionValidateOrder')
            && $this->registerHook('displayAdminOrderMain')
            && $this->registerHook('displayOrderConfirmation')
            && $this->installDb()
            && $this->installConfig();
    }

    public function uninstall()
    {
        return parent::uninstall()
            && $this->uninstallDb()
            && $this->uninstallConfig();
    }

    /**
     * Create database tables
     */
    protected function installDb()
    {
        $sql = [];
        
        // Table for lockers/boxes
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'rkpickup_locker` (
            `id_locker` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `lock_id` VARCHAR(64) NOT NULL,
            `name` VARCHAR(128) NOT NULL,
            `description` VARCHAR(255) DEFAULT NULL,
            `status` ENUM("available", "occupied", "maintenance") DEFAULT "available",
            `active` TINYINT(1) UNSIGNED DEFAULT 1,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_locker`),
            KEY `lock_id` (`lock_id`),
            KEY `status` (`status`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';
        
        // Table for pickup assignments
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'rkpickup_assignment` (
            `id_assignment` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_order` INT(11) UNSIGNED NOT NULL,
            `id_locker` INT(11) UNSIGNED NOT NULL,
            `pin_code` VARCHAR(16) NOT NULL,
            `ttlock_passcode_id` VARCHAR(64) DEFAULT NULL,
            `status` ENUM("pending", "ready", "picked_up", "expired", "cancelled") DEFAULT "pending",
            `valid_from` DATETIME NOT NULL,
            `valid_until` DATETIME NOT NULL,
            `picked_up_at` DATETIME DEFAULT NULL,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_assignment`),
            KEY `id_order` (`id_order`),
            KEY `id_locker` (`id_locker`),
            KEY `status` (`status`),
            KEY `pin_code` (`pin_code`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';
        
        foreach ($sql as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }
        
        return true;
    }

    protected function uninstallDb()
    {
        $sql = [];
        $sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'rkpickup_assignment`';
        $sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'rkpickup_locker`';
        
        foreach ($sql as $query) {
            Db::getInstance()->execute($query);
        }
        
        return true;
    }

    /**
     * Install default configuration
     */
    protected function installConfig()
    {
        $defaults = [
            'RKPICKUP_TTLOCK_CLIENT_ID' => '',
            'RKPICKUP_TTLOCK_CLIENT_SECRET' => '',
            'RKPICKUP_TTLOCK_ACCESS_TOKEN' => '',
            'RKPICKUP_TTLOCK_REFRESH_TOKEN' => '',
            'RKPICKUP_TTLOCK_TOKEN_EXPIRES' => '',
            'RKPICKUP_TTLOCK_USERNAME' => '',
            'RKPICKUP_TTLOCK_PASSWORD' => '',
            'RKPICKUP_PIN_VALIDITY_HOURS' => '72',
            'RKPICKUP_AUTO_ASSIGN' => '1',
            'RKPICKUP_SEND_EMAIL' => '1',
            'RKPICKUP_PICKUP_ADDRESS' => 'Calle Jazmín, 6 - Las Rozas de Madrid',
        ];

        foreach ($defaults as $key => $value) {
            Configuration::updateValue($key, $value);
        }

        return true;
    }

    protected function uninstallConfig()
    {
        $keys = [
            'RKPICKUP_TTLOCK_CLIENT_ID',
            'RKPICKUP_TTLOCK_CLIENT_SECRET',
            'RKPICKUP_TTLOCK_ACCESS_TOKEN',
            'RKPICKUP_TTLOCK_REFRESH_TOKEN',
            'RKPICKUP_TTLOCK_TOKEN_EXPIRES',
            'RKPICKUP_TTLOCK_USERNAME',
            'RKPICKUP_TTLOCK_PASSWORD',
            'RKPICKUP_PIN_VALIDITY_HOURS',
            'RKPICKUP_AUTO_ASSIGN',
            'RKPICKUP_SEND_EMAIL',
            'RKPICKUP_PICKUP_ADDRESS',
        ];

        foreach ($keys as $key) {
            Configuration::deleteByName($key);
        }

        return true;
    }

    /**
     * Module configuration page
     */
    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitRkPickupConfig')) {
            $this->saveConfig();
            $output .= $this->displayConfirmation($this->l('Configuración guardada'));
        }

        if (Tools::isSubmit('addLocker')) {
            $this->addLocker();
            $output .= $this->displayConfirmation($this->l('Taquilla añadida'));
        }

        if (Tools::getValue('releaseLocker') && Tools::getValue('id_locker')) {
            $this->releaseLocker((int) Tools::getValue('id_locker'));
            $output .= $this->displayConfirmation($this->l('Taquilla liberada'));
        }

        if (Tools::getValue('markCollected') && Tools::getValue('id_order')) {
            $this->markOrderCollected((int) Tools::getValue('id_order'));
            $output .= $this->displayConfirmation($this->l('Pedido marcado como recogido'));
        }

        if (Tools::isSubmit('testConnection')) {
            $result = $this->testTTLockConnection();
            if ($result['success']) {
                $output .= $this->displayConfirmation($this->l('Conexión exitosa con TTLock'));
            } else {
                $output .= $this->displayError($this->l('Error de conexión: ') . $result['error']);
            }
        }

        return $output . $this->renderConfigForm() . $this->renderLockersList();
    }

    protected function saveConfig()
    {
        $fields = [
            'RKPICKUP_TTLOCK_CLIENT_ID',
            'RKPICKUP_TTLOCK_CLIENT_SECRET',
            'RKPICKUP_TTLOCK_USERNAME',
            'RKPICKUP_TTLOCK_PASSWORD',
            'RKPICKUP_PIN_VALIDITY_HOURS',
            'RKPICKUP_AUTO_ASSIGN',
            'RKPICKUP_SEND_EMAIL',
            'RKPICKUP_PICKUP_ADDRESS',
        ];

        foreach ($fields as $field) {
            Configuration::updateValue($field, Tools::getValue($field));
        }
    }

    protected function addLocker()
    {
        $lockId = Tools::getValue('locker_lock_id');
        $name = Tools::getValue('locker_name');
        $description = Tools::getValue('locker_description');

        if ($lockId && $name) {
            Db::getInstance()->insert('rkpickup_locker', [
                'lock_id' => pSQL($lockId),
                'name' => pSQL($name),
                'description' => pSQL($description),
                'status' => 'available',
                'active' => 1,
                'date_add' => date('Y-m-d H:i:s'),
                'date_upd' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Release a locker (cancel active assignment)
     */
    protected function releaseLocker($idLocker)
    {
        // Cancel active assignment
        Db::getInstance()->update('rkpickup_assignment', [
            'status' => 'cancelled',
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_locker = ' . (int) $idLocker . ' AND status IN ("pending", "ready")');

        // Mark locker as available
        Db::getInstance()->update('rkpickup_locker', [
            'status' => 'available',
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_locker = ' . (int) $idLocker);
    }

    /**
     * Mark order as collected
     */
    protected function markOrderCollected($idOrder)
    {
        // Get assignment
        $sql = 'SELECT * FROM `'._DB_PREFIX_.'rkpickup_assignment` WHERE id_order = '.(int)$idOrder.' AND status IN ("pending", "ready")';
        $assignment = Db::getInstance()->getRow($sql);

        if ($assignment) {
            // Update assignment
            Db::getInstance()->update('rkpickup_assignment', [
                'status' => 'picked_up',
                'picked_up_at' => date('Y-m-d H:i:s'),
                'date_upd' => date('Y-m-d H:i:s'),
            ], 'id_assignment = ' . (int) $assignment['id_assignment']);

            // Release locker
            Db::getInstance()->update('rkpickup_locker', [
                'status' => 'available',
                'date_upd' => date('Y-m-d H:i:s'),
            ], 'id_locker = ' . (int) $assignment['id_locker']);
        }
    }

    protected function renderConfigForm()
    {
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->submit_action = 'submitRkPickupConfig';

        $helper->tpl_vars = [
            'fields_value' => $this->getConfigValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$this->getConfigForm()]);
    }

    protected function getConfigForm()
    {
        return [
            'form' => [
                'legend' => [
                    'title' => $this->l('Configuración TTLock Pickup'),
                    'icon' => 'icon-cogs',
                ],
                'tabs' => [
                    'api' => $this->l('API TTLock'),
                    'settings' => $this->l('Ajustes'),
                ],
                'input' => [
                    // API TAB
                    [
                        'type' => 'text',
                        'label' => $this->l('Client ID'),
                        'name' => 'RKPICKUP_TTLOCK_CLIENT_ID',
                        'tab' => 'api',
                        'desc' => $this->l('Client ID de tu aplicación TTLock'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Client Secret'),
                        'name' => 'RKPICKUP_TTLOCK_CLIENT_SECRET',
                        'tab' => 'api',
                        'desc' => $this->l('Client Secret de tu aplicación TTLock'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Usuario TTLock'),
                        'name' => 'RKPICKUP_TTLOCK_USERNAME',
                        'tab' => 'api',
                        'desc' => $this->l('Email de tu cuenta TTLock'),
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->l('Contraseña TTLock'),
                        'name' => 'RKPICKUP_TTLOCK_PASSWORD',
                        'tab' => 'api',
                        'desc' => $this->l('Contraseña de tu cuenta TTLock (se guarda cifrada)'),
                    ],
                    // SETTINGS TAB
                    [
                        'type' => 'text',
                        'label' => $this->l('Validez del PIN (horas)'),
                        'name' => 'RKPICKUP_PIN_VALIDITY_HOURS',
                        'tab' => 'settings',
                        'class' => 'fixed-width-sm',
                        'desc' => $this->l('Tiempo que el PIN será válido (por defecto 72 horas)'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Asignación automática'),
                        'name' => 'RKPICKUP_AUTO_ASSIGN',
                        'tab' => 'settings',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'on', 'value' => 1, 'label' => $this->l('Sí')],
                            ['id' => 'off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                        'desc' => $this->l('Asignar taquilla automáticamente al confirmar pedido'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Enviar email'),
                        'name' => 'RKPICKUP_SEND_EMAIL',
                        'tab' => 'settings',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'on', 'value' => 1, 'label' => $this->l('Sí')],
                            ['id' => 'off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                        'desc' => $this->l('Enviar email al cliente con instrucciones de recogida'),
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('Dirección de recogida'),
                        'name' => 'RKPICKUP_PICKUP_ADDRESS',
                        'tab' => 'settings',
                        'desc' => $this->l('Dirección que se mostrará en el email'),
                    ],
                ],
                'buttons' => [
                    [
                        'title' => $this->l('Probar conexión'),
                        'name' => 'testConnection',
                        'type' => 'submit',
                        'class' => 'btn btn-default pull-right',
                        'icon' => 'process-icon-refresh',
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Guardar'),
                ],
            ],
        ];
    }

    protected function getConfigValues()
    {
        return [
            'RKPICKUP_TTLOCK_CLIENT_ID' => Configuration::get('RKPICKUP_TTLOCK_CLIENT_ID'),
            'RKPICKUP_TTLOCK_CLIENT_SECRET' => Configuration::get('RKPICKUP_TTLOCK_CLIENT_SECRET'),
            'RKPICKUP_TTLOCK_USERNAME' => Configuration::get('RKPICKUP_TTLOCK_USERNAME'),
            'RKPICKUP_TTLOCK_PASSWORD' => Configuration::get('RKPICKUP_TTLOCK_PASSWORD'),
            'RKPICKUP_PIN_VALIDITY_HOURS' => Configuration::get('RKPICKUP_PIN_VALIDITY_HOURS'),
            'RKPICKUP_AUTO_ASSIGN' => Configuration::get('RKPICKUP_AUTO_ASSIGN'),
            'RKPICKUP_SEND_EMAIL' => Configuration::get('RKPICKUP_SEND_EMAIL'),
            'RKPICKUP_PICKUP_ADDRESS' => Configuration::get('RKPICKUP_PICKUP_ADDRESS'),
        ];
    }

    protected function renderLockersList()
    {
        // Get lockers with current assignment info
        $prefix = _DB_PREFIX_;
        $sql = "SELECT l.*, a.id_order as current_order_id, o.reference as current_order_ref, CONCAT(c.firstname, ' ', c.lastname) as current_customer, a.pin_code as current_pin, a.valid_until as current_valid_until FROM {$prefix}rkpickup_locker l LEFT JOIN {$prefix}rkpickup_assignment a ON l.id_locker = a.id_locker AND a.status IN ('pending', 'ready') LEFT JOIN {$prefix}orders o ON a.id_order = o.id_order LEFT JOIN {$prefix}customer c ON o.id_customer = c.id_customer WHERE l.active = 1 ORDER BY l.name ASC";
        $lockers = Db::getInstance()->executeS($sql);

        // Stats
        $stats = [
            'available' => 0,
            'occupied' => 0,
            'pending' => 0,
            'picked_today' => 0,
        ];
        
        foreach ($lockers as $locker) {
            if ($locker['status'] == 'available') {
                $stats['available']++;
            } else {
                $stats['occupied']++;
            }
        }
        
        $sql = "SELECT COUNT(*) FROM {$prefix}rkpickup_assignment WHERE status IN ('pending', 'ready')";
        $stats['pending'] = (int) Db::getInstance()->getValue($sql);
        
        $sql = "SELECT COUNT(*) FROM {$prefix}rkpickup_assignment WHERE status = 'picked_up' AND DATE(picked_up_at) = CURDATE()";
        $stats['picked_today'] = (int) Db::getInstance()->getValue($sql);

        // Active assignments
        $sql = "SELECT a.*, l.name as locker_name, o.reference as order_reference, CONCAT(c.firstname, ' ', c.lastname) as customer_name FROM {$prefix}rkpickup_assignment a JOIN {$prefix}rkpickup_locker l ON a.id_locker = l.id_locker JOIN {$prefix}orders o ON a.id_order = o.id_order JOIN {$prefix}customer c ON o.id_customer = c.id_customer WHERE a.status IN ('pending', 'ready') ORDER BY a.date_add DESC";
        $activeAssignments = Db::getInstance()->executeS($sql);

        // Recent history
        $sql = "SELECT a.*, l.name as locker_name, o.reference as order_reference FROM {$prefix}rkpickup_assignment a JOIN {$prefix}rkpickup_locker l ON a.id_locker = l.id_locker JOIN {$prefix}orders o ON a.id_order = o.id_order WHERE a.status IN ('picked_up', 'expired', 'cancelled') ORDER BY a.date_upd DESC LIMIT 10";
        $recentHistory = Db::getInstance()->executeS($sql);

        $baseUrl = AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules');

        $this->context->smarty->assign([
            'lockers' => $lockers,
            'stats' => $stats,
            'active_assignments' => $activeAssignments,
            'recent_history' => $recentHistory,
            'add_locker_url' => $baseUrl,
            'release_url' => $baseUrl . '&releaseLocker=1',
            'collected_url' => $baseUrl . '&markCollected=1',
            'order_link_base' => $this->context->link->getAdminLink('AdminOrders'),
        ]);

        return $this->display(__FILE__, 'views/templates/admin/lockers_list.tpl');
    }

    /**
     * Test TTLock API connection
     */
    protected function testTTLockConnection()
    {
        require_once dirname(__FILE__) . '/classes/TTLockAPI.php';
        
        $api = new TTLockAPI(
            Configuration::get('RKPICKUP_TTLOCK_CLIENT_ID'),
            Configuration::get('RKPICKUP_TTLOCK_CLIENT_SECRET')
        );

        return $api->authenticate(
            Configuration::get('RKPICKUP_TTLOCK_USERNAME'),
            Configuration::get('RKPICKUP_TTLOCK_PASSWORD')
        );
    }

    /**
     * Hook: When order is validated
     */
    public function hookActionValidateOrder($params)
    {
        if (!Configuration::get('RKPICKUP_AUTO_ASSIGN')) {
            return;
        }

        $order = $params['order'];
        
        // Check if this is a pickup order (you might want to add logic here)
        // For now, assign to all orders
        
        $this->assignLockerToOrder($order);
    }

    /**
     * Assign an available locker to an order
     */
    public function assignLockerToOrder($order)
    {
        require_once dirname(__FILE__) . '/classes/TTLockAPI.php';
        
        // Find available locker (getRow adds LIMIT 1 automatically)
        $sql = 'SELECT * FROM `'._DB_PREFIX_.'rkpickup_locker` WHERE `status` = "available" AND `active` = 1 ORDER BY `id_locker` ASC';
        $locker = Db::getInstance()->getRow($sql);

        if (!$locker) {
            PrestaShopLogger::addLog('RkPickup: No hay taquillas disponibles', 2, null, 'Order', $order->id);
            return false;
        }

        // Generate PIN via TTLock API
        $api = new TTLockAPI(
            Configuration::get('RKPICKUP_TTLOCK_CLIENT_ID'),
            Configuration::get('RKPICKUP_TTLOCK_CLIENT_SECRET')
        );

        $authResult = $api->authenticate(
            Configuration::get('RKPICKUP_TTLOCK_USERNAME'),
            Configuration::get('RKPICKUP_TTLOCK_PASSWORD')
        );

        if (!$authResult['success']) {
            PrestaShopLogger::addLog('RkPickup: Error autenticación TTLock', 3, null, 'Order', $order->id);
            return false;
        }

        // Calculate validity period
        $validityHours = (int) Configuration::get('RKPICKUP_PIN_VALIDITY_HOURS');
        $validFrom = time() * 1000; // TTLock uses milliseconds
        $validUntil = ($validFrom + ($validityHours * 3600 * 1000));

        // Create passcode
        $passcodeResult = $api->createPasscode(
            $locker['lock_id'],
            $validFrom,
            $validUntil
        );

        if (!$passcodeResult['success']) {
            PrestaShopLogger::addLog('RkPickup: Error creando PIN - ' . $passcodeResult['error'], 3, null, 'Order', $order->id);
            return false;
        }

        // Save assignment
        Db::getInstance()->insert('rkpickup_assignment', [
            'id_order' => (int) $order->id,
            'id_locker' => (int) $locker['id_locker'],
            'pin_code' => pSQL($passcodeResult['passcode']),
            'ttlock_passcode_id' => pSQL($passcodeResult['passcode_id']),
            'status' => 'ready',
            'valid_from' => date('Y-m-d H:i:s', $validFrom / 1000),
            'valid_until' => date('Y-m-d H:i:s', $validUntil / 1000),
            'date_add' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
        ]);

        // Mark locker as occupied
        Db::getInstance()->update('rkpickup_locker', [
            'status' => 'occupied',
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_locker = ' . (int) $locker['id_locker']);

        // Send email to customer
        if (Configuration::get('RKPICKUP_SEND_EMAIL')) {
            $this->sendPickupEmail($order, $locker, $passcodeResult['passcode']);
        }

        PrestaShopLogger::addLog(
            'RkPickup: Taquilla ' . $locker['name'] . ' asignada con PIN ' . $passcodeResult['passcode'],
            1, null, 'Order', $order->id
        );

        return true;
    }

    /**
     * Send pickup instructions email
     */
    protected function sendPickupEmail($order, $locker, $pinCode)
    {
        $customer = new Customer($order->id_customer);
        $validityHours = Configuration::get('RKPICKUP_PIN_VALIDITY_HOURS');
        $pickupAddress = Configuration::get('RKPICKUP_PICKUP_ADDRESS');

        $templateVars = [
            '{firstname}' => $customer->firstname,
            '{lastname}' => $customer->lastname,
            '{order_reference}' => $order->reference,
            '{locker_name}' => $locker['name'],
            '{pin_code}' => $pinCode,
            '{validity_hours}' => $validityHours,
            '{pickup_address}' => $pickupAddress,
        ];

        Mail::Send(
            (int) $order->id_lang,
            'pickup_ready',
            $this->l('Tu pedido está listo para recoger'),
            $templateVars,
            $customer->email,
            $customer->firstname . ' ' . $customer->lastname,
            null,
            null,
            null,
            null,
            dirname(__FILE__) . '/mails/',
            false,
            (int) $order->id_shop
        );
    }

    /**
     * Hook: Display in admin order page
     */
    public function hookDisplayAdminOrderMain($params)
    {
        $orderId = $params['id_order'];
        
        $sql = 'SELECT a.*, l.name as locker_name FROM `'._DB_PREFIX_.'rkpickup_assignment` a LEFT JOIN `'._DB_PREFIX_.'rkpickup_locker` l ON a.id_locker = l.id_locker WHERE a.id_order = '.(int)$orderId.' ORDER BY a.id_assignment DESC';
        $assignment = Db::getInstance()->getRow($sql);

        $this->context->smarty->assign([
            'assignment' => $assignment,
            'order_id' => $orderId,
        ]);

        return $this->display(__FILE__, 'views/templates/hook/admin_order.tpl');
    }

    /**
     * Hook: Display on order confirmation page
     */
    public function hookDisplayOrderConfirmation($params)
    {
        $order = $params['order'];
        
        $sql = 'SELECT a.*, l.name as locker_name FROM `'._DB_PREFIX_.'rkpickup_assignment` a LEFT JOIN `'._DB_PREFIX_.'rkpickup_locker` l ON a.id_locker = l.id_locker WHERE a.id_order = '.(int)$order->id.' ORDER BY a.id_assignment DESC';
        $assignment = Db::getInstance()->getRow($sql);

        if (!$assignment) {
            return '';
        }

        $this->context->smarty->assign([
            'assignment' => $assignment,
            'pickup_address' => Configuration::get('RKPICKUP_PICKUP_ADDRESS'),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/order_confirmation.tpl');
    }
}
