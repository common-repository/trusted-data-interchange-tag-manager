<?php
/**
 * Note : Code is released under the GNU LGPL
 *
 * Please do not change the header of this file
 *
 * This library is free software; you can redistribute it and/or modify it under the terms of the GNU
 * Lesser General Public License as published by the Free Software Foundation; either version 2 of
 * the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * See the GNU Lesser General Public License for more details.
 */

/**
 * Light PHP wrapper for the OAuth 2.0 protocol.
 *
 * This client is based on the OAuth2 specification draft v2.15
 * http://tools.ietf.org/html/draft-ietf-oauth-v2-15
 *
 * Modified for PHP5.2 support and WP HTTP APIs
 *
 * @author      Pierrick Charron <pierrick@webstart.fr>
 * @author      Anis Berejeb <anis.berejeb@gmail.com>
 * @author      Mezzobit team
 * @version     1.2-dev
 */
class Mezzobit_OAuth2_Client
{
    /**
     * Different AUTH method
     */
    const AUTH_TYPE_URI                 = 0;
    const AUTH_TYPE_AUTHORIZATION_BASIC = 1;
    const AUTH_TYPE_FORM                = 2;

    /**
     * Different Access token type
     */
    const ACCESS_TOKEN_URI      = 0;
    const ACCESS_TOKEN_BEARER   = 1;
    const ACCESS_TOKEN_OAUTH    = 2;
    const ACCESS_TOKEN_MAC      = 3;

    /**
    * Different Grant types
    */
    const GRANT_TYPE_AUTH_CODE          = 'authorization_code';
    const GRANT_TYPE_PASSWORD           = 'password';
    const GRANT_TYPE_CLIENT_CREDENTIALS = 'client_credentials';
    const GRANT_TYPE_REFRESH_TOKEN      = 'refresh_token';

    /**
     * HTTP Methods
     */
    const HTTP_METHOD_GET    = 'GET';
    const HTTP_METHOD_POST   = 'POST';
    const HTTP_METHOD_PUT    = 'PUT';
    const HTTP_METHOD_DELETE = 'DELETE';
    const HTTP_METHOD_HEAD   = 'HEAD';
    const HTTP_METHOD_PATCH   = 'PATCH';

    /**
     * HTTP Form content types
     */
    const HTTP_FORM_CONTENT_TYPE_APPLICATION = 0;
    const HTTP_FORM_CONTENT_TYPE_MULTIPART = 1;

    /**
     * Client ID
     *
     * @var string
     */
    protected $client_id = null;

    /**
     * Client Secret
     *
     * @var string
     */
    protected $client_secret = null;

    /**
     * Client Authentication method
     *
     * @var int
     */
    protected $client_auth = self::AUTH_TYPE_URI;

    /**
     * Access Token
     *
     * @var string
     */
    protected $access_token = null;

    /**
     * Access Token Type
     *
     * @var int
     */
    protected $access_token_type = self::ACCESS_TOKEN_URI;

    /**
     * Access Token Secret
     *
     * @var string
     */
    protected $access_token_secret = null;

    /**
     * Access Token crypt algorithm
     *
     * @var string
     */
    protected $access_token_algorithm = null;

    /**
     * Access Token Parameter name
     *
     * @var string
     */
    protected $access_token_param_name = 'access_token';

    /**
     * The path to the certificate file to use for https connections
     *
     * @var string  Defaults to .
     */
    protected $certificate_file = null;

