#!/bin/sh -e

#2992274 Installer tests fail if contrib driver hides database credentials form fields
curl https://www.drupal.org/files/issues/2020-11-23/2992274-13.patch | git apply -v

#3110546 Allow contributed modules (mostly database drivers) to override tests in core
curl https://git.drupalcode.org/project/drupal/-/merge_requests/291.diff | git apply -v

#3191623 Views aggregate queries do not escape the fields
curl https://git.drupalcode.org/project/drupal/-/merge_requests/785.diff | git apply -v

curl https://www.drupal.org/files/issues/2021-07-05/838992-pgsql-47.patch | git apply -v
#ls -al drudbal_staging/tests/github/drupal_patch.sh
#git apply -v drudbal_staging/tests/github/test.patch
