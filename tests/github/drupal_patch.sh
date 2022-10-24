#!/bin/sh -e

#3110546 Allow contributed modules (mostly database drivers) to override tests in core
curl https://git.drupalcode.org/project/drupal/-/merge_requests/291.diff | git apply -v

#3256642 Autoload classes of database drivers modules' dependencies
curl https://git.drupalcode.org/project/drupal/-/merge_requests/2844.diff | git apply -v

#3316923 Sort out more driver specific database kernel tests
curl https://git.drupalcode.org/project/drupal/-/merge_requests/2896.diff | git apply -v
