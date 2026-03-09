<?php
/**
 * TTLock API Client
 * 
 * Handles communication with TTLock Open Platform API
 * API Docs: https://euopen.ttlock.com/doc/api
 */

class TTLockAPI
{
    const API_BASE_URL = 'https://euapi.ttlock.com';
    
    protected $clientId;
    protected $clientSecret;
    protected $accessToken;
    protected $refreshToken;
    protected $tokenExpires;

    public function __construct($clientId, $clientSecret)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        
        // Load tokens from config if available
        $this->accessToken = Configuration::get('RKPICKUP_TTLOCK_ACCESS_TOKEN');
        $this->refreshToken = Configuration::get('RKPICKUP_TTLOCK_REFRESH_TOKEN');
        $this->tokenExpires = Configuration::get('RKPICKUP_TTLOCK_TOKEN_EXPIRES');
    }

    /**
     * Authenticate with TTLock API
     */
    public function authenticate($username, $password)
    {
        $endpoint = '/oauth2/token';
        
        $params = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'username' => $username,
            'password' => md5($password), // TTLock requires MD5 hashed password
        ];

        $response = $this->request($endpoint, $params, false);

        if (isset($response['access_token'])) {
            $this->accessToken = $response['access_token'];
            $this->refreshToken = $response['refresh_token'];
            $this->tokenExpires = time() + $response['expires_in'];
            
            // Save tokens
            Configuration::updateValue('RKPICKUP_TTLOCK_ACCESS_TOKEN', $this->accessToken);
            Configuration::updateValue('RKPICKUP_TTLOCK_REFRESH_TOKEN', $this->refreshToken);
            Configuration::updateValue('RKPICKUP_TTLOCK_TOKEN_EXPIRES', $this->tokenExpires);
            
            return ['success' => true];
        }

        return [
            'success' => false,
            'error' => isset($response['errmsg']) ? $response['errmsg'] : 'Authentication failed',
        ];
    }

    /**
     * Refresh access token
     */
    public function refreshAccessToken()
    {
        $endpoint = '/oauth2/token';
        
        $params = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->refreshToken,
        ];

        $response = $this->request($endpoint, $params, false);

        if (isset($response['access_token'])) {
            $this->accessToken = $response['access_token'];
            $this->refreshToken = $response['refresh_token'];
            $this->tokenExpires = time() + $response['expires_in'];
            
            Configuration::updateValue('RKPICKUP_TTLOCK_ACCESS_TOKEN', $this->accessToken);
            Configuration::updateValue('RKPICKUP_TTLOCK_REFRESH_TOKEN', $this->refreshToken);
            Configuration::updateValue('RKPICKUP_TTLOCK_TOKEN_EXPIRES', $this->tokenExpires);
            
            return true;
        }

        return false;
    }

    /**
     * Get list of locks
     */
    public function getLocks($pageNo = 1, $pageSize = 100)
    {
        $this->ensureValidToken();
        
        $endpoint = '/v3/lock/list';
        
        $params = [
            'clientId' => $this->clientId,
            'accessToken' => $this->accessToken,
            'pageNo' => $pageNo,
            'pageSize' => $pageSize,
            'date' => $this->getTimestamp(),
        ];

        return $this->request($endpoint, $params);
    }

    /**
     * Create a custom passcode for a lock
     */
    public function createPasscode($lockId, $startDate, $endDate, $passcode = null)
    {
        $this->ensureValidToken();
        
        $endpoint = '/v3/keyboardPwd/add';
        
        // Generate random 6-digit code if not provided
        if (!$passcode) {
            $passcode = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
        }

        $params = [
            'clientId' => $this->clientId,
            'accessToken' => $this->accessToken,
            'lockId' => $lockId,
            'keyboardPwd' => $passcode,
            'keyboardPwdName' => 'Order-' . time(),
            'startDate' => $startDate,
            'endDate' => $endDate,
            'addType' => 2, // 2 = Custom
            'date' => $this->getTimestamp(),
        ];

        $response = $this->request($endpoint, $params);

        if (isset($response['keyboardPwdId'])) {
            return [
                'success' => true,
                'passcode' => $passcode,
                'passcode_id' => $response['keyboardPwdId'],
            ];
        }

        return [
            'success' => false,
            'error' => isset($response['errmsg']) ? $response['errmsg'] : 'Failed to create passcode',
        ];
    }

    /**
     * Delete a passcode
     */
    public function deletePasscode($lockId, $passcodeId)
    {
        $this->ensureValidToken();
        
        $endpoint = '/v3/keyboardPwd/delete';
        
        $params = [
            'clientId' => $this->clientId,
            'accessToken' => $this->accessToken,
            'lockId' => $lockId,
            'keyboardPwdId' => $passcodeId,
            'deleteType' => 2, // 2 = Delete from lock via gateway
            'date' => $this->getTimestamp(),
        ];

        $response = $this->request($endpoint, $params);

        if (isset($response['errcode']) && $response['errcode'] == 0) {
            return ['success' => true];
        }
        
        return [
            'success' => false,
            'error' => isset($response['errmsg']) ? $response['errmsg'] : 'Failed to delete passcode',
        ];
    }

    /**
     * Get lock details
     */
    public function getLockDetails($lockId)
    {
        $this->ensureValidToken();
        
        $endpoint = '/v3/lock/detail';
        
        $params = [
            'clientId' => $this->clientId,
            'accessToken' => $this->accessToken,
            'lockId' => $lockId,
            'date' => $this->getTimestamp(),
        ];

        return $this->request($endpoint, $params);
    }

    /**
     * Get passcode list for a lock
     */
    public function getPasscodes($lockId, $pageNo = 1, $pageSize = 100)
    {
        $this->ensureValidToken();
        
        $endpoint = '/v3/lock/listKeyboardPwd';
        
        $params = [
            'clientId' => $this->clientId,
            'accessToken' => $this->accessToken,
            'lockId' => $lockId,
            'pageNo' => $pageNo,
            'pageSize' => $pageSize,
            'date' => $this->getTimestamp(),
        ];

        return $this->request($endpoint, $params);
    }

    /**
     * Ensure we have a valid access token
     */
    protected function ensureValidToken()
    {
        if ($this->tokenExpires && time() >= ($this->tokenExpires - 300)) {
            // Token expires in less than 5 minutes, refresh it
            $this->refreshAccessToken();
        }
    }

    /**
     * Get current timestamp in milliseconds
     */
    protected function getTimestamp()
    {
        return round(microtime(true) * 1000);
    }

    /**
     * Make HTTP request to TTLock API
     */
    protected function request($endpoint, $params, $requireAuth = true)
    {
        $url = self::API_BASE_URL . $endpoint;
        
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        curl_close($ch);

        if ($error) {
            PrestaShopLogger::addLog('TTLock API Error: ' . $error, 3);
            return ['success' => false, 'error' => $error];
        }

        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            PrestaShopLogger::addLog('TTLock API Invalid JSON: ' . $response, 3);
            return ['success' => false, 'error' => 'Invalid JSON response'];
        }

        return $data;
    }
}