    /**
     * Construct
     *
     * @param string $client_id Client ID
     * @param string $client_secret Client Secret
     * @param int    $client_auth (AUTH_TYPE_URI, AUTH_TYPE_AUTHORIZATION_BASIC, AUTH_TYPE_FORM)
     * @param string $certificate_file Indicates if we want to use a certificate file to trust the server. Optional, defaults to null.
     * @return void
     */
    public function __construct($client_id, $client_secret, $client_auth = self::AUTH_TYPE_URI, $certificate_file = null)
    {
        $this->client_id     = $client_id;
        $this->client_secret = $client_secret;
        $this->client_auth   = $client_auth;
        $this->certificate_file = $certificate_file;
        if (!empty($this->certificate_file)  && !is_file($this->certificate_file)) {
            throw new Mezzobit_OAuth2_InvalidArgumentException('The certificate file was not found', Mezzobit_OAuth2_InvalidArgumentException::CERTIFICATE_NOT_FOUND);
        }
    }

    /**
     * Get the client Id
     *
     * @return string Client ID
     */
    public function getClientId()
    {
        return $this->client_id;
    }

    /**
     * Get the client Secret
     *
     * @return string Client Secret
     */
    public function getClientSecret()
    {
        return $this->client_secret;
    }

    /**
     * getAuthenticationUrl
     *
     * @param string $auth_endpoint Url of the authentication endpoint
     * @param string $redirect_uri  Redirection URI
     * @param array  $extra_parameters  Array of extra parameters like scope or state (Ex: array('scope' => null, 'state' => ''))
     * @return string URL used for authentication
     */
    public function getAuthenticationUrl($auth_endpoint, $redirect_uri, $extra_parameters = array())
    {
        $parameters = array_merge(array(
            'response_type' => 'code',
            'client_id'     => $this->client_id,
            'redirect_uri'  => $redirect_uri
        ), $extra_parameters);
        return $auth_endpoint . '?' . http_build_query($parameters, null, '&');
    }

    /**
     * getAccessToken
     *
     * @param string $token_endpoint    Url of the token endpoint
     * @param int    $grant_type        Grant Type ('authorization_code', 'password', 'client_credentials', 'refresh_token', or a custom code (@see GrantType Classes)
     * @param array  $parameters        Array sent to the server (depend on which grant type you're using)
     * @return array Array of parameters required by the grant_type (CF SPEC)
     */
    public function getAccessToken($token_endpoint, $grant_type, $parameters)
    {
        if (!$grant_type) {
            throw new Mezzobit_OAuth2_InvalidArgumentException('The grant_type is mandatory.', OAuth2_InvalidArgumentException::INVALID_GRANT_TYPE);
        }
        $grantTypeClassName = $this->convertToCamelCase($grant_type);
        include_once 'GrantType/' . $grantTypeClassName . '.php';
        $grantTypeClass = 'Mezzobit_OAuth2_GrantType_' . $grantTypeClassName;
        if (!class_exists($grantTypeClass)) {
            throw new Mezzobit_OAuth2_InvalidArgumentException('Unknown grant type \'' . $grant_type . '\'', Mezzobit_OAuth2_InvalidArgumentException::INVALID_GRANT_TYPE);
        }
        $grantTypeObject = new $grantTypeClass();
        $grantTypeObject->validateParameters($parameters);
        if (!defined($grantTypeClass . '::GRANT_TYPE')) {
            throw new Mezzobit_OAuth2_Exception('Unknown constant GRANT_TYPE for class ' . $grantTypeClassName, Mezzobit_OAuth2_Exception::GRANT_TYPE_ERROR);
        }
        $ref = new ReflectionObject($grantTypeObject);
        $parameters['grant_type'] = $ref->getConstant('GRANT_TYPE');
        $http_headers = array();
        switch ($this->client_auth) {
            case self::AUTH_TYPE_URI:
            case self::AUTH_TYPE_FORM:
                $parameters['client_id'] = $this->client_id;
                $parameters['client_secret'] = $this->client_secret;
                break;
            case self::AUTH_TYPE_AUTHORIZATION_BASIC:
                $parameters['client_id'] = $this->client_id;
                $http_headers['Authorization'] = 'Basic ' . base64_encode($this->client_id .  ':' . $this->client_secret);
                break;
            default:
                throw new Mezzobit_OAuth2_Exception('Unknown client auth type.', Mezzobit_OAuth2_Exception::INVALID_CLIENT_AUTHENTICATION_TYPE);
                break;
        }

        return $this->executeRequest($token_endpoint, $parameters, self::HTTP_METHOD_POST, $http_headers, self::HTTP_FORM_CONTENT_TYPE_APPLICATION);
    }

