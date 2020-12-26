#!/bin/sh

# First you have to link/copy /skins directory from Roundcube repo
# into ./skins here

lessc --clean-css="--s1 --advanced" --rewrite-urls=all plugins/libkolab/skins/elastic/libkolab.less > plugins/libkolab/skins/elastic/libkolab.min.css
