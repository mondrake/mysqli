#!/bin/sh -e

#3110546 Allow contributed modules (mostly database drivers) to override tests in core
curl https://git.drupalcode.org/project/drupal/-/merge_requests/291.diff | git apply -v

#3256642 Autoload classes of database drivers modules' dependencies
curl https://git.drupalcode.org/project/drupal/-/merge_requests/1626.diff | git apply -v

#3259417 Missing typehints in test classes extending from Symfony
curl https://git.drupalcode.org/project/drupal/-/merge_requests/1682.diff | git apply -v