    /**
     * setToken
     *
     * @param string $token Set the access token
     * @return void
     */
    public function setAccessToken($token)
    {
        $this->access_token = $token;
    }

    /**
     * Set the client authentication type
     *
     * @param string $client_auth (AUTH_TYPE_URI, AUTH_TYPE_AUTHORIZATION_BASIC, AUTH_TYPE_FORM)
     * @return void
     */
    public function setClientAuthType($client_auth)
    {
        $this->client_auth = $client_auth;
    }

    /**
     * Set the access token type
     *
     * @param int $type Access token type (ACCESS_TOKEN_BEARER, ACCESS_TOKEN_MAC, ACCESS_TOKEN_URI)
     * @param string $secret The secret key used to encrypt the MAC header
     * @param string $algorithm Algorithm used to encrypt the signature
     * @return void
     */
    public function setAccessTokenType($type, $secret = null, $algorithm = null)
    {
        $this->access_token_type = $type;
        $this->access_token_secret = $secret;
        $this->access_token_algorithm = $algorithm;
    }

    /**
     * Fetch a protected ressource
     *
     * @param string $protected_ressource_url Protected resource URL
     * @param array  $parameters Array of parameters
     * @param string $http_method HTTP Method to use (POST, PUT, GET, HEAD, DELETE)
     * @param array  $http_headers HTTP headers
     * @param int    $form_content_type HTTP form content type to use
     * @return array
     */
    public function fetch($protected_resource_url, $parameters = array(), $http_method = self::HTTP_METHOD_GET, $http_headers = array(), $form_content_type = self::HTTP_FORM_CONTENT_TYPE_MULTIPART)
    {
        if ($this->access_token) {
            switch ($this->access_token_type) {
                case self::ACCESS_TOKEN_URI:
                    if (is_array($parameters)) {
                        $parameters[$this->access_token_param_name] = $this->access_token;
                    } else {
                        throw new Mezzobit_OAuth2_InvalidArgumentException(
                            'You need to give parameters as array if you want to give the token within the URI.',
                            Mezzobit_OAuth2_InvalidArgumentException::REQUIRE_PARAMS_AS_ARRAY
                        );
                    }
                    break;
                case self::ACCESS_TOKEN_BEARER:
                    $http_headers['Authorization'] = 'Bearer ' . $this->access_token;
                    break;
                case self::ACCESS_TOKEN_OAUTH:
                    $http_headers['Authorization'] = 'OAuth ' . $this->access_token;
                    break;
                case self::ACCESS_TOKEN_MAC:
                    $http_headers['Authorization'] = 'MAC ' . $this->generateMACSignature($protected_resource_url, $parameters, $http_method);
                    break;
                default:
                    throw new Mezzobit_OAuth2_Exception('Unknown access token type.', Mezzobit_OAuth2_Exception::INVALID_ACCESS_TOKEN_TYPE);
                    break;
            }
        }
        return $this->executeRequest($protected_resource_url, $parameters, $http_method, $http_headers, $form_content_type);
    }

