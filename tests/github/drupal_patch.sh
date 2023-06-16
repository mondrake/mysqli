#!/bin/sh -e

#3110546 Allow contributed modules (mostly database drivers) to override tests in core
curl https://git.drupalcode.org/project/drupal/-/merge_requests/291.diff | git apply -v

#3347497 Introduce a FetchModeTrait to allow emulating PDO fetch modes
curl https://git.drupalcode.org/project/drupal/-/merge_requests/3676.diff | git apply -v

#3217531 Deprecate usage of Connection::getDriverClass for some classes, and use standard autoloading instead
curl https://git.drupalcode.org/project/drupal/-/merge_requests/757.diff | git apply -v

# Extra patch
# git apply -v ./mysqli_staging/tests/github/extra_patch.patch
