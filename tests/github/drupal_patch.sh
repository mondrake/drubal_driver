#!/bin/sh -e

#2657888 Add Date function support in DTBNG
curl https://www.drupal.org/files/issues/2657888-18.patch | git apply -v

#2992274 Installer tests fail if contrib driver hides database credentials form fields
curl https://www.drupal.org/files/issues/2020-11-23/2992274-13.patch | git apply -v

#3110546 Allow contributed modules (mostly database drivers) to override tests in core
git apply -v drudbal_staging/tests/travis_ci/alt-fix.patch

#3191623 Views aggregate queries do not escape the fields
curl https://www.drupal.org/files/issues/2021-01-08/3190285-5-test-only.patch | git apply -v

#2795567 Use Symfony's VarDumper for easier test debugging with dump()
curl https://git.drupalcode.org/project/drupal/-/merge_requests/181.diff | git apply -v

#3129534 Automatically enable the module that is providing the current database driver
curl https://www.drupal.org/files/issues/2021-01-14/3129534-81_1.patch | git apply -v
