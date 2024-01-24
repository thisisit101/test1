<?php

/**
 * A *DAV client.
 *
 * @author Aleksander Machniak <machniak@apheleia-it.ch>
 *
 * Copyright (C) 2022, Apheleia IT AG <contact@apheleia-it.ch>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

class kolab_dav_client
{
    public $url;

    protected $user;
    protected $password;
    protected $rc;
    protected $responseHeaders = [];

    /**
     * Object constructor
     */
    public function __construct($url)
    {
        $this->rc = rcube::get_instance();

        $parsedUrl = parse_url($url);

        if (!empty($parsedUrl['user']) && !empty($parsedUrl['pass'])) {
            $this->user     = rawurldecode($parsedUrl['user']);
            $this->password = rawurldecode($parsedUrl['pass']);

            $url = str_replace(rawurlencode($this->user) . ':' . rawurlencode($this->password) . '@', '', $url);
        }
        else {
            $this->user     = $this->rc->get_user_name();
            $this->password = $this->rc->get_user_password();
        }

        $this->url = $url;
    }

    /**
     * Execute HTTP request to a DAV server
     */
    protected function request($path, $method, $body = '', $headers = [])
    {
        $rcube = rcube::get_instance();
        $debug = (array) $rcube->config->get('dav_debug');

        $request_config = [
            'store_body'       => true,
            'follow_redirects' => true,
        ];

        $this->responseHeaders = [];

        if ($path && ($rootPath = parse_url($this->url, PHP_URL_PATH)) && strpos($path, $rootPath) === 0) {
            $path = substr($path, strlen($rootPath));
        }

        try {
            $request = $this->initRequest($this->url . $path, $method, $request_config);

            $request->setAuth($this->user, $this->password);

            if ($body) {
                $request->setBody($body);
                $request->setHeader(['Content-Type' => 'application/xml; charset=utf-8']);
            }

            if (!empty($headers)) {
                $request->setHeader($headers);
            }

            if ($debug) {
                rcube::write_log('dav', "C: {$method}: " . (string) $request->getUrl()
                     . "\n" . $this->debugBody($body, $request->getHeaders()));
            }

            $response = $request->send();

            $body = $response->getBody();
            $code = $response->getStatus();

            if ($debug) {
                rcube::write_log('dav', "S: [{$code}]\n" . $this->debugBody($body, $response->getHeader()));
            }

            if ($code >= 300) {
                throw new Exception("DAV Error ($code):\n{$body}");
            }

            $this->responseHeaders = $response->getHeader();

            return $this->parseXML($body);
        }
        catch (Exception $e) {
            rcube::raise_error($e, true, false);
            return false;
        }
    }

    /**
     * Discover DAV home (root) collection of specified type.
     *
     * @param string $component Component to filter by (VEVENT, VTODO, VCARD)
     *
     * @return string|false Home collection location or False on error
     */
    public function discover($component = 'VEVENT')
    {
        if ($cache = $this->get_cache()) {
            $cache_key = "discover.{$component}." . md5($this->url);

            if ($response = $cache->get($cache_key)) {
                return $response;
            }
        }

        $path = parse_url($this->url, PHP_URL_PATH);

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind xmlns:d="DAV:">'
                . '<d:prop>'
                    . '<d:current-user-principal />'
                . '</d:prop>'
            . '</d:propfind>';

        // Note: Cyrus CardDAV service requires Depth:1 (CalDAV works without it)
        $response = $this->request('/', 'PROPFIND', $body, ['Depth' => 1, 'Prefer' => 'return-minimal']);

        if (empty($response)) {
            return false;
        }

        $elements = $response->getElementsByTagName('response');
        $principal_href = '';

        foreach ($elements as $element) {
            foreach ($element->getElementsByTagName('current-user-principal') as $prop) {
                $principal_href = $prop->nodeValue;
                break;
            }
        }

        if ($path && strpos($principal_href, $path) === 0) {
            $principal_href = substr($principal_href, strlen($path));
        }

        $homes = [
            'VEVENT' => 'calendar-home-set',
            'VTODO' => 'calendar-home-set',
            'VCARD' => 'addressbook-home-set',
        ];

        $ns = [
            'VEVENT' => 'caldav',
            'VTODO' => 'caldav',
            'VCARD' => 'carddav',
        ];

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:' . $ns[$component] . '">'
                . '<d:prop>'
                    . '<c:' . $homes[$component] . ' />'
                . '</d:prop>'
            . '</d:propfind>';

        $response = $this->request($principal_href, 'PROPFIND', $body);

        if (empty($response)) {
            return false;
        }

        $elements = $response->getElementsByTagName('response');

        foreach ($elements as $element) {
            foreach ($element->getElementsByTagName($homes[$component]) as $prop) {
                $root_href = $prop->nodeValue;
                break;
            }
        }

        if (empty($root_href)) {
            return false;
        }

        if ($path && strpos($root_href, $path) === 0) {
            $root_href = substr($root_href, strlen($path));
        }

        if ($cache) {
            $cache->set($cache_key, $root_href);
        }

        return $root_href;
    }

    /**
     * Get list of folders of specified type.
     *
     * @param string $component Component to filter by (VEVENT, VTODO, VCARD)
     *
     * @return false|array List of folders' metadata or False on error
     */
    public function listFolders($component = 'VEVENT')
    {
        $root_href = $this->discover($component);

        if ($root_href === false) {
            return false;
        }

        $ns    = 'xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/"';
        $props = '';

        if ($component != 'VCARD') {
            $ns .= ' xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:a="http://apple.com/ns/ical/" xmlns:k="Kolab:"';
            $props = '<c:supported-calendar-component-set />'
                . '<a:calendar-color />'
                . '<k:alarms />';
        }

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind ' . $ns . '>'
                . '<d:prop>'
                    . '<d:resourcetype />'
                    . '<d:displayname />'
                    // . '<d:sync-token />'
                    . '<cs:getctag />'
                    . $props
                . '</d:prop>'
            . '</d:propfind>';

        // Note: Cyrus CardDAV service requires Depth:1 (CalDAV works without it)
        $response = $this->request($root_href, 'PROPFIND', $body, ['Depth' => 1, 'Prefer' => 'return-minimal']);

        if (empty($response)) {
            return false;
        }

        $folders = [];
        foreach ($response->getElementsByTagName('response') as $element) {
            $folder = $this->getFolderPropertiesFromResponse($element);

            // Filter out the folders of other type
            if ($component == 'VCARD') {
                if (in_array('addressbook', $folder['resource_type'])) {
                    $folders[] = $folder;
                }
            }
            else if (in_array('calendar', $folder['resource_type']) && in_array($component, (array) $folder['types'])) {
                $folders[] = $folder;
            }
        }

        return $folders;
    }

    /**
     * Create a DAV object in a folder
     *
     * @param string $location  Object location
     * @param string $content   Object content
     * @param string $component Content type (VEVENT, VTODO, VCARD)
     *
     * @return false|string|null ETag string (or NULL) on success, False on error
     */
    public function create($location, $content, $component = 'VEVENT')
    {
        $ctype = [
            'VEVENT' => 'text/calendar',
            'VTODO' => 'text/calendar',
            'VCARD' => 'text/vcard',
        ];

        $headers = ['Content-Type' => $ctype[$component] . '; charset=utf-8'];

        $response = $this->request($location, 'PUT', $content, $headers);

        return $this->getETagFromResponse($response);
    }

    /**
     * Update a DAV object in a folder
     *
     * @param string $location  Object location
     * @param string $content   Object content
     * @param string $component Content type (VEVENT, VTODO, VCARD)
     *
     * @return false|string|null ETag string (or NULL) on success, False on error
     */
    public function update($location, $content, $component = 'VEVENT')
    {
        return $this->create($location, $content, $component);
    }

    /**
     * Delete a DAV object from a folder
     *
     * @param string $location Object location
     *
     * @return bool True on success, False on error
     */
    public function delete($location)
    {
        $response = $this->request($location, 'DELETE', '', ['Depth' => 1, 'Prefer' => 'return-minimal']);

        return $response !== false;
    }

    /**
     * Move a DAV object
     *
     * @param string $source Source object location
     * @param string $target Target object content
     *
     * @return false|string|null ETag string (or NULL) on success, False on error
     */
    public function move($source, $target)
    {
        $headers = ['Destination' => $target];

        $response = $this->request($source, 'MOVE', '', $headers);

        return $this->getETagFromResponse($response);
    }

    /**
     * Get folder properties.
     *
     * @param string $location Object location
     *
     * @return false|array Folder metadata or False on error
     */
    public function folderInfo($location)
    {
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind xmlns:d="DAV:">'
                . '<d:allprop/>'
            . '</d:propfind>';

        // Note: Cyrus CardDAV service requires Depth:1 (CalDAV works without it)
        $response = $this->request($location, 'PROPFIND', $body, ['Depth' => 0, 'Prefer' => 'return-minimal']);

        if (!empty($response)
            && ($element = $response->getElementsByTagName('response')->item(0))
            && ($folder = $this->getFolderPropertiesFromResponse($element))
        ) {
            return $folder;
        }

        return false;
    }

    /**
     * Create a DAV folder
     *
     * @param string $location   Object location (relative to the user home)
     * @param string $component  Content type (VEVENT, VTODO, VCARD)
     * @param array  $properties Object content
     *
     * @return bool True on success, False on error
     */
    public function folderCreate($location, $component, $properties = [])
    {
        $ns    = 'xmlns:d="DAV:"';
        $props = '';

        if ($component == 'VCARD') {
            $ns .= ' xmlns:c="urn:ietf:params:xml:ns:carddav"';
            $props = '<d:resourcetype><d:collection/><c:addressbook/></d:resourcetype>';
        }
        else {
            $ns .= ' xmlns:c="urn:ietf:params:xml:ns:caldav"';
            $props = '<d:resourcetype><d:collection/><c:calendar/></d:resourcetype>';
        }

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:mkcol ' . $ns . '>'
                . '<d:set>'
                    . '<d:prop>' . $props . '</d:prop>'
                . '</d:set>'
            . '</d:mkcol>';

        // Create the collection
        $response = $this->request($location, 'MKCOL', $body);

        if (empty($response)) {
            return false;
        }

        // Update collection properties
        return $this->folderUpdate($location, $component, $properties);
    }

    /**
     * Delete a DAV folder
     *
     * @param string $location Folder location
     *
     * @return bool True on success, False on error
     */
    public function folderDelete($location)
    {
        $response = $this->request($location, 'DELETE');

        return $response !== false;
    }

    /**
     * Update a DAV folder
     *
     * @param string $location   Object location
     * @param string $component  Content type (VEVENT, VTODO, VCARD)
     * @param array  $properties Object content
     *
     * @return bool True on success, False on error
     */
    public function folderUpdate($location, $component, $properties = [])
    {
        $ns    = 'xmlns:d="DAV:"';
        $props = '';

        if ($component == 'VCARD') {
            $ns .= ' xmlns:c="urn:ietf:params:xml:ns:carddav"';
            // Resourcetype property is protected
            // $props = '<d:resourcetype><d:collection/><c:addressbook/></d:resourcetype>';
        }
        else {
            $ns .= ' xmlns:c="urn:ietf:params:xml:ns:caldav"';
            // Resourcetype property is protected
            // $props = '<d:resourcetype><d:collection/><c:calendar/></d:resourcetype>';
            /*
                // Note: These are set by Cyrus automatically for calendars
                . '<c:supported-calendar-component-set>'
                    . '<c:comp name="VEVENT"/>'
                    . '<c:comp name="VTODO"/>'
                    . '<c:comp name="VJOURNAL"/>'
                    . '<c:comp name="VFREEBUSY"/>'
                    . '<c:comp name="VAVAILABILITY"/>'
                . '</c:supported-calendar-component-set>';
            */
        }

        foreach ($properties as $name => $value) {
            if ($name == 'name') {
                $props .= '<d:displayname>' . htmlspecialchars($value, ENT_XML1, 'UTF-8') . '</d:displayname>';
            }
            else if ($name == 'color' && strlen($value)) {
                if ($value[0] != '#') {
                    $value = '#' . $value;
                }

                $ns .= ' xmlns:a="http://apple.com/ns/ical/"';
                $props .= '<a:calendar-color>' . htmlspecialchars($value, ENT_XML1, 'UTF-8') . '</a:calendar-color>';
            }
            else if ($name == 'alarms') {
                if (!strpos($ns, 'Kolab:')) {
                    $ns .= ' xmlns:k="Kolab:"';
                }

                $props .= "<k:{$name}>" . ($value ? 'true' : 'false') . "</k:{$name}>";
            }
        }

        if (empty($props)) {
            return true;
        }

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propertyupdate ' . $ns . '>'
                . '<d:set>'
                    . '<d:prop>' . $props . '</d:prop>'
                . '</d:set>'
            . '</d:propertyupdate>';

        $response = $this->request($location, 'PROPPATCH', $body);

        // TODO: Should we make sure "200 OK" status is set for all requested properties?

        return $response !== false;
    }

    /**
     * Fetch DAV objects metadata (ETag, href) a folder
     *
     * @param string $location  Folder location
     * @param string $component Object type (VEVENT, VTODO, VCARD)
     *
     * @return false|array Objects metadata on success, False on error
     */
    public function getIndex($location, $component = 'VEVENT')
    {
        $queries = [
            'VEVENT' => 'calendar-query',
            'VTODO' => 'calendar-query',
            'VCARD' => 'addressbook-query',
        ];

        $ns = [
            'VEVENT' => 'caldav',
            'VTODO' => 'caldav',
            'VCARD' => 'carddav',
        ];

        $filter = '';
        if ($component != 'VCARD') {
            $filter = '<c:comp-filter name="VCALENDAR">'
                    . '<c:comp-filter name="' . $component . '" />'
                . '</c:comp-filter>';
        }

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            .' <c:' . $queries[$component] . ' xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:' . $ns[$component]. '">'
                . '<d:prop>'
                    . '<d:getetag />'
                . '</d:prop>'
                . ($filter ? "<c:filter>$filter</c:filter>" : '')
            . '</c:' . $queries[$component] . '>';

        $response = $this->request($location, 'REPORT', $body, ['Depth' => 1, 'Prefer' => 'return-minimal']);

        if (empty($response)) {
            return false;
        }

        $objects = [];

        foreach ($response->getElementsByTagName('response') as $element) {
            $objects[] = $this->getObjectPropertiesFromResponse($element);
        }

        return $objects;
    }

    /**
     * Fetch DAV objects data from a folder
     *
     * @param string $location  Folder location
     * @param string $component Object type (VEVENT, VTODO, VCARD)
     * @param array  $hrefs     List of objects' locations to fetch (empty for all objects)
     *
     * @return false|array Objects metadata on success, False on error
     */
    public function getData($location, $component = 'VEVENT', $hrefs = [])
    {
        if (empty($hrefs)) {
            return [];
        }

        $body = '';
        foreach ($hrefs as $href) {
            $body .= '<d:href>' . $href . '</d:href>';
        }

        $queries = [
            'VEVENT' => 'calendar-multiget',
            'VTODO' => 'calendar-multiget',
            'VCARD' => 'addressbook-multiget',
        ];

        $ns = [
            'VEVENT' => 'caldav',
            'VTODO' => 'caldav',
            'VCARD' => 'carddav',
        ];

        $types = [
            'VEVENT' => 'calendar-data',
            'VTODO' => 'calendar-data',
            'VCARD' => 'address-data',
        ];

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            .' <c:' . $queries[$component] . ' xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:' . $ns[$component] . '">'
                . '<d:prop>'
                    . '<d:getetag />'
                    . '<c:' . $types[$component]. ' />'
                . '</d:prop>'
                . $body
            . '</c:' . $queries[$component] . '>';

        $response = $this->request($location, 'REPORT', $body, ['Depth' => 1, 'Prefer' => 'return-minimal']);

        if (empty($response)) {
            return false;
        }

        $objects = [];

        foreach ($response->getElementsByTagName('response') as $element) {
            $objects[] = $this->getObjectPropertiesFromResponse($element);
        }

        return $objects;
    }

    /**
     * Parse XML content
     */
    protected function parseXML($xml)
    {
        $doc = new DOMDocument('1.0', 'UTF-8');

        if (stripos($xml, '<?xml') === 0) {
            if (!$doc->loadXML($xml)) {
                throw new Exception("Failed to parse XML");
            }

            $doc->formatOutput = true;
        }

        return $doc;
    }

    /**
     * Parse request/response body for debug purposes
     */
    protected function debugBody($body, $headers)
    {
        $head = '';
        foreach ($headers as $header_name => $header_value) {
            $head .= "{$header_name}: {$header_value}\n";
        }

        if (stripos($body, '<?xml') === 0) {
            $doc = new DOMDocument('1.0', 'UTF-8');

            $doc->formatOutput = true;
            $doc->preserveWhiteSpace = false;

            if (!$doc->loadXML($body)) {
                throw new Exception("Failed to parse XML");
            }

            $body = $doc->saveXML();
        }

        return $head . "\n" . rtrim($body);
    }

    /**
     * Extract folder properties from a server 'response' element
     */
    protected function getFolderPropertiesFromResponse(DOMNode $element)
    {

        if ($href = $element->getElementsByTagName('href')->item(0)) {
            $href = $href->nodeValue;
/*
            $path = parse_url($this->url, PHP_URL_PATH);
            if ($path && strpos($href, $path) === 0) {
                $href = substr($href, strlen($path));
            }
*/
        }

        if ($color = $element->getElementsByTagName('calendar-color')->item(0)) {
            if (preg_match('/^#[0-9a-fA-F]{6,8}$/', $color->nodeValue)) {
                $color = substr($color->nodeValue, 1);
            } else {
                $color = null;
            }
        }

        if ($name = $element->getElementsByTagName('displayname')->item(0)) {
            $name = $name->nodeValue;
        }

        if ($ctag = $element->getElementsByTagName('getctag')->item(0)) {
            $ctag = $ctag->nodeValue;
        }

        $components = [];
        if ($set_element = $element->getElementsByTagName('supported-calendar-component-set')->item(0)) {
            foreach ($set_element->getElementsByTagName('comp') as $comp_element) {
                $components[] = $comp_element->attributes->getNamedItem('name')->nodeValue;
            }
        }

        $types = [];
        if ($type_element = $element->getElementsByTagName('resourcetype')->item(0)) {
            foreach ($type_element->childNodes as $node) {
                $_type = explode(':', $node->nodeName);
                $types[] = count($_type) > 1 ? $_type[1] : $_type[0];
            }
        }

        $result = [
            'href' => $href,
            'name' => $name,
            'ctag' => $ctag,
            'color' => $color,
            'types' => $components,
            'resource_type' => $types,
        ];

        foreach (['alarms'] as $tag) {
            if ($el = $element->getElementsByTagName($tag)->item(0)) {
                if (strlen($el->nodeValue) > 0) {
                    $result[$tag] = strtolower($el->nodeValue) === 'true';
                }
            }
        }

        return $result;
    }

    /**
     * Extract object properties from a server 'response' element
     */
    protected function getObjectPropertiesFromResponse(DOMNode $element)
    {
        $uid = null;
        if ($href = $element->getElementsByTagName('href')->item(0)) {
            $href = $href->nodeValue;
/*
            $path = parse_url($this->url, PHP_URL_PATH);
            if ($path && strpos($href, $path) === 0) {
                $href = substr($href, strlen($path));
            }
*/
            // Extract UID from the URL
            $href_parts = explode('/', $href);
            $uid = preg_replace('/\.[a-z]+$/', '', $href_parts[count($href_parts)-1]);
        }

        if ($data = $element->getElementsByTagName('calendar-data')->item(0)) {
            $data = $data->nodeValue;
        }
        else if ($data = $element->getElementsByTagName('address-data')->item(0)) {
            $data = $data->nodeValue;
        }

        if ($etag = $element->getElementsByTagName('getetag')->item(0)) {
            $etag = $etag->nodeValue;
            if (preg_match('|^".*"$|', $etag)) {
                $etag = substr($etag, 1, -1);
            }
        }

        return [
            'href' => $href,
            'data' => $data,
            'etag' => $etag,
            'uid' => $uid,
        ];
    }

    /**
     * Get ETag from a response
     */
    protected function getETagFromResponse($response)
    {
        if ($response !== false) {
            // Note: ETag is not always returned, e.g. https://github.com/cyrusimap/cyrus-imapd/issues/2456
            $etag = isset($this->responseHeaders['etag']) ? $this->responseHeaders['etag'] : null;

            if (is_string($etag) && preg_match('|^".*"$|', $etag)) {
                $etag = substr($etag, 1, -1);
            }

            return $etag;
        }

        return false;
    }

    /**
     * Initialize HTTP request object
     */
    protected function initRequest($url = '', $method = 'GET', $config = array())
    {
        $rcube       = rcube::get_instance();
        $http_config = (array) $rcube->config->get('kolab_http_request');

        // deprecated configuration options
        if (empty($http_config)) {
            foreach (array('ssl_verify_peer', 'ssl_verify_host') as $option) {
                $value = $rcube->config->get('kolab_' . $option, true);
                if (is_bool($value)) {
                    $http_config[$option] = $value;
                }
            }
        }

        if (!empty($config)) {
            $http_config = array_merge($http_config, $config);
        }

        // load HTTP_Request2 (support both composer-installed and system-installed package)
        if (!class_exists('HTTP_Request2')) {
            require_once 'HTTP/Request2.php';
        }

        try {
            $request = new HTTP_Request2();
            $request->setConfig($http_config);

            // proxy User-Agent string
            if (isset($_SERVER['HTTP_USER_AGENT'])) {
                $request->setHeader('user-agent', $_SERVER['HTTP_USER_AGENT']);
            }

            // cleanup
            $request->setBody('');
            $request->setUrl($url);
            $request->setMethod($method);

            return $request;
        }
        catch (Exception $e) {
            rcube::raise_error($e, true, true);
        }
    }

    /**
     * Return caching object if enabled
     */
    protected function get_cache()
    {
        $rcube = rcube::get_instance();
        if ($cache_type = $rcube->config->get('dav_cache', 'db')) {
            $cache_ttl  = $rcube->config->get('dav_cache_ttl', '10m');
            $cache_name = 'DAV';

            return $rcube->get_cache($cache_name, $cache_type, $cache_ttl);
        }
    }
}
