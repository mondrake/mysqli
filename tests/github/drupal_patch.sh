#!/bin/sh -e

#3256642 Autoload classes of database drivers modules' dependencies
curl https://git.drupalcode.org/project/drupal/-/merge_requests/1626.diff | git apply -v

#3260007 Decouple Connection from the wrapped PDO connection to allow alternative clients
curl https://git.drupalcode.org/project/drupal/-/merge_requests/1704.diff | git apply -v
