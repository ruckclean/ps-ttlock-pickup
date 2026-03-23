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
        $this->version = '1.4.0';
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
            && $this->registerHook('actionOrderStatusPostUpdate')
            && $this->registerHook('displayAdminOrderMain')
            && $this->registerHook('displayOrderConfirmation')
            && $this->registerHook('actionEmailSendBefore')
            && $this->installDb()
            && $this->installConfig()
            && $this->installTab();
    }

    public function uninstall()
    {
        return parent::uninstall()
            && $this->uninstallDb()
            && $this->uninstallConfig()
            && $this->uninstallTab();
    }

    /**
     * Install admin tab under Orders menu
     */
    protected function installTab()
    {
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminRkPickupDashboard';
        $tab->module = $this->name;
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminParentOrders');
        $tab->icon = 'lock';
        
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'Taquillas de Recogida';
        }
        
        return $tab->add();
    }

    /**
     * Uninstall admin tab
     */
    protected function uninstallTab()
    {
        $idTab = (int) Tab::getIdFromClassName('AdminRkPickupDashboard');
        if ($idTab) {
            $tab = new Tab($idTab);
            return $tab->delete();
        }
        return true;
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
            `status` ENUM("available", "assigned", "occupied", "pending_refill", "maintenance") DEFAULT "available",
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
            `status` ENUM("pending", "ready", "waiting", "expired_grace", "picked_up", "expired", "cancelled") DEFAULT "pending",
            `valid_from` DATETIME NOT NULL,
            `valid_until` DATETIME NOT NULL,
            `warning_sent` TINYINT(1) UNSIGNED DEFAULT 0,
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
            'RKPICKUP_CRON_TOKEN' => bin2hex(random_bytes(16)),
        ];

        foreach ($defaults as $key => $value) {
            Configuration::updateValue($key, $value);
        }

        // Create custom order statuses
        $this->createOrderStatuses();

        return true;
    }

    /**
     * Create custom order statuses for pickup workflow
     */
    protected function createOrderStatuses()
    {
        $statuses = [
            'RKPICKUP_OS_WAITING' => ['name' => 'Esperando taquilla', 'color' => '#E74C3C', 'send_email' => false],
            'RKPICKUP_OS_READY' => ['name' => 'Listo para recoger', 'color' => '#00D4AA', 'send_email' => false],
            'RKPICKUP_OS_EXPIRED_GRACE' => ['name' => 'Plazo expirado (gracia)', 'color' => '#F39C12', 'send_email' => false],
            'RKPICKUP_OS_EXPIRED' => ['name' => 'Reserva cancelada', 'color' => '#C0392B', 'send_email' => false],
            'RKPICKUP_OS_PICKED_UP' => ['name' => 'Recogido', 'color' => '#27AE60', 'send_email' => false],
        ];

        foreach ($statuses as $configKey => $data) {
            // Check if already exists
            $existingId = Configuration::get($configKey);
            if ($existingId) {
                continue;
            }

            $orderState = new OrderState();
            $orderState->color = $data['color'];
            $orderState->send_email = $data['send_email'];
            $orderState->module_name = $this->name;
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
            'RKPICKUP_CRON_TOKEN',
            'RKPICKUP_OS_WAITING',
            'RKPICKUP_OS_READY',
            'RKPICKUP_OS_EXPIRED_GRACE',
            'RKPICKUP_OS_EXPIRED',
            'RKPICKUP_OS_PICKED_UP',
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
                    'cron' => $this->l('Cron / Expiración'),
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
                        'type' => 'text',
                        'label' => $this->l('Contraseña TTLock'),
                        'name' => 'RKPICKUP_TTLOCK_PASSWORD',
                        'tab' => 'api',
                        'desc' => $this->l('Contraseña de tu cuenta TTLock'),
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
                    // CRON TAB
                    [
                        'type' => 'html',
                        'name' => 'cron_info',
                        'tab' => 'cron',
                        'html_content' => $this->getCronInfoHtml(),
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

    /**
     * Generate HTML for cron configuration tab
     */
    protected function getCronInfoHtml()
    {
        $token = Configuration::get('RKPICKUP_CRON_TOKEN');
        if (!$token) {
            $token = bin2hex(random_bytes(16));
            Configuration::updateValue('RKPICKUP_CRON_TOKEN', $token);
        }
        
        $cronUrl = $this->context->link->getModuleLink('rkpickup', 'cron', ['token' => $token], true);
        
        $html = '
        <div class="panel">
            <div class="panel-heading"><i class="icon-clock-o"></i> Configuración del Cron</div>
            <div class="panel-body">
                <div class="alert alert-info">
                    <p><strong>¿Qué hace el cron?</strong></p>
                    <ul>
                        <li>Envía avisos de expiración 24h antes de que expire el PIN</li>
                        <li>Procesa reservas expiradas (cancela o pone en gracia según cola de espera)</li>
                        <li>Recomendado: ejecutar cada hora</li>
                    </ul>
                </div>
                
                <div class="form-group">
                    <label>URL del Cron (secreta)</label>
                    <div class="input-group">
                        <input type="text" class="form-control" readonly value="' . htmlspecialchars($cronUrl) . '" id="cron_url">
                        <span class="input-group-btn">
                            <button class="btn btn-default" type="button" onclick="navigator.clipboard.writeText(document.getElementById(\'cron_url\').value); alert(\'URL copiada!\');">
                                <i class="icon-copy"></i> Copiar
                            </button>
                        </span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Token de seguridad</label>
                    <input type="text" class="form-control" readonly value="' . htmlspecialchars($token) . '">
                    <p class="help-block">Este token protege el cron. No lo compartas.</p>
                </div>
                
                <div class="alert alert-warning">
                    <p><strong>Comando crontab sugerido (ejecutar cada hora):</strong></p>
                    <code>0 * * * * curl -s "' . htmlspecialchars($cronUrl) . '" > /dev/null 2>&1</code>
                </div>

                <hr>
                <h4>Flujo de Expiración</h4>
                <table class="table table-bordered">
                    <thead>
                        <tr><th>Situación</th><th>Acción</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>PIN expira en 24h</td>
                            <td><span class="label label-warning">Email de aviso</span></td>
                        </tr>
                        <tr>
                            <td>PIN expirado + NO hay cola</td>
                            <td><span class="label label-info">Gracia: PIN sigue activo</span> + email aviso</td>
                        </tr>
                        <tr>
                            <td>PIN expirado + SÍ hay cola</td>
                            <td><span class="label label-danger">PIN cancelado</span> → asigna al siguiente</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>';
        
        return $html;
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
        
        // Don't assign locker for bank transfer - wait for payment confirmation
        $paymentModule = $order->module;
        $offlinePayments = ['ps_wirepayment', 'bankwire', 'cheque', 'ps_checkpayment'];
        
        if (in_array($paymentModule, $offlinePayments)) {
            PrestaShopLogger::addLog(
                'RkPickup: Pedido por transferencia, esperando confirmación de pago',
                1, null, 'Order', $order->id
            );
            return; // Don't assign yet
        }
        
        // For online payments (Redsys, PayPal, etc.), assign locker but DON'T change order status yet
        // The payment module will set "Payment accepted" after this, then we change to "Ready for pickup"
        $this->assignLockerToOrder($order, false); // false = don't change PS order status
    }

    /**
     * Hook: When order status changes
     * Assign locker when payment is confirmed for offline payments
     */
    public function hookActionOrderStatusPostUpdate($params)
    {
        if (!Configuration::get('RKPICKUP_AUTO_ASSIGN')) {
            return;
        }

        $newOrderStatus = $params['newOrderStatus'];
        $orderId = $params['id_order'];
        
        // Payment accepted status (typically id 2 in PrestaShop)
        $paidStatuses = [2, 12]; // 2 = Payment accepted, 12 = Payment accepted remotely
        
        if (!in_array($newOrderStatus->id, $paidStatuses)) {
            return; // Not a payment confirmation
        }

        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            return;
        }

        // Check if this order already has an assignment with status 'ready'
        $existingAssignment = Db::getInstance()->getRow(
            'SELECT * FROM `'._DB_PREFIX_.'rkpickup_assignment` WHERE id_order = '.(int)$orderId
        );

        if ($existingAssignment) {
            // Order already has assignment - update PS order status to "Ready for pickup"
            // This happens for online payments (Redsys) where we assigned locker in actionValidateOrder
            // but didn't change PS status (waiting for Redsys to confirm payment first)
            if ($existingAssignment['status'] == 'ready') {
                PrestaShopLogger::addLog(
                    'RkPickup: Pago confirmado, actualizando estado a Listo para recoger',
                    1, null, 'Order', $order->id
                );
                $this->updateOrderStatusFromAssignment($orderId, 'ready');
            } elseif ($existingAssignment['status'] == 'waiting') {
                PrestaShopLogger::addLog(
                    'RkPickup: Pago confirmado, pedido en cola de espera',
                    1, null, 'Order', $order->id
                );
                $this->updateOrderStatusFromAssignment($orderId, 'waiting');
            }
            return;
        }

        // No assignment yet - this is an offline payment (bank transfer)
        $offlinePayments = ['ps_wirepayment', 'bankwire', 'cheque', 'ps_checkpayment'];
        
        if (in_array($order->module, $offlinePayments)) {
            PrestaShopLogger::addLog(
                'RkPickup: Pago offline confirmado, asignando taquilla',
                1, null, 'Order', $order->id
            );
            $this->assignLockerToOrder($order, true); // true = update PS order status
        }
    }

    /**
     * Assign an available locker to an order
     */
    /**
     * Assign an available locker to an order
     * @param Order $order
     * @param bool $updateOrderStatus - Whether to update PrestaShop order status (false for online payments, Redsys will trigger it)
     */
    public function assignLockerToOrder($order, $updateOrderStatus = true)
    {
        require_once dirname(__FILE__) . '/classes/TTLockAPI.php';
        
        // Find available locker (getRow adds LIMIT 1 automatically)
        $sql = 'SELECT * FROM `'._DB_PREFIX_.'rkpickup_locker` WHERE `status` = "available" AND `active` = 1 ORDER BY `id_locker` ASC';
        $locker = Db::getInstance()->getRow($sql);

        // If no available locker, check for lockers with expired_grace orders (can be reclaimed)
        if (!$locker) {
            $locker = $this->reclaimExpiredGraceLocker();
        }

        if (!$locker) {
            // No lockers available - put order in waiting queue
            PrestaShopLogger::addLog('RkPickup: No hay taquillas disponibles, pedido en cola de espera', 2, null, 'Order', $order->id);
            return $this->addToWaitingQueue($order, $updateOrderStatus);
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
        // Copy llavero_uid from locker if pre-loaded
        $llaveroUid = !empty($locker['llavero_uid']) ? pSQL($locker['llavero_uid']) : null;
        
        Db::getInstance()->insert('rkpickup_assignment', [
            'id_order' => (int) $order->id,
            'id_locker' => (int) $locker['id_locker'],
            'pin_code' => pSQL($passcodeResult['passcode']),
            'ttlock_passcode_id' => pSQL($passcodeResult['passcode_id']),
            'llavero_uid' => $llaveroUid,
            'status' => 'ready',
            'valid_from' => date('Y-m-d H:i:s', $validFrom / 1000),
            'valid_until' => date('Y-m-d H:i:s', $validUntil / 1000),
            'date_add' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
        ]);

        // Mark locker as occupied and clear pre-loaded llavero (now assigned to order)
        Db::getInstance()->update('rkpickup_locker', [
            'status' => 'occupied',
            'llavero_uid' => null,
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_locker = ' . (int) $locker['id_locker']);

        // Update order status to ready (only if not waiting for payment module to finish)
        if ($updateOrderStatus) {
            $this->updateOrderStatusFromAssignment($order->id, 'ready');
        }

        // Send email to customer
        if (Configuration::get('RKPICKUP_SEND_EMAIL')) {
            $this->sendPickupEmail($order, $locker, $passcodeResult['passcode']);
        }

        PrestaShopLogger::addLog(
            'RkPickup: Taquilla ' . $locker['name'] . ' asignada con PIN ' . $passcodeResult['passcode'],
            1, null, 'Order', $order->id
        );

        // Add to history
        $this->addHistory(
            'assigned',
            'Taquilla ' . $locker['name'] . ' asignada a pedido #' . $order->id . '.',
            $order->id,
            $locker['id_locker']
        );

        return true;
    }

    /**
     * Find and reclaim a locker from an expired_grace order
     * Moves the expired order back to waiting queue and returns the locker
     */
    protected function reclaimExpiredGraceLocker()
    {
        $prefix = _DB_PREFIX_;
        
        // Find a locker with an expired_grace assignment
        $sql = "SELECT l.*, a.id_assignment, a.id_order as expired_order_id, a.ttlock_passcode_id FROM {$prefix}rkpickup_locker l JOIN {$prefix}rkpickup_assignment a ON l.id_locker = a.id_locker WHERE a.status = 'expired_grace' AND l.active = 1 ORDER BY a.valid_until ASC LIMIT 1";
        $result = Db::getInstance()->getRow($sql);
        
        if (!$result) {
            return null;
        }
        
        $lockerId = (int) $result['id_locker'];
        $expiredOrderId = (int) $result['expired_order_id'];
        $expiredAssignmentId = (int) $result['id_assignment'];
        $passcodeId = $result['ttlock_passcode_id'];
        
        PrestaShopLogger::addLog("RkPickup: Reclamando taquilla {$result['name']} de pedido expirado #{$expiredOrderId}", 1, null, 'Order', $expiredOrderId);
        
        // Delete the PIN from TTLock
        if ($passcodeId) {
            require_once dirname(__FILE__) . '/classes/TTLockAPI.php';
            $api = new TTLockAPI(Configuration::get('RKPICKUP_TTLOCK_CLIENT_ID'), Configuration::get('RKPICKUP_TTLOCK_CLIENT_SECRET'));
            $authResult = $api->authenticate(Configuration::get('RKPICKUP_TTLOCK_USERNAME'), Configuration::get('RKPICKUP_TTLOCK_PASSWORD'));
            if ($authResult['success']) {
                $api->deletePasscode($result['lock_id'], $passcodeId);
            }
        }
        
        // Move expired order back to waiting queue
        Db::getInstance()->update('rkpickup_assignment', ['id_locker' => 0, 'pin_code' => '', 'ttlock_passcode_id' => '', 'status' => 'waiting', 'date_upd' => date('Y-m-d H:i:s'), 'warning_sent' => 0], 'id_assignment = ' . $expiredAssignmentId);
        
        // Update order status to waiting
        $this->updateOrderStatusFromAssignment($expiredOrderId, 'waiting');
        
        // Send requeued email
        $expiredOrder = new Order($expiredOrderId);
        if (Validate::isLoadedObject($expiredOrder) && Configuration::get('RKPICKUP_SEND_EMAIL')) {
            $customer = new Customer($expiredOrder->id_customer);
            $templateVars = ['{firstname}' => $customer->firstname, '{lastname}' => $customer->lastname, '{order_reference}' => $expiredOrder->reference, '{pickup_address}' => Configuration::get('RKPICKUP_PICKUP_ADDRESS')];
            Mail::Send((int) $expiredOrder->id_lang, 'pickup_expired_requeued', $this->l('Tu pedido ha vuelto a la cola de espera'), $templateVars, $customer->email, $customer->firstname . ' ' . $customer->lastname, null, null, null, null, dirname(__FILE__) . '/mails/', false, (int) $expiredOrder->id_shop);
        }
        
        // Mark locker as available (will be immediately occupied by new order)
        Db::getInstance()->update('rkpickup_locker', ['status' => 'available', 'date_upd' => date('Y-m-d H:i:s')], 'id_locker = ' . $lockerId);
        
        // Add to history
        $this->addHistory('reclaimed', "Taquilla {$result['name']} reclamada de pedido expirado #{$expiredOrderId} para nuevo pedido.", $expiredOrderId, $lockerId);
        
        // Return the locker data for the new assignment
        return Db::getInstance()->getRow("SELECT * FROM {$prefix}rkpickup_locker WHERE id_locker = {$lockerId}");
    }

    /**
     * Add order to waiting queue when no lockers available
     */
    protected function addToWaitingQueue($order, $updateOrderStatus = true)
    {
        // Create waiting assignment (no locker assigned yet)
        Db::getInstance()->insert('rkpickup_assignment', [
            'id_order' => (int) $order->id,
            'id_locker' => 0, // No locker yet
            'pin_code' => '',
            'ttlock_passcode_id' => '',
            'status' => 'waiting',
            'valid_from' => date('Y-m-d H:i:s'),
            'valid_until' => date('Y-m-d H:i:s'),
            'date_add' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
        ]);

        // Update order status to waiting (only if not waiting for payment module)
        if ($updateOrderStatus) {
            $this->updateOrderStatusFromAssignment($order->id, 'waiting');
        }

        // Send waiting email to customer
        if (Configuration::get('RKPICKUP_SEND_EMAIL')) {
            $this->sendWaitingEmail($order);
        }

        PrestaShopLogger::addLog(
            'RkPickup: Pedido añadido a cola de espera',
            1, null, 'Order', $order->id
        );

        // Add to history
        $this->addHistory(
            'waiting',
            'Pedido #' . $order->id . ' añadido a cola de espera (sin taquillas disponibles).',
            $order->id
        );

        return true;
    }

    /**
     * Send waiting queue email
     */
    protected function sendWaitingEmail($order)
    {
        $customer = new Customer($order->id_customer);
        $pickupAddress = Configuration::get('RKPICKUP_PICKUP_ADDRESS');

        $templateVars = [
            '{firstname}' => $customer->firstname,
            '{lastname}' => $customer->lastname,
            '{order_reference}' => $order->reference,
            '{pickup_address}' => $pickupAddress,
        ];

        Mail::Send(
            (int) $order->id_lang,
            'pickup_waiting',
            $this->l('Tu pedido está en cola de espera'),
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
     * Process waiting queue - assign oldest waiting order to available locker
     * Called when a locker becomes available
     */
    public function processWaitingQueue($idLocker = null)
    {
        require_once dirname(__FILE__) . '/classes/TTLockAPI.php';
        
        // Find oldest waiting order
        $sql = 'SELECT a.*, o.id_lang, o.id_shop FROM `'._DB_PREFIX_.'rkpickup_assignment` a JOIN `'._DB_PREFIX_.'orders` o ON a.id_order = o.id_order WHERE a.status = "waiting" ORDER BY a.date_add ASC';
        $waitingAssignment = Db::getInstance()->getRow($sql);

        if (!$waitingAssignment) {
            return false; // No waiting orders
        }

        // Find available locker
        if ($idLocker) {
            $sql = 'SELECT * FROM `'._DB_PREFIX_.'rkpickup_locker` WHERE `id_locker` = '.(int)$idLocker.' AND `status` = "available" AND `active` = 1';
        } else {
            $sql = 'SELECT * FROM `'._DB_PREFIX_.'rkpickup_locker` WHERE `status` = "available" AND `active` = 1 ORDER BY `id_locker` ASC';
        }
        $locker = Db::getInstance()->getRow($sql);

        if (!$locker) {
            return false; // No available locker
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
            PrestaShopLogger::addLog('RkPickup: Error autenticación TTLock al procesar cola', 3, null, 'Order', $waitingAssignment['id_order']);
            return false;
        }

        // Calculate validity period
        $validityHours = (int) Configuration::get('RKPICKUP_PIN_VALIDITY_HOURS');
        $validFrom = time() * 1000;
        $validUntil = ($validFrom + ($validityHours * 3600 * 1000));

        // Create passcode
        $passcodeResult = $api->createPasscode(
            $locker['lock_id'],
            $validFrom,
            $validUntil
        );

        if (!$passcodeResult['success']) {
            PrestaShopLogger::addLog('RkPickup: Error creando PIN desde cola - ' . $passcodeResult['error'], 3, null, 'Order', $waitingAssignment['id_order']);
            return false;
        }

        // Update assignment
        Db::getInstance()->update('rkpickup_assignment', [
            'id_locker' => (int) $locker['id_locker'],
            'pin_code' => pSQL($passcodeResult['passcode']),
            'ttlock_passcode_id' => pSQL($passcodeResult['passcode_id']),
            'status' => 'ready',
            'valid_from' => date('Y-m-d H:i:s', $validFrom / 1000),
            'valid_until' => date('Y-m-d H:i:s', $validUntil / 1000),
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_assignment = ' . (int) $waitingAssignment['id_assignment']);

        // Mark locker as occupied
        Db::getInstance()->update('rkpickup_locker', [
            'status' => 'occupied',
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_locker = ' . (int) $locker['id_locker']);

        // Update order status to ready
        $this->updateOrderStatusFromAssignment($waitingAssignment['id_order'], 'ready');

        // Send pickup ready email
        $order = new Order($waitingAssignment['id_order']);
        if (Validate::isLoadedObject($order) && Configuration::get('RKPICKUP_SEND_EMAIL')) {
            $this->sendPickupEmail($order, $locker, $passcodeResult['passcode']);
        }

        PrestaShopLogger::addLog(
            'RkPickup: Pedido de cola asignado a taquilla ' . $locker['name'] . ' con PIN ' . $passcodeResult['passcode'],
            1, null, 'Order', $waitingAssignment['id_order']
        );

        // Add to history
        $this->addHistory(
            'assigned_from_queue',
            'Taquilla ' . $locker['name'] . ' asignada a pedido #' . $waitingAssignment['id_order'] . ' desde cola de espera.',
            $waitingAssignment['id_order'],
            $locker['id_locker'],
            $waitingAssignment['id_assignment']
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
     * Add entry to operations history
     */
    public function addHistory($action, $description, $idOrder = null, $idLocker = null, $idAssignment = null)
    {
        return Db::getInstance()->insert('rkpickup_history', [
            'id_order' => $idOrder ? (int) $idOrder : null,
            'id_locker' => $idLocker ? (int) $idLocker : null,
            'id_assignment' => $idAssignment ? (int) $idAssignment : null,
            'action' => pSQL($action),
            'description' => pSQL($description),
            'date_add' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Update order status based on assignment status (bijective mapping)
     * Only for Click & Collect orders
     */
    public function updateOrderStatusFromAssignment($orderId, $assignmentStatus)
    {
        $statusMap = [
            'waiting' => Configuration::get('RKPICKUP_OS_WAITING'),
            'ready' => Configuration::get('RKPICKUP_OS_READY'),
            'expired_grace' => Configuration::get('RKPICKUP_OS_EXPIRED_GRACE'),
            'expired' => Configuration::get('RKPICKUP_OS_EXPIRED'),
            'picked_up' => Configuration::get('RKPICKUP_OS_PICKED_UP'),
        ];

        if (!isset($statusMap[$assignmentStatus]) || !$statusMap[$assignmentStatus]) {
            return false;
        }

        $newStatusId = (int) $statusMap[$assignmentStatus];
        $order = new Order($orderId);
        
        if (!Validate::isLoadedObject($order)) {
            return false;
        }

        // Don't update if already in that status
        if ($order->current_state == $newStatusId) {
            return true;
        }

        $history = new OrderHistory();
        $history->id_order = $orderId;
        $history->id_employee = 0;
        $history->changeIdOrderState($newStatusId, $orderId);
        $history->add();

        PrestaShopLogger::addLog(
            sprintf('RkPickup: Order #%d status changed to %s', $orderId, $assignmentStatus),
            1, null, 'Order', $orderId, true
        );

        return true;
    }

    /**
     * Process expired assignments (called by cron)
     */
    public function processExpirations()
    {
        require_once dirname(__FILE__) . '/classes/TTLockAPI.php';
        
        $now = date('Y-m-d H:i:s');
        $prefix = _DB_PREFIX_;

        // Find expired assignments that are still 'ready'
        $sql = "SELECT a.*, l.lock_id, o.id_lang 
                FROM {$prefix}rkpickup_assignment a 
                JOIN {$prefix}rkpickup_locker l ON a.id_locker = l.id_locker
                JOIN {$prefix}orders o ON a.id_order = o.id_order
                WHERE a.status = 'ready' 
                AND a.valid_until < '{$now}'";
        $expiredAssignments = Db::getInstance()->executeS($sql);

        if (!$expiredAssignments) {
            return ['processed' => 0];
        }

        // Check if there are waiting orders
        $waitingCount = (int) Db::getInstance()->getValue(
            "SELECT COUNT(*) FROM {$prefix}rkpickup_assignment WHERE status = 'waiting'"
        );

        $processed = 0;
        foreach ($expiredAssignments as $assignment) {
            if ($waitingCount > 0) {
                // There are waiting orders - cancel this one and reassign
                $this->expireAndReassign($assignment);
                $waitingCount--; // One waiting order will be assigned
            } else {
                // No waiting orders - grace period
                $this->setExpiredGrace($assignment);
            }
            $processed++;
        }

        return ['processed' => $processed, 'had_waiting' => $waitingCount > 0];
    }

    /**
     * Expire assignment and put order back in waiting queue
     * The locker is then assigned to the next waiting order
     */
    protected function expireAndReassign($assignment)
    {
        $api = new TTLockAPI(
            Configuration::get('RKPICKUP_TTLOCK_CLIENT_ID'),
            Configuration::get('RKPICKUP_TTLOCK_CLIENT_SECRET')
        );

        $api->authenticate(
            Configuration::get('RKPICKUP_TTLOCK_USERNAME'),
            Configuration::get('RKPICKUP_TTLOCK_PASSWORD')
        );

        // Delete the PIN
        if ($assignment['ttlock_passcode_id']) {
            $api->deletePasscode($assignment['lock_id'], $assignment['ttlock_passcode_id']);
        }

        // Put assignment back in waiting queue (not cancelled - customer already paid!)
        // IMPORTANT: Update date_add to NOW so this order goes to the END of the queue
        // (otherwise it would be reassigned immediately because it has the oldest date_add)
        Db::getInstance()->update('rkpickup_assignment', [
            'status' => 'waiting',
            'id_locker' => 0,
            'pin_code' => '',
            'ttlock_passcode_id' => '',
            'warning_sent' => 0,
            'date_add' => date('Y-m-d H:i:s'), // Reset to now - goes to end of queue
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_assignment = ' . (int) $assignment['id_assignment']);

        // Update order status to waiting
        $this->updateOrderStatusFromAssignment($assignment['id_order'], 'waiting');

        // Mark locker as available
        Db::getInstance()->update('rkpickup_locker', [
            'status' => 'available',
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_locker = ' . (int) $assignment['id_locker']);

        // Send requeued email (not cancelled!)
        $this->sendExpiredRequeuedEmail($assignment);

        // Add to history
        $this->addHistory(
            'expired',
            'Reserva expirada. Pedido #' . $assignment['id_order'] . ' vuelve a cola de espera.',
            $assignment['id_order'],
            $assignment['id_locker'],
            $assignment['id_assignment']
        );

        // Process waiting queue for this locker (will assign to oldest waiting, which might be someone else)
        $this->processWaitingQueue($assignment['id_locker']);

        PrestaShopLogger::addLog(
            'RkPickup: Assignment expired, order #' . $assignment['id_order'] . ' back in waiting queue',
            1, null, 'Order', $assignment['id_order'], true
        );
    }

    /**
     * Set assignment to grace period (expired but can still pickup)
     */
    protected function setExpiredGrace($assignment)
    {
        // Update assignment status but keep PIN active
        Db::getInstance()->update('rkpickup_assignment', [
            'status' => 'expired_grace',
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_assignment = ' . (int) $assignment['id_assignment']);

        // Update order status
        $this->updateOrderStatusFromAssignment($assignment['id_order'], 'expired_grace');

        // Send grace period email
        $this->sendExpiredGraceEmail($assignment);

        PrestaShopLogger::addLog(
            'RkPickup: Assignment set to grace period for order #' . $assignment['id_order'],
            1, null, 'Order', $assignment['id_order'], true
        );
    }

    /**
     * Send expired grace email
     */
    protected function sendExpiredGraceEmail($assignment)
    {
        $order = new Order($assignment['id_order']);
        $customer = new Customer($order->id_customer);
        
        $locker = Db::getInstance()->getRow(
            'SELECT * FROM '._DB_PREFIX_.'rkpickup_locker WHERE id_locker = '.(int)$assignment['id_locker']
        );

        $templateVars = [
            '{firstname}' => $customer->firstname,
            '{lastname}' => $customer->lastname,
            '{order_reference}' => $order->reference,
            '{locker_name}' => $locker['name'],
            '{pin_code}' => $assignment['pin_code'],
            '{pickup_address}' => Configuration::get('RKPICKUP_PICKUP_ADDRESS'),
        ];

        Mail::Send(
            (int) $order->id_lang,
            'pickup_expired_grace',
            $this->l('Aviso: Tu plazo de recogida ha expirado'),
            $templateVars,
            $customer->email,
            $customer->firstname . ' ' . $customer->lastname,
            null, null, null, null,
            dirname(__FILE__) . '/mails/',
            false,
            (int) $order->id_shop
        );
    }

    /**
     * Send expired requeued email (order goes back to waiting queue)
     */
    protected function sendExpiredRequeuedEmail($assignment)
    {
        $order = new Order($assignment['id_order']);
        $customer = new Customer($order->id_customer);

        $templateVars = [
            '{firstname}' => $customer->firstname,
            '{lastname}' => $customer->lastname,
            '{order_reference}' => $order->reference,
            '{pickup_address}' => Configuration::get('RKPICKUP_PICKUP_ADDRESS'),
        ];

        Mail::Send(
            (int) $order->id_lang,
            'pickup_expired_requeued',
            $this->l('Tu reserva de taquilla ha expirado - Vuelves a la cola'),
            $templateVars,
            $customer->email,
            $customer->firstname . ' ' . $customer->lastname,
            null, null, null, null,
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

    /**
     * Hook: Intercept emails before sending
     * Block standard order/payment emails for pickup orders
     */
    public function hookActionEmailSendBefore($params)
    {
        // Email templates to block for pickup orders
        $blockedTemplates = ['order_conf', 'payment'];
        
        $template = $params['template'] ?? '';
        
        // Check if this is a blocked template
        if (!in_array($template, $blockedTemplates)) {
            return true; // Allow email
        }

        // Try to get order ID from template vars
        $templateVars = $params['templateVars'] ?? [];
        $orderId = null;
        
        // PrestaShop sends {id_order} or we can extract from {order_name}
        if (isset($templateVars['{id_order}'])) {
            $orderId = (int) $templateVars['{id_order}'];
        } elseif (isset($templateVars['{order_name}'])) {
            // Get order by reference
            $reference = $templateVars['{order_name}'];
            $sql = 'SELECT id_order FROM `'._DB_PREFIX_.'orders` WHERE reference = "'.pSQL($reference).'"';
            $orderId = (int) Db::getInstance()->getValue($sql);
        }

        if (!$orderId) {
            return true; // Can't determine order, allow email
        }

        // Check if this order has a pickup assignment
        $sql = 'SELECT COUNT(*) FROM `'._DB_PREFIX_.'rkpickup_assignment` WHERE id_order = '.(int)$orderId;
        $hasPickup = (int) Db::getInstance()->getValue($sql) > 0;

        if ($hasPickup) {
            // Block this email - the module will send its own pickup email
            PrestaShopLogger::addLog(
                'RkPickup: Blocked "'.$template.'" email for pickup order #'.$orderId,
                1, null, 'Order', $orderId, true
            );
            return false; // Block email
        }

        return true; // Allow email for non-pickup orders
    }
}
