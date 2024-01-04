<?php

/**
 * A class representing a DAV folder object.
 *
 * @author Aleksander Machniak <machniak@apheleia-it.ch>
 *
 * Copyright (C) 2014-2022, Apheleia IT AG <contact@apheleia-it.ch>
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

#[AllowDynamicProperties]
class kolab_storage_dav_folder extends kolab_storage_folder
{
    /** @var kolab_dav_client DAV Client */
    public $dav;

    /** @var string Folder URL */
    public $href;

    /** @var array Folder DAV attributes */
    public $attributes;


    /**
     * Object constructor
     */
    public function __construct($dav, $attributes, $type = '')
    {
        $this->attributes = $attributes;

        $this->href  = $this->attributes['href'];
        $this->id    = kolab_storage_dav::folder_id($dav->url, $this->href);
        $this->dav   = $dav;
        $this->valid = true;

        list($this->type, $suffix) = strpos($type, '.') ? explode('.', $type) : [$type, ''];
        $this->default = $suffix == 'default';
        $this->subtype = $this->default ? '' : $suffix;

        // Init cache
        $this->cache = kolab_storage_dav_cache::factory($this);
    }

    /**
     * Returns the owner of the folder.
     *
     * @param bool Return a fully qualified owner name (i.e. including domain for shared folders)
     *
     * @return string The owner of this folder.
     */
    public function get_owner($fully_qualified = false)
    {
        // return cached value
        if (isset($this->owner)) {
            return $this->owner;
        }

        $rcube = rcube::get_instance();
        $this->owner = $rcube->get_user_name();
        $this->valid = true;

        // TODO: Support shared folders

        return $this->owner;
    }

    /**
     * Get a folder Etag identifier
     */
    public function get_ctag()
    {
        // Refresh requested, get the current CTag from the DAV server
        if ($this->attributes['ctag'] === false) {
            if ($fresh = $this->dav->folderInfo($this->href)) {
                $this->attributes['ctag'] = $fresh['ctag'];
            }
        }

        return $this->attributes['ctag'];
    }

    /**
     * Getter for the name of the namespace to which the folder belongs
     *
     * @return string Name of the namespace (personal, other, shared)
     */
    public function get_namespace()
    {
        // TODO: Support shared folders
        return 'personal';
    }

    /**
     * Get the display name value of this folder
     *
     * @return string Folder name
     */
    public function get_name()
    {
        return kolab_storage_dav::object_name($this->attributes['name']);
    }

    /**
     * Getter for the top-end folder name (not the entire path)
     *
     * @return string Name of this folder
     */
    public function get_foldername()
    {
        return $this->attributes['name'];
    }

    public function get_folder_info()
    {
        return []; // todo ?
    }

    /**
     * Getter for parent folder path
     *
     * @return string Full path to parent folder
     */
    public function get_parent()
    {
        // TODO
        return '';
    }

    /**
     * Compose a unique resource URI for this folder
     */
    public function get_resource_uri()
    {
        if (!empty($this->resource_uri)) {
            return $this->resource_uri;
        }

        // compose fully qualified resource uri for this instance
        $host = preg_replace('|^https?://|', 'dav://' . urlencode($this->get_owner(true)) . '@', $this->dav->url);
        $path = $this->href[0] == '/' ? $this->href : "/{$this->href}";

        $host_path = parse_url($host, PHP_URL_PATH);
        if ($host_path && strpos($path, $host_path) === 0) {
            $path = substr($path, strlen($host_path));
        }

        $this->resource_uri = unslashify($host) . $path;

        return $this->resource_uri;
    }

    /**
     * Getter for the Cyrus mailbox identifier corresponding to this folder
     * (e.g. user/john.doe/Calendar/Personal@example.org)
     *
     * @return string Mailbox ID
     */
    public function get_mailbox_id()
    {
        // TODO: This is used with Bonnie related features
        return '';
    }

    /**
     * Get the color value stored in metadata
     *
     * @param string Default color value to return if not set
     *
     * @return mixed Color value from the folder metadata or $default if not set
     */
    public function get_color($default = null)
    {
        return !empty($this->attributes['color']) ? $this->attributes['color'] : $default;
    }

    /**
     * Get ACL information for this folder
     *
     * @return string Permissions as string
     */
    public function get_myrights()
    {
        // TODO
        return '';
    }

    /**
     * Helper method to extract folder UID
     *
     * @return string Folder's UID
     */
    public function get_uid()
    {
        return $this->id;
    }

    /**
     * Check activation status of this folder
     *
     * @return bool True if enabled, false if not
     */
    public function is_active()
    {
        return true; // Unused
    }

    /**
     * Change activation status of this folder
     *
     * @param bool The desired subscription status: true = active, false = not active
     *
     * @return bool True on success, false on error
     */
    public function activate($active)
    {
        return true; // Unused
    }

    /**
     * Check subscription status of this folder
     *
     * @return bool True if subscribed, false if not
     */
    public function is_subscribed()
    {
        return true; // TODO
    }

    /**
     * Change subscription status of this folder
     *
     * @param bool The desired subscription status: true = subscribed, false = not subscribed
     *
     * @return True on success, false on error
     */
    public function subscribe($subscribed)
    {
        return true; // TODO
    }

    /**
     * Delete the specified object from this folder.
     *
     * @param array|string $object  The Kolab object to delete or object UID
     * @param bool         $expunge Should the folder be expunged?
     *
     * @return bool True if successful, false on error
     */
    public function delete($object, $expunge = true)
    {
        if (!$this->valid) {
            return false;
        }

        $uid = is_array($object) ? $object['uid'] : $object;

        $success = $this->dav->delete($this->object_location($uid));

        if ($success) {
            $this->cache->set($uid, false);
        }

        return $success;
    }

    /**
     * Delete all objects in a folder.
     *
     * Note: This method is used by kolab_addressbook plugin only
     *
     * @return bool True if successful, false on error
     */
    public function delete_all()
    {
        if (!$this->valid) {
            return false;
        }

        // TODO: Maybe just deleting and re-creating a folder would be
        //       better, but probably might not always work (ACL)

        $this->cache->synchronize();

        foreach (array_keys($this->cache->folder_index()) as $uid) {
            $this->dav->delete($this->object_location($uid));
        }

        $this->cache->purge();

        return true;
    }

    /**
     * Restore a previously deleted object
     *
     * @param string $uid Object UID
     *
     * @return mixed Message UID on success, false on error
     */
    public function undelete($uid)
    {
        if (!$this->valid) {
            return false;
        }

        // TODO

        return false;
    }

    /**
     * Move a Kolab object message to another IMAP folder
     *
     * @param string                   Object UID
     * @param kolab_storage_dav_folder Target folder to move object into
     *
     * @return bool True on success, false on failure
     */
    public function move($uid, $target_folder)
    {
        if (!$this->valid) {
            return false;
        }

        $source = $this->object_location($uid);
        $target = $target_folder->object_location($uid);

        $success = $this->dav->move($source, $target) !== false;

        if ($success) {
            $this->cache->set($uid, false);
            $target_folder->reset();
            $this->reset();
        }

        return $success;
    }

    /**
     * Save an object in this folder.
     *
     * @param array  $object The array that holds the data of the object.
     * @param string $type   The type of the kolab object.
     * @param string $uid    The UID of the old object if it existed before
     *
     * @return mixed False on error or object UID on success
     */
    public function save(&$object, $type = null, $uid = null)
    {
        if (!$this->valid || empty($object)) {
            return false;
        }

        if (!$type) {
            $type = $this->type;
        }

        $result = false;

        if (empty($uid)) {
            if (empty($object['created'])) {
                $object['created'] = new DateTime('now');
            }
        }
        else {
            $object['changed'] = new DateTime('now');
        }

        // Make sure UID exists
        if (empty($object['uid'])) {
            if ($uid) {
                $object['uid'] = $uid;
            }
            else {
                $username = rcube::get_instance()->user->get_username();
                $object['uid'] = strtoupper(md5(time() . uniqid(rand())) . '-' . substr(md5($username), 0, 16));
            }
        }

        // generate and save object message
        if ($content = $this->to_dav($object)) {
            $method   = $uid ? 'update' : 'create';
            $dav_type = $this->get_dav_type();
            $result   = $this->dav->{$method}($this->object_location($object['uid']), $content, $dav_type);

            // Note: $result can be NULL if the request was successful, but ETag wasn't returned
            if ($result !== false) {
                // insert/update object in the cache
                $object['etag'] = $result;
                $object['_raw'] = $content;
                $this->cache->save($object, $uid);
                $result = true;
                unset($object['_raw']);
            }
        }

        return $result;
    }

    /**
     * Fetch the object the DAV server and convert to internal format
     *
     * @param string The object UID to fetch
     * @param string The object type expected (use wildcard '*' to accept all types)
     * @param string Unused (kept for compat. with the parent class)
     *
     * @return mixed Hash array representing the Kolab object, a kolab_format instance or false if not found
     */
    public function read_object($uid, $type = null, $folder = null)
    {
        if (!$this->valid) {
            return false;
        }

        $href    = $this->object_location($uid);
        $objects = $this->dav->getData($this->href, $this->get_dav_type(), [$href]);

        if (!is_array($objects) || count($objects) != 1) {
            rcube::raise_error([
                    'code' => 900,
                    'message' => "Failed to fetch {$href}"
                ], true);
            return false;
        }

        return $this->from_dav($objects[0]);
    }

    /**
     * Fetch multiple objects from the DAV server and convert to internal format
     *
     * @param array The object UIDs to fetch
     *
     * @return mixed Hash array representing the Kolab objects
     */
    public function read_objects($uids)
    {
        if (!$this->valid) {
            return false;
        }

        if (empty($uids)) {
            return [];
        }

        foreach ($uids as $uid) {
            $hrefs[] = $this->object_location($uid);
        }

        $objects = $this->dav->getData($this->href, $this->get_dav_type(), $hrefs);

        if (!is_array($objects)) {
            rcube::raise_error([
                    'code' => 900,
                    'message' => "Failed to fetch objects from {$this->href}"
                ], true);
            return false;
        }

        $objects = array_map([$this, 'from_dav'], $objects);

        foreach ($uids as $idx => $uid) {
            foreach ($objects as $oidx => $object) {
                if ($object && $object['uid'] == $uid) {
                    $uids[$idx] = $object;
                    unset($objects[$oidx]);
                    continue 2;
                }
            }

            $uids[$idx] = false;
        }

        return $uids;
    }

    /**
     * Reset the folder status
     */
    public function reset()
    {
        $this->cache->reset();
        $this->attributes['ctag'] = false;
    }

    /**
     * Convert DAV object into PHP array
     *
     * @param array Object data in kolab_dav_client::fetchData() format
     *
     * @return array|false Object properties, False on error
     */
    public function from_dav($object)
    {
        if (empty($object ) || empty($object['data'])) {
            return false;
        }

        if ($this->type == 'event' || $this->type == 'task') {
            $ical = libcalendaring::get_ical();
            $objects = $ical->import($object['data']);

            if (!count($objects) || empty($objects[0]['uid'])) {
                return false;
            }

            $result = $objects[0];

            $result['_attachments'] = $result['attachments'] ?? [];
            unset($result['attachments']);
        }
        else if ($this->type == 'contact') {
            if (stripos($object['data'], 'BEGIN:VCARD') !== 0) {
                return false;
            }

            // vCard properties not supported by rcube_vcard
            $map = [
                'uid'      => 'UID',
                'kind'     => 'KIND',
                'member'   => 'MEMBER',
                'x-kind'   => 'X-ADDRESSBOOKSERVER-KIND',
                'x-member' => 'X-ADDRESSBOOKSERVER-MEMBER',
            ];

            // TODO: We should probably use Sabre/Vobject to parse the vCard

            $vcard = new rcube_vcard($object['data'], RCUBE_CHARSET, false, $map);

            if (!empty($vcard->displayname) || !empty($vcard->surname) || !empty($vcard->firstname) || !empty($vcard->email)) {
                $result = $vcard->get_assoc();

                // Contact groups
                if (!empty($result['x-kind']) && implode($result['x-kind']) == 'group') {
                    $result['_type'] = 'group';
                    $members = isset($result['x-member']) ? $result['x-member'] : [];
                    unset($result['x-kind'], $result['x-member']);
                }
                else if (!empty($result['kind']) && implode($result['kind']) == 'group') {
                    $result['_type'] = 'group';
                    $members = isset($result['member']) ? $result['member'] : [];
                    unset($result['kind'], $result['member']);
                }

                if (isset($members)) {
                    $result['member'] = [];
                    foreach ($members as $member) {
                        if (strpos($member, 'urn:uuid:') === 0) {
                            $result['member'][] = ['uid' => substr($member, 9)];
                        }
                        else if (strpos($member, 'mailto:') === 0) {
                            $member = reset(rcube_mime::decode_address_list(urldecode(substr($member, 7))));
                            if (!empty($member['mailto'])) {
                                $result['member'][] = ['email' => $member['mailto'], 'name' => $member['name']];
                            }
                        }
                    }
                }

                if (!empty($result['uid'])) {
                    $result['uid'] = preg_replace('/^urn:uuid:/', '', implode($result['uid']));
                }
            }
            else {
                return false;
            }
        }

        $result['etag'] = $object['etag'];
        $result['href'] = !empty($object['href']) ? $object['href'] : null;
        $result['uid']  = !empty($object['uid']) ? $object['uid'] : $result['uid'];

        return $result;
    }

    /**
     * Convert Kolab object into DAV format (iCalendar)
     */
    public function to_dav($object)
    {
        $result = '';

        if ($this->type == 'event' || $this->type == 'task') {
            $ical = libcalendaring::get_ical();

            if (!empty($object['exceptions'])) {
                $object['recurrence']['EXCEPTIONS'] = $object['exceptions'];
            }

            $object['_type'] = $this->type;

            // pre-process attachments
            if (isset($object['_attachments']) && is_array($object['_attachments'])) {
                foreach ($object['_attachments'] as $key => $attachment) {
                    if ($attachment === false) {
                        // Deleted attachment
                        unset($object['_attachments'][$key]);
                        continue;
                    }

                    // make sure size is set
                    if (!isset($attachment['size'])) {
                        if (!empty($attachment['data'])) {
                            if (is_resource($attachment['data'])) {
                                // this need to be a seekable resource, otherwise
                                // fstat() fails and we're unable to determine size
                                // here nor in rcube_imap_generic before IMAP APPEND
                                $stat = fstat($attachment['data']);
                                $attachment['size'] = $stat ? $stat['size'] : 0;
                            }
                            else {
                                $attachment['size'] = strlen($attachment['data']);
                            }
                        }
                        else if (!empty($attachment['path'])) {
                            $attachment['size'] = filesize($attachment['path']);
                        }

                        $object['_attachments'][$key] = $attachment;
                    }
                }
            }

            $object['attachments'] = $object['_attachments'] ?? [];
            unset($object['_attachments']);

            $result = $ical->export([$object], null, false, [$this, 'get_attachment']);
        }
        else if ($this->type == 'contact') {
            // copy values into vcard object
            // TODO: We should probably use Sabre/Vobject to create the vCard

            // vCard properties not supported by rcube_vcard
            $map   = ['uid' => 'UID', 'kind' => 'KIND'];
            $vcard = new rcube_vcard('', RCUBE_CHARSET, false, $map);

            if ((!empty($object['_type']) && $object['_type'] == 'group')
                || (!empty($object['type']) && $object['type'] == 'group')
            ) {
                $object['kind'] = 'group';
            }

            foreach ($object as $key => $values) {
                list($field, $section) = rcube_utils::explode(':', $key);

                // avoid casting DateTime objects to array
                if (is_object($values) && $values instanceof DateTimeInterface) {
                    $values = [$values];
                }

                foreach ((array) $values as $value) {
                    if (isset($value)) {
                        $vcard->set($field, $value, $section);
                    }
                }
            }

            $result = $vcard->export(false);

            if (!empty($object['kind']) && $object['kind'] == 'group') {
                $members = '';
                foreach ((array) $object['member'] as $member) {
                    $value = null;
                    if (!empty($member['uid'])) {
                        $value = 'urn:uuid:' . $member['uid'];
                    }
                    else if (!empty($member['email']) && !empty($member['name'])) {
                        $value = 'mailto:' . urlencode(sprintf('"%s" <%s>', addcslashes($member['name'], '"'), $member['email']));
                    }
                    else if (!empty($member['email'])) {
                        $value = 'mailto:' . $member['email'];
                    }

                    if ($value) {
                        $members .= "MEMBER:{$value}\r\n";
                    }
                }

                if ($members) {
                    $result = preg_replace('/\r\nEND:VCARD/', "\r\n{$members}END:VCARD", $result);
                }

                /**
                    Version 4.0 of the vCard format requires Cyrus >= 3.6.0, we'll use Version 3.0 for now

                $result = preg_replace('/\r\nVERSION:3\.0\r\n/', "\r\nVERSION:4.0\r\n", $result);
                $result = preg_replace('/\r\nN:[^\r]+/', '', $result);
                $result = preg_replace('/\r\nUID:([^\r]+)/', "\r\nUID:urn:uuid:\\1", $result);
                */

                $result = preg_replace('/\r\nMEMBER:([^\r]+)/', "\r\nX-ADDRESSBOOKSERVER-MEMBER:\\1", $result);
                $result = preg_replace('/\r\nKIND:([^\r]+)/', "\r\nX-ADDRESSBOOKSERVER-KIND:\\1", $result);
            }
        }

        if ($result) {
            // The content must be UTF-8, otherwise if we try to fetch the object
            // from server XML parsing would fail.
            $result = rcube_charset::clean($result);
        }

        return $result;
    }

    public function object_location($uid)
    {
        return unslashify($this->href) . '/' . urlencode($uid) . '.' . $this->get_dav_ext();
    }

    /**
     * Get a folder DAV content type
     */
    public function get_dav_type()
    {
        return kolab_storage_dav::get_dav_type($this->type);
    }

    /**
     * Get body of an attachment
     */
    public function get_attachment($id, $event, $unused1 = null, $unused2 = false, $unused3 = null, $unused4 = false)
    {
        // Note: 'attachments' is defined when saving the data into the DAV server
        //       '_attachments' is defined after fetching the object from the DAV server
        if (is_int($id) && isset($event['attachments'][$id])) {
            $attachment = $event['attachments'][$id];
        }
        else if (is_int($id) && isset($event['_attachments'][$id])) {
            $attachment = $event['_attachments'][$id];
        }
        else if (is_string($id) && !empty($event['attachments'])) {
            foreach ($event['attachments'] as $att) {
                if (!empty($att['id']) && $att['id'] === $id) {
                    $attachment = $att;
                }
            }
        }
        else if (is_string($id) && !empty($event['_attachments'])) {
            foreach ($event['_attachments'] as $att) {
                if (!empty($att['id']) && $att['id'] === $id) {
                    $attachment = $att;
                }
            }
        }

        if (empty($attachment)) {
            return false;
        }

        if (!empty($attachment['path'])) {
            return file_get_contents($attachment['path']);
        }

        return $attachment['data'] ?? null;
    }

    /**
     * Get a DAV file extension for specified Kolab type
     */
    public function get_dav_ext()
    {
        $types = [
            'event' => 'ics',
            'task'  => 'ics',
            'contact' => 'vcf',
        ];

        return $types[$this->type];
    }

    /**
     * Return folder name as string representation of this object
     *
     * @return string Folder display name
     */
    public function __toString()
    {
        return $this->attributes['name'];
    }
}
