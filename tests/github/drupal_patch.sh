#!/bin/sh -e

#3110546 Allow contributed modules (mostly database drivers) to override tests in core
curl https://git.drupalcode.org/project/drupal/-/merge_requests/291.diff | git apply -v

#3256642 Introduce database driver extensions and autoload database drivers' dependencies
curl https://git.drupalcode.org/project/drupal/-/merge_requests/3169.diff | git apply -v

#3265086 Fix memory usage regression in StatementWrapper iterator
curl https://www.drupal.org/files/issues/2023-02-03/3265086-62.patch | git apply -v

# Extra patch
# git apply -v ./mysqli_staging/tests/github/extra_patch.patch
