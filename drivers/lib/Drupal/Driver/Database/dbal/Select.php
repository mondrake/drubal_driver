<?php

namespace Drupal\Driver\Database\dbal;

use Drupal\Core\Database\Query\Select as QuerySelect;

/**
 * DruDbal implementation of \Drupal\Core\Database\Query\Select.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to driver extension
 * specific code in
 * Drupal\Driver\Database\dbal\DbalExtension\[dbal_driver_name] classes and
 * execution handed over to there.
 */
class Select extends QuerySelect { }
