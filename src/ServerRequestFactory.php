<?php
namespace Phly\Http;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\MessageInterface;
use stdClass;

/**
 * Class for marshaling a request object from the current PHP environment.
 *
 * Logic largely refactored from the ZF2 Zend\Http\PhpEnvironment\Request class.
 *
 * @copyright Copyright (c) 2005-2014 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */
abstract class ServerRequestFactory
{
    /**
     * Create a request from the supplied superglobal values.
     *
     * If any argument is not supplied, the corresponding superglobal value will
     * be used.
     *
     * The ServerRequest created is then passed to the fromServer() method in
     * order to marshal the request URI and headers.
     *
     * @see fromServer()
     * @param array $server $_SERVER superglobal
     * @param array $query $_GET superglobal
     * @param array $body $_POST superglobal
     * @param array $cookies $_COOKIE superglobal
     * @param array $files $_FILES superglobal
     * @return ServerRequest
     */
    public static function fromGlobals(
        array $server = null,
        array $query = null,
        array $body = null,
        array $cookies = null,
        array $files = null
    ) {
        $server  = self::normalizeServer($server ?: $_SERVER);
        $files   = $files   ?: $_FILES;
        $request = new ServerRequest(
            'php://input',
            $server,
            $files
        );

        $request->setMethod(self::get('REQUEST_METHOD', $server, 'GET'));
        $request->setAbsoluteUri((string) self::marshalUriFromServer($server, $headers));
        $request->setHeaders(self::marshalHeaders($server));
        $request->setCookieParams($cookies ?: $_COOKIE);
        $request->setQueryParams($query ?: $_GET);
        $request->setBodyParams($body ?: $_POST);

        return $request;
    }

    /**
     * Populates a request object from the given $_SERVER array
     *
     * @param array $server
     * @param ServerRequestInterface $request
     * @return void
     * @deprecated as of 0.7.0. Use fromGlobals().
     * @throws Exception\DeprecatedMethodException on all requests.
     */
    public static function fromServer(array $server, ServerRequestInterface $request = null)
    {
        throw new Exception\DeprecatedMethodException(sprintf(
            '%s is deprecated as of phly/http 0.7.0dev; always use fromGlobals()',
            __METHOD__
        ));
    }

    /**
     * Access a value in an array, returning a default value if not found
     *
     * Will also do a case-insensitive search if a case sensitive search fails.
     *
     * @param string $key
     * @param array $values
     * @param mixed $default
     * @return mixed
     */
    public static function get($key, array $values, $default = null)
    {
        if (array_key_exists($key, $values)) {
            return $values[$key];
        }

        return $default;
    }

    /**
     * Search for a header value.
     *
     * Does a case-insensitive search for a matching header.
     *
     * If found, it is returned as a string, using comma concatenation.
     *
     * If not, the $default is returned.
     *
     * @param string $header
     * @param array $headers
     * @param mixed $default
     * @return string
     */
    public static function getHeader($header, array $headers, $default = null)
    {
        $header  = strtolower($header);
        $headers = array_change_key_case($headers, CASE_LOWER);
        if (array_key_exists($header, $headers)) {
            $value = is_array($headers[$header]) ? implode(', ', $headers[$header]) : $headers[$header];
            return $value;
        }

        return $default;
    }

    /**
     * Marshal the $_SERVER array
     *
     * Pre-processes and returns the $_SERVER superglobal.
     *
     * @return array
     */
    public static function normalizeServer(array $server)
    {
        // This seems to be the only way to get the Authorization header on Apache
        if (isset($server['HTTP_AUTHORIZATION'])
            || ! function_exists('apache_request_headers')
        ) {
            return $server;
        }

        $apacheRequestHeaders = apache_request_headers();
        if (isset($apacheRequestHeaders['Authorization'])) {
            $server['HTTP_AUTHORIZATION'] = $apacheRequestHeaders['Authorization'];
            return $server;
        }

        if (isset($apacheRequestHeaders['authorization'])) {
            $server['HTTP_AUTHORIZATION'] = $apacheRequestHeaders['authorization'];
            return $server;
        }

        return $server;
    }

    /**
     * Marshal headers from $_SERVER
     *
     * @param array $server
     * @return array
     */
    public static function marshalHeaders(array $server)
    {
        $headers = array();
        foreach ($server as $key => $value) {
            if (strpos($key, 'HTTP_COOKIE') === 0) {
                // Cookies are handled using the $_COOKIE superglobal
                continue;
            }

            if ($value && strpos($key, 'HTTP_') === 0) {
                $name = strtr(substr($key, 5), '_', ' ');
                $name = strtr(ucwords(strtolower($name)), ' ', '-');
                $name = strtolower($name);

                $headers[$name] = $value;
                continue;
            }

            if ($value && strpos($key, 'CONTENT_') === 0) {
                $name = substr($key, 8); // Content-
                $name = 'Content-' . (($name == 'MD5') ? $name : ucfirst(strtolower($name)));
                $name = strtolower($name);
                $headers[$name] = $value;
                continue;
            }
        }

        return $headers;
    }

    /**
     * Marshal the URI from the $_SERVER array and headers
     *
     * @param array $server
     * @param MessageInterface $request
     * @return Uri
     * @deprecated as of 0.7.0; use marshalUriFromServer() instead.
     */
    public static function marshalUri(array $server, MessageInterface $request)
    {
        return self::marshalUriFromServer($server, $request->getHeaders());
    }

