<?php

/**
 * Kolab configuration storage.
 *
 * Plugin to use Kolab server as a configuration storage. Provides an API to handle
 * configuration according to http://wiki.kolab.org/KEP:9.
 *
 * @version @package_version@
 * @author Machniak Aleksander <machniak@kolabsys.com>
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2011-2012, Kolab Systems AG <contact@kolabsys.com>
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

class kolab_config extends rcube_plugin
{
    public $task = 'utils|mail';

    private $config;
    private $loaded = false;
    private $dicts = [];

    public const O_TYPE = 'dictionary';

    /**
     * Required startup method of a Roundcube plugin
     */
    public function init()
    {
        $rcmail = rcube::get_instance();

        // Register spellchecker dictionary handlers
        if (strtolower($rcmail->config->get('spellcheck_dictionary')) != 'shared') {
            $this->add_hook('spell_dictionary_save', [$this, 'dictionary_save']);
            $this->add_hook('spell_dictionary_get', [$this, 'dictionary_get']);
        }
        /*
                // Register addressbook saved searches handlers
                $this->add_hook('saved_search_create', array($this, 'saved_search_create'));
                $this->add_hook('saved_search_delete', array($this, 'saved_search_delete'));
                $this->add_hook('saved_search_list', array($this, 'saved_search_list'));
                $this->add_hook('saved_search_get', array($this, 'saved_search_get'));
        */

        // @TODO: responses (snippets)
    }

    /**
     * Initializes config object and dependencies
     */
    private function load()
    {
        if ($this->loaded) {
            return;
        }

        $this->require_plugin('libkolab');

        $this->config = kolab_storage_config::get_instance();
        $this->loaded = true;
    }

    /**
     * Saves spellcheck dictionary.
     *
     * @param array $args Hook arguments
     *
     * @return array Hook arguments
     */
    public function dictionary_save($args)
    {
        $this->load();

        if (!$this->config->is_enabled()) {
            return $args;
        }

        $lang = $args['language'];
        $dict = $this->read_dictionary($lang, true);

        $dict['language'] = $args['language'];
        $dict['e']        = $args['dictionary'];

        if (empty($dict['e'])) {
            // Delete the object
            $this->config->delete($dict['uid']);
        } else {
            // Update the object
            $this->config->save($dict, self::O_TYPE, $dict['uid']);
        }

        $args['abort'] = true;

        return $args;
    }

    /**
     * Returns spellcheck dictionary.
     *
     * @param array $args Hook arguments
     *
     * @return array Hook arguments
     */
    public function dictionary_get($args)
    {
        $this->load();

        if (!$this->config->is_enabled()) {
            return $args;
        }

        $lang = $args['language'];
        $dict = $this->read_dictionary($lang);

        if (!empty($dict)) {
            $args['dictionary'] = (array)$dict['e'];
        }

        $args['abort'] = true;

        return $args;
    }

    /**
     * Load dictionary config objects from Kolab storage
     *
     * @param string $lang    The language (2 chars) to load
     * @param bool   $default Only load objects from default folder
     *
     * @return array|null Dictionary object as hash array
     */
    private function read_dictionary($lang, $default = false)
    {
        if (isset($this->dicts[$lang])) {
            return $this->dicts[$lang];
        }

        $query = [['type','=',self::O_TYPE], ['tags','=',$lang]];

        foreach ($this->config->get_objects($query, $default, null, 100) as $object) {
            if ($object['language'] == $lang || $object['language'] == 'XX') {
                if (isset($this->dicts[$lang]) && is_array($this->dicts[$lang])) {
                    $this->dicts[$lang]['e'] = array_merge((array)$this->dicts[$lang]['e'], $object['e']);
                } else {
                    $this->dicts[$lang] = $object;
                }
                /*
                                // make sure the default object is cached
                                if ($folder->default && $object['language'] != 'XX') {
                                    $object['e'] = $this->dicts[$lang]['e'];
                                    $this->dicts[$lang] = $object;
                                }
                */
            }
        }

        return $this->dicts[$lang] ?? null;
    }
}
