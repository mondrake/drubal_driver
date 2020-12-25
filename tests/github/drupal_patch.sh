#!/bin/sh -e

#2657888 Add Date function support in DTBNG
curl https://www.drupal.org/files/issues/2657888-18.patch | git apply -v

#2992274 Installer tests fail if contrib driver hides database credentials form fields
curl https://www.drupal.org/files/issues/2020-11-23/2992274-13.patch | git apply -v

#3110546 Allow contributed modules (mostly database drivers) to override tests in core
git apply -v drudbal_staging/tests/travis_ci/alt-fix.patch

#3189880 Use square brackets syntax in expressions
curl https://git.drupalcode.org/project/drupal/-/merge_requests/187.diff | git apply -v