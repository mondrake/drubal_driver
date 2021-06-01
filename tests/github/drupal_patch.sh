#!/bin/sh -e

#2992274 Installer tests fail if contrib driver hides database credentials form fields
curl https://www.drupal.org/files/issues/2020-11-23/2992274-13.patch | git apply -v

#3110546 Allow contributed modules (mostly database drivers) to override tests in core
curl https://git.drupalcode.org/project/drupal/-/merge_requests/291.diff | git apply -v

#3191623 Views aggregate queries do not escape the fields
curl https://www.drupal.org/files/issues/2021-01-08/3190285-5-test-only.patch | git apply -v

#3185269 Deprecate the 'return' query option and Database::RETURN_* constants
curl https://git.drupalcode.org/project/drupal/-/merge_requests/78.diff | git apply -v
