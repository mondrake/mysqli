#!/bin/sh -e

#3110546 Allow contributed modules (mostly database drivers) to override tests in core
curl https://git.drupalcode.org/project/drupal/-/merge_requests/291.diff | git apply -v

#3256642 Introduce database driver extensions and autoload database drivers' dependencies
curl https://git.drupalcode.org/project/drupal/-/merge_requests/3169.diff | git apply -v

#3347497 Introduce a FetchModeTrait to allow emulating PDO fetch modes
curl https://git.drupalcode.org/project/drupal/-/merge_requests/3676.diff | git apply -v

# Extra patch
# git apply -v ./mysqli_staging/tests/github/extra_patch.patch