    /**
     * Marshal the URI from the $_SERVER array and headers
     *
     * @param array $server
     * @param array $headers
     * @return Uri
     */
    public static function marshalUriFromServer(array $server, array $headers)
    {
        // URI scheme
        $scheme = 'http';
        $https  = self::get('HTTPS', $server);
        if (($https && 'off' !== $https)
            || self::getHeader('x-forwarded-proto', $headers, false) === 'https'
        ) {
            $scheme = 'https';
        }

        // Set the host
        $accumulator = (object) ['host' => '', 'port' => null];
        self::marshalHostAndPortFromHeaders($accumulator, $server, $headers);
        $host = $accumulator->host;
        $port = $accumulator->port;

        // URI path
        $path = self::marshalRequestUri($server);
        $path = self::stripQueryString($path);

        // URI query
        $query = null;
        if (isset($server['QUERY_STRING'])) {
            $query = ltrim($server['QUERY_STRING'], '?');
        }

        return Uri::fromArray(compact(
            'scheme',
            'host',
            'port',
            'path',
            'query'
        ));
    }

    /**
     * Marshal the host and port from HTTP headers and/or the PHP environment
     *
     * @param array $server
     * @param MessageInterface $request
     * @return array Array with two members, host and port, at indices 0 and 1, respectively
     * @deprecated as of 0.7.0; use marshalHostAndPortFromHeaders() instead.
     */
    public static function marshalHostAndPort(stdClass $accumulator, array $server, MessageInterface $request)
    {
        return self::marshalHostAndPortFromHeaders($accumulator, $server, $request->getHeaders());
    }

    /**
     * Marshal the host and port from HTTP headers and/or the PHP environment
     *
     * @param array $server
     * @param array $headers
     * @return array Array with two members, host and port, at indices 0 and 1, respectively
     */
    public static function marshalHostAndPortFromHeaders(stdClass $accumulator, array $server, array $headers)
    {
        if (self::getHeader('host', $headers, false)) {
            return self::marshalHostAndPortFromHeader($accumulator, self::getHeader('host', $headers));
        }

        if (! isset($server['SERVER_NAME'])) {
            return;
        }

        $accumulator->host = $server['SERVER_NAME'];
        if (isset($server['SERVER_PORT'])) {
            $accumulator->port = (int) $server['SERVER_PORT'];
        }

        if (! isset($server['SERVER_ADDR']) || ! preg_match('/^\[[0-9a-fA-F\:]+\]$/', $accumulator->host)) {
            return;
        }

        // Misinterpreted IPv6-Address
        // Reported for Safari on Windows
        self::marshalIpv6HostAndPort($accumulator, $server);
    }

    /**
     * Detect the base URI for the request
     *
     * Looks at a variety of criteria in order to attempt to autodetect a base
     * URI, including rewrite URIs, proxy URIs, etc.
     *
     * From ZF2's Zend\Http\PhpEnvironment\Request class
     * @copyright Copyright (c) 2005-2014 Zend Technologies USA Inc. (http://www.zend.com)
     * @license   http://framework.zend.com/license/new-bsd New BSD License
     *
     * @param array $server
     * @return string
     */
    public static function marshalRequestUri(array $server)
    {
        // IIS7 with URL Rewrite: make sure we get the unencoded url
        // (double slash problem).
        $iisUrlRewritten = self::get('IIS_WasUrlRewritten', $server);
        $unencodedUrl    = self::get('UNENCODED_URL', $server, '');
        if ('1' == $iisUrlRewritten && ! empty($unencodedUrl)) {
            return $unencodedUrl;
        }

        $requestUri = self::get('REQUEST_URI', $server);

        // Check this first so IIS will catch.
        $httpXRewriteUrl = self::get('HTTP_X_REWRITE_URL', $server);
        if ($httpXRewriteUrl !== null) {
            $requestUri = $httpXRewriteUrl;
        }

        // Check for IIS 7.0 or later with ISAPI_Rewrite
        $httpXOriginalUrl = self::get('HTTP_X_ORIGINAL_URL', $server);
        if ($httpXOriginalUrl !== null) {
            $requestUri = $httpXOriginalUrl;
        }

        if ($requestUri !== null) {
            return preg_replace('#^[^/:]+://[^/]+#', '', $requestUri);
        }

        $origPathInfo = self::get('ORIG_PATH_INFO', $server);
        if (empty($origPathInfo)) {
            return '/';
        }

        return $origPathInfo;
    }

    /**
     * Strip the query string from a path
     *
     * @param mixed $path
     * @return void
     */
    public static function stripQueryString($path)
    {
        if (($qpos = strpos($path, '?')) !== false) {
            return substr($path, 0, $qpos);
        }
        return $path;
    }

    /**
     * Marshal the host and port from the request header
     *
     * @param stdClass $accumulator
     * @param string|array $host
     * @return void
     */
    private static function marshalHostAndPortFromHeader(stdClass $accumulator, $host)
    {
        if (is_array($host)) {
            $host = implode(', ', $host);
        }

        $accumulator->host = $host;
        $accumulator->port = null;

        // works for regname, IPv4 & IPv6
        if (preg_match('|\:(\d+)$|', $accumulator->host, $matches)) {
            $accumulator->host = substr($accumulator->host, 0, -1 * (strlen($matches[1]) + 1));
            $accumulator->port = (int) $matches[1];
        }
    }

    /**
     * Marshal host/port from misinterpreted IPv6 address
     *
     * @param stdClass $accumulator
     * @param array $server
     */
    private static function marshalIpv6HostAndPort(stdClass $accumulator, array $server)
    {
        $accumulator->host = '[' . $server['SERVER_ADDR'] . ']';
        $accumulator->port = $accumulator->port ?: 80;
        if ($accumulator->port . ']' == substr($accumulator->host, strrpos($accumulator->host, ':')+1)) {
            // The last digit of the IPv6-Address has been taken as port
            // Unset the port so the default port can be used
            $accumulator->port = null;
        }
    }
}
