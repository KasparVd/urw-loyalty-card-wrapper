<?php
/**
 * Class for using URW's loyalty card API
 *
 * PHP version 7.3
 *
 * @author     Kaspar van Dalfsen <kasparvdalfsen@gmail.com>
 * @see
 */

namespace urw\loyalty;

use \Datetime;

class loyalty
{

    /**
     * Array varibles to access API
     *
     * @var array
     */
    protected $api = '';

    /**
     * Constructor
     *
     * @param array $api varibles
     * @param bool $debug
     */
    public function __construct($api, $debug = false)
    {
        $this->apikey       = $api['key'];
        $this->user         = $api['user'];
        $this->password     = $api['password'];
        $this->host         = $api['host'];
        $this->tokenFile    = $api['tokenFile'];
        $this->token        = $this->getToken($this->tokenFile);
        $this->log          = "api.log";
        if ($debug === true) {
            $this->debug    = true;
        }
    }

    /**
     * Get API token from file
     *
     * @param string        $file   The file name for the file used to store the API token to.
     *
     * @return string|bool  $token  The token used for API authentication. Returns false if no valid json string can be found.
     */
    private function getToken($file)
    {
        $token      = false;
        $content    = file_get_contents($file);
        if ($arr = json_decode($content, true)) {
            $token = $arr['token'];
        }
        return $token;
    }

    /**
     * Store the API token to a file
     *
     * @param string    $file   The file name for the file used to store the API token to.
     * @param string    $token  The token used for API authentication.
     */
    private function saveToken($file, $token)
    {
        $arr = array("token" => $token);
        if (!file_put_contents($file, json_encode($arr))) {
            //trow error?
            //notify me if this happens
            file_put_contents($this->log, date('Y-m-d H:i:s')." saveToken exception\n", FILE_APPEND | LOCK_EX);
        }
    }
    
    /**
     * Validates the API token.
     * Creates a new token if the current token has expired or has a invalid format.
     *
     * @param string    $token  The token used for API authentication.
     *
     * @return string   $token  A valid API token.
     */
    private function validateToken($token)
    {
        if (substr_count($token, '.') !== 2) {
            /* This should not happen. If is does, log it. */
            $token = $this->requestToken();
            $this->saveToken($this->tokenFile, $token);

            file_put_contents($this->log, date('Y-m-d H:i:s')." validateToken exception\n", FILE_APPEND | LOCK_EX);
        } else {
            $decode = explode(".", $token);
            $json = json_decode(base64_decode($decode[1]), true);

            if ($json['exp'] < time()) {
                $token = $this->requestToken();
                $this->saveToken($this->tokenFile, $token);
            }
        }
        return $token;
    }

    /**
     * Request a fresh API token.
     * Creates a new token if the current token has expired or has a invalid format.
     *
     * @return string   $token  A new API token used for API authentication.
     */
    private function requestToken()
    {
        $token = false;
        $auth['username'] = $this->user;
        $auth['password'] = $this->password;

        $response = $this->callAPI($this->host, 'login', 'POST', json_encode($auth), $this->apikey);
        if ($arr = json_decode($response['responseData'], true)) {
            $token = $arr['token'];
        } else {
            /* This should not happen. If is does, log it. */
            file_put_contents($this->log, date('Y-m-d H:i:s')." requestToken exception\n", FILE_APPEND | LOCK_EX);
        }
        return $token;
    }

    /**
     * Call to the URW API
     *
     * @param string        $host       The API URL.
     * @param string        $endpoint   The API endpoint.
     * @param string        $method     The method used for the call. GET, POST, PUT or DELETE.
     * @param string        $data       The data payload for the API.
     * @param string        $apikey     The API key for authentication.
     * @param string|bool   $token      The API token for authentication. Defaults to false when no token is provided.
     *
     * @return array   $arr  The API response in a array.
     */
    private function callAPI($host, $endpoint, $method, $data, $apikey, $token = false)
    {
        $headers = [
            'Cache-Control: no-cache',
            'Content-Type: application/json',
            'x-api-key: '. $apikey,
            'Authorization: '. $token
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $host.$endpoint);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($data !== null && $method !== "GET") {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response   = curl_exec($ch);
        $headers    = curl_getinfo($ch);
        curl_close($ch);

        if ($this->debug === true) {
            print_r($headers['request_header']);
        }
        $arr['statusCode']     = $headers['http_code'];
        $arr['responseData']   = $response;

        return $arr;
    }

    /**
     * Adds loyalty card user
     *
     * @param array     $data       Data of the customer.
     *
     * @return array    $return     The API response in a array.
     */
    public function addCustomer($data)
    {
        $this->token = $this->validateToken($this->token);

        $response = $this->callAPI($this->host, 'partner/customers/full', 'POST', json_encode($data), $this->apikey, $this->token);

        if (!$arr = json_decode($response['responseData'], true)) {
            /* This should not happen. If is does, log it. */
            file_put_contents($this->log, date('Y-m-d H:i:s')." addCustomer json exception\n", FILE_APPEND | LOCK_EX);
        }

        if ($response['statusCode'] === 200) {
            $return['status']              = "success";
   
            $return['message']['barcode']  = $arr['barcode'];
            $return['message']['email']    = $arr['email'];
            $return['message']['firstName']= $arr['firstName'];
        } else {
            $return['statusCode']   = $response['statusCode'];
            $return['status']       = "error";
            $return['message']      = $arr['error']['details']['message'];

            $exists = substr($arr['error']['details']['message'], 0, 23);
            if (strcasecmp("Customer already exists", $exists) == 0) {
                $return['status']   = "warning";
                $return['message']  = "Dit e-mailadres is al bij ons geregistreerd. Ben je je inloggegevens vergeten? Vraag een nieuw wachtwoord aan via de smartphone app.";
            } else {
                /* This should not happen. If is does, log it. */
                $age = DateTime::createFromFormat('Y-m-d', $data['birthDate'])->diff(new DateTime('now'))->y;

                if (!$arr['error']['details']['message']) {
                    $exception = "birthDate(" . $age .")";
                } else {
                    $exception = $arr['error']['details']['message'];
                }
                file_put_contents($this->log, date('Y-m-d H:i:s')." addCustomer exists exception: ".$exception. "\n", FILE_APPEND | LOCK_EX);
            }
        }

        if ($this->debug === true) {
            $return['ApiResponse'] = $response;
            $return['token'] = $this->token;
        }

        return $return;
    }
}
