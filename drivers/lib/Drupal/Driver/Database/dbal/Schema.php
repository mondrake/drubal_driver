<?php

namespace Drupal\Driver\Database\dbal;

use Drupal\Core\Database\Query\Condition;
use Drupal\Core\Database\SchemaException;
use Drupal\Core\Database\SchemaObjectExistsException;
use Drupal\Core\Database\SchemaObjectDoesNotExistException;
use Drupal\Core\Database\Schema as DatabaseSchema;
use Drupal\Component\Utility\Unicode;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Schema\Schema as DbalSchema;
use Doctrine\DBAL\Schema\SchemaException as DBALSchemaException;
use Doctrine\DBAL\Types\Type as DbalType;

/**
 * DruDbal implementation of \Drupal\Core\Database\Schema.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class Schema extends DatabaseSchema {

  /**
   * DBAL schema manager.
   *
   * @var \Doctrine\DBAL\Schema\AbstractSchemaManager
   */
  protected $dbalSchemaManager;

  /**
   * DBAL platform.
   *
   * @var \Doctrine\DBAL\Platforms\AbstractPlatform
   */
  protected $dbalPlatform;

  /**
   * Current DBAL schema.
   *
   * @var \Doctrine\DBAL\Schema\Schema
   */
  protected $dbalCurrentSchema;

  /**
   * The Dbal extension for the DBAL driver.
   *
   * @var \Drupal\Driver\Database\dbal\DbalExtension\DbalExtensionInterface
   */
  protected $dbalExtension;

  /**
   * Constructs a Schema object.
   *
   * @var \Drupal\Driver\Database\dbal\Connection
   *   The DBAL driver Drupal database connection.
   */
  public function __construct(Connection $connection) {
    parent::__construct($connection);
    $this->dbalExtension = $this->connection->getDbalExtension();
    $this->dbalSchemaManager = $this->connection->getDbalConnection()->getSchemaManager();
    $this->dbalPlatform = $this->connection->getDbalConnection()->getDatabasePlatform();
    $this->dbalExtension->alterDefaultSchema($this->defaultSchema);
  }

  /**
   * Returns a fully prefixed table name from Drupal's {table} syntax.
   *
   * @param string $drupal table
   *   The table name in Drupal's syntax.
   *
   * @return string
   *   The fully prefixed table name to be used in the DBMS.
   */
  protected function tableName($drupal_table) {
    return $this->connection->getPrefixedTableName($drupal_table);
  }

  /**
   * {@inheritdoc}
   */
  public function createTable($name, $table) {
    if ($this->tableExists($name)) {
      throw new SchemaObjectExistsException(t('Table @name already exists.', ['@name' => $name]));
    }

    // Create table via DBAL.
    $current_schema = $this->dbalSchema();
    $to_schema = clone $current_schema;
    $new_table = $to_schema->createTable($this->tableName($name));

    // Add table comment.
    if (!empty($table['description'])) {
      $comment = $this->connection->prefixTables($table['description']);
      $this->dbalExtension->alterSetTableComment($comment, $name, $to_schema, $table);
      $new_table->addOption('comment', $this->prepareComment($comment));
    }

    // Let DBAL extension alter the table options if required.
    $this->dbalExtension->alterCreateTableOptions($new_table, $to_schema, $table, $name);

    // Add columns.
    foreach ($table['fields'] as $field_name => $field) {
      $dbal_type = $this->getDbalColumnType($field);
      $new_column = $new_table->addColumn($field_name, $dbal_type, $this->getDbalColumnOptions('createTable', $field_name, $dbal_type, $field));
    }

    // Add primary key.
    if (!empty($table['primary key'])) {
      // @todo in MySql, this could still be a list of columns with length.
      // However we have to add here instead of separate calls to
      // ::addPrimaryKey to avoid failure when creating a table with an
      // autoincrement column.
      $new_table->setPrimaryKey($this->dbalGetFieldList($table['primary key']));
    }

    // Execute the table creation.
    $this->dbalExecuteSchemaChange($to_schema);

    // Add unique keys.
    if (!empty($table['unique keys'])) {
      foreach ($table['unique keys'] as $key => $fields) {
        $this->addUniqueKey($name, $key, $fields);
      }
    }

    // Add indexes.
    if (!empty($table['indexes'])) {
      foreach ($table['indexes'] as $index => $fields) {
        $this->addIndex($name, $index, $fields, $table);
      }
    }
  }

  /**
   * Gets DBAL column type, given Drupal's field specs.
   *
   * @param array $field
   *   A field description array, as specified in the schema documentation.
   *
   * @return string
   *   The string identifier of the DBAL column type.
   */
  public function getDbalColumnType(array $field) {
    $dbal_type = NULL;

    // Delegate to DBAL extension.
    if ($this->dbalExtension->delegateGetDbalColumnType($dbal_type, $field)) {
      return $dbal_type;
    }

    // DBAL extension did not pick up, proceed with DBAL.
    if (!isset($field['size'])) {
      $field['size'] = 'normal';
    }
    $map = $this->getFieldTypeMap();

    $key = $field['type'] . ':' . $field['size'];
    if (!isset($map[$key])) {
      throw new \InvalidArgumentException("There is no DBAL mapping for column type $key");
    }

    return $map[$key];
  }

  /**
   * Gets DBAL column options, given Drupal's field specs.
   *
   * @param string $context
   *   The context from where the method is called. Can be 'createTable',
   *   'addField', 'changeField'.
   * @param string $field_name
   *   The column name.
   * @param string $dbal_type
   *   The string identifier of the DBAL column type.
   * @param array $field
   *   A field description array, as specified in the schema documentation.
   *
   * @return array
   *   An array of DBAL column options, including the SQL column definition
   *   specification in the 'columnDefinition' option.
   */
  protected function getDbalColumnOptions($context, $field_name, $dbal_type, array $field) {
    $options = [];

    $options['type'] = DbalType::getType($dbal_type);

    if (isset($field['length'])) {
      $options['length'] = $field['length'];
    }

    if (isset($field['precision']) && isset($field['scale'])) {
      $options['precision'] = $field['precision'];
      $options['scale'] = $field['scale'];
    }

    if (!empty($field['unsigned'])) {
      $options['unsigned'] = $field['unsigned'];
    }

    if (!empty($field['not null'])) {
      $options['notnull'] = (bool) $field['not null'];
    }
    else {
      $options['notnull'] = FALSE;
    }

    // $field['default'] can be NULL, so we explicitly check for the key here.
    if (array_key_exists('default', $field)) {
      if (is_null($field['default'])) {
        if ((isset($field['not null']) && (bool) $field['not null'] === FALSE) || !isset($field['not null'])) {
          $options['notnull'] = FALSE;
        }
      }
      else {
        $options['default'] = $this->dbalExtension->getDbalEncodedStringForDDLSql($field['default']);
      }
    }

    if (isset($field['type']) && $field['type'] == 'serial') {
      $options['autoincrement'] = TRUE;
      $options['notnull'] = TRUE;
    }

    if (!empty($field['description'])) {
      $comment = $this->connection->prefixTables($field['description']);
      $this->dbalExtension->alterSetColumnComment($comment, $dbal_type, $field, $field_name);
      $options['comment'] = $this->prepareComment($comment);
    }

    // Let DBAL extension alter the column options if required.
    $this->dbalExtension->alterDbalColumnOptions($context, $options, $dbal_type, $field, $field_name);

    // Get the column definition from DBAL, and trim the field name.
    $dbal_column_definition = substr($this->dbalPlatform->getColumnDeclarationSQL($field_name, $options), strlen($field_name) + 1);

    // Let DBAL extension alter the column definition if required.
    $this->dbalExtension->alterDbalColumnDefinition($context, $dbal_column_definition, $options, $dbal_type, $field, $field_name);

    // Add the SQL column definiton as the 'columnDefinition' option.
    $options['columnDefinition'] = $dbal_column_definition;

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldTypeMap() {
    // Put :normal last so it gets preserved by array_flip. This makes
    // it much easier for modules (such as schema.module) to map
    // database types back into schema types.
    // $map does not use drupal_static as its value never changes.
    static $map = [
      'varchar_ascii:normal' => 'string',

      'varchar:normal'  => 'string',
      'char:normal'     => 'string',

      'text:tiny'       => 'text',
      'text:small'      => 'text',
      'text:medium'     => 'text',
      'text:big'        => 'text',
      'text:normal'     => 'text',

      'serial:tiny'     => 'smallint',
      'serial:small'    => 'smallint',
      'serial:medium'   => 'integer',
      'serial:big'      => 'bigint',
      'serial:normal'   => 'integer',

      'int:tiny'        => 'smallint',
      'int:small'       => 'smallint',
      'int:medium'      => 'integer',
      'int:big'         => 'bigint',
      'int:normal'      => 'integer',

      'float:tiny'      => 'float',
      'float:small'     => 'float',
      'float:medium'    => 'float',
      'float:big'       => 'float',
      'float:normal'    => 'float',

      'numeric:normal'  => 'decimal',

      'blob:big'        => 'blob',
      'blob:normal'     => 'blob',
    ];
    return $map;
  }

  /**
   * {@inheritdoc}
   */
  public function renameTable($table, $new_name) {
    if (!$this->tableExists($table)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot rename @table to @table_new: table @table doesn't exist.", ['@table' => $table, '@table_new' => $new_name]));
    }
    if ($this->tableExists($new_name)) {
      throw new SchemaObjectExistsException(t("Cannot rename @table to @table_new: table @table_new already exists.", ['@table' => $table, '@table_new' => $new_name]));
    }

    // DBAL Schema will drop the old table and create a new one, so we go for
    // using the manager instead that allows in-place renaming.
    // @see https://github.com/doctrine/migrations/issues/17
    $this->dbalSchemaManager->renameTable($this->tableName($table), $this->tableName($new_name));
    $this->dbalSchemaForceReload();
  }

  /**
   * {@inheritdoc}
   */
  public function dropTable($table) {
    if (!$this->tableExists($table)) {
      return FALSE;
    }

    // DBAL Schema is slow here, especially for tearDown while testing, so we
    // use the manager directly.
    // @todo this will affect possibility to drop FKs in an orderly way, so
    // we would need to revise at later stage if we want the driver to support
    // a broader set of capabilities.
    $this->dbalSchemaManager->dropTable($this->tableName($table));
    $this->dbalSchemaForceReload();
    return TRUE;

    // @todo preferred way:
    // if ($this->dbalSchema()->hasTable($this->tableName($table))) {
    //   $current_schema = $this->dbalSchema();
    //   $to_schema = clone $current_schema;
    //   $to_schema->dropTable($this->tableName($table));
    //   $this->dbalExecuteSchemaChange($to_schema);
    //   return TRUE;
    // }
    // return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function addField($table, $field, $spec, $keys_new = []) {
    if (!$this->tableExists($table)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot add field @table.@field: table doesn't exist.", ['@field' => $field, '@table' => $table]));
    }
    if ($this->fieldExists($table, $field)) {
      throw new SchemaObjectExistsException(t("Cannot add field @table.@field: field already exists.", ['@field' => $field, '@table' => $table]));
    }

    $fixnull = FALSE;
    if (!empty($spec['not null']) && !isset($spec['default'])) {
      $fixnull = TRUE;
      $spec['not null'] = FALSE;
    }

    $current_schema = $this->dbalSchema();
    $to_schema = clone $current_schema;
    $dbal_table = $to_schema->getTable($this->tableName($table));

    // Drop primary key if it is due to be changed.
    if (!empty($keys_new['primary key']) && $dbal_table->hasPrimaryKey()) {
      $dbal_table->dropPrimaryKey();
      $this->dbalExecuteSchemaChange($to_schema);
      $current_schema = $this->dbalSchema();
      $to_schema = clone $current_schema;
      $dbal_table = $to_schema->getTable($this->tableName($table));
    }

    // Delegate to DBAL extension.
    $primary_key_processed_by_extension = FALSE;
    $dbal_type = $this->getDbalColumnType($spec);
    $dbal_column_options = $this->getDbalColumnOptions('addField', $field, $dbal_type, $spec);
    if ($this->dbalExtension->delegateAddField($primary_key_processed_by_extension, $table, $field, $spec, $keys_new, $dbal_column_options)) {
      $this->dbalSchemaForceReload();
    }
    else {
      // DBAL extension did not pick up, proceed with DBAL.
      $dbal_table->addColumn($field, $dbal_type, $dbal_column_options);
      // Manage change to primary key.
      if (!empty($keys_new['primary key'])) {
        // @todo in MySql, this could still be a list of columns with length.
        // However we have to add here instead of separate calls to
        // ::addPrimaryKey to avoid failure when creating a table with an
        // autoincrement column.
        $dbal_table->setPrimaryKey($this->dbalGetFieldList($keys_new['primary key']));
      }
      $this->dbalExecuteSchemaChange($to_schema);
    }

    // Add unique keys.
    if (!empty($keys_new['unique keys'])) {
      foreach ($keys_new['unique keys'] as $key => $fields) {
        $this->addUniqueKey($table, $key, $fields);
      }
    }

    // Add indexes.
    if (!empty($keys_new['indexes'])) {
      foreach ($keys_new['indexes'] as $index => $fields) {
        $this->addIndex($table, $index, $fields, $keys_new);
      }
    }

    if (isset($spec['initial'])) {
      $this->connection->update($table)
        ->fields([$field => $spec['initial']])
        ->execute();
    }
    if (isset($spec['initial_from_field'])) {
      $this->connection->update($table)
        ->expression($field, $spec['initial_from_field'])
        ->execute();
    }
    if ($fixnull) {
      $spec['not null'] = TRUE;
      $this->changeField($table, $field, $field, $spec);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function dropField($table, $field) {
    if (!$this->fieldExists($table, $field)) {
      return FALSE;
    }

    $current_schema = $this->dbalSchema();
    $to_schema = clone $current_schema;
    $to_schema->getTable($this->tableName($table))->dropColumn($field);
    $this->dbalExecuteSchemaChange($to_schema);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSetDefault($table, $field, $default) {
    if (!$this->fieldExists($table, $field)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot set default value of field @table.@field: field doesn't exist.", ['@table' => $table, '@field' => $field]));
    }

    // Delegate to DBAL extension.
    if ($this->dbalExtension->delegateFieldSetDefault($table, $field, $this->escapeDefaultValue($default))) {
      $this->dbalSchemaForceReload();
      return;
    }

    // DBAL extension did not pick up, proceed with DBAL.
    $current_schema = $this->dbalSchema();
    $to_schema = clone $current_schema;
    // @todo this may not work - need to see if ::escapeDefaultValue
    // provides a sensible output.
    $to_schema->getTable($this->tableName($table))->getColumn($field)->setDefault($this->escapeDefaultValue($default));
    $this->dbalExecuteSchemaChange($to_schema);
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSetNoDefault($table, $field) {
    if (!$this->fieldExists($table, $field)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot remove default value of field @table.@field: field doesn't exist.", ['@table' => $table, '@field' => $field]));
    }

    // Delegate to DBAL extension.
    if ($this->dbalExtension->delegateFieldSetNoDefault($table, $field)) {
      $this->dbalSchemaForceReload();
      return;
    }

    // DBAL extension did not pick up, proceed with DBAL.
    $current_schema = $this->dbalSchema();
    $to_schema = clone $current_schema;
    // @todo this may not work - we need to 'DROP' the default, not set it
    // to null.
    $to_schema->getTable($this->tableName($table))->getColumn($field)->setDefault(NULL);
    $this->dbalExecuteSchemaChange($to_schema);
  }

  /**
   * {@inheritdoc}
   */
  public function indexExists($table, $name) {
    if (!$this->tableExists($table)) {
      return FALSE;
    }

    // Delegate to DBAL extension.
    $result = FALSE;
    if ($this->dbalExtension->delegateIndexExists($result, $this->dbalSchema(), $table, $name)) {
      return $result;
    }

    // DBAL extension did not pick up, proceed with DBAL.
    $index_name = $this->dbalExtension->delegateGetIndexName($table, $name, $this->getPrefixInfo($table));
    return in_array($index_name, array_keys($this->dbalSchemaManager->listTableIndexes($this->tableName($table))));
  }

  /**
   * {@inheritdoc}
   */
  public function addPrimaryKey($table, $fields) {
    if (!$this->tableExists($table)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot add primary key to table @table: table doesn't exist.", ['@table' => $table]));
    }

    if ($this->dbalSchema()->getTable($this->tableName($table))->hasPrimaryKey()) {
      throw new SchemaObjectExistsException(t("Cannot add primary key to table @table: primary key already exists.", ['@table' => $table]));
    }

    // Delegate to DBAL extension.
    if ($this->dbalExtension->delegateAddPrimaryKey($this->dbalSchema(), $table, $fields)) {
      $this->dbalSchemaForceReload();
      return;
    }

    // DBAL extension did not pick up, proceed with DBAL.
    $current_schema = $this->dbalSchema();
    $to_schema = clone $current_schema;
    $to_schema->getTable($this->tableName($table))->setPrimaryKey($this->dbalGetFieldList($fields));
    $this->dbalExecuteSchemaChange($to_schema);
  }

  /**
   * {@inheritdoc}
   */
  public function dropPrimaryKey($table) {
    if (!$this->tableExists($table)) {
      return FALSE;
    }
    if (!$this->dbalSchema()->getTable($this->tableName($table))->hasPrimaryKey()) {
      return FALSE;
    }
    $current_schema = $this->dbalSchema();
    $to_schema = clone $current_schema;
    $to_schema->getTable($this->tableName($table))->dropPrimaryKey();
    $this->dbalExecuteSchemaChange($to_schema);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function addUniqueKey($table, $name, $fields) {
    if (!$this->tableExists($table)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot add unique key @name to table @table: table doesn't exist.", ['@table' => $table, '@name' => $name]));
    }
    if ($this->indexExists($table, $name)) {
      throw new SchemaObjectExistsException(t("Cannot add unique key @name to table @table: unique key already exists.", ['@table' => $table, '@name' => $name]));
    }

    // Delegate to DBAL extension.
    if ($this->dbalExtension->delegateAddUniqueKey($table, $name, $fields)) {
      $this->dbalSchemaForceReload();
      return;
    }

    // DBAL extension did not pick up, proceed with DBAL.
    $index_name = $this->dbalExtension->delegateGetIndexName($table, $name, $this->getPrefixInfo($table));
    $current_schema = $this->dbalSchema();
    $to_schema = clone $current_schema;
    $to_schema->getTable($this->tableName($table))->addUniqueIndex($this->dbalGetFieldList($fields), $index_name);
    $this->dbalExecuteSchemaChange($to_schema);
  }

  /**
   * {@inheritdoc}
   */
  public function dropUniqueKey($table, $name) {
    return $this->dropIndex($table, $name);
  }

  /**
   * {@inheritdoc}
   */
  public function addIndex($table, $name, $fields, array $spec) {
    if (!$this->tableExists($table)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot add index @name to table @table: table doesn't exist.", ['@table' => $table, '@name' => $name]));
    }
    if ($this->indexExists($table, $name)) {
      throw new SchemaObjectExistsException(t("Cannot add index @name to table @table: index already exists.", ['@table' => $table, '@name' => $name]));
    }

    // Delegate to DBAL extension.
    if ($this->dbalExtension->delegateAddIndex($table, $name, $fields, $spec)) {
      $this->dbalSchemaForceReload();
      return;
    }

    // DBAL extension did not pick up, proceed with DBAL.
    $index_name = $this->dbalExtension->delegateGetIndexName($table, $name, $this->getPrefixInfo($table));
    $current_schema = $this->dbalSchema();
    $to_schema = clone $current_schema;
    $to_schema->getTable($this->tableName($table))->addIndex($this->dbalGetFieldList($fields), $index_name);
    $this->dbalExecuteSchemaChange($to_schema);
  }

  /**
   * {@inheritdoc}
   */
  public function dropIndex($table, $name) {
    if (!$this->indexExists($table, $name)) {
      return FALSE;
    }
    $current_schema = $this->dbalSchema();
    $to_schema = clone $current_schema;
    $to_schema->getTable($this->tableName($table))->dropIndex($name);
    $this->dbalExecuteSchemaChange($to_schema);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function changeField($table, $field, $field_new, $spec, $keys_new = []) {
    if (!$this->fieldExists($table, $field)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot change the definition of field @table.@name: field doesn't exist.", ['@table' => $table, '@name' => $field]));
    }
    if (($field != $field_new) && $this->fieldExists($table, $field_new)) {
      throw new SchemaObjectExistsException(t("Cannot rename field @table.@name to @name_new: target field already exists.", ['@table' => $table, '@name' => $field, '@name_new' => $field_new]));
    }

    $dbal_type = $this->getDbalColumnType($spec);
    $dbal_column_options = $this->getDbalColumnOptions('changeField', $field_new, $dbal_type, $spec);
    // DBAL is limited here, if we pass only 'columnDefinition' to
    // ::changeColumn the schema diff will not capture any change. We need to
    // fallback to platform specific syntax.
    // @see https://github.com/doctrine/dbal/issues/1033
    $primary_key_processed_by_extension = FALSE;
    if (!$this->dbalExtension->delegateChangeField($primary_key_processed_by_extension, $table, $field, $field_new, $spec, $keys_new, $dbal_column_options)) {
      return;
    }
    // We need to reload the schema at next get.
    $this->dbalSchemaForceReload();

    // New primary key.
    if (!empty($keys_new['primary key']) && !$primary_key_processed_by_extension) {
      // Drop the existing one before altering the table.
      $this->dropPrimaryKey($table);
      $this->addPrimaryKey($table, $keys_new['primary key']);
    }

    // Add unique keys.
    if (!empty($keys_new['unique keys'])) {
      foreach ($keys_new['unique keys'] as $key => $fields) {
        $this->addUniqueKey($table, $key, $fields);
      }
    }

    // Add indexes.
    if (!empty($keys_new['indexes'])) {
      foreach ($keys_new['indexes'] as $index => $fields) {
        $this->addIndex($table, $index, $fields, $keys_new);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function prepareComment($comment, $length = NULL) {
    // Truncate comment to maximum comment length.
    if (isset($length)) {
      // Add table prefixes before truncating.
      $comment = Unicode::truncate($comment, $length, TRUE, TRUE);
    }
    // Remove semicolons to avoid triggering multi-statement check.
    $comment = strtr($comment, [';' => '.']);
    return $comment;
  }

  /**
   * Retrieves a table or column comment.
   *
   * @param string $table
   *   The name of the table.
   * @param string $column
   *   (Optional) The name of the column.
   *
   * @return string|null
   *   The comment string or NULL if the comment is not supported.
   *
   * @todo remove once https://www.drupal.org/node/2879677 (Decouple getting
   *   table vs column comments in Schema) is in.
   */
  public function getComment($table, $column = NULL) {
    if ($column === NULL) {
      try {
        return $this->getTableComment($table);
      }
      catch (\RuntimeException $e) {
        return NULL;
      }
    }
    else {
      try {
        return $this->getColumnComment($table, $column);
      }
      catch (\RuntimeException$e) {
        return NULL;
      }
    }
  }

  /**
   * Retrieves a table comment.
   *
   * By default this is not supported. Drivers implementations should override
   * this method if returning comments is supported.
   *
   * @param string $table
   *   The name of the table.
   *
   * @return string|null
   *   The comment string.
   *
   * @throws \RuntimeExceptions
   *   When table comments are not supported.
   *
   * @todo remove docblock once https://www.drupal.org/node/2879677
   *   (Decouple getting table vs column comments in Schema) is in.
   */
  public function getTableComment($table) {
    return $this->dbalExtension->delegateGetTableComment($this->dbalSchema(), $table);
  }

  /**
   * Retrieves a column comment.
   *
   * By default this is not supported. Drivers implementations should override
   * this method if returning comments is supported.
   *
   * @param string $table
   *   The name of the table.
   * @param string $column
   *   The name of the column.
   *
   * @return string|null
   *   The comment string.
   *
   * @throws \RuntimeExceptions
   *   When table comments are not supported.
   *
   * @todo remove docblock once https://www.drupal.org/node/2879677
   *   (Decouple getting table vs column comments in Schema) is in.
   */
  public function getColumnComment($table, $column) {
    return $this->dbalExtension->delegateGetColumnComment($this->dbalSchema(), $table, $column);
  }

  /**
   * {@inheritdoc}
   */
  public function tableExists($table) {
    $result = NULL;
    if ($this->dbalExtension->delegateTableExists($result, $table)) {
      return $result;
    }

    // DBAL extension did not pick up, proceed with DBAL.
    return $this->dbalSchemaManager->tablesExist([$this->tableName($table)]);
  }

  /**
   * {@inheritdoc}
   */
  public function fieldExists($table, $column) {
    $result = NULL;
    if ($this->dbalExtension->delegateFieldExists($result, $table, $column)) {
      return $result;
    }

    // DBAL extension did not pick up, proceed with DBAL.
    if (!$this->tableExists($table)) {
      return FALSE;
    }
    return in_array($column, array_keys($this->dbalSchemaManager->listTableColumns($this->tableName($table))));
  }

  /**
   * Builds and returns the DBAL schema of the database.
   *
   * @return \Doctrine\DBAL\Schema\Schema
   *   The DBAL schema of the database.
   */
  protected function dbalSchema() {
    if ($this->dbalCurrentSchema === NULL) {
      $this->dbalSetCurrentSchema($this->dbalSchemaManager->createSchema());
    }
    return $this->dbalCurrentSchema;
  }

  /**
   * Sets the DBAL schema of the database.
   *
   * @param \Doctrine\DBAL\Schema\Schema $dbal_schema
   *   The DBAL schema of the database.
   *
   * @return $this
   */
  protected function dbalSetCurrentSchema(DbalSchema $dbal_schema = NULL) {
    $this->dbalCurrentSchema = $dbal_schema;
    return $this;
  }

  /**
   * Forces a reload of the DBAL schema.
   *
   * @return $this
   */
  protected function dbalSchemaForceReload() {
    return $this->dbalSetCurrentSchema(NULL);
  }

  /**
   * Executes the DDL statements required to change the schema.
   *
   * @param \Doctrine\DBAL\Schema\Schema $to_schema
   *   The destination DBAL schema.
   *
   * @return bool
   *   TRUE if no exceptions were raised.
   */
  protected function dbalExecuteSchemaChange($to_schema) {
    foreach ($this->dbalSchema()->getMigrateToSql($to_schema, $this->dbalPlatform) as $sql) {
      $this->connection->getDbalConnection()->exec($sql);
    }
    $this->dbalSetCurrentSchema($to_schema);
    return TRUE;
  }

  /**
   * Gets the list of columns from Drupal field specs.
   *
   * Normalizes fields with length to field name only.
   *
   * @param array[] $fields
   *   An array of field description arrays, as specified in the schema
   *   documentation.
   *
   * @return string[]
   *   The list of columns.
   */
  protected function dbalGetFieldList(array $fields) {
    $return = [];
    foreach ($fields as $field) {
      if (is_array($field)) {
        $return[] = $field[0];
      }
      else {
        $return[] = $field;
      }
    }
    return $return;
  }

  /**
   * {@inheritdoc}
   */
  public function findTables($table_expression) {
    $individually_prefixed_tables = $this->connection->getUnprefixedTablesMap();
    $default_prefix = $this->connection->tablePrefix();
    $default_prefix_length = strlen($default_prefix);

    $tables = [];
    foreach ($this->dbalSchemaManager->listTableNames() as $table_name) {
      // Take into account tables that have an individual prefix.
      if (isset($individually_prefixed_tables[$table_name])) {
        $prefix_length = strlen($this->connection->tablePrefix($individually_prefixed_tables[$table_name]));
      }
      elseif ($default_prefix && substr($table_name, 0, $default_prefix_length) !== $default_prefix) {
        // This table name does not start the default prefix, which means that
        // it is not managed by Drupal so it should be excluded from the result.
        continue;
      }
      else {
        $prefix_length = $default_prefix_length;
      }

      // Remove the prefix from the returned tables.
      $unprefixed_table_name = substr($table_name, $prefix_length);

      // The pattern can match a table which is the same as the prefix. That
      // will become an empty string when we remove the prefix, which will
      // probably surprise the caller, besides not being a prefixed table. So
      // remove it.
      if (!empty($unprefixed_table_name)) {
        $tables[$unprefixed_table_name] = $unprefixed_table_name;
      }
    }

    // Convert the table expression from its SQL LIKE syntax to a regular
    // expression and escape the delimiter that will be used for matching.
    $table_expression = str_replace(['%', '_'], ['.*?', '.'], preg_quote($table_expression, '/'));
    $tables = preg_grep('/^' . $table_expression . '$/i', $tables);

    return $tables;
  }

}