    /**
     * Generate the MAC signature
     *
     * @param string $url Called URL
     * @param array  $parameters Parameters
     * @param string $http_method Http Method
     * @return string
     */
    private function generateMACSignature($url, $parameters, $http_method)
    {
        $timestamp = time();
        $nonce = uniqid();
        $parsed_url = parse_url($url);
        if (!isset($parsed_url['port']))
        {
            $parsed_url['port'] = ($parsed_url['scheme'] == 'https') ? 443 : 80;
        }
        if ($http_method == self::HTTP_METHOD_GET) {
            if (is_array($parameters)) {
                $parsed_url['path'] .= '?' . http_build_query($parameters, null, '&');
            } elseif ($parameters) {
                $parsed_url['path'] .= '?' . $parameters;
            }
        }

        $signature = base64_encode(hash_hmac($this->access_token_algorithm,
                    $timestamp . "\n"
                    . $nonce . "\n"
                    . $http_method . "\n"
                    . $parsed_url['path'] . "\n"
                    . $parsed_url['host'] . "\n"
                    . $parsed_url['port'] . "\n\n"
                    , $this->access_token_secret, true));

        return 'id="' . $this->access_token . '", ts="' . $timestamp . '", nonce="' . $nonce . '", mac="' . $signature . '"';
    }

    /**
     * Execute a request (with wp_remote_request)
     *
     * @param string $url URL
     * @param mixed  $parameters Array of parameters
     * @param string $http_method HTTP Method
     * @param array  $http_headers HTTP Headers
     * @param int    $form_content_type HTTP form content type to use
     * @return array
     */
    private function executeRequest($url, $parameters = array(), $http_method = self::HTTP_METHOD_GET, $http_headers = null, $form_content_type = self::HTTP_FORM_CONTENT_TYPE_MULTIPART)
    {
        $reqOptions = array(
            'method' => $http_method,
            'sslverify' => false
        );

        switch ($http_method) {
            case self::HTTP_METHOD_POST:
            case self::HTTP_METHOD_PUT:
            case self::HTTP_METHOD_PATCH:
                $reqOptions['body'] = $parameters;
                break;
            case self::HTTP_METHOD_DELETE:
            case self::HTTP_METHOD_GET:
                if (is_array($parameters)) {
                    $url .= '?' . http_build_query($parameters, null, '&');
                } elseif ($parameters) {
                    $url .= '?' . $parameters;
                }
                break;
        }

        if (is_array($http_headers)) {
            $reqOptions['headers'] = $http_headers;
        }

        $response = wp_remote_request($url, $reqOptions);
        if (is_wp_error($response)) {
          throw new Mezzobit_OAuth2_Exception($response->get_error_message(), Mezzobit_OAuth2_Exception::HTTP_ERROR);
        }
        $http_code = $response['response']['code'];
        $content_type = isset($response['headers']['content-type']) ? $response['headers']['content-type'] : null;
        $hasBody = isset($response['body']);
        $json_decode = $hasBody ? json_decode($response['body'], true) : null;

        return array(
            'result' => (null === $json_decode) ? ($hasBody ? $response['body'] : null) : $json_decode,
            'code' => $http_code,
            'content_type' => $content_type
        );
    }

    /**
     * Set the name of the parameter that carry the access token
     *
     * @param string $name Token parameter name
     * @return void
     */
    public function setAccessTokenParamName($name)
    {
        $this->access_token_param_name = $name;
    }

    /**
     * Converts the class name to camel case
     *
     * @param  mixed  $grant_type  the grant type
     * @return string
     */
    private function convertToCamelCase($grant_type)
    {
        $parts = explode('_', $grant_type);
        array_walk($parts, create_function('&$item', '$item = ucfirst($item);'));
        return implode('', $parts);
    }
}

class Mezzobit_OAuth2_Exception extends Exception
{
    const HTTP_ERROR                         = 0x02;
    const GRANT_TYPE_ERROR                   = 0x03;
    const INVALID_CLIENT_AUTHENTICATION_TYPE = 0x04;
    const INVALID_ACCESS_TOKEN_TYPE          = 0x05;
}

class Mezzobit_OAuth2_InvalidArgumentException extends InvalidArgumentException
{
    const INVALID_GRANT_TYPE      = 0x01;
    const CERTIFICATE_NOT_FOUND   = 0x02;
    const REQUIRE_PARAMS_AS_ARRAY = 0x03;
    const MISSING_PARAMETER       = 0x04;
}