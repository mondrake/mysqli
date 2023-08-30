#!/bin/sh -e

#3110546 Allow contributed modules (mostly database drivers) to override tests in core
curl https://git.drupalcode.org/project/drupal/-/merge_requests/291.diff | git apply -v

#3364706 Refactor transactions
curl https://git.drupalcode.org/project/drupal/-/merge_requests/4101.diff | git apply -v

# Extra patch
# git apply -v ./mysqli_staging/tests/github/extra_patch.patch
