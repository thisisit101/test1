<?php

/**
 * A plugin that adds a task menu icon linking to the Nextcloud files
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

class nextcloud_link extends rcube_plugin
{
    public $task = '?(?!login|logout).*';

    private $rc;

    /**
     * Plugin initialization.
     */
    function init()
    {
        $this->rc = rcube::get_instance();

        if ($this->rc->output->type !== 'html' || !empty($this->rc->output->env['framed'])) {
            return;
        }

        $this->load_config();

        $url = $this->rc->config->get('nextcloud_link_url');

        if (empty($url)) {
            return;
        }

        $this->add_button([
            'command'    => 'nextcloud',
            'class'      => 'button-nextcloud',
            'classsel'   => 'button-nextcloud button-selected',
            'innerclass' => 'button-inner',
            'label'      => '',
            'type'       => 'link'
        ], 'taskbar');

        $this->rc->output->add_footer("
<style>#taskmenu a.button-nextcloud:before { content: \"\\f0c2\"; }</style>
<script>\$('#taskmenu a.button-nextcloud').on('click', function () { window.open('$url', '_blank'); });</script>");
    }
}
